<?php

namespace App\Services\Kikoba;

use App\Models\KikobaAccount;
use App\Models\KikobaAccountTransaction;
use App\Models\KikobaContribution;
use App\Models\KikobaLoan;
use App\Models\KikobaLoanRepayment;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Posts ledger entries against a Kikoba account and keeps its running
 * balance in sync. Mirrors BankController::registerTransaction()'s shape
 * (opening/closing balance snapshot per entry) but scoped to Kikoba groups,
 * each of which may register more than one account.
 */
class KikobaAccountService
{
    /**
     * The account auto-posting falls back to when nothing more specific is
     * known — a group's designated primary, or null if it has none (or none
     * registered at all).
     */
    private function primaryAccountFor(int $groupId): ?KikobaAccount
    {
        return KikobaAccount::where('kikoba_group_id', $groupId)->where('is_primary', true)->first();
    }

    /**
     * @throws InvalidArgumentException when a debit would overdraw the account
     */
    public function post(
        KikobaAccount $account,
        string $type,
        float $amount,
        string $transactionDate,
        string $source = 'manual',
        ?int $registeredBy = null,
        ?string $referenceNumber = null,
        ?string $description = null,
        ?int $contributionId = null,
        ?int $loanId = null
    ): KikobaAccountTransaction {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Transaction amount must be greater than zero.');
        }

