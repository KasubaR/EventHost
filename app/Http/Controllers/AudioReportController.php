<?php

namespace App\Http\Controllers;

use App\Models\AudioReport;
use App\Models\Event;
use App\Notifications\AudioReportNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;

/**
 * Public, no-login way to report copyrighted music on an invitation. Acting quickly on these is the
 * platform's protection, so it is deliberately easy to reach and cannot be used to probe events:
 * an event with no music answers 404 exactly like one that does not exist.
 */
class AudioReportController extends Controller
{
    public function show(Event $event): View
    {
        abort_unless($this->audioPath($event) !== null || session('reported'), 404);

        return view('audio-report', ['event' => $event]);
    }

    public function store(Request $request, Event $event): RedirectResponse
    {
        $path = $this->audioPath($event);
        abort_unless($path !== null, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:150'],
            'rights_holder' => ['nullable', 'string', 'max:150'],
            'details' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $report = AudioReport::query()->create([
            'event_id' => $event->id,
            'event_name' => $event->name,
            'audio_path' => $path,
            'reporter_name' => $data['name'],
            'reporter_email' => $data['email'],
            'rights_holder' => $data['rights_holder'] ?? null,
            'details' => $data['details'],
        ]);

        // The row is the record; a mail failure must never lose or fail the report.
        try {
            Notification::route('mail', config('mail.support_address'))
                ->notify(new AudioReportNotification($report));
        } catch (\Throwable $e) {
            Log::error('audio_report.notify_failed', ['report_id' => $report->id, 'exception' => $e->getMessage()]);
        }

        return redirect()->route('audio-report.show', $event)->with('reported', true);
    }

    private function audioPath(Event $event): ?string
    {
        if ($event->trashed()) {
            return null;
        }

        $path = $event->invitation_customization['effects']['audio_track'] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }
}
