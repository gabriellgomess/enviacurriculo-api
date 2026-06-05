<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FranquiaOnboardingProgresso extends Model
{
    protected $table    = 'franquia_onboarding_progresso';
    protected $fillable = ['franquia_id', 'item_id', 'concluido', 'concluido_em'];
    protected $casts    = ['concluido' => 'boolean', 'concluido_em' => 'datetime'];
}
