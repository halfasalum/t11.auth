<?php

namespace App\Http\Controllers\Api\V2\Kikoba;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\KikobaAccount;
use App\Models\KikobaGroup;
use App\Services\Kikoba\KikobaAccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Kikoba bank accounts — a group can register more than one, each holding
 * its own pooled funds. Manual deposit/withdraw/transfer here mirror
 * BankController's shape; contribution auto-crediting and any loan
 * repayment whose disbursement account is unknown target the group's
 * PRIMARY account (see KikobaAccountService) — everything else (loan
 * disbursement, manual transactions) always names an explicit account.
 */
class KikobaAccountController extends BaseController
{
    public function index(Request $request)
    {
        $query = KikobaAccount::where('company_id', $this->getCompanyId())
            ->with(['group', 'creator:id,name,first_name,last_name']);

        if ($request->filled('kikoba_group_id')) {
            $query->where('kikoba_group_id', $request->integer('kikoba_group_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $accounts = $query->orderBy('account_name')->paginate((int) $request->input('per_page', 20));

        return $this->successResponse($this->paginateResponse($accounts));
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'kikoba_group_id' => 'required|integer|exists:kikoba_groups,id',
                'account_name' => 'required|string|max:255',
                'initial_balance' => 'nullable|numeric|min:0',
                'currency' => 'nullable|string|size:3',
                'description' => 'nullable|string|max:500',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $group = KikobaGroup::where('company_id', $this->getCompanyId())->find($data['kikoba_group_id']);
        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        // The group's first account is automatically its primary — the one
        // contribution auto-crediting targets. Later accounts stay secondary
        // unless explicitly promoted (see setPrimary()).
        $isFirstForGroup = ! KikobaAccount::where('kikoba_group_id', $group->id)->exists();

        $account = KikobaAccount::create([
            'company_id' => $this->getCompanyId(),
            'kikoba_group_id' => $group->id,
            'account_name' => $data['account_name'],
            'account_number' => $this->generateAccountNumber($group),
            'balance' => 0,
            'currency' => $data['currency'] ?? 'TZS',
            'is_primary' => $isFirstForGroup,
            'description' => $data['description'] ?? null,
            'created_by' => $this->getUserId(),
        ]);

        $initialBalance = (float) ($data['initial_balance'] ?? 0);
        if ($initialBalance > 0) {
            app(KikobaAccountService::class)->post(
                account: $account,
                type: 'credit',
                amount: $initialBalance,
                transactionDate: now()->toDateString(),
                registeredBy: $this->getUserId(),
                description: 'Initial account funding'
            );
        }

        return $this->successResponse($account->fresh()->load('group'), 'Account registered successfully', 201);
    }

    /**
     * Designate this account as its group's primary (unsets any other
     * primary in the same group). The primary is where contribution
     * auto-crediting lands, and the fallback target for a repayment whose
     * loan somehow doesn't know which account it was disbursed from.
     */
    public function setPrimary(int $id)
    {
        $account = KikobaAccount::where('company_id', $this->getCompanyId())->find($id);
        if (! $account) {
            return $this->errorResponse('Account not found', 404);
        }

        DB::transaction(function () use ($account) {
            KikobaAccount::where('kikoba_group_id', $account->kikoba_group_id)
                ->where('id', '!=', $account->id)
                ->update(['is_primary' => false]);

            $account->update(['is_primary' => true]);
        });

        return $this->successResponse($account->fresh(), 'Account set as primary');
    }

    public function show(int $id)
    {
        $account = KikobaAccount::where('company_id', $this->getCompanyId())
            ->with(['group', 'creator:id,name,first_name,last_name'])
            ->find($id);

        if (! $account) {
            return $this->errorResponse('Account not found', 404);
        }

        return $this->successResponse($account);
    }

    public function transactions(Request $request, int $id)
    {
        $account = KikobaAccount::where('company_id', $this->getCompanyId())->find($id);
        if (! $account) {
            return $this->errorResponse('Account not found', 404);
        }

        $transactions = $account->transactions()
            ->with('registeredBy:id,name,first_name,last_name')
            ->paginate((int) $request->input('per_page', 20));

        return $this->successResponse($this->paginateResponse($transactions));
    }

    public function deposit(Request $request, int $id, KikobaAccountService $service)
    {
        return $this->postManual($request, $id, 'credit', $service);
    }

    public function withdraw(Request $request, int $id, KikobaAccountService $service)
    {
        return $this->postManual($request, $id, 'debit', $service);
    }

    private function postManual(Request $request, int $id, string $type, KikobaAccountService $service)
    {
        try {
            $data = $request->validate([
                'amount' => 'required|numeric|min:0.01',
                'transaction_date' => 'required|date|date_format:Y-m-d',
                'reference_number' => 'nullable|string|max:100',
                'description' => 'nullable|string|max:500',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $account = KikobaAccount::where('company_id', $this->getCompanyId())->find($id);
        if (! $account) {
            return $this->errorResponse('Account not found', 404);
        }

        try {
            $service->post(
                account: $account,
                type: $type,
                amount: (float) $data['amount'],
                transactionDate: $data['transaction_date'],
                registeredBy: $this->getUserId(),
                referenceNumber: $data['reference_number'] ?? null,
                description: $data['description'] ?? ($type === 'credit' ? 'Deposit' : 'Withdrawal')
            );
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse(
            $account->fresh(),
            $type === 'credit' ? 'Deposit completed successfully' : 'Withdrawal completed successfully'
        );
    }

    public function transfer(Request $request, KikobaAccountService $service)
    {
        try {
            $data = $request->validate([
                'from_account_id' => 'required|integer|exists:kikoba_accounts,id',
                'to_account_id' => 'required|integer|different:from_account_id|exists:kikoba_accounts,id',
                'amount' => 'required|numeric|min:0.01',
                'transaction_date' => 'required|date|date_format:Y-m-d',
                'reference_number' => 'nullable|string|max:100',
                'description' => 'nullable|string|max:500',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $companyId = $this->getCompanyId();
        $fromAccount = KikobaAccount::where('company_id', $companyId)->find($data['from_account_id']);
        $toAccount = KikobaAccount::where('company_id', $companyId)->find($data['to_account_id']);

        if (! $fromAccount || ! $toAccount) {
            return $this->errorResponse('One or both accounts not found', 404);
        }

        $reference = $data['reference_number'] ?? ('TRF-' . now()->format('ymdHis') . '-' . Str::upper(Str::random(4)));

        try {
            $service->post(
                account: $fromAccount,
                type: 'debit',
                amount: (float) $data['amount'],
                transactionDate: $data['transaction_date'],
                registeredBy: $this->getUserId(),
                referenceNumber: $reference,
                description: "Transfer to {$toAccount->account_name}" . (isset($data['description']) ? ": {$data['description']}" : '')
            );

            $service->post(
                account: $toAccount,
                type: 'credit',
                amount: (float) $data['amount'],
                transactionDate: $data['transaction_date'],
                registeredBy: $this->getUserId(),
                referenceNumber: $reference,
                description: "Transfer from {$fromAccount->account_name}" . (isset($data['description']) ? ": {$data['description']}" : '')
            );
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse([
            'from_account' => $fromAccount->fresh(),
            'to_account' => $toAccount->fresh(),
            'amount' => (float) $data['amount'],
        ], 'Transfer completed successfully');
    }

    private function generateAccountNumber(KikobaGroup $group): string
    {
        $prefix = 'KAC-' . ($group->code ?: $group->id);

        return $prefix . '-' . Str::upper(Str::random(6));
    }
}
