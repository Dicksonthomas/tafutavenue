<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'reg_no',
        'staff_id',
        'position',
        'email',
        'password',
        'role',
        'phone',
        'campus',
        'sex',
        'faculty',
        'department',
        'program',
        'level',
        'year_of_study',
        'is_active',
        'approved_at',
        'preferred_color',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_super_admin' => 'boolean',
            'is_main_super_admin' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isStaff(): bool
    {
        return $this->role === 'staff';
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'admin' && $this->is_super_admin;
    }

    /**
     * The "Main" Super Admin - the only one who cannot be removed or
     * changed by other, promoted Super Admins. Unlike isSuperAdmin(), which
     * is true for all Super Admins (main and promoted).
     */
    public function isMainSuperAdmin(): bool
    {
        return $this->role === 'admin' && $this->is_super_admin && $this->is_main_super_admin;
    }

    /**
     * The campus this Admin should ONLY see - null for a Super Admin
     * (they see all campuses).
     */
    public function campusScope(): ?string
    {
        return $this->isSuperAdmin() ? null : $this->campus;
    }

    /** @return HasMany<Booking> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
