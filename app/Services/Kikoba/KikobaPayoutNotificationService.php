<?php

namespace App\Services\Kikoba;

use App\Mail\KikobaPayoutReportMail;
use App\Models\KikobaFinancialYearCloseReport;
use App\Models\KikobaGroupFinancialYear;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Throwable;

class KikobaPayoutNotificationService
{
    public function __construct(
        protected NotificationService $notificationService,
        protected KikobaFinancialYearCloseReportService $closeReportService,
    ) {
    }

    /**
     * SMS + email every member of a finalized close report their own
     * payout figure — one attempt per channel they have on file, so a
     * member with only a phone number (or only an email) still gets
     * notified on whichever channel is available, and one missing/failing
     * channel never blocks the other. Refuses on a cycle that isn't
     * finalized yet, since draft figures can still change under
     * generate()/purge().
     *
     * @return array{
     *     total_members: int,
     *     sms_sent: int,
     *     sms_failed: int,
     *     email_sent: int,
     *     email_failed: int,
     *     skipped_no_contact: int,
     *     details: array
     * }
     *
     * @throws InvalidArgumentException when this cycle isn't finalized
     */
    public function notifyMembers(KikobaGroupFinancialYear $groupFinancialYear): array
    {
        if (! $this->closeReportService->isFinalized($groupFinancialYear)) {
            throw new InvalidArgumentException(
                'This financial-year cycle is not finalized yet — notifications can only be sent once the payout report is locked in.'
            );
        }

        $group = $groupFinancialYear->group;
        $company = $group->company;
        $financialYearName = $groupFinancialYear->financialYear?->name ?? '';
        $periodLabel = $this->formatPeriod($groupFinancialYear);

        $reports = KikobaFinancialYearCloseReport::where('group_financial_year_id', $groupFinancialYear->id)
            ->where('status', 'finalized')
            ->with('groupMember.member')
            ->get();

        $summary = [
            'total_members' => $reports->count(),
            'sms_sent' => 0,
            'sms_failed' => 0,
            'email_sent' => 0,
            'email_failed' => 0,
            'skipped_no_contact' => 0,
            'details' => [],
        ];

        foreach ($reports as $report) {
            $member = $report->groupMember?->member;

            $detail = [
                'kikoba_group_member_id' => $report->kikoba_group_member_id,
                'name' => $member ? trim("{$member->first_name} {$member->last_name}") : "#{$report->kikoba_group_member_id}",
                'sms' => null,
                'email' => null,
            ];

            if (! $member) {
                $summary['skipped_no_contact']++;
                $summary['details'][] = $detail;

                continue;
            }

            $hadContact = false;

            if (! empty($member->phone)) {
                $hadContact = true;

                $ok = $this->notificationService->sendKikobaPayoutSMS(
                    $member,
                    $group->name,
                    $financialYearName,
                    (float) $report->total_payout,
                    $company
                );

                $detail['sms'] = $ok;
                $ok ? $summary['sms_sent']++ : $summary['sms_failed']++;
            }

            if (! empty($member->email)) {
                $hadContact = true;

                try {
                    Mail::to($member->email)->send(new KikobaPayoutReportMail(
                        memberName: $detail['name'],
                        groupName: $group->name,
                        financialYearName: $financialYearName,
                        periodLabel: $periodLabel,
                        totalSavingsAmount: (float) $report->total_savings_amount,
                        totalShareAmount: (float) $report->total_share_amount,
                        profitAmount: (float) $report->profit_amount,
                        totalPayout: (float) $report->total_payout,
                        companyName: $company->company_name,
                        companyPhone: $company->company_phone,
                    ));

                    $detail['email'] = true;
                    $summary['email_sent']++;
                } catch (Throwable $e) {
                    Log::error('Failed to send Kikoba payout report email', [
                        'kikoba_group_member_id' => $report->kikoba_group_member_id,
                        'email' => $member->email,
                        'error' => $e->getMessage(),
                    ]);

                    $detail['email'] = false;
                    $summary['email_failed']++;
                }
            }

            if (! $hadContact) {
                $summary['skipped_no_contact']++;
            }

            $summary['details'][] = $detail;
        }

        return $summary;
    }

    protected function formatPeriod(KikobaGroupFinancialYear $groupFinancialYear): string
    {
        $start = $groupFinancialYear->start_date?->format('d/m/Y');
        $end = $groupFinancialYear->end_date?->format('d/m/Y');

        return $start && $end ? "{$start} - {$end}" : '';
    }
}
