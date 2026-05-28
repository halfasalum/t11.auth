<?php
// app/Mail/DatabaseBackupMail.php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DatabaseBackupMail extends Mailable
{
    use Queueable, SerializesModels;

    public $backupPath;
    public $backupSize;
    public $databaseName;
    public $backupDate;

    /**
     * Create a new message instance.
     */
    public function __construct($backupPath, $backupSize, $databaseName)
    {
        $this->backupPath = $backupPath;
        $this->backupSize = $this->formatBytes($backupSize);
        $this->databaseName = $databaseName;
        $this->backupDate = now()->format('Y-m-d H:i:s');
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[' . config('app.name') . '] Database Backup - ' . now()->format('Y-m-d'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.database-backup',
        );
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        $filename = basename($this->backupPath);
        
        // Determine correct mime type based on file extension
        $mimeType = 'application/sql';
        if (str_ends_with($filename, '.gz')) {
            $mimeType = 'application/gzip';
        } elseif (str_ends_with($filename, '.zip')) {
            $mimeType = 'application/zip';
        }
        
        return [
            Attachment::fromPath($this->backupPath)
                ->as($filename)
                ->withMime($mimeType),
        ];
    }

    private function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}