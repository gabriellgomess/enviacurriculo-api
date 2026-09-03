<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;
    protected $table = 'audit_logs';

    protected $fillable = [
        'user_id',
        'user_name',
        'user_email',
        'role',
        'franquia_id',
        'action',
        'descricao',
        'entity_type',
        'entity_id',
        'dados_anteriores',
        'dados_novos',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'dados_anteriores' => 'array',
        'dados_novos'      => 'array',
        'created_at'       => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function franquia()
    {
        return $this->belongsTo(Franquia::class);
    }
}
