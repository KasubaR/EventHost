<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TicketingStatus;
use App\Exceptions\TicketingActivationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApproveTicketingRequest;
use App\Http\Requests\Admin\RejectTicketingRequest;
use App\Models\Admin;
use App\Models\Event;
use App\Services\TicketingActivationService;
use App\Support\AdminActivity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TicketingController extends Controller
{
    public function index(Request $request): View
    {
        $status = (string) $request->query('status', TicketingStatus::PendingReview->value);
        $allowed = collect(TicketingStatus::cases())
            ->reject(fn (TicketingStatus $case) => $case === TicketingStatus::NotApplicable)
            ->map(fn (TicketingStatus $case) => $case->value);

        if ($status !== 'all' && ! $allowed->contains($status)) {
            $status = TicketingStatus::PendingReview->value;
        }

        $events = Event::query()
            ->ticketed()
            ->with(['user:id,name,email', 'ticketTypes'])
            ->withCount('ticketTypes')
            ->when($status !== 'all', fn ($q) => $q->where('ticketing_status', $status))
            ->orderByDesc('ticketing_submitted_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.ticketing.index', [
            'events' => $events,
            'status' => $status,
        ]);
    }

    public function show(Event $event): View
    {
        abort_unless($event->isTicketed(), 404);

        $event->load(['user:id,name,email,phone,status', 'ticketTypes', 'ticketingReviewer:id,name,email']);

        return view('admin.ticketing.show', [
            'adminEvent' => $event,
        ]);
    }

    public function approve(
        ApproveTicketingRequest $request,
        Event $event,
        TicketingActivationService $activation
    ): RedirectResponse {
        abort_unless($event->isTicketed(), 404);

        $admin = auth('admin')->user();
        if (! $admin instanceof Admin) {
            abort(403);
        }

        try {
            $activation->approve($event, $admin, $request->validated('agreed_payout_on'));
        } catch (TicketingActivationException $e) {
            return redirect()->back()->withErrors(['ticketing' => $e->getMessage()]);
        }

        AdminActivity::log('Admin approved ticket sales', [
            'event_id' => $event->id,
        ]);

        return redirect()
            ->route('admin.ticketing.show', $event)
            ->with('status', 'ticketing-approved');
    }

    public function reject(
        RejectTicketingRequest $request,
        Event $event,
        TicketingActivationService $activation
    ): RedirectResponse {
        abort_unless($event->isTicketed(), 404);

        $admin = auth('admin')->user();
        if (! $admin instanceof Admin) {
            abort(403);
        }

        try {
            $activation->reject($event, $admin, $request->validated('ticketing_rejection_note'));
        } catch (TicketingActivationException $e) {
            return redirect()->back()->withErrors(['ticketing' => $e->getMessage()]);
        }

        AdminActivity::log('Admin declined ticket sales', [
            'event_id' => $event->id,
        ]);

        return redirect()
            ->route('admin.ticketing.show', $event)
            ->with('status', 'ticketing-rejected');
    }
}
