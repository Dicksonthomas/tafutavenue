<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    private function myQuery(Request $request)
    {
        $user = $request->user();

        return Notification::where('user_id', $user->id)
            // Defensive: a Staff Admin should never see a CR-pending notice
            // (and vice versa for a general Admin), even if a stray row
            // exists from before domain-aware targeting was added.
            ->when(
                $user->role === 'admin' && $user->isStaffAdmin(),
                fn ($q) => $q->where('type', '!=', 'cr_pending')
            )
            ->when(
                $user->role === 'admin' && ! $user->isStaffAdmin(),
                fn ($q) => $q->where('type', '!=', 'staff_pending')
            );
    }

    /**
     * The logged-in user's own notifications - booking status changes for a
     * CR, or new booking requests for an Admin, plus any announcements sent
     * to them. Filterable by type, read/unread, a single date, and a free
     * text search over title/body.
     */
    public function index(Request $request): JsonResponse
    {
        $perPageInput = $request->input('per_page', 20);
        $perPage = ($perPageInput === 'all' || ! is_numeric($perPageInput)) ? 100000 : max(1, (int) $perPageInput);

        $notifications = $this->myQuery($request)
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('read'), function ($q) use ($request) {
                $read = (string) $request->string('read');
                if ($read === 'unread') {
                    $q->whereNull('read_at');
                } elseif ($read === 'read') {
                    $q->whereNotNull('read_at');
                }
            })
            ->when($request->filled('date'), fn ($q) => $q->whereDate('created_at', $request->string('date')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = $request->string('q');
                $q->where(function ($w) use ($term) {
                    $w->where('title', 'like', "%{$term}%")->orWhere('body', 'like', "%{$term}%");
                });
            })
            ->with(['booking.venue'])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json($notifications);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'count' => $this->myQuery($request)->whereNull('read_at')->count(),
        ]);
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json($notification);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $count = $this->myQuery($request)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.', 'updated' => $count]);
    }

    /**
     * Delete a single notification from the viewer's own inbox - this only
     * removes it for them, it doesn't affect the same announcement/booking
     * notice for any other recipient.
     */
    public function destroy(Request $request, Notification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        $notification->delete();

        return response()->json(['message' => 'Notification deleted.']);
    }
}
