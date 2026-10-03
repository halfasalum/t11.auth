<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class KikobaPayoutReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $memberName,
        public string $groupName,
        public string $financialYearName,
        public string $periodLabel,
        public float $totalSavingsAmount,
        public int $totalShareUnits,
        public float $totalShareAmount,
        public int $broughtForwardShareUnits,
        public float $broughtForwardShareAmount,
        public float $profitAmount,
        public float $totalPayout,
        public array $breakdown,
        public string $companyName,
        public ?string $companyPhone,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Taarifa ya Malipo - {$this->groupName}" . ($this->financialYearName ? " ({$this->financialYearName})" : ''),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.kikoba-payout-report',
        );
    }
}
