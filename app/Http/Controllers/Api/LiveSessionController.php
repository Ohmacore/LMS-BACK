<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Folder;
use App\Models\LiveSession;
use App\Models\Module;
use App\Services\AccessService;
use App\Services\JitsiTokenService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LiveSessionController extends Controller
{
    public function __construct(
        private AccessService $accessService,
        private JitsiTokenService $jitsiTokenService,
        private NotificationService $notificationService
    ) {
    }

    public function teacherIndex(Request $request, int $moduleId)
    {
        $module = $this->teacherOwnedModule($request, $moduleId);

        if (!$module) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $sessions = LiveSession::with('chapter')
            ->where('module_id', $module->id)
            ->orderByRaw("CASE status WHEN 'live' THEN 0 WHEN 'scheduled' THEN 1 WHEN 'completed' THEN 2 ELSE 3 END")
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn(LiveSession $session) => $this->formatSession($session, $request->user()));

        return response()->json(['live_sessions' => $sessions]);
    }

    public function store(Request $request, int $moduleId)
    {
        $module = $this->teacherOwnedModule($request, $moduleId);

        if (!$module) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'scheduled_at' => 'required|date',
            'chapter_id' => 'nullable|exists:folders,id',
            'provider' => 'nullable|string|in:jitsi,livekit,zoom,external',
        ]);

        if (!empty($validated['chapter_id'])) {
            $chapterBelongsToModule = Folder::where('id', $validated['chapter_id'])
                ->where('module_id', $module->id)
                ->whereNull('parent_folder_id')
                ->exists();

            if (!$chapterBelongsToModule) {
                return response()->json(['message' => 'Chapter does not belong to this module'], 422);
            }
        }

        $provider = $validated['provider'] ?? 'jitsi';
        $room = $this->makeProviderRoom($module, $validated['title']);
        $joinUrl = $this->providerUrl($provider, $room);

        $session = LiveSession::create([
            'module_id' => $module->id,
            'chapter_id' => $validated['chapter_id'] ?? null,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'scheduled_at' => $validated['scheduled_at'],
            'provider' => $provider,
            'provider_room' => $room,
            'join_url' => $joinUrl,
            'start_url' => $joinUrl,
            'zoom_join_url' => $provider === 'zoom' ? $joinUrl : null,
            'zoom_start_url' => $provider === 'zoom' ? $joinUrl : null,
            'status' => 'scheduled',
        ]);

        $this->notificationService->notifyEligibleStudentsForLive($session, 'live_scheduled');

        return response()->json([
            'message' => 'Live session scheduled',
            'live_session' => $this->formatSession($session->load('chapter'), $request->user()),
        ], 201);
    }

    public function start(Request $request, LiveSession $liveSession)
    {
        if (!$this->teacherOwnsSession($request, $liveSession)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (in_array($liveSession->status, ['completed', 'cancelled'], true)) {
            return response()->json(['message' => 'This live session cannot be started'], 422);
        }

        $liveSession->update([
            'status' => 'live',
            'started_at' => $liveSession->started_at ?? now(),
        ]);

        $this->notificationService->notifyEligibleStudentsForLive($liveSession, 'live_started');

        return response()->json([
            'message' => 'Live session started',
            'live_session' => $this->formatSession($liveSession->fresh(['module', 'chapter']), $request->user()),
        ]);
    }

    public function launch(Request $request, LiveSession $liveSession)
    {
        if (!$this->teacherOwnsSession($request, $liveSession)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($liveSession->status !== 'live') {
            return response()->json(['message' => 'This live session is not live yet'], 409);
        }

        $liveSession->load('module.teacher.user', 'chapter');

        return response()->json([
            'live_session' => $this->formatSession($liveSession, $request->user()),
            'launch_url' => $this->sessionStartUrl($liveSession, $request->user()),
        ]);
    }

    public function cancel(Request $request, LiveSession $liveSession)
    {
        if (!$this->teacherOwnsSession($request, $liveSession)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($liveSession->status === 'completed') {
            return response()->json(['message' => 'Completed live sessions cannot be cancelled'], 422);
        }

        $liveSession->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        return response()->json([
            'message' => 'Live session cancelled',
            'live_session' => $this->formatSession($liveSession->fresh(['module', 'chapter']), $request->user()),
        ]);
    }

    public function complete(Request $request, LiveSession $liveSession)
    {
        if (!$this->teacherOwnsSession($request, $liveSession)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'recording_url' => 'nullable|url',
            'recording_resource_id' => 'nullable|exists:resources,id',
        ]);

        $liveSession->update([
            'status' => 'completed',
            'ended_at' => now(),
            'recording_url' => $validated['recording_url'] ?? $liveSession->recording_url,
            'recording_resource_id' => $validated['recording_resource_id'] ?? $liveSession->recording_resource_id,
        ]);

        if ($liveSession->recording_url || $liveSession->recording_resource_id) {
            $this->notificationService->notifyEligibleStudentsForLive($liveSession, 'recording_available');
        }

        return response()->json([
            'message' => 'Live session completed',
            'live_session' => $this->formatSession($liveSession->fresh(['module', 'chapter']), $request->user()),
        ]);
    }

    public function studentIndex(Request $request)
    {
        $sessions = LiveSession::with(['module.teacher.user', 'chapter'])
            ->whereIn('status', ['scheduled', 'live'])
            ->where('scheduled_at', '>=', now()->subHours(2))
            ->orderByRaw("CASE status WHEN 'live' THEN 0 ELSE 1 END")
            ->orderBy('scheduled_at')
            ->get()
            ->filter(fn(LiveSession $session) => $this->accessService->canJoinLiveSession($session, $request->user()))
            ->values()
            ->map(fn(LiveSession $session) => $this->formatSession($session, $request->user()));

        return response()->json(['live_sessions' => $sessions]);
    }

    public function studentModuleIndex(Request $request, int $moduleId)
    {
        $module = Module::findOrFail($moduleId);

        $sessions = LiveSession::with(['module.teacher.user', 'chapter'])
            ->where('module_id', $module->id)
            ->whereIn('status', ['scheduled', 'live'])
            ->where('scheduled_at', '>=', now()->subHours(2))
            ->orderByRaw("CASE status WHEN 'live' THEN 0 ELSE 1 END")
            ->orderBy('scheduled_at')
            ->get()
            ->filter(fn(LiveSession $session) => $this->accessService->canJoinLiveSession($session, $request->user()))
            ->values()
            ->map(fn(LiveSession $session) => $this->formatSession($session, $request->user()));

        return response()->json(['live_sessions' => $sessions]);
    }

    public function join(Request $request, LiveSession $liveSession)
    {
        if (!$this->accessService->canJoinLiveSession($liveSession, $request->user())) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($liveSession->status !== 'live') {
            return response()->json(['message' => 'This live session is not live yet'], 409);
        }

        return response()->json([
            'live_session' => $this->formatSession($liveSession->load('module.teacher.user', 'chapter'), $request->user()),
            'join_url' => $this->sessionJoinUrl($liveSession, $request->user()),
        ]);
    }

    private function teacherOwnedModule(Request $request, int $moduleId): ?Module
    {
        $teacher = $request->user()->teacher;

        if (!$teacher) {
            return null;
        }

        return Module::where('teacher_id', $teacher->id)->find($moduleId);
    }

    private function teacherOwnsSession(Request $request, LiveSession $liveSession): bool
    {
        $liveSession->loadMissing('module');
        $teacher = $request->user()->teacher;

        return $teacher && $liveSession->module->teacher_id === $teacher->id;
    }

    private function makeProviderRoom(Module $module, string $title): string
    {
        return Str::slug("yacine {$module->id} {$title} " . now()->timestamp . ' ' . Str::random(6));
    }

    private function providerUrl(string $provider, string $room): string
    {
        if ($provider === 'jitsi') {
            return $this->jitsiTokenService->roomUrl($room);
        }

        return '';
    }

    private function sessionJoinUrl(LiveSession $session, $user): string
    {
        if ($session->provider === 'jitsi' && $session->provider_room) {
            return $this->jitsiTokenService->urlForSession($session, $user, false);
        }

        return $session->join_url ?: $session->zoom_join_url ?: '';
    }

    private function sessionStartUrl(LiveSession $session, $user): string
    {
        if ($session->provider === 'jitsi' && $session->provider_room) {
            return $this->jitsiTokenService->urlForSession($session, $user, true);
        }

        return $session->start_url ?: $session->zoom_start_url ?: '';
    }

    private function formatSession(LiveSession $session, $user): array
    {
        $session->loadMissing('module.teacher.user', 'chapter');
        $isTeacherOwner = $user->teacher && $session->module->teacher_id === $user->teacher->id;
        $canJoin = $session->status === 'live' && $this->accessService->canJoinLiveSession($session, $user);
        $canLaunch = $isTeacherOwner && $session->status === 'live';

        return [
            'id' => $session->id,
            'module_id' => $session->module_id,
            'chapter_id' => $session->chapter_id,
            'title' => $session->title,
            'description' => $session->description,
            'scheduled_at' => $session->scheduled_at,
            'started_at' => $session->started_at,
            'ended_at' => $session->ended_at,
            'cancelled_at' => $session->cancelled_at,
            'status' => $session->status,
            'provider' => $session->provider,
            'provider_room' => $session->provider_room,
            'join_url' => ($canJoin && !$isTeacherOwner) ? $this->sessionJoinUrl($session, $user) : null,
            'start_url' => $canLaunch ? $this->sessionStartUrl($session, $user) : null,
            'recording_url' => $session->recording_url,
            'recording_resource_id' => $session->recording_resource_id,
            'can_join' => $canJoin,
            'module' => [
                'id' => $session->module->id,
                'name' => $session->module->name,
                'teacher' => [
                    'id' => $session->module->teacher->id,
                    'name' => $session->module->teacher->user->name,
                    'pseudo' => $session->module->teacher->user->pseudo,
                ],
            ],
            'chapter' => $session->chapter ? [
                'id' => $session->chapter->id,
                'name' => $session->chapter->name,
            ] : null,
        ];
    }
}
