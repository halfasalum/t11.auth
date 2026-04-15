<?php

namespace App\Http\Controllers\Api\V2\Reports;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\Customers;
use App\Models\Loans;
use App\Models\PaymentSubmissions;
use App\Models\LoansProducts;
use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AnalyticsReportController extends BaseController
{
    /**
     * Default Risk Prediction
     */
    public function defaultRiskPrediction(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();

            $customers = Customers::byCompany($companyId)
                ->with(['loans' => function ($query) {
                    // Only load active and overdue loans
                    $query->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE]);
                }, 'zoneAssignment'])
                ->get();

            // Filter customers who have active or overdue loans
            $customersWithActiveLoans = $customers->filter(function ($customer) {
                return $customer->loans->isNotEmpty();
            });

            $highRisk = [];
            $mediumRisk = [];
            $lowRisk = [];
            $totalExposure = 0;

            foreach ($customersWithActiveLoans as $customer) {
                $riskScore = $this->calculateRiskScore($customer);

                // Calculate outstanding balance only from active/overdue loans
                $outstandingBalance = $customer->loans->sum(function ($loan) {
                    return ($loan->total_loan + ($loan->penalty_amount ?? 0)) - ($loan->loan_paid ?? 0);
                });

                $totalExposure += $outstandingBalance;

                $riskData = [
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->fullname,
                    'phone' => $customer->phone,
                    'risk_score' => (int) round($riskScore),
                    'probability_default' => (int) round(min(100, max(0, $riskScore))),
                    'outstanding_balance' => (float) $outstandingBalance,
                    'active_loans_count' => $customer->loans->count(),
                    'key_factors' => $this->getRiskFactors($customer),
                    'recommended_action' => $this->getRecommendedAction($riskScore),
                ];

                if ($riskScore >= 70) {
                    $riskData['risk_level'] = 'High Risk';
                    $highRisk[] = $riskData;
                } elseif ($riskScore >= 40) {
                    $riskData['risk_level'] = 'Medium Risk';
                    $mediumRisk[] = $riskData;
                } else {
                    $riskData['risk_level'] = 'Low Risk';
                    $lowRisk[] = $riskData;
                }
            }

            // Calculate expected loss based on risk-weighted exposure
            $expectedLoss = $this->calculateExpectedLoss($highRisk, $mediumRisk, $lowRisk);

            $data = [
                'customers' => array_merge($highRisk, $mediumRisk, $lowRisk),
                'summary' => [
                    'total_high_risk' => count($highRisk),
                    'total_medium_risk' => count($mediumRisk),
                    'total_low_risk' => count($lowRisk),
                    'total_customers_at_risk' => count($customersWithActiveLoans),
                    'total_exposure' => (float) $totalExposure,
                    'expected_loss' => (float) $expectedLoss,
                    'expected_loss_percentage' => $totalExposure > 0 ? round(($expectedLoss / $totalExposure) * 100, 2) : 0,
                ],
            ];

            return $this->successResponse($data, 'Default risk prediction retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Default risk prediction error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return $this->errorResponse('Failed to load default risk prediction: ' . $e->getMessage(), 500);
        }
    }


    /**
     * Calculate expected loss based on risk-weighted exposure
     */
    private function calculateExpectedLoss($highRisk, $mediumRisk, $lowRisk)
    {
        // Expected loss rates by risk category
        $highRiskRate = 0.25;  // 25% expected loss for high risk
        $mediumRiskRate = 0.10; // 10% expected loss for medium risk
        $lowRiskRate = 0.02;    // 2% expected loss for low risk

        $highRiskExposure = array_sum(array_column($highRisk, 'outstanding_balance'));
        $mediumRiskExposure = array_sum(array_column($mediumRisk, 'outstanding_balance'));
        $lowRiskExposure = array_sum(array_column($lowRisk, 'outstanding_balance'));

        return ($highRiskExposure * $highRiskRate) +
            ($mediumRiskExposure * $mediumRiskRate) +
            ($lowRiskExposure * $lowRiskRate);
    }

    /**
     * Customer Lifetime Value (LTV)
     */
    public function customerLTV(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();

            $customers = Customers::byCompany($companyId)
                ->with(['loans'])
                ->get();

            $ltvData = [];
            $totalValue = 0;

            foreach ($customers as $customer) {
                $totalBorrowed = $customer->loans->sum('principal_amount');
                $totalInterest = $customer->loans->sum('interest_amount');
                $totalPaid = $customer->loans->sum('loan_paid');
                $numberOfLoans = $customer->loans->count();
                $avgLoanSize = $numberOfLoans > 0 ? $totalBorrowed / $numberOfLoans : 0;

                // Calculate LTV (total interest + fees)
                $ltv = $totalInterest;
                $totalValue += $ltv;

                // Determine segment
                $segment = 'Bronze';
                if ($ltv >= 500000) $segment = 'Platinum';
                elseif ($ltv >= 250000) $segment = 'Gold';
                elseif ($ltv >= 100000) $segment = 'Silver';

                $ltvData[] = [
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->fullname,
                    'phone' => $customer->phone,
                    'total_borrowed' => (float) $totalBorrowed,
                    'total_interest_paid' => (float) $totalInterest,
                    'total_fees_paid' => 0,
                    'total_value' => (float) $ltv,
                    'number_of_loans' => $numberOfLoans,
                    'avg_loan_size' => (float) $avgLoanSize,
                    'customer_since' => $customer->created_at,
                    'segment' => $segment,
                ];
            }

            // Sort by total value descending
            usort($ltvData, function ($a, $b) {
                return $b['total_value'] <=> $a['total_value'];
            });

            // Calculate segment summary
            $segments = ['Platinum', 'Gold', 'Silver', 'Bronze'];
            $bySegment = [];
            foreach ($segments as $segment) {
                $segmentCustomers = array_filter($ltvData, function ($c) use ($segment) {
                    return $c['segment'] === $segment;
                });
                $bySegment[] = [
                    'segment' => $segment,
                    'count' => count($segmentCustomers),
                    'avg_ltv' => count($segmentCustomers) > 0 ? array_sum(array_column($segmentCustomers, 'total_value')) / count($segmentCustomers) : 0,
                    'total_value' => array_sum(array_column($segmentCustomers, 'total_value')),
                ];
            }

            $data = [
                'customers' => $ltvData,
                'summary' => [
                    'total_customers' => count($ltvData),
                    'average_ltv' => count($ltvData) > 0 ? $totalValue / count($ltvData) : 0,
                    'total_portfolio_value' => (float) $totalValue,
                    'by_segment' => $bySegment,
                ],
            ];

            return $this->successResponse($data, 'Customer LTV retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to load customer LTV: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Seasonal Demand Report
     */
    public function seasonalDemand(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();
            $year = $request->get('year', Carbon::now()->year);

            $loans = Loans::where('company', $companyId)
                ->whereYear('created_at', $year)
                ->get();

            $monthlyData = [];
            $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

            for ($i = 1; $i <= 12; $i++) {
                $monthStart = Carbon::create($year, $i, 1)->startOfMonth();
                $monthEnd = $monthStart->copy()->endOfMonth();

                $monthLoans = $loans->filter(function ($l) use ($monthStart, $monthEnd) {
                    return Carbon::parse($l->created_at)->between($monthStart, $monthEnd);
                });

                $monthlyData[] = [
                    'month' => $monthNames[$i - 1],
                    'count' => $monthLoans->count(),
                    'amount' => (float) $monthLoans->sum('principal_amount'),
                ];
            }

            // Find peak and low months
            $peakMonths = array_slice($monthlyData, 0, 3);
            usort($peakMonths, function ($a, $b) {
                return $b['count'] <=> $a['count'];
            });
            $peakMonths = array_slice($peakMonths, 0, 3);

            $lowMonths = array_slice($monthlyData, 0, 3);
            usort($lowMonths, function ($a, $b) {
                return $a['count'] <=> $b['count'];
            });
            $lowMonths = array_slice($lowMonths, 0, 3);

            // Calculate YoY growth (compare with previous year)
            $previousYearLoans = Loans::where('company', $companyId)
                ->whereYear('created_at', $year - 1)
                ->count();
            $currentYearLoans = $loans->count();
            $yoyGrowth = $previousYearLoans > 0 ? round((($currentYearLoans - $previousYearLoans) / $previousYearLoans) * 100, 2) : 0;

            $data = [
                'year' => $year,
                'monthly_applications' => $monthlyData,
                'peak_months' => $peakMonths,
                'low_months' => $lowMonths,
                'yoy_growth' => $yoyGrowth,
                'avg_monthly_loans' => round($currentYearLoans / 12, 2),
            ];

            return $this->successResponse($data, 'Seasonal demand retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to load seasonal demand: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Portfolio Diversification Report
     */
    public function portfolioDiversification(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();

            // Get all active products for this company
            $products = LoansProducts::where('company', $companyId)
                ->where('status', 1)
                ->get();

            $byProduct = [];
            $totalPortfolio = 0;
            $productColors = ['primary', 'success', 'info', 'warning', 'danger', 'secondary', 'dark', 'purple'];
            $colorIndex = 0;

            // Use a simple array to track unique product names
            $processedProducts = [];

            foreach ($products as $product) {
                $productName = trim($product->product_name); // Trim to remove any spaces

                // Skip if we already processed this product name
                if (in_array($productName, $processedProducts)) {
                    Log::info('Skipping duplicate product: ' . $productName);
                    continue;
                }

                // Mark as processed
                $processedProducts[] = $productName;

                // Get all loans for products with this name (in case there are multiple IDs with same name)
                $productIds = LoansProducts::where('company', $companyId)
                    ->where('product_name', 'LIKE', $productName) // Use LIKE to catch variations
                    ->pluck('id')
                    ->toArray();

                $loans = Loans::whereIn('product', $productIds)
                    ->where('company', $companyId)
                    ->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])
                    ->get();

                $amount = $loans->sum(function ($loan) {
                    return ($loan->total_loan + ($loan->penalty_amount ?? 0)) - ($loan->loan_paid ?? 0);
                });

                $totalPortfolio += $amount;

                $color = $productColors[$colorIndex % count($productColors)];
                $colorIndex++;

                $byProduct[] = [
                    'name' => $productName,
                    'amount' => (float) $amount,
                    'percentage' => 0,
                    'color' => $color,
                ];
            }

            // Calculate percentages
            foreach ($byProduct as &$product) {
                $product['percentage'] = $totalPortfolio > 0 ? round(($product['amount'] / $totalPortfolio) * 100, 2) : 0;
            }

            // Sort by amount descending
            usort($byProduct, function ($a, $b) {
                return $b['amount'] <=> $a['amount'];
            });

            // By Region (Zone)
            $zones = Zone::whereHas('zone_branch', function ($q) use ($companyId) {
                $q->where('company', $companyId);
            })->get();

            $byRegion = [];
            foreach ($zones as $zone) {
                $loans = Loans::where('zone', $zone->id)
                    ->where('company', $companyId)
                    ->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])
                    ->get();

                $amount = $loans->sum(function ($loan) {
                    return ($loan->total_loan + ($loan->penalty_amount ?? 0)) - ($loan->loan_paid ?? 0);
                });

                if ($amount > 0) {
                    $byRegion[] = [
                        'name' => $zone->zone_name,
                        'amount' => (float) $amount,
                        'percentage' => $totalPortfolio > 0 ? round(($amount / $totalPortfolio) * 100, 2) : 0,
                    ];
                }
            }

            usort($byRegion, function ($a, $b) {
                return $b['amount'] <=> $a['amount'];
            });

            $byRegion = array_values($byRegion);

            // By Loan Size
            $sizeBuckets = [
                ['range' => '0 - 100,000', 'min' => 0, 'max' => 100000],
                ['range' => '100,001 - 500,000', 'min' => 100001, 'max' => 500000],
                ['range' => '500,001 - 1,000,000', 'min' => 500001, 'max' => 1000000],
                ['range' => '1,000,001 - 5,000,000', 'min' => 1000001, 'max' => 5000000],
                ['range' => '5,000,001+', 'min' => 5000001, 'max' => PHP_INT_MAX],
            ];

            $byLoanSize = [];
            foreach ($sizeBuckets as $bucket) {
                $loans = Loans::where('company', $companyId)
                    ->whereBetween('principal_amount', [$bucket['min'], $bucket['max']])
                    ->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])
                    ->get();

                $amount = $loans->sum(function ($loan) {
                    return ($loan->total_loan + ($loan->penalty_amount ?? 0)) - ($loan->loan_paid ?? 0);
                });

                if ($amount > 0) {
                    $byLoanSize[] = [
                        'range' => $bucket['range'],
                        'amount' => (float) $amount,
                        'percentage' => $totalPortfolio > 0 ? round(($amount / $totalPortfolio) * 100, 2) : 0,
                        'loan_count' => $loans->count(),
                    ];
                }
            }

            // Calculate Herfindahl Index
            $herfindahl = 0;
            foreach ($byProduct as $product) {
                $herfindahl += pow($product['percentage'], 2);
            }
            $herfindahl = round($herfindahl, 2);

            $concentrationRisk = $herfindahl < 1500 ? 'Low' : ($herfindahl < 2500 ? 'Moderate' : 'High');
            $riskInterpretation = $this->getConcentrationInterpretation($herfindahl);

            $data = [
                'by_product' => $byProduct,
                'by_region' => $byRegion,
                'by_loan_size' => $byLoanSize,
                'herfindahl_index' => $herfindahl,
                'concentration_risk' => $concentrationRisk,
                'risk_interpretation' => $riskInterpretation,
                'total_portfolio' => (float) $totalPortfolio,
            ];

            Log::info('Final by_product count: ' . count($byProduct));
            Log::info('Final by_product:', $byProduct);

            return $this->successResponse($data, 'Portfolio diversification retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Portfolio diversification error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return $this->errorResponse('Failed to load portfolio diversification: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get interpretation of concentration risk
     */
    private function getConcentrationInterpretation($herfindahl)
    {
        if ($herfindahl < 1500) {
            return 'Well-diversified portfolio with low concentration risk. No single product dominates the portfolio.';
        } elseif ($herfindahl < 2500) {
            return 'Moderately concentrated portfolio. Consider further diversification to reduce risk.';
        } else {
            return 'Highly concentrated portfolio. Heavy reliance on few products. High vulnerability to market changes.';
        }
    }

    private function calculateRiskScore($customer)
    {
        $score = 0;

        // Payment history (40%)
        $totalPayments = 0;
        $latePayments = 0;
        foreach ($customer->loans as $loan) {
            $payments = PaymentSubmissions::where('loan_number', $loan->loan_number)
                ->where('submission_status', 11)
                ->get();
            foreach ($payments as $payment) {
                $totalPayments++;
                $schedule = $payment->schedule;
                if ($schedule && $payment->submitted_date > $schedule->payment_due_date) {
                    $latePayments++;
                }
            }
        }
        $lateRate = $totalPayments > 0 ? ($latePayments / $totalPayments) * 100 : 0;
        $score += $lateRate * 0.4;

        // Debt-to-Income (30%)
        $totalDebt = $customer->loans->sum(function ($loan) {
            return ($loan->total_loan + ($loan->penalty_amount ?? 0)) - ($loan->loan_paid ?? 0);
        });
        $income = $customer->income ?? 0;
        $dtiRatio = $income > 0 ? ($totalDebt / $income) * 100 : 100;
        $score += min(100, $dtiRatio) * 0.3;

        // Default history (30%)
        $defaultedLoans = $customer->loans->where('status', Loans::STATUS_DEFAULTED)->count();
        $score += ($defaultedLoans > 0 ? 100 : 0) * 0.3;

        return min(100, $score);
    }

    private function getRiskFactors($customer)
    {
        $factors = [];

        // Check late payments
        $latePayments = 0;
        foreach ($customer->loans as $loan) {
            $payments = PaymentSubmissions::where('loan_number', $loan->loan_number)
                ->where('submission_status', 11)
                ->get();
            foreach ($payments as $payment) {
                $schedule = $payment->schedule;
                if ($schedule && $payment->submitted_date > $schedule->payment_due_date) {
                    $latePayments++;
                }
            }
        }
        if ($latePayments > 0) {
            $factors[] = "$latePayments late payment(s)";
        }

        // Check high DTI
        $totalDebt = $customer->loans->sum(function ($loan) {
            return ($loan->total_loan + ($loan->penalty_amount ?? 0)) - ($loan->loan_paid ?? 0);
        });
        $income = $customer->income ?? 0;
        if ($income > 0 && ($totalDebt / $income) > 0.5) {
            $factors[] = "High debt-to-income ratio";
        }

        // Check default history
        $defaultedLoans = $customer->loans->where('status', Loans::STATUS_DEFAULTED)->count();
        if ($defaultedLoans > 0) {
            $factors[] = "Previous default(s)";
        }

        if (empty($factors)) {
            $factors[] = "Good repayment history";
        }

        return $factors;
    }

    private function getRecommendedAction($riskScore)
    {
        if ($riskScore >= 70) {
            return "Immediate Action Required";
        } elseif ($riskScore >= 40) {
            return "Monitor Closely";
        } else {
            return "Regular Monitoring";
        }
    }
}
