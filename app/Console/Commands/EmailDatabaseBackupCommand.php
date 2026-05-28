<?php
// app/Console/Commands/EmailDatabaseBackupCommand.php

namespace App\Console\Commands;

use App\Mail\DatabaseBackupMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class EmailDatabaseBackupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:database-email
                            {--email= : Send backup to specific email address}
                            {--compress : Compress the backup file}
                            {--skip-email : Skip sending email, only save backup locally}
                            {--method=auto : Backup method (auto, mysqldump, php)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Take fast database backup and optionally send via email';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting database backup process...');

        $recipientEmail = $this->option('email') ?? env('BACKUP_EMAIL', null);
        $compress = $this->option('compress') ?? env('BACKUP_COMPRESS', false);
        $skipEmail = $this->option('skip-email');
        $method = $this->option('method');

        $startTime = microtime(true);

        try {
            // Create backup using appropriate method
            $backupFile = $this->createBackup($compress, $method);
            $fileSize = filesize($backupFile);
            $executionTime = round(microtime(true) - $startTime, 2);

            $this->info("✅ Backup created: " . basename($backupFile));
            $this->info("📊 Backup size: " . $this->formatBytes($fileSize));
            $this->info("⏱️  Execution time: {$executionTime} seconds");

            // Save to permanent storage
            $savedPath = $this->saveToStorage($backupFile);
            $this->info("💾 Backup saved to: storage/app/backups/" . basename($savedPath));

            // Send email if requested
            if (!$skipEmail && $recipientEmail) {
                $this->sendBackupEmail($savedPath, $recipientEmail);
                $this->info("📧 Backup sent to: {$recipientEmail}");
            } elseif (!$skipEmail && !$recipientEmail) {
                $this->warn("No email recipient specified. Use --email=user@example.com or set BACKUP_EMAIL in .env");
                $this->info("Backup saved locally only.");
            } elseif ($skipEmail) {
                $this->info("Email sending skipped. Backup saved locally only.");
            }

            // Clean up temporary file (keep the stored one)
            if ($backupFile !== $savedPath && file_exists($backupFile)) {
                unlink($backupFile);
            }

            $this->info('✅ Database backup completed successfully!');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('❌ Backup failed: ' . $e->getMessage());
            Log::error('Email database backup failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Create database backup using appropriate method
     */
    protected function createBackup($compress = false, $method = 'auto')
    {
        if ($method === 'php') {
            $this->info("Using PHP method (slower but works everywhere)");
            return $this->createBackupWithPHP($compress);
        }

        // Try to find and use mysqldump
        $mysqldumpPath = $this->findMysqldumpPath();

        if ($mysqldumpPath) {
            $this->info("🚀 Using mysqldump for fast backup");
            return $this->createBackupWithMysqldump($compress, $mysqldumpPath);
        }

        $this->warn("mysqldump not found, using PHP method (slower)");
        return $this->createBackupWithPHP($compress);
    }

    /**
     * Find mysqldump path
     */
    protected function findMysqldumpPath()
    {
        // First check if path is specified in .env
        $envPath = env('MYSQLDUMP_PATH');
        if ($envPath && file_exists($envPath)) {
            return $envPath;
        }

        // Check common paths
        $commonPaths = [
            'mysqldump',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/opt/lampp/bin/mysqldump',
            '/opt/cpanel/bin/mysqldump',
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
            'C:\\Program Files\\MySQL\\MySQL Server 5.7\\bin\\mysqldump.exe',
        ];

        foreach ($commonPaths as $path) {
            if (strpos(PHP_OS, 'WIN') !== false) {
                if (file_exists($path)) {
                    return $path;
                }
            } else {
                exec("which {$path} 2>/dev/null", $output, $returnCode);
                if ($returnCode === 0 && !empty($output)) {
                    return $output[0];
                }
            }
        }

        return null;
    }

    /**
     * Create backup using mysqldump (FAST)
     */
    protected function createBackupWithMysqldump($compress = false, $mysqldumpPath = 'mysqldump')
    {
        $database = config('database.connections.mysql.database');
        $username = config('database.connections.mysql.username');
        $password = config('database.connections.mysql.password');
        $host = config('database.connections.mysql.host');
        $port = config('database.connections.mysql.port') ?: 3306;

        $timestamp = date('Y-m-d_H-i-s');
        $backupDir = storage_path('app/backups/temp');

        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $filename = "backup_{$database}_{$timestamp}.sql";
        $filepath = "{$backupDir}/{$filename}";

        // Optimized mysqldump command for speed
        $command = sprintf(
            '"%s" --no-tablespaces --single-transaction --quick --skip-lock-tables --skip-add-locks --skip-comments --compact --host=%s --port=%d --user=%s --password=%s %s > "%s" 2>&1',
            $mysqldumpPath,
            escapeshellarg($host),
            $port,
            escapeshellarg($username),
            escapeshellarg($password),
            escapeshellarg($database),
            $filepath
        );

        $this->line("   Running mysqldump...");

        // Execute command
        exec($command, $output, $returnCode);

        // Check if backup was successful
        if ($returnCode !== 0 || !file_exists($filepath) || filesize($filepath) === 0) {
            $error = implode("\n", $output);
            if (file_exists($filepath)) {
                unlink($filepath);
            }
            throw new \Exception('mysqldump failed: ' . $error);
        }

        $fileSize = filesize($filepath);
        $this->line("   mysqldump completed. Size: " . $this->formatBytes($fileSize));

        // Compress if requested
        if ($compress) {
            $this->line("   Compressing backup...");
            $compressedPath = $filepath . '.gz';

            if ($this->gzipCompress($filepath, $compressedPath)) {
                unlink($filepath);
                $filepath = $compressedPath;
                $this->line("   Compressed size: " . $this->formatBytes(filesize($filepath)));
            }
        }

        return $filepath;
    }

    /**
     * Create backup using pure PHP (Fallback)
     */
    protected function createBackupWithPHP($compress = false)
    {
        $database = config('database.connections.mysql.database');
        $timestamp = date('Y-m-d_H-i-s');
        $backupDir = storage_path('app/backups/temp');

        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $filename = "backup_{$database}_{$timestamp}.sql";
        $filepath = "{$backupDir}/{$filename}";

        $this->line("   Using PHP method (this may take a while)...");

        $handle = fopen($filepath, 'w');

        fwrite($handle, "-- Database Backup\n");
        fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
        fwrite($handle, "-- Database: {$database}\n\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        $tables = DB::select('SHOW TABLES');
        $tableKey = 'Tables_in_' . $database;

        foreach ($tables as $table) {
            $tableName = $table->$tableKey;
            $this->line("   Processing: {$tableName}");

            fwrite($handle, "DROP TABLE IF EXISTS `{$tableName}`;\n");
            $createTable = DB::select("SHOW CREATE TABLE `{$tableName}`");
            fwrite($handle, $createTable[0]->{'Create Table'} . ";\n\n");

            $totalRows = DB::table($tableName)->count();

            if ($totalRows > 0) {
                $chunkSize = 1000;
                $offset = 0;

                while ($offset < $totalRows) {
                    $rows = DB::table($tableName)->skip($offset)->take($chunkSize)->get();

                    if ($rows->count() > 0) {
                        $values = [];
                        foreach ($rows as $row) {
                            $rowArray = (array) $row;
                            $escapedValues = array_map(function ($value) {
                                if ($value === null) return 'NULL';
                                if (is_numeric($value)) return $value;
                                return "'" . addcslashes($value, "\0\n\r\\'\"\x1a") . "'";
                            }, $rowArray);
                            $values[] = "(" . implode(',', $escapedValues) . ")";
                        }
                        fwrite($handle, "INSERT INTO `{$tableName}` VALUES " . implode(",\n", $values) . ";\n");
                    }

                    $offset += $chunkSize;
                }

                fwrite($handle, "\n");
            }
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);

        if ($compress && extension_loaded('zlib')) {
            $compressedPath = $filepath . '.gz';
            $this->gzipCompress($filepath, $compressedPath);
            unlink($filepath);
            $filepath = $compressedPath;
        }

        return $filepath;
    }

    /**
     * Save backup to permanent storage
     */
    protected function saveToStorage($tempPath)
    {
        $filename = basename($tempPath);
        $storagePath = storage_path('app/backups/' . $filename);

        // Create backups directory if it doesn't exist
        $backupDir = storage_path('app/backups');
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        // Copy file to permanent location
        copy($tempPath, $storagePath);

        // Clean up old backups (keep last 30 days)
        $this->cleanupOldBackups($backupDir);

        return $storagePath;
    }

    /**
     * Clean up old backup files
     */
    protected function cleanupOldBackups($backupDir)
    {
        $keepDays = env('BACKUP_KEEP_DAYS', 30);
        $cutoffDate = now()->subDays($keepDays);

        $files = glob($backupDir . '/backup_*.sql*');

        foreach ($files as $file) {
            if (filemtime($file) < $cutoffDate->timestamp) {
                unlink($file);
                $this->line("   Deleted old backup: " . basename($file));
            }
        }
    }

    /**
     * Send backup via email with better error handling
     */
    protected function sendBackupEmail($filePath, $recipient)
    {
        try {
            $fileSize = filesize($filePath);
            $databaseName = config('database.connections.mysql.database');

            // Configure mail for this specific send
            config([
                'mail.mailers.smtp.verify_peer' => false,
                'mail.mailers.smtp.verify_peer_name' => false,
            ]);

            Mail::to($recipient)
                ->send(new DatabaseBackupMail($filePath, $fileSize, $databaseName));
        } catch (\Exception $e) {
            $this->warn("Email sending failed: " . $e->getMessage());
            $this->info("Backup is still available locally at: " . $filePath);
            Log::warning('Email backup failed, but file was saved locally', [
                'error' => $e->getMessage(),
                'file' => $filePath
            ]);
        }
    }

    /**
     * Gzip compress a file
     */
    protected function gzipCompress($source, $destination)
    {
        $sourceHandle = fopen($source, 'rb');
        $destHandle = gzopen($destination, 'wb9');

        if (!$sourceHandle || !$destHandle) {
            return false;
        }

        while (!feof($sourceHandle)) {
            gzwrite($destHandle, fread($sourceHandle, 1024 * 1024));
        }

        fclose($sourceHandle);
        gzclose($destHandle);

        return true;
    }

    /**
     * Format bytes to human readable
     */
    protected function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
