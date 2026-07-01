<?php

namespace App\Services;

use AllanBernier\LaravelGpt\ChatGPT;
use App\Models\Loans;
use App\Models\PaymentSubmissions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AIAssistantService
{
    public $chatGPT;
    protected $maxHistory = 10; // Keep last 10 messages for context

    public function __construct()
    {
        $this->chatGPT = ChatGPT::new();
    }

    /**
     * Get comprehensive loan knowledge base
     */
    protected function getLoanKnowledgeBase(): string
    {
        return <<<'KNOWLEDGE'
## MFUMO WA MIKOPO - MAELEKEZO KAMILI

### 1. HALI ZA MKOPO (LOAN STATUSES)

| Hali | Namba | Maana | Hatua Zinazowezekana |
|------|-------|-------|---------------------|
| **Submitted** | 4 | Mkopo umewasilishwa kwa idhini | Kukagua, kuidhinisha, kukataa |
| **Active** | 5 | Mkopo umetolewa na unalipwa kwa wakati | Kurekodi malipo, kufuatilia |
| **Completed** | 6 | Mkopo umelipwa kikamilifu | Kufunga mkopo, kutoa cheti |
| **Defaulted** | 7 | Mkopo umefeli (haijalipwa kwa muda mrefu) | Kurekebisha, kuchukua hatua za kisheria |
| **Overdue** | 12 | Mkopo umechelewa kulipwa | Kutuma arifa, kukusanya malipo |
| **Rejected** | 9 | Mkopo umekataliwa | Kuwajulisha mteja, kuhifadhi rekodi |
| **Written Off** | 13 | Mkopo umeandikwa kuwa hasara | Kufunga mkopo, kodi |
| **Foreclosure** | 14 | Kukamua rehani au dhamana | Hatua za kisheria, kuuza dhamana |
| **Early Settled** | 15 | Kulipa kabla ya muda | Kupunguziwa riba |
| **Restructured** | 16 | Masharti yamebadilishwa | Ratiba mpya, kupanua muda |

### 2. MAELEZO YA MKOPO (LOAN FIELDS)

- **principal_amount**: Kiasi cha mkopo uliotolewa
- **interest_amount**: Kiasi cha riba
- **total_loan**: Jumla ya mkopo (principal + interest)
- **loan_paid**: Kiasi kilicholipwa hadi sasa
- **penalty_amount**: Faini kwa kuchelewa
- **outstanding_balance**: Salio linalosubiri kulipwa
- **loan_period**: Muda wa mkopo
- **start_date**: Tarehe ya kuanza
- **end_date**: Tarehe ya mwisho

### 3. MATUKIO MAALUM (SPECIAL EVENTS)

**Kukataa Mkopo (Rejection):**
- Hali inakuwa 9 (Rejected)
- Sababu huwekwa kwenye sehemu ya `remarks` au `reason`
- Mteja anaweza kuomba tena baadaye

**Kuandika Hasara (Write Off):**
- Hali inakuwa 13 (Written Off)
- `written_off_amount` = kiasi kilichoandikwa
- `written_off_reason` = sababu ya kuandika hasara
- Hakuna malipo zaidi yanatarajiwa

**Kukamua Rehani (Foreclosure):**
- Hali inakuwa 14 (Foreclosure)
- `foreclosure_status`: initiated, in_progress, completed
- Inahitaji dhamana (collateral)
- Mteja ana muda wa kuikomboa rehani

**Kurekebisha Mkopo (Restructure):**
- Hali inakuwa 16 (Restructured)
- `restructure_count`: ni mara ngapi imerekebishwa
- Ratiba mpya ya malipo inaundwa

**Kulipa Kabla ya Muda (Early Settlement):**
- Hali inakuwa 15 (Early Settled)
- `settlement_discount`: punguzo kwa kulipa kabla ya wakati

### 4. KUKOKOTOA SALIO (BALANCE CALCULATIONS)

outstanding_balance = (total_loan + penalty_amount) - loan_paid
repayment_rate = (loan_paid / total_loan) * 100%
days_overdue = Leo - tarehe_ya_mwisho_ya_malipo

### 5. HATUA ZA KUFUATILIA MKOPO ULIOCHELEWA

1. **Siku 1-30**: Tumia SMS/Kivinjari kukumbusha
2. **Siku 31-60**: Piga simu mteja
3. **Siku 61-90**: Tembelea makazi au biashara
4. **Siku 91+**: Anza mchakato wa kukusanya (collection)
5. **Siku 180+**: Fikiria kuandika hasara (write off)

KNOWLEDGE;
    }

    /**
     * Get real portfolio statistics for a specific company (Enhanced with monthly data)
     */
    public function getRealPortfolioStatistics($companyId)
    {
        if (!$companyId) {
            return null;
        }

        // Get current month's data
        $currentMonthStart = now()->startOfMonth();
        $currentMonthEnd = now()->endOfMonth();

        $stats = Loans::where('company', $companyId)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 5 THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 4 THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 6 THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 12 THEN 1 ELSE 0 END) as overdue,
                SUM(CASE WHEN status = 7 THEN 1 ELSE 0 END) as defaulted,
                SUM(CASE WHEN status = 13 THEN 1 ELSE 0 END) as written_off,
                SUM(principal_amount) as total_disbursed,
                SUM(loan_paid) as total_repaid
            ")->first();

        // Get this month's disbursements
        $monthlyDisbursed = Loans::where('company', $companyId)
            ->whereBetween('created_at', [$currentMonthStart, $currentMonthEnd])
            ->sum('principal_amount');

        // Get this month's collections
        $monthlyCollected = PaymentSubmissions::where('company', $companyId)
            ->where('submission_status', 11)
            ->whereBetween('created_at', [$currentMonthStart, $currentMonthEnd])
            ->sum('amount');

        $outstanding = ($stats->total_disbursed ?? 0) - ($stats->total_repaid ?? 0);
        $collectionRate = $stats->total_disbursed > 0
            ? ($stats->total_repaid / $stats->total_disbursed) * 100
            : 0;
        $monthlyCollectionRate = $monthlyDisbursed > 0
            ? ($monthlyCollected / $monthlyDisbursed) * 100
            : 0;
        $atRisk = ($stats->overdue ?? 0) + ($stats->defaulted ?? 0);

        return [
            'summary' => [
                'total_loans' => (int)($stats->total ?? 0),
                'active' => (int)($stats->active ?? 0),
                'pending' => (int)($stats->pending ?? 0),
                'completed' => (int)($stats->completed ?? 0),
                'overdue' => (int)($stats->overdue ?? 0),
                'defaulted' => (int)($stats->defaulted ?? 0),
                'written_off' => (int)($stats->written_off ?? 0),
            ],
            'financial' => [
                'total_disbursed' => (float)($stats->total_disbursed ?? 0),
                'total_repaid' => (float)($stats->total_repaid ?? 0),
                'outstanding' => (float)$outstanding,
                'collection_rate' => round($collectionRate, 2),
                'monthly_disbursed' => (float)$monthlyDisbursed,
                'monthly_collected' => (float)$monthlyCollected,
                'monthly_collection_rate' => round($monthlyCollectionRate, 2),
            ],
            'risk' => [
                'at_risk_count' => (int)$atRisk,
                'at_risk_percentage' => $stats->total > 0 ? round(($atRisk / $stats->total) * 100, 2) : 0,
            ],
            'current_month' => now()->format('F Y'),
        ];
    }

    /**
     * Get recent overdue loans
     */
    public function getRecentOverdueLoans($companyId, $limit = 5)
    {
        if (!$companyId) {
            return [];
        }

        return Loans::where('company', $companyId)
            ->where('status', Loans::STATUS_OVERDUE)
            ->with('customer')
            ->orderBy('days_overdue', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($loan) {
                return [
                    'loan_number' => $loan->loan_number,
                    'customer_name' => $loan->customer->fullname ?? 'N/A',
                    'days_overdue' => $loan->days_overdue ?? 0,
                    'outstanding' => $loan->outstanding_balance,
                ];
            });
    }

    /**
     * Get comprehensive loan context with all details
     */
    public function getComprehensiveLoanContext($loanId = null, $companyId = null)
    {
        if (!$loanId) {
            return null;
        }

        $loan = Loans::with(['customer', 'product', 'schedules'])
            ->where('id', $loanId)
            ->when($companyId, function ($query) use ($companyId) {
                return $query->where('company', $companyId);
            })
            ->first();

        if (!$loan) {
            return null;
        }

        $totalPaid = PaymentSubmissions::where('loan_number', $loan->loan_number)
            ->where('submission_status', 11)
            ->sum('amount');

        $schedules = $loan->schedules;
        $totalSchedules = $schedules->count();
        $paidSchedules = $schedules->where('paid_amount', '>', 0)->count();
        $overdueSchedules = $schedules->where('overdue_flag', 1)->count();
        $upcomingSchedules = $schedules->where('payment_due_date', '>', now())->count();

        $outstandingBalance = $loan->outstanding_balance;
        $repaymentRate = $loan->total_loan > 0 ? ($loan->loan_paid / $loan->total_loan) * 100 : 0;

        // Get workflow information
        $workflowInfo = [];

        if ($loan->status === Loans::STATUS_DEFAULTED) {
            $workflowInfo['defaulted'] = [
                'date' => $loan->defaulted_date,
                'reason' => $loan->defaulted_reason,
                'by_system' => $loan->defaulted_by_system,
            ];
        }

        if ($loan->status === Loans::STATUS_WRITTEN_OFF) {
            $workflowInfo['written_off'] = [
                'date' => $loan->written_off_date,
                'amount' => $loan->written_off_amount,
                'reason' => $loan->written_off_reason,
            ];
        }

        if ($loan->status === Loans::STATUS_FORECLOSURE) {
            $workflowInfo['foreclosure'] = [
                'date' => $loan->foreclosure_date,
                'status' => $loan->foreclosure_status,
                'reason' => $loan->foreclosure_reason,
                'notice_date' => $loan->foreclosure_notice_date,
                'redemption_date' => $loan->foreclosure_redemption_date,
            ];
        }

        if ($loan->status === Loans::STATUS_RESTRUCTURED) {
            $workflowInfo['restructured'] = [
                'date' => $loan->restructured_at,
                'count' => $loan->restructure_count,
                'reason' => $loan->restructure_reason,
            ];
        }

        if ($loan->status === Loans::STATUS_EARLY_SETTLED) {
            $workflowInfo['early_settled'] = [
                'date' => $loan->settlement_date,
                'amount' => $loan->settlement_amount,
                'discount' => $loan->settlement_discount,
            ];
        }

        return [
            'loan_number' => $loan->loan_number,
            'customer_name' => $loan->customer->fullname ?? 'N/A',
            'customer_phone' => $loan->customer->phone ?? 'N/A',
            'product' => $loan->product->product_name ?? 'N/A',
            'principal_amount' => (float)$loan->principal_amount,
            'interest_amount' => (float)$loan->interest_amount,
            'penalty_amount' => (float)($loan->penalty_amount ?? 0),
            'total_loan' => (float)$loan->total_loan,
            'paid_amount' => (float)$loan->loan_paid,
            'outstanding_balance' => (float)$outstandingBalance,
            'repayment_rate' => round($repaymentRate, 2),
            'start_date' => $loan->start_date?->toDateString(),
            'end_date' => $loan->end_date?->toDateString(),
            'days_remaining' => $loan->end_date ? max(0, now()->diffInDays($loan->end_date, false)) : null,
            'is_overdue' => $loan->status === Loans::STATUS_OVERDUE,
            'days_overdue' => $loan->days_overdue ?? 0,
            'status_code' => $loan->status,
            'status_label' => $loan->status_label,
            'status_description' => $this->getStatusDescription($loan->status),
            'total_schedules' => $totalSchedules,
            'paid_schedules' => $paidSchedules,
            'overdue_schedules' => $overdueSchedules,
            'upcoming_schedules' => $upcomingSchedules,
            'next_payment_date' => $schedules->where('payment_due_date', '>', now())->sortBy('payment_due_date')->first()?->payment_due_date?->toDateString(),
            'workflow' => $workflowInfo,
            'recommendations' => $this->getRecommendationsForStatus($loan->status, $outstandingBalance, $loan->days_overdue ?? 0),
        ];
    }

    /**
     * Get status description in Swahili
     */
    protected function getStatusDescription($status): string
    {
        return match ($status) {
            Loans::STATUS_SUBMITTED => "Mkopo umewasilishwa na unasubiri idhini",
            Loans::STATUS_ACTIVE => "Mkopo umetolewa na unalipwa kwa wakati",
            Loans::STATUS_COMPLETED => "Mkopo umelipwa kikamilifu",
            Loans::STATUS_DEFAULTED => "Mkopo umefeli, haijalipwa kwa muda mrefu",
            Loans::STATUS_OVERDUE => "Mkopo umechelewa kulipwa",
            Loans::STATUS_REJECTED => "Mkopo umekataliwa",
            Loans::STATUS_WRITTEN_OFF => "Mkopo umeandikwa kuwa hasara",
            Loans::STATUS_FORECLOSURE => "Mchakato wa kukamua rehani umeanza",
            Loans::STATUS_EARLY_SETTLED => "Mkopo umelipwa kabla ya muda",
            Loans::STATUS_RESTRUCTURED => "Masharti ya mkopo yamebadilishwa",
            default => "Hali isiyojulikana",
        };
    }

    /**
     * Get recommendations based on loan status
     */
    protected function getRecommendationsForStatus($status, $outstandingBalance, $daysOverdue): array
    {
        $recommendations = [];

        switch ($status) {
            case Loans::STATUS_SUBMITTED:
                $recommendations[] = "Kagua taarifa za mteja na uwezo wake wa kulipa";
                $recommendations[] = "Thibitisha dhamana (collateral) ikiwa ipo";
                $recommendations[] = "Wasiliana na mteja kwa maswali yoyote";
                break;

            case Loans::STATUS_ACTIVE:
                $recommendations[] = "Fuatilia malipo yanayokuja kwa wakati";
                $recommendations[] = "Toa arifa kwa mteja kabla ya tarehe ya malipo";
                $recommendations[] = "Rekodi malipo yote kwa usahihi";
                break;

            case Loans::STATUS_OVERDUE:
                if ($daysOverdue <= 30) {
                    $recommendations[] = "Tuma SMS au ujumbe wa kukumbusha kwa mteja";
                    $recommendations[] = "Piga simu mteja kujua sababu ya kuchelewa";
                } elseif ($daysOverdue <= 60) {
                    $recommendations[] = "Tembelea makazi au biashara ya mteja";
                    $recommendations[] = "Andaa mpango wa marejesho";
                } else {
                    $recommendations[] = "Anza mchakato wa ukusanyaji (collection)";
                    $recommendations[] = "Zingatia kurekebisha masharti ya mkopo";
                }
                break;

            case Loans::STATUS_DEFAULTED:
                $recommendations[] = "Tathmini uwezekano wa kurekebisha mkopo";
                $recommendations[] = "Angalia kama kuna dhamana ya kukamua";
                $recommendations[] = "Wasiliana na idara ya sheria kwa ushauri";
                break;

            case Loans::STATUS_WRITTEN_OFF:
                $recommendations[] = "Hakikisha rekodi za kodi zimesasishwa";
                $recommendations[] = "Hifadhi nyaraka za kuandika hasara";
                break;

            case Loans::STATUS_FORECLOSURE:
                $recommendations[] = "Fuata taratibu za kisheria kwa uangalifu";
                $recommendations[] = "Washa mteja kuhusu haki zake za kukomboa";
                break;
        }

        return $recommendations;
    }

    /**
     * Get conversation history from cache
     */
    protected function getConversationHistory($sessionId)
    {
        $key = "ai_chat_history_{$sessionId}";
        return Cache::get($key, []);
    }

    /**
     * Save conversation history to cache
     */
    protected function saveConversationHistory($sessionId, $history)
    {
        $key = "ai_chat_history_{$sessionId}";
        $history = array_slice($history, -$this->maxHistory);
        Cache::put($key, $history, now()->addHours(24));
    }

    /**
     * Clear conversation history
     */
    public function clearHistory($sessionId)
    {
        $key = "ai_chat_history_{$sessionId}";
        Cache::forget($key);
    }

    /**
     * Send a message to AI with memory
     */
    public function chat($message, $context = [])
    {
        try {
            $sessionId = $context['session_id'] ?? $context['user_id'];
            $history = $this->getConversationHistory($sessionId);

            $systemPrompt = $this->buildSystemPrompt($context);

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
            ];

            foreach ($history as $hist) {
                $messages[] = ['role' => $hist['role'], 'content' => $hist['content']];
            }

            $messages[] = ['role' => 'user', 'content' => $message];

            $response = $this->chatGPT
                ->messages($messages)
                ->send();

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
     * Chat with streaming response and memory
     */
    public function chatStream($message, $context = [], callable $callback)
    {
        try {
            $sessionId = $context['session_id'] ?? $context['user_id'];
            $history = $this->getConversationHistory($sessionId);

            $systemPrompt = $this->buildSystemPrompt($context);

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
            ];

            foreach ($history as $hist) {
                $messages[] = ['role' => $hist['role'], 'content' => $hist['content']];
            }

            $messages[] = ['role' => 'user', 'content' => $message];

            $response = $this->chatGPT
                ->messages($messages)
                ->send();

            $fullResponse = $response->content ?? '';

            if ($fullResponse) {
                $callback($fullResponse);
            }

            $history[] = ['role' => 'user', 'content' => $message];
            $history[] = ['role' => 'assistant', 'content' => $fullResponse];
            $this->saveConversationHistory($sessionId, $history);

            return ['success' => true];
        } catch (\Exception $e) {
            Log::error('AI Stream Error: ' . $e->getMessage());
            $callback("\n\nSamahani, nimekosa kuunganisha. Tafadhali jaribu tena.");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Build system prompt with loan management context
     */
    public function buildSystemPrompt($context)
    {
        $language = $context['language'] ?? 'sw';
        $companyId = $context['company_id'] ?? null;
        $loanId = $context['loan_id'] ?? null;

        // Get REAL data for this company using enhanced method
        $portfolioStats = null;
        if ($companyId) {
            $portfolioStats = $this->getRealPortfolioStatistics($companyId);
        }

        $loanInfo = null;
        if ($loanId && $companyId) {
            $loanInfo = $this->getComprehensiveLoanContext($loanId, $companyId);
        }

        if ($language === 'en') {
            return $this->buildEnglishPrompt($loanInfo, $portfolioStats, $context);
        }

        return $this->buildSwahiliPrompt($loanInfo, $portfolioStats, $context);
    }

    /**
     * Build Swahili system prompt with knowledge base and REAL data
     */
    protected function buildSwahiliPrompt($loanInfo, $portfolioStats, $context)
    {
        $currentMonth = now()->format('F Y');

        $prompt = "Wewe ni Msaidizi wa AI wa Mfumo wa Usimamizi wa Mikopo (Loan Management System). ";
        $prompt .= "Unaongea Kiswahili sanifu na unaelewa istilahi za kifedha za Kitanzania.\n\n";
        $prompt .= "## ZANA ZINAZOPATIKANA (TOOLS):\n";
        $prompt .= "Unaweza kutumia zana zifuatazo ili kutoa data halisi:\n";
        $prompt .= "1. **getLoanDetails** - Kupata maelezo ya mkopo maalum kwa namba au jina la mteja\n";
        $prompt .= "2. **searchLoans** - Kutafuta mikopo kwa hali, siku za kuchelewa, n.k\n";
        $prompt .= "3. **getPortfolioStatistics** - Kupata takwimu za kwingineko kwa kipindi maalum\n";
        $prompt .= "4. **getPaymentHistory** - Kuona historia ya malipo ya mkopo fulani\n\n";
        $prompt .= "Tumia zana hizi wakati muhimu ili kujibu maswali kwa usahihi.\n\n";

        $prompt .= $this->getLoanKnowledgeBase();
        $prompt .= "\n\n";

        $prompt .= "## TAREHE NA MUDA:\n";
        $prompt .= "Leo ni: " . now()->format('Y-m-d H:i:s') . "\n";
        $prompt .= "Mwezi wa sasa: {$currentMonth}\n\n";

        $prompt .= "## 📊 MUHTASARI WA KWINGINEKO YAKO (PORTFOLIO SUMMARY):\n";
        if ($portfolioStats && isset($portfolioStats['summary'])) {
            $prompt .= "┌─────────────────────────────────────────────────┐\n";
            $prompt .= "│ Jumla ya Mikopo: " . str_pad(number_format($portfolioStats['summary']['total_loans']), 35) . " │\n";
            $prompt .= "│ Mikopo Inayotumika: " . str_pad(number_format($portfolioStats['summary']['active']), 32) . " │\n";
            $prompt .= "│ Mikopo Inasubiri: " . str_pad(number_format($portfolioStats['summary']['pending']), 33) . " │\n";
            $prompt .= "│ Mikopo Imechelewa: " . str_pad(number_format($portfolioStats['summary']['overdue']), 32) . " │\n";
            $prompt .= "│ Mikopo Imefeli: " . str_pad(number_format($portfolioStats['summary']['defaulted']), 34) . " │\n";
            $prompt .= "│ Mikopo Imekamilika: " . str_pad(number_format($portfolioStats['summary']['completed']), 32) . " │\n";
            $prompt .= "│ Mikopo Imeandikwa Hasara: " . str_pad(number_format($portfolioStats['summary']['written_off']), 28) . " │\n";
            $prompt .= "├─────────────────────────────────────────────────┤\n";
            $prompt .= "│ Jumla Iliyotolewa: TZS " . str_pad(number_format($portfolioStats['financial']['total_disbursed'], 0), 21) . " │\n";
            $prompt .= "│ Jumla Iliyolipwa: TZS " . str_pad(number_format($portfolioStats['financial']['total_repaid'], 0), 22) . " │\n";
            $prompt .= "│ Salio la Jumla: TZS " . str_pad(number_format($portfolioStats['financial']['outstanding'], 0), 23) . " │\n";
            $prompt .= "│ Kiwango cha Ukusanyaji: " . str_pad($portfolioStats['financial']['collection_rate'] . "%", 28) . " │\n";
            $prompt .= "├─────────────────────────────────────────────────┤\n";
            $prompt .= "│ Mwezi Huu (Tolewa): TZS " . str_pad(number_format($portfolioStats['financial']['monthly_disbursed'], 0), 16) . " │\n";
            $prompt .= "│ Mwezi Huu (Kusanywa): TZS " . str_pad(number_format($portfolioStats['financial']['monthly_collected'], 0), 15) . " │\n";
            $prompt .= "│ Kiwango cha Mwezi Huu: " . str_pad($portfolioStats['financial']['monthly_collection_rate'] . "%", 27) . " │\n";
            $prompt .= "├─────────────────────────────────────────────────┤\n";
            $prompt .= "│ Mikopo Yenye Hatari: " . str_pad(number_format($portfolioStats['risk']['at_risk_count']), 29) . " │\n";
            $prompt .= "│ Asilimia ya Hatari: " . str_pad($portfolioStats['risk']['at_risk_percentage'] . "%", 30) . " │\n";
            $prompt .= "└─────────────────────────────────────────────────┘\n\n";

            // Add analysis based on data
            if ($portfolioStats['summary']['overdue'] > 0) {
                $prompt .= "## ⚠️ TAHADHARI: Una **" . number_format($portfolioStats['summary']['overdue']) . "** mikopo iliyochelewa!\n";
                $prompt .= "Hii ni **" . round(($portfolioStats['summary']['overdue'] / max($portfolioStats['summary']['total_loans'], 1)) * 100, 1) . "%** ya mikopo yako.\n\n";
            }

            if ($portfolioStats['financial']['collection_rate'] < 70) {
                $prompt .= "## 📉 KIWANGO CHA UKUSA NYAJI: **" . $portfolioStats['financial']['collection_rate'] . "%**\n";
                $prompt .= "Hiki ni chini ya lengo la 70%. Inahitaji uangalizi maalum.\n\n";
            }

            if ($portfolioStats['financial']['monthly_collected'] > 0) {
                $prompt .= "## 📈 MWEZI HUU: Umetoa TZS **" . number_format($portfolioStats['financial']['monthly_disbursed'], 0) . "** ";
                $prompt .= "na ukusanyaji wa TZS **" . number_format($portfolioStats['financial']['monthly_collected'], 0) . "**\n\n";
            }
        } else {
            $prompt .= "Taarifa za kwingineko hazipatikani kwa sasa. Hakikisha umeingia kwenye mfumo.\n\n";
        }

        if ($loanInfo && is_array($loanInfo)) {
            $prompt .= "## MAELEZO YA MKOPO MAALUM:\n";
            $prompt .= "┌─────────────────────────────────────────────┐\n";
            $prompt .= "│ Namba: " . str_pad(($loanInfo['loan_number'] ?? 'N/A'), 38) . "│\n";
            $prompt .= "│ Mteja: " . str_pad(($loanInfo['customer_name'] ?? 'N/A'), 39) . "│\n";
            $prompt .= "│ Salio: TZS " . str_pad(number_format($loanInfo['outstanding_balance'] ?? 0, 0), 28) . "│\n";
            $prompt .= "│ Hali: " . str_pad(($loanInfo['status_label'] ?? 'N/A'), 39) . "│\n";
            $prompt .= "└─────────────────────────────────────────────┘\n\n";

            if (!empty($loanInfo['recommendations'])) {
                $prompt .= "## MAPENDEKEZO KWA MKOPO HUU:\n";
                foreach ($loanInfo['recommendations'] as $idx => $rec) {
                    $prompt .= ($idx + 1) . ". " . $rec . "\n";
                }
                $prompt .= "\n";
            }
        }

        $prompt .= "## KANUNI ZA KUJIBU:\n";
        $prompt .= "1. Jibu kwa Kiswahili sanifu na kitaalamu\n";
        $prompt .= "2. Tumia **bold** kuonyesha namba muhimu\n";
        $prompt .= "3. Toa mapendekezo ya vitendo kulingana na data halisi hapo juu\n";
        $prompt .= "4. Ikiwa kuna mikopo iliyochelewa, pendekeza hatua za haraka\n";
        $prompt .= "5. Tumia data halisi ya kwingineko iliyotolewa kujibu maswali\n\n";

        return $prompt;
    }

    /**
     * Build English system prompt
     */
    protected function buildEnglishPrompt($loanInfo, $portfolioStats, $context)
    {
        $prompt = "You are an AI Loan Assistant for a Loan Management System. ";
        $prompt .= "You help loan officers, managers, and customers with loan-related inquiries.\n\n";

        $prompt .= "## LOAN STATUSES:\n";
        $prompt .= "- 4: Submitted (pending approval)\n";
        $prompt .= "- 5: Active (disbursed, paying on time)\n";
        $prompt .= "- 6: Completed (fully paid)\n";
        $prompt .= "- 7: Defaulted (failed to pay)\n";
        $prompt .= "- 12: Overdue (late payment)\n";
        $prompt .= "- 13: Written Off (deemed uncollectible)\n";
        $prompt .= "- 14: Foreclosure (collateral seizure)\n";
        $prompt .= "- 15: Early Settled (paid before term)\n";
        $prompt .= "- 16: Restructured (terms modified)\n\n";

        $prompt .= "## CURRENT DATE: " . now()->format('Y-m-d H:i:s') . "\n\n";

        if ($portfolioStats && isset($portfolioStats['summary'])) {
            $prompt .= "## REAL PORTFOLIO DATA:\n";
            $prompt .= "Total Loans: " . number_format($portfolioStats['summary']['total_loans']) . "\n";
            $prompt .= "Active: " . number_format($portfolioStats['summary']['active']) . "\n";
            $prompt .= "Pending: " . number_format($portfolioStats['summary']['pending']) . "\n";
            $prompt .= "Overdue: " . number_format($portfolioStats['summary']['overdue']) . "\n";
            $prompt .= "Defaulted: " . number_format($portfolioStats['summary']['defaulted']) . "\n";
            $prompt .= "Completed: " . number_format($portfolioStats['summary']['completed']) . "\n";
            $prompt .= "Written Off: " . number_format($portfolioStats['summary']['written_off']) . "\n";
            $prompt .= "Collection Rate: " . $portfolioStats['financial']['collection_rate'] . "%\n";
            $prompt .= "Outstanding: TZS " . number_format($portfolioStats['financial']['outstanding'], 0) . "\n";
            $prompt .= "Monthly Disbursed: TZS " . number_format($portfolioStats['financial']['monthly_disbursed'], 0) . "\n";
            $prompt .= "Monthly Collected: TZS " . number_format($portfolioStats['financial']['monthly_collected'], 0) . "\n";
            $prompt .= "Monthly Collection Rate: " . $portfolioStats['financial']['monthly_collection_rate'] . "%\n";
            $prompt .= "At Risk Loans: " . number_format($portfolioStats['risk']['at_risk_count']) . " (" . $portfolioStats['risk']['at_risk_percentage'] . "%)\n\n";
        }

        if ($loanInfo && is_array($loanInfo)) {
            $prompt .= "## CURRENT LOAN DETAILS:\n";
            $prompt .= "Loan Number: " . ($loanInfo['loan_number'] ?? 'N/A') . "\n";
            $prompt .= "Customer: " . ($loanInfo['customer_name'] ?? 'N/A') . "\n";
            $prompt .= "Outstanding Balance: TZS " . number_format($loanInfo['outstanding_balance'] ?? 0, 0) . "\n";
            $prompt .= "Status: " . ($loanInfo['status_label'] ?? 'N/A') . "\n\n";
        }

        $prompt .= "## RESPONSE RULES:\n";
        $prompt .= "1. Respond in Kiswahili (Swahili) professionally\n";
        $prompt .= "2. Use **bold** for important numbers\n";
        $prompt .= "3. Provide actionable recommendations based on real data\n";
        $prompt .= "4. If there are overdue loans, recommend immediate actions\n\n";

        return $prompt;
    }

    // Add this to your AIAssistantService

    /**
     * Get available functions for the AI to call
     */
    public function getAvailableFunctions(): array
    {
        return [
            [
                'name' => 'getLoanDetails',
                'description' => 'Get detailed information about a specific loan using loan number or customer name',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'loan_number' => [
                            'type' => 'string',
                            'description' => 'The loan number to look up (e.g., LN-2024-001)'
                        ],
                        'customer_name' => [
                            'type' => 'string',
                            'description' => 'Customer name to search for'
                        ],
                        'company_id' => [
                            'type' => 'integer',
                            'description' => 'Company ID (will use from context)'
                        ]
                    ]
                ]
            ],
            [
                'name' => 'searchLoans',
                'description' => 'Search for loans based on various criteria',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => [
                            'type' => 'array',
                            'description' => 'Loan status codes (4,5,6,7,12,13,14,15,16)',
                            'items' => ['type' => 'integer']
                        ],
                        'min_days_overdue' => [
                            'type' => 'integer',
                            'description' => 'Minimum days overdue'
                        ],
                        'max_days_overdue' => [
                            'type' => 'integer',
                            'description' => 'Maximum days overdue'
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Maximum number of results to return',
                            'default' => 10
                        ]
                    ]
                ]
            ],
            [
                'name' => 'getPortfolioStatistics',
                'description' => 'Get portfolio statistics for a company, optionally filtered by date range',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'start_date' => [
                            'type' => 'string',
                            'description' => 'Start date (YYYY-MM-DD)'
                        ],
                        'end_date' => [
                            'type' => 'string',
                            'description' => 'End date (YYYY-MM-DD)'
                        ]
                    ]
                ]
            ],
            [
                'name' => 'getPaymentHistory',
                'description' => 'Get payment history for a specific loan',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'loan_id' => [
                            'type' => 'integer',
                            'description' => 'Loan ID'
                        ],
                        'loan_number' => [
                            'type' => 'string',
                            'description' => 'Loan number'
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Number of payments to return',
                            'default' => 10
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * Execute a function call based on AI's request
     */
    public function executeFunction(string $functionName, array $parameters, $companyId)
    {
        switch ($functionName) {
            case 'getLoanDetails':
                return $this->executeGetLoanDetails($parameters, $companyId);
            case 'searchLoans':
                return $this->executeSearchLoans($parameters, $companyId);
            case 'getPortfolioStatistics':
                return $this->executeGetPortfolioStats($parameters, $companyId);
            case 'getPaymentHistory':
                return $this->executeGetPaymentHistory($parameters, $companyId);
            default:
                return ['error' => "Function {$functionName} not found"];
        }
    }

    protected function executeGetLoanDetails(array $params, $companyId)
    {
        $query = Loans::with(['customer', 'product', 'schedules'])
            ->where('company', $companyId);

        if (!empty($params['loan_number'])) {
            $query->where('loan_number', $params['loan_number']);
        }

        if (!empty($params['customer_name'])) {
            $query->whereHas('customer', function ($q) use ($params) {
                $q->where('fullname', 'LIKE', '%' . $params['customer_name'] . '%');
            });
        }

        $loan = $query->first();

        if (!$loan) {
            return ['error' => 'Loan not found'];
        }

        return $this->getComprehensiveLoanContext($loan->id, $companyId);
    }

    protected function executeSearchLoans(array $params, $companyId)
    {
        $query = Loans::with(['customer'])
            ->where('company', $companyId);

        if (!empty($params['status'])) {
            $query->whereIn('status', $params['status']);
        }

        if (isset($params['min_days_overdue'])) {
            $query->where('days_overdue', '>=', $params['min_days_overdue']);
        }

        if (isset($params['max_days_overdue'])) {
            $query->where('days_overdue', '<=', $params['max_days_overdue']);
        }

        $limit = $params['limit'] ?? 10;
        $loans = $query->limit($limit)->get();

        return $loans->map(function ($loan) {
            return [
                'loan_number' => $loan->loan_number,
                'customer' => $loan->customer->fullname ?? 'N/A',
                'status' => $loan->status_label,
                'outstanding' => (float)$loan->outstanding_balance,
                'days_overdue' => $loan->days_overdue ?? 0,
            ];
        })->toArray();
    }

    protected function executeGetPortfolioStats(array $params, $companyId)
    {
        $startDate = $params['start_date'] ?? null;
        $endDate = $params['end_date'] ?? null;

        $stats = Loans::where('company', $companyId);

        if ($startDate) {
            $stats->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $stats->whereDate('created_at', '<=', $endDate);
        }

        // Return formatted statistics
        return $this->getRealPortfolioStatistics($companyId);
    }

    protected function executeGetPaymentHistory(array $params, $companyId)
    {
        // Find the loan first
        $loanQuery = Loans::where('company', $companyId);

        if (!empty($params['loan_id'])) {
            $loanQuery->where('id', $params['loan_id']);
        } elseif (!empty($params['loan_number'])) {
            $loanQuery->where('loan_number', $params['loan_number']);
        } else {
            return ['error' => 'Loan identifier required'];
        }

        $loan = $loanQuery->first();

        if (!$loan) {
            return ['error' => 'Loan not found'];
        }

        $payments = PaymentSubmissions::where('loan_number', $loan->loan_number)
            ->where('submission_status', 11)
            ->orderBy('created_at', 'desc')
            ->limit($params['limit'] ?? 10)
            ->get();

        return $payments->map(function ($payment) {
            return [
                'date' => $payment->created_at->toDateString(),
                'amount' => (float)$payment->amount,
                'method' => $payment->payment_method ?? 'Unknown',
                'reference' => $payment->transaction_reference ?? 'N/A',
            ];
        })->toArray();
    }
}
