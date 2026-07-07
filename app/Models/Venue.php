<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Venue extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'building',
        'faculty',
        'campus',
        'capacity',
        'type',
        'status',
        'source',
        'description',
        'created_by',
        'blocked_purposes',
        'restricted_levels',
        'restricted_department',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'blocked_purposes' => 'array',
            'restricted_levels' => 'array',
        ];
    }

    public function allowsPurpose(string $purpose): bool
    {
        return ! in_array($purpose, $this->blocked_purposes ?? [], true);
    }

    public function allowsUser(User $user): bool
    {
        if ($user->role !== 'admin' && $this->campus && $user->campus && $this->campus !== $user->campus) {
            return false;
        }

        if (! empty($this->restricted_levels) && ! in_array($user->level, $this->restricted_levels, true)) {
            return false;
        }

        if (! empty($this->restricted_department) && $this->restricted_department !== $user->department) {
            return false;
        }

        return true;
    }

    /** @return BelongsTo<User, Venue> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<TimetableSlot> */
    public function timetableSlots(): HasMany
    {
        return $this->hasMany(TimetableSlot::class);
    }

    /** @return HasMany<Booking> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
