<?php

namespace App\Jobs;

use App\Models\Backup;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class RunDatabaseBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $backupId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $backupId)
    {
        $this->backupId = $backupId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $backup = Backup::find($this->backupId);
        if (!$backup) {
            return;
        }

        $backup->update(['status' => 'processing']);

        try {
            $diskName = $backup->disk ?? 'local';
            $disk = Storage::disk($diskName);

            $timestamp = now()->format('Y-m-d_H-i-s');
            $fileName = "backups/db-backup-{$timestamp}.sql.gz";

            // Ensure directory
            if (!$disk->exists('backups')) {
                $disk->makeDirectory('backups');
            }

            // TEMP file path
            $localPath = storage_path("app/{$fileName}");

            // Build command using env variables
            $dbHost = env('DB_HOST');
            $dbPort = env('DB_PORT', 3306);
            $dbName = env('DB_DATABASE');
            $dbUser = env('DB_USERNAME');
            $dbPass = env('DB_PASSWORD');

            // NOTE: make sure mysqldump is installed on your server
            $command = sprintf(
                'mysqldump -h%s -P%s -u%s -p%s %s | gzip > %s',
                escapeshellarg($dbHost),
                escapeshellarg($dbPort),
                escapeshellarg($dbUser),
                escapeshellarg($dbPass),
                escapeshellarg($dbName),
                escapeshellarg($localPath)
            );

            // Run the command
            $result = null;
            $output = [];
            exec($command, $output, $result);

            if ($result !== 0) {
                throw new Exception('mysqldump failed with code ' . $result);
            }

            // Move file into configured disk if not local
            if ($diskName === 'local') {
                // already in local disk path
            } else {
                $disk->put($fileName, file_get_contents($localPath));
                @unlink($localPath);
            }

            // Get size
            $size = $disk->size($fileName);

            $backup->update([
                'path' => $fileName,
                'size_bytes' => $size,
                'status' => 'completed',
            ]);
        } catch (Exception $e) {
            Log::error('Database backup failed', [
                'backup_id' => $this->backupId,
                'error' => $e->getMessage(),
            ]);

            $backup->update([
                'status' => 'failed',
                'meta' => array_merge($backup->meta ?? [], [
                    'error' => $e->getMessage(),
                ]),
            ]);
        }
    }
}

