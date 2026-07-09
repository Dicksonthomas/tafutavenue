<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'venue_id',
        'semester_id',
        'booking_date',
        'start_time',
        'end_time',
        'purpose',
        'title',
        'reason',
        'status',
        'signature',
        'signed_at',
        'approved_by',
        'approved_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            // Explicit Y-m-d format, not the bare 'date' cast - the bare cast
            // serializes to a full ISO8601 datetime converted to UTC, which
            // silently shifts the date back a day once the app timezone
            // (Africa/Dar_es_Salaam, UTC+3) is anything other than UTC.
            'booking_date' => 'date:Y-m-d',
            'signed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, Booking> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return BelongsTo<Venue, Booking> */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /** @return BelongsTo<Semester, Booking> */
    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    /** @return BelongsTo<User, Booking> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }

    /**
     * Filter bookings that clash with the given time range (start_time -
     * end_time) for a venue and date. Rejected/cancelled bookings don't
     * block others. $excludingId lets an edit re-check for conflicts
     * without the booking clashing with its own, not-yet-saved, old self.
     */
    public function scopeOverlapping(Builder $query, int $venueId, string $date, string $start, string $end, ?int $excludingId = null): Builder
    {
        return $query
            ->where('venue_id', $venueId)
            ->whereDate('booking_date', $date)
            ->whereIn('status', ['pending', 'approved'])
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->when($excludingId, fn ($q) => $q->where('id', '!=', $excludingId));
    }
}
