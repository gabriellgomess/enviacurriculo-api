<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiscLeadResultado extends Model
{
    protected $table    = 'disc_lead_resultados';
    protected $fillable = [
        'convite_id', 'lead_id', 'score_d', 'score_i', 'score_s', 'score_c',
        'perfil_dominante', 'respostas',
    ];
    protected $casts = ['respostas' => 'array'];

    public function convite() { return $this->belongsTo(DiscConvite::class, 'convite_id'); }
    public function lead()    { return $this->belongsTo(FranquiaLead::class, 'lead_id'); }
}
