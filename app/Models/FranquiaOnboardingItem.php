<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FranquiaOnboardingItem extends Model
{
    protected $table    = 'franquia_onboarding_itens';
    protected $fillable = ['titulo', 'descricao', 'ordem', 'active'];
    protected $casts    = ['active' => 'boolean'];
}
