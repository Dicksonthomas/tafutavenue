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
}
