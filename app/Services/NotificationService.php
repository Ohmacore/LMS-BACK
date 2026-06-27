<?php

namespace App\Services;

use App\Models\LiveSession;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Collection;

class NotificationService
{
    public function __construct(private AccessService $accessService)
    {
    }

    public function notifyUser(
        User $user,
        string $type,
        string $title,
        ?string $body = null,
        array $data = [],
        ?string $actionUrl = null,
        $scheduledFor = null
    ): Notification {
        return Notification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'action_url' => $actionUrl,
            'scheduled_for' => $scheduledFor,
        ]);
    }

    public function notifyUsers(Collection $users, string $type, string $title, ?string $body = null, array $data = [], ?string $actionUrl = null, $scheduledFor = null): void
    {
        $users->each(function (User $user) use ($type, $title, $body, $data, $actionUrl, $scheduledFor) {
            $this->notifyUser($user, $type, $title, $body, $data, $actionUrl, $scheduledFor);
        });
    }

    public function notifyEligibleStudentsForLive(LiveSession $liveSession, string $type): void
    {
        $students = $this->accessService->eligibleStudentsForLiveSession($liveSession);
        $users = $students->pluck('user')->filter();

        [$title, $body] = $this->liveCopy($liveSession, $type);

        $this->notifyUsers(
            $users,
            $type,
            $title,
            $body,
            ['live_session_id' => $liveSession->id, 'module_id' => $liveSession->module_id],
            "/student/live/{$liveSession->id}",
            $type === 'live_scheduled' ? $liveSession->scheduled_at : null
        );
    }

    public function createDueLiveRemindersFor(User $user): void
    {
        if (!$user->student) {
            return;
        }

        $now = now();
        $windowEnd = now()->addMinutes(15);

        LiveSession::with('module', 'chapter')
            ->where('status', 'scheduled')
            ->whereBetween('scheduled_at', [$now, $windowEnd])
            ->get()
            ->filter(fn(LiveSession $session) => $this->accessService->canJoinLiveSession($session, $user))
            ->each(function (LiveSession $session) use ($user) {
                $exists = Notification::where('user_id', $user->id)
                    ->where('type', 'live_reminder')
                    ->where('action_url', "/student/live/{$session->id}")
                    ->exists();

                if ($exists) {
                    return;
                }

                $this->notifyUser(
                    $user,
                    'live_reminder',
                    'Live dans 15 minutes',
                    "{$session->title} commence bientot.",
                    ['live_session_id' => $session->id, 'module_id' => $session->module_id],
                    "/student/live/{$session->id}",
                    $session->scheduled_at
                );
            });
    }

    private function liveCopy(LiveSession $liveSession, string $type): array
    {
        return match ($type) {
            'live_started' => [
                'Live demarre',
                "{$liveSession->title} est en direct. Vous pouvez rejoindre la session.",
            ],
            'recording_available' => [
                'Enregistrement disponible',
                "L'enregistrement de {$liveSession->title} est disponible.",
            ],
            default => [
                'Live programme',
                "{$liveSession->title} est programme pour " . $liveSession->scheduled_at->format('d/m/Y H:i') . '.',
            ],
        };
    }
}
