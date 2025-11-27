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
use Spatie\Multitenancy\Jobs\NotTenantAware;
use Spatie\DbDumper\Databases\MySql;

class RunDatabaseBackupJob implements ShouldQueue, NotTenantAware
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $backupId;

    public function __construct(int $backupId)
    {
        $this->backupId = $backupId;
    }

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
            $fileName = "backups/db-backup-{$timestamp}.sql";

            if (!$disk->exists('backups')) {
                $disk->makeDirectory('backups');
            }

            $tempPath = storage_path("app/temp-dump-{$timestamp}.sql");

            Log::info('Starting DB backup', [
                'backup_id' => $this->backupId,
                'temp_path' => $tempPath,
                'disk' => $diskName,
            ]);

            MySql::create()
                ->setDbName(config('database.connections.mysql.database'))
                ->setUserName(config('database.connections.mysql.username'))
                ->setPassword(config('database.connections.mysql.password'))
                ->setHost(config('database.connections.mysql.host'))
                ->setPort(config('database.connections.mysql.port', 3306))
                ->dumpToFile($tempPath);

            if (!file_exists($tempPath)) {
                throw new \Exception("Dump file was not created at {$tempPath}");
            }

            $disk->put($fileName, file_get_contents($tempPath));
            @unlink($tempPath);

            $size = $disk->size($fileName);

            $backup->update([
                'path' => $fileName,
                'size_bytes' => $size,
                'status' => 'completed',
            ]);

            Log::info('Database backup completed', [
                'backup_id' => $this->backupId,
                'path' => $fileName,
                'size_bytes' => $size,
            ]);
        } catch (\Exception $e) {
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
