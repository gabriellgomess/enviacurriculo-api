<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
        ];
    }

    public function roles()
    {
        return $this->hasMany(UserRole::class);
    }

    public function contexts()
    {
        return $this->hasMany(UserContext::class);
    }

    public function hasRole(string $role): bool
    {
        return $this->roles()->where('role', $role)->exists();
    }

    public function hasAnyRole(array $roles): bool
    {
        return $this->roles()->whereIn('role', $roles)->exists();
    }

    public function getRoleNames(): array
    {
        return $this->roles()->pluck('role')->toArray();
    }

    public function adminPermission()
    {
        return $this->hasOne(AdminPermission::class);
    }

    public function candidato()
    {
        return $this->hasOne(Candidato::class);
    }

    public function parceiro()
    {
        return $this->hasOne(Parceiro::class);
    }
}
