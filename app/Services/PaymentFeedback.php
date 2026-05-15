<?php

namespace App\Services;

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
                $paid = $payment->amount;

                $balance = $loan->total_loan - $loan->loan_paid - $paid;
                $penalty = $loan->penalty_amount;
                if ($paid <= 0) {
                } else {
                    $phone = $customer->phone;
                    $message =
                        "MALIPO YAMEPOKELEWA\n 
            Mkopo : " . $loan->loan_number . " 
            kiasi :  " . $paid . " 
            Rejesho la  " . $schedule->payment_due_date . "
            Deni la sasa : " . $balance;
                    $this->notificationService->sendSms($phone, $message);
                }
            }
        }
    }
}
