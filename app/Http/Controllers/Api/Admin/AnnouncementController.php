<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    /**
     * Post an announcement that fans out as a Notification to every CR - on
     * the Admin's own campus if they're scoped to one, or every CR
     * university-wide for a Super Admin.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $announcement = Announcement::create([
            'admin_id' => $request->user()->id,
            'title' => $data['title'],
            'body' => $data['body'],
        ]);

        $campusScope = $request->user()->campusScope();

        $recipientIds = User::where('role', 'cr')
            ->when($campusScope, fn ($q) => $q->where('campus', $campusScope))
            ->pluck('id');

        $now = now();
        $rows = $recipientIds->map(fn ($userId) => [
            'user_id' => $userId,
            'type' => 'announcement',
            'title' => $announcement->title,
            'body' => $announcement->body,
            'announcement_id' => $announcement->id,
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($rows !== []) {
            Notification::insert($rows);
        }

        ActivityLog::record(
            $request->user()->id,
            'announcement_sent',
            "{$request->user()->name} sent an announcement to {$recipientIds->count()} CR(s): \"{$announcement->title}\"."
        );

        return response()->json(['announcement' => $announcement, 'recipients' => $recipientIds->count()], 201);
    }
}
