<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunDatabaseBackupJob;
use App\Models\Backup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminBackupController extends Controller
{
    public function index(Request $request)
    {
        // You can add filters later (status, date range, type)
        $backups = Backup::orderByDesc('created_at')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $backups,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        // Optional: restrict to SuperAdmin only
        if (!in_array($user->role, ['SuperAdmin', 'Administrator'])) {
            abort(403, 'Not allowed to trigger backups.');
        }

        $backup = Backup::create([
            'disk' => 'local',
            'path' => null,
            'size_bytes' => 0,
            'type' => 'database',
            'status' => 'queued',
            'meta' => [
                'initiated_by' => $user->id,
            ],
        ]);

        RunDatabaseBackupJob::dispatch($backup->id);

        return response()->json([
            'success' => true,
            'message' => 'Backup requested. It will be processed shortly.',
            'backup' => $backup,
        ], 201);
    }


    public function download(Request $request, Backup $backup)
    {
        $user = $request->user();

        if ($user->role !== 'SuperAdmin') {
            abort(403, 'Not allowed to download backups.');
        }

        if ($backup->status !== 'completed' || !$backup->path) {
            abort(400, 'Backup is not ready or has failed.');
        }

        $disk = Storage::disk($backup->disk);

        if (!$disk->exists($backup->path)) {
            abort(404, 'Backup file not found.');
        }

        return $disk->download($backup->path);
    }
}
