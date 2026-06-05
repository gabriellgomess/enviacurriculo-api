<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserContext extends Model
{
    protected $fillable = ['user_id', 'role', 'context_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function franquia()
    {
        return $this->belongsTo(Franquia::class, 'context_id');
    }
}
