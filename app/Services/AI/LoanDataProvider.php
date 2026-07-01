<?php

namespace App\Services\AI;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\Loans;

class LoanDataProvider extends BaseController
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public function getLoanDetails(string $loanNumber): array
    {
        $company = $this->getCompanyId();
        $userZones = $this->getUserZones();
        $userBranches = $this->getUserBranches();
        $isCEO = $this->hasPermission(21);
        $isOfficer = $this->hasPermission(19);
        $isManager = $this->hasPermission(20);
        $loan = Loans::where('company', $company)
            ->where('loan_number', $loanNumber)
            ->where('status', '!=', 9);
        if ($isOfficer) {
            $loan->whereIn('zone', $userZones);
        }
        $loan = $loan->first();

        if (!$loan) {
            return ['error' => 'Mkopo haujapatikana.'];
        }

        return [
            'loan_number' => $loan->loan_number,
            'outstanding_balance' => (float)$loan->outstanding_balance,
            'status' => $loan->status_label,
            'days_overdue' => $loan->days_overdue ?? 0
        ];
    }
}
