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

            // Beem rejects (400 API_UNSUPPORTED_VALUE) any message containing
            // a character outside the plain GSM 7-bit alphabet unless Unicode
            // encoding is explicitly requested — normalize the common "smart"
            // punctuation callers reach for by habit (em/en dashes, curly
            // quotes, ellipsis) to their plain-ASCII equivalents so this
            // never has to depend on getting an unconfirmed encoding value
            // right, and messages stay in the cheaper 160-char GSM7 segments
            // instead of Unicode's 70-char ones.
            $message = $this->normalizeToGsm7($message);

            // Log SMS attempt
            Log::info('Sending SMS', [
                'phone' => $phone,
                'message' => $message,
                'company' => $company
            ]);

            if (! $this->isGsm7Safe($message)) {
                Log::warning('SMS message still contains non-GSM7 characters after normalization — Beem may reject it', [
                    'phone' => $phone,
                    'message' => $message,
                ]);
            }

            $api_key = config('services.beem.api_key', '26fba7e5c594adf4');
            $secret_key = config('services.beem.secret_key', 'NTAzMTYyMDIwZWU0ZDgxMDQ5NDcyNjRjOTk0OTg3ZTRlNTIyNDA1NzZhYTU3MjFmMjcxNzAyNzY0OGUwY2E2ZQ==');

            $postData = [
                'source_addr' => 'CMS',
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
     * Replace common "smart"/typographic punctuation with the plain-ASCII
     * equivalent GSM7 actually supports, so a caller reaching for an em
     * dash or a curly quote (easy to do without thinking about it) doesn't
     * silently fail against Beem. Not a full Unicode transliteration —
     * just the handful of characters callers in this codebase realistically
     * type.
     */
    private function normalizeToGsm7(string $message): string
    {
        $replacements = [
            "\u{2014}" => '-',  // em dash —
            "\u{2013}" => '-',  // en dash –
            "\u{2018}" => "'",  // left single quote '
            "\u{2019}" => "'",  // right single quote '
            "\u{201C}" => '"',  // left double quote "
            "\u{201D}" => '"',  // right double quote "
            "\u{2026}" => '...', // ellipsis …
            "\u{00A0}" => ' ',  // non-breaking space
            "\u{2022}" => '-',  // bullet •
        ];

        return strtr($message, $replacements);
    }

    /**
     * Whether every character in the message is in the GSM 03.38 default
     * alphabet (the basic Latin letters/digits/punctuation Beem accepts
     * without Unicode encoding). Used only to log a warning when
     * normalizeToGsm7() couldn't clean up everything — this app has no
     * confirmed way to safely request Unicode encoding from Beem, so a
     * message that's still non-GSM7 at this point will likely still be
     * rejected; better to know from the log than guess at an API value.
     */
    private function isGsm7Safe(string $message): bool
    {
        $gsm7Basic = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ ÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡"
            . "ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
        $gsm7Extended = "{}[]~|€^\\";

        $allowed = $gsm7Basic . $gsm7Extended;

        for ($i = 0, $len = mb_strlen($message); $i < $len; $i++) {
            if (! str_contains($allowed, mb_substr($message, $i, 1))) {
                return false;
            }
        }

        return true;
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