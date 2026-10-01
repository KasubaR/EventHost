<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AudioReport;
use App\Models\Event;
use App\Services\InvitationAudioTakedown;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AudioReportController extends Controller
{
    public function index(): View
    {
        return view('admin.audio-reports.index', [
            'open' => AudioReport::query()->with('event')->where('status', AudioReport::OPEN)->orderBy('created_at')->get(),
            'handled' => AudioReport::query()->with(['event', 'handledBy'])->where('status', '!=', AudioReport::OPEN)->latest('handled_at')->limit(50)->get(),
        ]);
    }

    public function remove(AudioReport $audioReport, InvitationAudioTakedown $takedown): RedirectResponse
    {
        if ($audioReport->event !== null) {
            $takedown->remove($audioReport->event);
        }

        $this->close($audioReport, AudioReport::REMOVED);

        return back()->with('status', 'Music removed from the invitation and the report closed.');
    }

    public function dismiss(AudioReport $audioReport): RedirectResponse
    {
        $this->close($audioReport, AudioReport::DISMISSED);

        return back()->with('status', 'Report dismissed. The music was left in place.');
    }

    /**
     * The "remove music" button on the admin event page, with no report attached. Any open reports
     * for the event are closed with it, so the queue does not keep a complaint that is now moot.
     */
    public function removeFromEvent(Event $event, InvitationAudioTakedown $takedown): RedirectResponse
    {
        $removed = $takedown->remove($event);

        AudioReport::query()
            ->where('event_id', $event->id)
            ->where('status', AudioReport::OPEN)
            ->get()
            ->each(fn (AudioReport $r) => $this->close($r, AudioReport::REMOVED));

        return back()->with('status', $removed ? 'Background music removed.' : 'This event has no background music.');
    }

    private function close(AudioReport $report, string $status): void
    {
        $report->forceFill([
            'status' => $status,
            'handled_by_admin_id' => auth('admin')->id(),
            'handled_at' => now(),
        ])->save();
    }
}
