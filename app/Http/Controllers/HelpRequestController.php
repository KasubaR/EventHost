<?php

namespace App\Http\Controllers;

use App\Enums\HelpRequestKind;
use App\Http\Requests\StoreHelpRequestRequest;
use App\Models\AdminHelpRequest;
use App\Services\HelpRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The client's side of "ask our team for help". Plan: plans/admin-create-events.md (Step 0).
 */
class HelpRequestController extends Controller
{
    public function show(Request $request): View
    {
        AdminHelpRequest::expireStale();

        $user = $request->user();

        $current = AdminHelpRequest::query()
            ->where('user_id', $user->id)
            ->current()
            ->with(['event:id,name', 'assignedAdmin:id,name'])
            ->latest()
            ->first();

        $past = AdminHelpRequest::query()
            ->where('user_id', $user->id)
            ->when($current, fn ($q) => $q->whereKeyNot($current->id))
            ->with('event:id,name')
            ->latest()
            ->limit(10)
            ->get();

        $preselectedEvent = $user->events()->whereKey($request->integer('event'))->first();

        return view('help.show', [
            'current' => $current,
            'past' => $past,
            'events' => $user->events()->orderByDesc('created_at')->get(['id', 'name']),
            'kinds' => HelpRequestKind::cases(),
            'selectedEventId' => $preselectedEvent?->id,
            'selectedKind' => $preselectedEvent ? HelpRequestKind::EditEvent->value : $request->query('kind', HelpRequestKind::CreateEvent->value),
        ]);
    }

    public function store(StoreHelpRequestRequest $request, HelpRequestService $service): RedirectResponse
    {
        $created = $service->create($request->user(), $request->helpRequestData());

        if ($created === null) {
            return redirect()->route('help-request.show')
                ->with('error', 'You already have a request open. Cancel it first if you want to send a new one.');
        }

        return redirect()->route('help-request.show')->with('status', 'help-request-sent');
    }

    public function destroy(Request $request, AdminHelpRequest $helpRequest, HelpRequestService $service): RedirectResponse
    {
        abort_unless($helpRequest->user_id === $request->user()->id, 404);

        $service->cancel($helpRequest);

        return redirect()->route('help-request.show')->with('status', 'help-request-cancelled');
    }
}
