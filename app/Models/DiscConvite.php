<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DiscConvite extends Model
{
    protected $table    = 'disc_convites';
    protected $fillable = [
        'lead_id', 'candidato_id', 'vaga_envio_id', 'empresa_id', 'criado_por',
        'token', 'status', 'expires_at',
    ];
    protected $casts = ['expires_at' => 'datetime'];

    public function lead()               { return $this->belongsTo(FranquiaLead::class, 'lead_id'); }
    public function candidato()          { return $this->belongsTo(Candidato::class); }
    public function resultado()          { return $this->hasOne(DiscLeadResultado::class, 'convite_id'); }
    public function resultadoCandidato() { return $this->hasOne(CandidatoDisc::class, 'convite_id'); }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public static function gerarParaLead(int $leadId, int $diasValidade = 7): self
    {
        return static::create([
            'lead_id'    => $leadId,
            'token'      => Str::random(64),
            'status'     => 'pendente',
            'expires_at' => now()->addDays($diasValidade),
        ]);
    }
}
