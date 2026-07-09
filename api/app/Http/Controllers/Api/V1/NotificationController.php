<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Notifications
 *
 * APIs for notification management - view, mark as read, and manage preferences.
 */
class NotificationController extends Controller
{
    /**
     * Get all notifications for the authenticated user.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $notifications = $request->user()
            ->notifications()
            ->when($request->has('unread') || $request->has('unread_only'), function ($query) use ($request) {
                if ($request->boolean('unread') || $request->boolean('unread_only')) {
                    $query->whereNull('read_at');
                } else {
                    $query->whereNotNull('read_at');
                }
            })
            ->when($request->has('type'), function ($query) use ($request) {
                $query->where('data->type', $request->input('type'));
            })
            ->latest()
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => [
                'notifications' => NotificationResource::collection($notifications->items()),
                'unread_count' => $request->user()->unreadNotifications()->count(),
            ],
            'meta' => [
                'current_page' => $notifications->currentPage(), 'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(), 'total' => $notifications->total(),
            ],
        ]);
    }

    /**
     * Get unread notifications count.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = $request->user()->unreadNotifications()->count();

        return response()->json([
            'success' => true,
            'data' => [
                'unread_count' => $count,
            ],
        ]);
    }

    /**
     * Get a specific notification.
     */
    public function show(Request $request, string $id): NotificationResource|JsonResponse
    {
        $notification = $request->user()
            ->notifications()
            ->where('id', $id)
            ->first();

        if (! $notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found.',
            ], 404);
        }

        return new NotificationResource($notification);
    }

    /**
     * Mark a notification as read.
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()
            ->notifications()
            ->where('id', $id)
            ->first();

        if (! $notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found.',
            ], 404);
        }

        $notification->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read.',
            'data' => new NotificationResource($notification),
        ]);
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read.',
        ]);
    }

    /**
     * Delete a notification.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()
            ->notifications()
            ->where('id', $id)
            ->first();

        if (! $notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found.',
            ], 404);
        }

        $notification->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notification deleted.',
        ]);
    }

    /**
     * Delete all notifications for the user.
     */
    public function destroyAll(Request $request): JsonResponse
    {
        $request->user()->notifications()->delete();

        return response()->json([
            'success' => true,
            'message' => 'All notifications deleted.',
        ]);
    }
}
