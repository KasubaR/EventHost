<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationLogController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status');
        $channel = $request->query('channel');

        $logs = NotificationLog::query()
            ->with(['event:id,name', 'guest:id,name'])
            ->when(is_string($status) && $status !== '', function ($q) use ($status): void {
                $q->where('status', $status);
            })
            ->when(is_string($channel) && $channel !== '', function ($q) use ($channel): void {
                $q->where('channel', $channel);
            })
            ->orderByDesc('created_at')
            ->paginate(40)
            ->withQueryString();

        $counts = [
            'sent' => NotificationLog::query()->where('status', NotificationLog::STATUS_SENT)->count(),
            'failed' => NotificationLog::query()->where('status', NotificationLog::STATUS_FAILED)->count(),
            'pending' => NotificationLog::query()->where('status', NotificationLog::STATUS_PENDING)->count(),
        ];

        return view('admin.notifications.index', [
            'logs' => $logs,
            'counts' => $counts,
            'filterStatus' => is_string($status) ? $status : '',
            'filterChannel' => is_string($channel) ? $channel : '',
        ]);
    }
}
