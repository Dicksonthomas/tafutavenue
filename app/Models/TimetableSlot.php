<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimetableSlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'venue_id',
        'semester_id',
        'day_of_week',
        'start_time',
        'end_time',
        'course_unit',
        'lecturer_name',
        'program',
        'source',
    ];

    /** @return BelongsTo<Venue, TimetableSlot> */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /** @return BelongsTo<Semester, TimetableSlot> */
    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    /**
     * Filter lecture/timetable slots that clash with the given time range,
     * for a venue and day of week.
     */
    public function scopeOverlapping(Builder $query, int $venueId, string $dayOfWeek, string $start, string $end): Builder
    {
        return $query
            ->where('venue_id', $venueId)
            ->where('day_of_week', $dayOfWeek)
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start);
    }
}
