<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Send SMS using Beem Africa API
     * 
     * @param string $phone Recipient phone number
     * @param string $message SMS message content
     * @param string|null $company Company name for logging
     * @return bool Success status
     */
    public function sendSMS($phone, $message, $company = null)
    {
        try {
            // Validate phone number format
            $phone = $this->formatPhoneNumber($phone);
            
            // Log SMS attempt
            Log::info('Sending SMS', [
                'phone' => $phone,
                'message' => $message,
                'company' => $company
            ]);

            $api_key = config('services.beem.api_key', 'cd265ba2a9711dd6');
            $secret_key = config('services.beem.secret_key', 'MzA2ZjNiMDBjNjgwOTQ0Njc2ZjU0MmE5YmU1YzNkZGIwOTcwNzQ5ZWMwY2Q0OWVmM2QyZjI0NmJkNzlhY2VmZg==');

            $postData = [
                'source_addr' => 'TerminalXI',
                'encoding' => 0,
                'schedule_time' => '',
                'message' => $message,
                'recipients' => [
                    [
                        'recipient_id' => '1',
                        'dest_addr' => $phone
                    ]
                ]
            ];

            $url = 'https://apisms.beem.africa/v1/send';

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Basic ' . base64_encode("$api_key:$secret_key"),
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if (curl_error($ch)) {
                throw new \Exception('Curl error: ' . curl_error($ch));
            }
            
            curl_close($ch);

            // Log response
            Log::info('SMS Response', [
                'http_code' => $httpCode,
                'response' => $response
            ]);

            return $httpCode === 200;
            
        } catch (\Exception $e) {
            Log::error('Failed to send SMS', [
                'error' => $e->getMessage(),
                'phone' => $phone,
                'message' => $message
            ]);
            return false;
        }
    }

    /**
     * Format phone number to international format
     */
    private function formatPhoneNumber($phone)
    {
        // Remove any non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Remove leading 0 if present (e.g., 0657... -> 657...)
        if (strlen($phone) === 10 && substr($phone, 0, 1) === '0') {
            $phone = substr($phone, 1);
        }
        
        // Add Tanzania country code if not present
        if (strlen($phone) === 9) {
            $phone = '255' . $phone;
        } elseif (strlen($phone) === 12 && substr($phone, 0, 3) !== '255') {
            $phone = '255' . substr($phone, -9);
        }
        
        return $phone;
    }

    /**
     * Send bulk SMS to multiple recipients
     */
    public function sendBulkSMS(array $recipients, string $message, $company = null)
    {
        $results = [];
        
        foreach ($recipients as $recipient) {
            $phone = is_array($recipient) ? ($recipient['phone'] ?? null) : $recipient;
            if ($phone) {
                $results[$phone] = $this->sendSMS($phone, $message, $company);
            }
        }
        
        return $results;
    }

    /**
     * Send loan approval notification
     */
    public function sendLoanApprovalSMS($customer, $loan, $company)
    {
        $message = "Habari {$customer->fullname},\n\n" .
                   "Mkopo wako wa TZS " . number_format($loan->principal_amount, 0) . " umekubaliwa!\n" .
                   "Namba ya mkopo: {$loan->loan_number}\n\n" .
                   "Wasiliana nasi kwa maelezo zaidi.\n" .
                   "Asante kwa kuchagua {$company->company_name}.";
        
        return $this->sendSMS($customer->phone, $message, $company->company_name);
    }

    /**
     * Send loan disbursement notification
     */
    public function sendLoanDisbursementSMS($customer, $loan, $company)
    {
        $message = "Habari {$customer->fullname},\n\n" .
                   "Mkopo wako wa TZS " . number_format($loan->principal_amount, 0) . " umetolewa!\n" .
                   "Namba ya mkopo: {$loan->loan_number}\n\n" .
                   "Asante kwa kuchagua {$company->company_name}.";
        
        return $this->sendSMS($customer->phone, $message, $company->company_name);
    }

    /**
     * Send payment received notification
     */
    public function sendPaymentReceivedSMS($customer, $payment, $company)
    {
        $message = "Habari {$customer->fullname},\n\n" .
                   "Malipo yako ya TZS " . number_format($payment->amount, 0) . " yamepokelewa!\n" .
                   "Namba ya mkopo: {$payment->loan_number}\n\n" .
                   "Asante kwa malipo yako.\n" .
                   "Wasiliana nasi kwa maelezo zaidi.";
        
        return $this->sendSMS($customer->phone, $message, $company->company_name);
    }

    /**
     * Send loan reminder notification
     */
    public function sendLoanReminderSMS($customer, $schedule, $company)
    {
        $dueDate = \Carbon\Carbon::parse($schedule->payment_due_date)->format('d/m/Y');
        $message = "Habari {$customer->fullname},\n\n" .
                   "Kumbusho: Malipo yako ya TZS " . number_format($schedule->payment_total_amount, 0) . " yanatarajiwa tarehe {$dueDate}.\n" .
                   "Namba ya mkopo: {$schedule->loan_number}\n\n" .
                   "Asante kwa ushirikiano wako.";
        
        return $this->sendSMS($customer->phone, $message, $company->company_name);
    }

    /**
     * Send overdue payment notification
     */
    public function sendOverduePaymentSMS($customer, $schedule, $company)
    {
        $dueDate = \Carbon\Carbon::parse($schedule->payment_due_date)->format('d/m/Y');
        $message = "Habari {$customer->fullname},\n\n" .
                   "Kumbusho: Malipo yako ya TZS " . number_format($schedule->payment_total_amount, 0) . " yamechelewa.\n" .
                   "Tarehe ya malipo: {$dueDate}\n" .
                   "Namba ya mkopo: {$schedule->loan_number}\n\n" .
                   "Tafadhali wasiliana nasi kwa maelezo zaidi.";
        
        return $this->sendSMS($customer->phone, $message, $company->company_name);
    }
}