<?php

namespace App\Services\Kikoba;

use App\Models\KikobaAccount;
use App\Models\KikobaAccountTransaction;
use App\Models\KikobaContribution;
use App\Models\KikobaLoan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Posts ledger entries against a group's Kikoba account and keeps its
 * running balance in sync. Mirrors BankController::registerTransaction()'s
 * shape (opening/closing balance snapshot per entry) but scoped to one
 * account per Kikoba group instead of company-wide branch/zone accounts.
 */
class KikobaAccountService
{
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
     * Auto-post a contribution as a credit to its group's account. A group
     * without a registered account simply doesn't get a ledger entry —
     * banking is additive, not required, so this never blocks a contribution.
     */
    public function creditForContribution(int $groupId, float $amount, KikobaContribution $contribution): void
    {
        $account = KikobaAccount::where('kikoba_group_id', $groupId)->first();
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
     * Disburse an approved loan: debits the given (or the group's single)
     * account and records the ledger entry. Unlike the contribution
     * auto-credit, this is the explicit point money actually moves, so a
     * missing account or insufficient balance both hard-fail here rather
     * than silently no-op'ing.
     *
     * @throws InvalidArgumentException when there's no account to disburse
     *                                   from, or it can't cover the amount
     */
    public function disburseLoan(KikobaLoan $loan, ?int $accountId, float $amount, string $disbursementDate, int $disbursedBy): KikobaAccount
    {
        $account = $accountId
            ? KikobaAccount::where('company_id', $loan->company_id)->find($accountId)
            : KikobaAccount::where('kikoba_group_id', $loan->kikoba_group_id)->first();

        if (! $account) {
            throw new InvalidArgumentException(
                'No account found to disburse from. Register an account for this group first.'
            );
        }

        $this->post(
            account: $account,
            type: 'debit',
            amount: $amount,
            transactionDate: $disbursementDate,
            source: 'loan_disbursement',
            registeredBy: $disbursedBy,
            description: "Loan disbursement — {$loan->loan_number}",
            loanId: $loan->id
        );

        return $account;
    }
}
