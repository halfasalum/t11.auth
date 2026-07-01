<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\Loans;
use App\Services\AIAssistantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AIController extends BaseController
{
    protected $aiService;

    public function __construct(AIAssistantService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Send a message to AI and get response
     */
    public function chat($message, $context = [])
    {
        try {
            $sessionId = $context['session_id'] ?? $context['user_id'];
            $history = $this->getConversationHistory($sessionId);
            $companyId = $context['company_id'] ?? null;
$aiService = new    AIAssistantService();
            $systemPrompt = $aiService->buildSystemPrompt($context);

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
            ];

            foreach ($history as $hist) {
                $messages[] = ['role' => $hist['role'], 'content' => $hist['content']];
            }

            $messages[] = ['role' => 'user', 'content' => $message];

            // Get available functions
            $functions = $aiService->getAvailableFunctions();

            $response = $aiService->chatGPT
                ->messages($messages)
                ->functions($functions)  // Pass functions to the AI
                ->functionCall('auto')   // Let AI decide when to call
                ->send();

            // Handle function calls if any
            if ($response->functionCall) {
                $functionName = $response->functionCall->name;
                $parameters = json_decode($response->functionCall->arguments, true);

                // Execute the function
                $result = $aiService->executeFunction($functionName, $parameters, $companyId);

                // Add function result to conversation
                $functionResponseMessage = [
                    'role' => 'function',
                    'name' => $functionName,
                    'content' => json_encode($result)
                ];

                $messages[] = $response->functionCall; // The AI's function call
                $messages[] = $functionResponseMessage; // The function result

                // Get final response from AI using the function result
                $finalResponse = $this->chatGPT
                    ->messages($messages)
                    ->send();

                $response = $finalResponse;
            }

            // Save to history
            $history[] = ['role' => 'user', 'content' => $message];
            $history[] = ['role' => 'assistant', 'content' => $response->content];
            $this->saveConversationHistory($sessionId, $history);

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
     * Stream AI response (Server-Sent Events) with memory
     */
    public function chatStream(Request $request)
    {
        $request->validate([
            'message' => 'required|string|min:1',
            'loan_id' => 'nullable|exists:loans,id',
            'session_id' => 'nullable|string',
            'clear_history' => 'nullable|boolean',
            'language' => 'nullable|string|in:sw,en',
        ]);

        $sessionId = $request->session_id ?? $this->getUserId() . '_' . date('Y-m-d');

        // Clear history if requested
        if ($request->clear_history) {
            $this->aiService->clearHistory($sessionId);
        }

        $context = [
            'loan_id' => $request->loan_id,
            'user_id' => $request->user()->id,
            'session_id' => $sessionId,
            'language' => $request->language ?? 'sw',
        ];

        return response()->stream(function () use ($request, $context) {
            $sent = false;

            $this->aiService->chatStream($request->message, $context, function ($chunk) use (&$sent) {
                echo $chunk;
                ob_flush();
                flush();
                $sent = true;
            });

            if (!$sent) {
                $language = $context['language'] ?? 'sw';
                if ($language === 'sw') {
                    echo "Samahani, ninatatizo la kuunganisha. Tafadhali jaribu tena.";
                } else {
                    echo "Sorry, I'm having trouble connecting. Please try again.";
                }
            }
        }, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'no-cache',
        ]);
    }

    /**
     * Get conversation history/summary
     */
    public function getConversationHistory(Request $request)
    {
        $sessionId = $request->session_id ?? $this->getUserId() . '_' . date('Y-m-d');

        // This would need to be implemented in the service
        // For now, return basic info
        return response()->json([
            'success' => true,
            'data' => [
                'session_id' => $sessionId,
                'message' => 'Conversation history is maintained automatically',
            ],
        ]);
    }

    /**
     * Clear conversation history
     */
    public function clearHistory(Request $request)
    {
        $sessionId = $request->session_id ?? $this->getUserId() . '_' . date('Y-m-d');
        $this->aiService->clearHistory($sessionId);

        $language = $request->language ?? 'sw';
        $message = $language === 'sw'
            ? 'Historia ya mazungumzo imefutwa'
            : 'Conversation history cleared';

        return response()->json([
            'success' => true,
            'message' => $message,
        ]);
    }

    /**
     * Get loan summary for AI analysis
     */
    public function getLoanAnalysis($loanId)
    {
        $loan = Loans::with(['customer', 'product', 'schedules'])->findOrFail($loanId);

        $schedules = $loan->schedules;
        $totalDue = $schedules->sum('payment_total_amount');
        $totalPaid = $schedules->sum('paid_amount');
        $onTime = $schedules->where('overdue_flag', 0)->count();
        $overdue = $schedules->where('overdue_flag', 1)->count();

        $analysis = [
            'loan' => [
                'number' => $loan->loan_number,
                'amount' => $loan->principal_amount,
                'interest_rate' => $loan->product->interest_rate ?? 'N/A',
                'status' => $loan->status_label,
                'progress' => $totalDue > 0 ? round(($totalPaid / $totalDue) * 100, 2) : 0,
            ],
            'customer' => [
                'name' => $loan->customer->fullname,
                'phone' => $loan->customer->phone,
            ],
            'repayment' => [
                'total_schedules' => $schedules->count(),
                'paid_schedules' => $schedules->where('paid_amount', '>', 0)->count(),
                'on_time' => $onTime,
                'overdue' => $overdue,
                'total_paid' => $totalPaid,
                'total_remaining' => $totalDue - $totalPaid,
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $analysis,
        ]);
    }
}
