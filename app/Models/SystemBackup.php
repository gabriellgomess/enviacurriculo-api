<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemBackup extends Model
{
    protected $table = 'system_backups';

    protected $fillable = [
        'backup_type',
        'status',
        'tables_count',
        'records_count',
        'size_bytes',
        'restored_at',
        'filename',
    ];

    protected $casts = [
        'restored_at' => 'datetime',
    ];
}
