<?php

namespace App\Services;

use AllanBernier\LaravelGpt\ChatGPT;
use App\Models\Customer;
use App\Models\Loans;
use App\Models\PaymentSubmission;
use App\Models\PaymentSubmissions;
use Illuminate\Support\Facades\Log;

class AIAssistantServiceBK
{
    protected $chatGPT;

    public function __construct()
    {
        $this->chatGPT = ChatGPT::new();
    }

    /**
     * Get loan summary for AI context
     */
    public function getLoanContext($loanId = null)
    {
        if (!$loanId) {
            return "No specific loan referenced.";
        }

        $loan = Loans::with(['customer', 'product'])->find($loanId);

        if (!$loan) {
            return "Loan not found.";
        }

        $totalPaid = PaymentSubmissions::where('loan_number', $loan->loan_number)
            ->where('submission_status', 11)
            ->sum('amount');

        $remaining = $loan->total_loan - $loan->loan_paid;

        return [
            'loan_number' => $loan->loan_number,
            'customer_name' => $loan->customer->fullname ?? 'N/A',
            'product' => $loan->product->product_name ?? 'N/A',
            'principal' => $loan->principal_amount,
            'interest' => $loan->interest_amount,
            'total_loan' => $loan->total_loan,
            'paid' => $loan->loan_paid,
            'remaining' => $remaining,
            'status' => $loan->status_label,
            'start_date' => $loan->start_date,
            'end_date' => $loan->end_date,
        ];
    }

    /**
     * Send a message to AI and get response
     */
    public function chat($message, $context = [])
    {
        try {
            $systemPrompt = $this->buildSystemPrompt($context);

            $response = $this->chatGPT
                ->messages([
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $message],
                ])
                ->send();

            return [
                'success' => true,
                'response' => $response->content,
                'usage' => $response->usage,
            ];
        } catch (\Exception $e) {
            Log::error('AI Chat Error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Chat with streaming response (real-time)
     */
    public function chatStream($message, $context = [], callable $callback)
    {
        try {
            $systemPrompt = $this->buildSystemPrompt($context);

            $response = $this->chatGPT
                ->messages([
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $message],
                ])
                ->send();

            if ($response->content) {
                $callback($response->content);
            }

            return ['success' => true];
        } catch (\Exception $e) {
            Log::error('AI Stream Error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Build system prompt with loan management context
     */
    protected function buildSystemPrompt($context)
    {
        $loanInfo = $this->getLoanContext($context['loan_id'] ?? null);

        $prompt = "You are an AI assistant for a Loan Management System. ";
        $prompt .= "Your role is to help loan officers, managers, and customers with loan-related inquiries.\n\n";

        $prompt .= "## Your Capabilities:\n";
        $prompt .= "- Answer questions about loan products, applications, and approvals\n";
        $prompt .= "- Explain repayment schedules and calculate payment amounts\n";
        $prompt .= "- Provide loan portfolio summaries and performance insights\n";
        $prompt .= "- Explain loan statuses and what actions can be taken\n";
        $prompt .= "- Help troubleshoot common issues\n\n";

        $prompt .= "## Important Rules:\n";
        $prompt .= "1. Never provide financial advice or predict loan approvals\n";
        $prompt .= "2. Always refer users to contact their loan officer for specific cases\n";
        $prompt .= "3. Be helpful but concise\n";
        $prompt .= "4. Use the context provided to give relevant answers\n\n";

        if ($loanInfo && is_array($loanInfo)) {
            $prompt .= "## Current Loan Context:\n";
            $prompt .= json_encode($loanInfo, JSON_PRETTY_PRINT) . "\n\n";
        }

        $prompt .= "## Current Date: " . now()->format('Y-m-d H:i:s') . "\n";
        $prompt .= "## Environment: " . app()->environment() . "\n\n";
        $prompt .= "Respond in a professional, helpful manner. Format responses with clear sections using markdown when appropriate.";

        return $prompt;
    }
}
