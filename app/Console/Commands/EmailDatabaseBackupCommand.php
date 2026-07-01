<?php

namespace App\Console\Commands;

use App\Mail\DatabaseBackupMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class EmailDatabaseBackupCommand extends Command
{
    protected $signature = 'backup:database-email
        {--email=}
        {--compress}
        {--skip-email}';

    protected $description = 'Database backup (PHP streaming safe version)';

    public function handle()
    {
        $this->info("Starting database backup...");

        $email = $this->option('email') ?? env('BACKUP_EMAIL');
        $compress = $this->option('compress');
        $skipEmail = $this->option('skip-email');

        try {

            $file = $this->createBackup($compress);

            $this->info("Backup created: " . basename($file));

            if (!$skipEmail && $email) {
                if ($this->sendEmail($file, $email)) {
                    $this->info("Email sent to: {$email}");
                }
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {

            $this->error("Backup failed: " . $e->getMessage());

            Log::error('Backup failed', [
                'error' => $e->getMessage()
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * STREAMING PHP BACKUP (NO MEMORY EXPLOSION)
     */
    protected function createBackup($compress = false)
    {
        $db = config('database.connections.mysql.database');
        $path = storage_path("app/backups");

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $file = "{$path}/backup_{$db}_" . date('Y_m_d_H_i_s') . ".sql";

        $handle = fopen($file, 'w');

        fwrite($handle, "-- Laravel Backup\n");
        fwrite($handle, "-- Date: " . date('Y-m-d H:i:s') . "\n\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        $tables = DB::select('SHOW TABLES');
        $key = "Tables_in_" . $db;

        foreach ($tables as $table) {

            $tableName = $table->$key;

            $this->info("Processing: {$tableName}");

            fwrite($handle, "\nDROP TABLE IF EXISTS `$tableName`;\n");

            $create = DB::select("SHOW CREATE TABLE `$tableName`");
            fwrite($handle, $create[0]->{'Create Table'} . ";\n\n");

            $this->streamTableData($handle, $tableName);
        }

        fwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");

        fclose($handle);

        if ($compress) {
            $file = $this->gzip($file);
        }

        return $file;
    }

    /**
     * STREAM TABLE DATA WITHOUT LOADING EVERYTHING
     */
    protected function streamTableData($handle, $table)
    {
        $chunk = 500;
        $offset = 0;

        while (true) {

            $rows = DB::table($table)
                ->offset($offset)
                ->limit($chunk)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {

                $data = array_map(function ($value) {

                    if (is_null($value)) {
                        return "NULL";
                    }

                    return "'" . addslashes($value) . "'";

                }, (array) $row);

                fwrite(
                    $handle,
                    "INSERT INTO `$table` VALUES (" .
                    implode(",", $data) .
                    ");\n"
                );
            }

            unset($rows);
            gc_collect_cycles();

            $offset += $chunk;
        }
    }

    /**
     * COMPRESS FILE
     */
    protected function gzip($file)
    {
        $gz = $file . ".gz";

        $in = fopen($file, "rb");
        $out = gzopen($gz, "wb9");

        while (!feof($in)) {
            gzwrite($out, fread($in, 1024 * 1024));
        }

        fclose($in);
        gzclose($out);

        unlink($file);

        return $gz;
    }

    /**
     * EMAIL BACKUP
     */
    protected function sendEmail($file, $email)
    {
        try {

            Mail::to($email)->send(
                new DatabaseBackupMail(
                    $file,
                    filesize($file),
                    config('database.connections.mysql.database')
                )
            );
            return true;

        } catch (\Exception $e) {
            $this->error("Email failed: " . $e->getMessage());
            Log::warning("Email failed", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}