        return DB::transaction(function () use (
            $account, $type, $amount, $transactionDate, $source,
            $registeredBy, $referenceNumber, $description, $contributionId, $loanId
        ) {
            /** @var KikobaAccount $locked */
            $locked = KikobaAccount::lockForUpdate()->findOrFail($account->id);

            $opening = (float) $locked->balance;

            if ($type === 'debit' && $opening < $amount) {
                throw new InvalidArgumentException(
                    "Insufficient balance in account {$locked->account_name} (available: " .
                        number_format($opening, 2) . ', required: ' . number_format($amount, 2) . ')'
                );
            }

            $closing = $type === 'credit' ? $opening + $amount : $opening - $amount;
            $locked->update(['balance' => $closing]);

            return KikobaAccountTransaction::create([
                'company_id' => $locked->company_id,
                'kikoba_account_id' => $locked->id,
                'kikoba_group_id' => $locked->kikoba_group_id,
                'transaction_type' => $type,
                'amount' => $amount,
                'opening_balance' => $opening,
                'closing_balance' => $closing,
                'transaction_date' => $transactionDate,
                'source' => $source,
                'kikoba_contribution_id' => $contributionId,
                'kikoba_loan_id' => $loanId,
                'reference_number' => $referenceNumber,
                'description' => $description,
                'registered_by' => $registeredBy,
            ]);
        });
    }

    /**
     * Auto-post a contribution as a credit to its group's PRIMARY account. A
     * group without one (no accounts at all, or none marked primary) simply
     * doesn't get a ledger entry — banking is additive, not required, so
     * this never blocks a contribution.
     */
    public function creditForContribution(int $groupId, float $amount, KikobaContribution $contribution): void
    {
        $account = $this->primaryAccountFor($groupId);
        if (! $account) {
            return;
        }

        $this->post(
            account: $account,
            type: 'credit',
            amount: $amount,
            transactionDate: $contribution->paid_date ?? now()->toDateString(),
            source: 'contribution',
            registeredBy: $contribution->received_by,
            description: 'Member contribution',
            contributionId: $contribution->id
        );
    }

    /**
     * Disburse an approved loan against the given (or the group's single)
     * account. Unlike the contribution auto-credit, this is the explicit
     * point money actually moves, so a missing account or insufficient
     * balance both hard-fail here rather than silently no-op'ing.
     *
     * For a 'deducted_upfront' product ($upfrontInterest > 0), this posts
     * TWO ledger legs rather than a single net debit: the full approved
     * amount leaves as a 'loan_disbursement', then the interest portion is
     * immediately credited back as 'interest_income' — net change to the
     * balance is the same either way, but this keeps the loan's full face
     * value and the interest actually earned both visible in the ledger,
     * instead of only ever seeing the netted-down disbursement. Solvency is
     * checked once against that net amount, not the intermediate full debit,
     * so a balance that only covers the net amount isn't wrongly rejected.
     *
     * @throws InvalidArgumentException when there's no account to disburse
     *                                   from, or it can't cover the net amount
     */
    public function disburseLoan(
        KikobaLoan $loan,
        ?int $accountId,
        float $approvedAmount,
        float $upfrontInterest,
        string $disbursementDate,
        int $disbursedBy
    ): KikobaAccount {
        $account = $accountId
            ? KikobaAccount::where('company_id', $loan->company_id)->find($accountId)
            : $this->primaryAccountFor($loan->kikoba_group_id);

        if (! $account) {
            throw new InvalidArgumentException(
                'No account found to disburse from. Register an account for this group first.'
            );
        }

        $netAmount = round($approvedAmount - $upfrontInterest, 2);

        return DB::transaction(function () use ($account, $loan, $approvedAmount, $upfrontInterest, $netAmount, $disbursementDate, $disbursedBy) {
            /** @var KikobaAccount $locked */
            $locked = KikobaAccount::lockForUpdate()->findOrFail($account->id);
            $opening = (float) $locked->balance;

            if ($opening < $netAmount) {
                throw new InvalidArgumentException(
                    "Insufficient balance in account {$locked->account_name} (available: " .
                        number_format($opening, 2) . ', required: ' . number_format($netAmount, 2) . ')'
                );
            }

            $afterDisbursement = round($opening - $approvedAmount, 2);
            KikobaAccountTransaction::create([
                'company_id' => $locked->company_id,
                'kikoba_account_id' => $locked->id,
                'kikoba_group_id' => $locked->kikoba_group_id,
                'transaction_type' => 'debit',
                'amount' => $approvedAmount,
                'opening_balance' => $opening,
                'closing_balance' => $afterDisbursement,
                'transaction_date' => $disbursementDate,
                'source' => 'loan_disbursement',
                'kikoba_loan_id' => $loan->id,
                'description' => "Loan disbursement — {$loan->loan_number}",
                'registered_by' => $disbursedBy,
            ]);

            $closing = $afterDisbursement;
            if ($upfrontInterest > 0) {
                $closing = round($afterDisbursement + $upfrontInterest, 2);
                KikobaAccountTransaction::create([
                    'company_id' => $locked->company_id,
                    'kikoba_account_id' => $locked->id,
                    'kikoba_group_id' => $locked->kikoba_group_id,
                    'transaction_type' => 'credit',
                    'amount' => $upfrontInterest,
                    'opening_balance' => $afterDisbursement,
                    'closing_balance' => $closing,
                    'transaction_date' => $disbursementDate,
                    'source' => 'interest_income',
                    'kikoba_loan_id' => $loan->id,
                    'description' => "Interest collected upfront — {$loan->loan_number}",
                    'registered_by' => $disbursedBy,
                ]);
            }

            $locked->update(['balance' => $closing]);

            return $locked;
        });
    }

    /**
     * Auto-post a loan repayment as a credit back to the SAME account it was
     * disbursed from (loans.kikoba_account_id) — the mirror image of
     * disburseLoan(). Falls back to the group's primary account for an old
     * loan that predates that column. Additive like the contribution
     * credit: a group with no account just doesn't get a ledger entry, since
     * repayment recording shouldn't be blocked by banking being unset up.
     */
    public function creditLoanRepayment(KikobaLoan $loan, float $amount, KikobaLoanRepayment $repayment): void
    {
        $account = $loan->kikoba_account_id
            ? KikobaAccount::find($loan->kikoba_account_id)
            : $this->primaryAccountFor($loan->kikoba_group_id);

        if (! $account) {
            return;
        }

        $this->post(
            account: $account,
            type: 'credit',
            amount: $amount,
            transactionDate: $repayment->paid_date ?? now()->toDateString(),
            source: 'loan_repayment',
            registeredBy: $repayment->received_by,
            description: "Loan repayment — {$loan->loan_number}",
            loanId: $loan->id
        );
    }
}
