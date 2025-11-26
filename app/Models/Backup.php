<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Backup extends Model
{
    use HasFactory;

    protected $fillable = [
        'disk',
        'path',
        'size_bytes',
        'type',
        'status',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function initiatedByUser()
    {
        $id = $this->meta['initiated_by'] ?? null;
        if (!$id) {
            return null;
        }

        return User::find($id);
    }
}
