<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGroupRsvpRequest;
use App\Services\CommunicationService;
use App\Services\GroupRsvpResolver;
use App\Services\GroupRsvpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * plans/group-rsvp-links.md — the public side of a group's shared RSVP link (/g/{token}).
 */
class GroupRsvpController extends Controller
{
    public function show(string $token, GroupRsvpResolver $resolver): View
    {
        $resolved = $resolver->resolve($token);

        return view('rsvp.group-show', [
            'event' => $resolved['event'],
            'group' => $resolved['group'],
            'state' => $resolved['state'],
            'maxSeats' => max(1, min($resolved['event']->allow_plus_one ? 2 : 1, $resolved['remaining'])),
        ]);
    }

    public function store(
        string $token,
        StoreGroupRsvpRequest $request,
        GroupRsvpResolver $resolver,
        GroupRsvpService $service,
        CommunicationService $communication,
    ): RedirectResponse {
        $resolved = $resolver->resolve($token);
        $validated = $request->validated();

        $result = $service->request(
            $resolved['group'],
            $resolved['event'],
            ['name' => $validated['name'], 'email' => $validated['email'], 'phone' => $validated['phone']],
            ['attendee_count' => (int) $validated['attendee_count'], 'message' => $validated['message'] ?? null],
        );

        // Held for host review, so this sends the host's "awaiting approval" notice and nothing to the guest.
        $communication->dispatchRsvpNotifications($resolved['event'], $result['guest'], $result['rsvp']);

        return redirect()->route('rsvp.token.thanks', ['token' => $result['guest']->invitation_token]);
    }
}
