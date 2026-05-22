<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Customers;
use App\Models\Loans;
use App\Models\LoanSchedules;
use App\Models\PaymentSubmissions;

class PaymentFeedback
{

    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }
    /**
     * Create a new class instance.
     */
    public function sendFeedback($scheduleId = null)
    {
        if (!is_null($scheduleId)) {
            $payment = PaymentSubmissions::where('schedule_id', $scheduleId)
                ->where('submission_status', 11)
                ->first();
            $loan = Loans::where('loan_number', $payment->loan_number)->first();
            $schedule = LoanSchedules::find($payment->schedule_id);
            $customer = Customers::find($loan->customer);

            if ($payment) {
                $company = Company::find($payment->company);
                $company_name = $company->company_name;
                $company_phone = $company->company_phone;
                $paid = $payment->amount;

                $balance = $loan->total_loan - $loan->loan_paid - $paid;
                $penalty = $loan->penalty_amount;
                $phone = $customer->phone;
                if ($paid <= 0) {
                    $message =
                        "MALIPO HAYAJAPOKELEWA\nMkopo : " . $loan->loan_number . "\nkiasi :  " . $paid . "\nRejesho la  " . $schedule->payment_due_date . "\nDeni la sasa : " . $balance . "\nJumla ya penati : " . $penalty . "\n" . $company_name . "\n" . $company_phone;
                } else {
                    $message =
                        "MALIPO YAMEPOKELEWA\nMkopo : " . $loan->loan_number . "\nkiasi :  " . $paid . "\nRejesho la  " . $schedule->payment_due_date . "\nDeni la sasa : " . $balance . "\n\n" . $company_name . "\n" . $company_phone;
                }
                $this->notificationService->sendSms($phone, $message);
            }
        }
    }
}
