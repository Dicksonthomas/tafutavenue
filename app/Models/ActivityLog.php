<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    protected $fillable = [
        'user_id',
        'booking_id',
        'action',
        'message',
    ];

    /** @return BelongsTo<User, ActivityLog> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return BelongsTo<Booking, ActivityLog> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public static function record(?int $userId, string $action, string $message, ?int $bookingId = null): self
    {
        return static::create([
            'user_id' => $userId,
            'booking_id' => $bookingId,
            'action' => $action,
            'message' => $message,
        ]);
    }
}
