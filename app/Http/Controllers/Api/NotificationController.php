<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private NotificationService $notificationService)
    {
    }

    public function index(Request $request)
    {
        $this->notificationService->createDueLiveRemindersFor($request->user());

        $status = $request->query('status', 'current');
        $limit = min((int) $request->query('limit', 50), 100);

        $query = Notification::where('user_id', $request->user()->id);

        if ($status === 'archived') {
            $query->whereNotNull('read_at');
        } elseif ($status === 'all') {
            $query->orderByRaw('read_at IS NULL DESC');
        } else {
            $query->whereNull('read_at');
        }

        $notifications = $query
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => Notification::where('user_id', $request->user()->id)->unread()->count(),
            'archived_count' => Notification::where('user_id', $request->user()->id)->whereNotNull('read_at')->count(),
        ]);
    }

    public function unreadCount(Request $request)
    {
        $this->notificationService->createDueLiveRemindersFor($request->user());

        return response()->json([
            'unread_count' => Notification::where('user_id', $request->user()->id)->unread()->count(),
        ]);
    }

    public function markRead(Request $request, Notification $notification)
    {
        if ($notification->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $notification->update(['read_at' => $notification->read_at ?? now()]);

        return response()->json(['notification' => $notification->fresh()]);
    }

    public function destroy(Request $request, Notification $notification)
    {
        if ($notification->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $notification->delete();

        return response()->json(['message' => 'Notification deleted']);
    }

    public function markAllRead(Request $request)
    {
        Notification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Notifications marked as read']);
    }
}
