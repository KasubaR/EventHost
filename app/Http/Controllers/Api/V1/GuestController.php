<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RsvpStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreGuestApiRequest;
use App\Http\Requests\Api\V1\UpdateGuestApiRequest;
use App\Http\Resources\Api\V1\GuestGroupResource;
use App\Http\Resources\Api\V1\GuestResource;
use App\Http\Resources\Api\V1\TableResource;
use App\Models\Event;
use App\Models\Guest;
use App\Services\QrCodeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * JSON sibling of App\Http\Controllers\GuestController — no session, no redirect+flash.
 * Authorizes every mutation via GuestPolicy/EventPolicy directly (owner or accepted
 * Manager staff) instead of the narrower owner-only FormRequest checks the web
 * controller relies on — see the Slice C3 plan, design decision #1. No qr() action:
 * GuestResource.check_in_qr_url replaces it (decision #4). The web controller is
 * untouched by this class.
 */
class GuestController extends Controller
{
    /**
     * @param  Builder<Guest>|Relation<Guest, Event, *>  $query
     * @return Builder<Guest>|Relation<Guest, Event, *>
     */
    private function applyGuestFilters(Builder|Relation $query, Request $request): Builder|Relation
    {
        $query
            ->search($request->query('q'))
            ->forGuestGroupFilter($request->query('group'))
            ->forInvitationSentFilter($request->query('invitation_sent'))
            ->forPlusOneFilter($request->query('plus_one'))
            ->forCheckedInFilter($request->query('checked_in'));

        return match ((string) $request->query('response', 'all')) {
            'pending' => $query->whereDoesntHave('rsvp'),
            'responded' => $query->whereHas('rsvp'),
            RsvpStatus::Accepted->value => $query->whereHas('rsvp', fn ($q) => $q->where('status', RsvpStatus::Accepted)),
            RsvpStatus::Declined->value => $query->whereHas('rsvp', fn ($q) => $q->where('status', RsvpStatus::Declined)),
            RsvpStatus::Maybe->value => $query->whereHas('rsvp', fn ($q) => $q->where('status', RsvpStatus::Maybe)),
            default => $query,
        };
    }

    public function index(Request $request, Event $event): JsonResponse
    {
        $this->authorizeInvitation($event);
        $event->loadMissing('user');

        $guestsQuery = $this->applyGuestFilters(
            $event->guests()->with(['rsvp', 'group', 'eventTable']),
            $request
        )->orderBy('name');

        $paginated = $guestsQuery->paginate(30);

        $stats = [
            'total' => $event->guests()->count(),
            'pending' => $event->guests()->whereDoesntHave('rsvp')->count(),
            'accepted' => $event->guests()->whereHas('rsvp', fn ($q) => $q->where('status', RsvpStatus::Accepted))->count(),
            'declined' => $event->guests()->whereHas('rsvp', fn ($q) => $q->where('status', RsvpStatus::Declined))->count(),
        ];

        return response()->json([
            'guests' => [
                'data' => $paginated->getCollection()->map(fn (Guest $g) => new GuestResource($g, $event))->values(),
                'meta' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                ],
            ],
            'stats' => $stats,
            'groups' => GuestGroupResource::collection($event->guestGroups()->get()),
            'tables' => $event->tables()->orderBy('sort_order')->orderBy('label')->get()
                ->map(fn ($t) => new TableResource($t, $event))->values(),
        ]);
    }

    public function store(StoreGuestApiRequest $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);
        abort_unless($event->isInvitation(), 404);

        if ($event->hasReachedGuestCapacity()) {
            return response()->json([
                'error' => 'guest_capacity_reached',
                'limit' => $event->guestCapacity(),
            ], 422);
        }

        $validated = $request->validated();
        $markSent = $validated['mark_invitation_sent'] ?? false;

        $guest = Guest::query()->create([
            'event_id' => $event->id,
            'guest_group_id' => $validated['guest_group_id'] ?? null,
            'event_table_id' => $validated['event_table_id'] ?? null,
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'invitation_token' => Str::random(48),
            'plus_one_allowed' => $validated['plus_one_allowed'] ?? false,
            'invitation_sent' => $markSent,
            'invitation_sent_at' => $markSent ? now() : null,
        ]);

        $event->loadMissing('user');

        return response()->json(new GuestResource($guest, $event), 201);
    }

    public function update(UpdateGuestApiRequest $request, Event $event, Guest $guest): JsonResponse
    {
        $guest->loadMissing('event.user');
        $this->authorize('update', $guest);
        abort_unless($guest->event_id === $event->id, 404);
        abort_unless($event->isInvitation(), 404);

        $validated = $request->validated();

        $data = [
            'guest_group_id' => $validated['guest_group_id'] ?? null,
            'event_table_id' => $validated['event_table_id'] ?? null,
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'plus_one_allowed' => $validated['plus_one_allowed'] ?? false,
        ];

        if (($validated['mark_invitation_sent'] ?? false) === true) {
            $data['invitation_sent'] = true;
            $data['invitation_sent_at'] = now();
        }

        if (($validated['regenerate_invitation_token'] ?? false) === true) {
            $data['invitation_token'] = Str::random(48);
        }

        $guest->fill($data)->save();

        return response()->json(new GuestResource($guest->fresh(), $event))->setStatusCode(200);
    }

    public function destroy(Event $event, Guest $guest): JsonResponse
    {
        $guest->loadMissing('event');
        $this->authorize('delete', $guest);
        abort_unless($guest->event_id === $event->id, 404);
        abort_unless($event->isInvitation(), 404);

        $guest->delete();

        return response()->json(null, 204);
    }

    public function markInvitationSent(Event $event, Guest $guest): JsonResponse
    {
        $guest->loadMissing('event.user');
        $this->authorize('update', $guest);
        abort_unless($guest->event_id === $event->id, 404);
        abort_unless($event->isInvitation(), 404);

        $guest->forceFill([
            'invitation_sent' => true,
            'invitation_sent_at' => now(),
        ])->save();

        return response()->json(new GuestResource($guest->fresh(), $event))->setStatusCode(200);
    }

    public function export(Request $request, Event $event): StreamedResponse
    {
        $this->authorizeInvitation($event);

        $guestsQuery = $this->applyGuestFilters(
            $event->guests()->with(['rsvp', 'group', 'checkedInBy']),
            $request
        )->orderBy('name');

        $filename = 'guests-'.str($event->name)->slug().'.csv';

        return response()->streamDownload(function () use ($guestsQuery) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Name', 'Email', 'Phone', 'Group',
                'RSVP Status', 'Attendee Count', 'Message',
                'Meal Preference', 'Transportation Note', 'Song Request',
                'Invitation Sent', 'Invitation Sent At',
                'Checked In At', 'Checked In By',
            ]);

            $guestsQuery->chunk(200, function ($chunk) use ($handle) {
                foreach ($chunk as $guest) {
                    $rsvp = $guest->rsvp;
                    fputcsv($handle, [
                        $guest->name,
                        $guest->email ?? '',
                        $guest->phone ?? '',
                        $guest->group?->name ?? '',
                        $rsvp ? $rsvp->status->value : 'pending',
                        $rsvp && $rsvp->status->countsTowardGuestLimit() ? $rsvp->attendee_count : '',
                        $rsvp?->message ?? '',
                        $rsvp?->meal_preference ?? '',
                        $rsvp?->transportation_note ?? '',
                        $rsvp?->song_request ?? '',
                        $guest->invitation_sent ? 'Yes' : 'No',
                        $guest->invitation_sent_at?->format('Y-m-d H:i') ?? '',
                        $guest->checked_in_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '',
                        $guest->checkedInByLabel() ?? '',
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function exportPdf(Request $request, Event $event): Response
    {
        $this->authorizeInvitation($event);

        $filter = (string) $request->query('response', 'all');

        $guestsQuery = $this->applyGuestFilters(
            $event->guests()->with(['rsvp', 'group', 'checkedInBy']),
            $request
        )->orderBy('name');

        $guests = $guestsQuery->get();

        $filterLabels = [
            'pending' => 'Pending',
            'responded' => 'Responded',
            'accepted' => 'Accepted',
            'declined' => 'Declined',
            'maybe' => 'Maybe',
        ];
        $filterLabel = $filter !== 'all' ? ($filterLabels[$filter] ?? null) : null;

        $checkedInFilter = (string) $request->query('checked_in', '');
        $checkedInLabel = match ($checkedInFilter) {
            'yes' => 'Checked in',
            'no' => 'Not checked in',
            default => null,
        };

        $filename = 'guests-'.str($event->name)->slug().'.pdf';

        $pdf = Pdf::loadView('events.guests.export-pdf', compact('event', 'guests', 'filterLabel', 'checkedInLabel'))
            ->setPaper('a4', 'landscape');

        return $pdf->download($filename);
    }

    public function qrSheet(Event $event, QrCodeService $qrCodeService): Response|JsonResponse
    {
        $this->authorizeInvitation($event);

        if (! $event->ownerHasPremiumEventTools()) {
            return response()->json(['error' => 'premium_required', 'required_tier' => 'pro'], 403);
        }

        $guests = $event->guests()
            ->whereNotNull('invitation_token')
            ->with('eventTable')
            ->orderBy('name')
            ->get()
            ->map(fn (Guest $guest) => [
                'name' => $guest->name,
                'table_label' => $guest->tableLabel(),
                'qr_data_uri' => 'data:image/svg+xml;base64,'.base64_encode($qrCodeService->svg((string) $guest->checkInQrUrl(), 220)),
            ]);

        $pdf = Pdf::loadView('events.guests.qr-sheet', compact('event', 'guests'))
            ->setPaper('a4', 'portrait');

        return $pdf->download('guest-qr-codes-'.$event->slug.'.pdf');
    }

    private function authorizeInvitation(Event $event): void
    {
        $this->authorize('update', $event);

        abort_unless($event->isInvitation(), 404);
    }
}
