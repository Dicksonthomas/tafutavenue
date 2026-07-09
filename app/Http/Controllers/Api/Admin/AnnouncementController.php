<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnnouncementController extends Controller
{
    private const VALID_LEVELS = ['Certificate', 'Diploma', 'Degree', 'Masters', 'PhD'];

    /**
     * Announcements the Admin can manage - their own, or every one if
     * they're a Super Admin (same oversight pattern as Admin management).
     */
    public function index(Request $request): JsonResponse
    {
        $perPageInput = $request->input('per_page', 20);
        $perPage = ($perPageInput === 'all' || ! is_numeric($perPageInput)) ? 100000 : max(1, (int) $perPageInput);

        $announcements = Announcement::with('admin:id,name')
            ->when(! $request->user()->isSuperAdmin(), fn ($q) => $q->where('admin_id', $request->user()->id))
            ->withCount([
                'notifications',
                'notifications as read_count' => fn ($q) => $q->whereNotNull('read_at'),
            ])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json($announcements);
    }

    private function assertManageable(Request $request, Announcement $announcement): void
    {
        abort_unless(
            $announcement->admin_id === $request->user()->id || $request->user()->isSuperAdmin(),
            403,
            'You can only manage your own announcements.'
        );
    }

    /**
     * Post an announcement that fans out as a Notification to a chosen
     * audience of CRs. A regular Admin is always locked to their own campus;
     * a Super Admin may pick a campus (or leave it blank for everyone
     * university-wide). Either way, faculty/department/program/level/year
     * are all optional extra filters - leaving them blank sends to
     * everyone in the campus scope above.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:2000'],
            'campus' => ['nullable', 'string'],
            'faculty' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'program' => ['nullable', 'string', 'max:255'],
            'level' => ['nullable', Rule::in(self::VALID_LEVELS)],
            'year_of_study' => ['nullable', 'integer', 'min:1', 'max:4'],
        ]);

        $announcement = Announcement::create([
            'admin_id' => $request->user()->id,
            'title' => $data['title'],
            'body' => $data['body'],
        ]);

        $campusScope = $request->user()->campusScope();

        $recipientIds = User::where('role', 'cr')
            ->when($campusScope, fn ($q) => $q->where('campus', $campusScope))
            ->when(! $campusScope && ! empty($data['campus']), fn ($q) => $q->where('campus', $data['campus']))
            ->when(! empty($data['faculty']), fn ($q) => $q->where('faculty', $data['faculty']))
            ->when(! empty($data['department']), fn ($q) => $q->where('department', $data['department']))
            ->when(! empty($data['program']), fn ($q) => $q->where('program', $data['program']))
            ->when(! empty($data['level']), fn ($q) => $q->where('level', $data['level']))
            ->when(! empty($data['year_of_study']), fn ($q) => $q->where('year_of_study', $data['year_of_study']))
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

    /**
     * Edit an already-sent announcement's title/body - also updates every
     * Notification row it already fanned out to, so CRs who already have it
     * in their list see the corrected text instead of the original.
     */
    public function update(Request $request, Announcement $announcement): JsonResponse
    {
        $this->assertManageable($request, $announcement);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $announcement->update($data);

        $announcement->notifications()->update([
            'title' => $data['title'],
            'body' => $data['body'],
        ]);

        ActivityLog::record(
            $request->user()->id,
            'announcement_updated',
            "{$request->user()->name} edited the announcement \"{$announcement->title}\"."
        );

        return response()->json($announcement);
    }

    /**
     * Delete an announcement and every Notification it fanned out to - this
     * is also how it's "hidden" from CRs, since deleting the Notification
     * rows removes it from their list immediately.
     */
    public function destroy(Request $request, Announcement $announcement): JsonResponse
    {
        $this->assertManageable($request, $announcement);

        $title = $announcement->title;
        $announcement->notifications()->delete();
        $announcement->delete();

        ActivityLog::record(
            $request->user()->id,
            'announcement_deleted',
            "{$request->user()->name} deleted the announcement \"{$title}\"."
        );

        return response()->json(['message' => 'Announcement deleted.']);
    }
}
