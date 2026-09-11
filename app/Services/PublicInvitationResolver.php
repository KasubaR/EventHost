<?php

namespace App\Services;

use App\Enums\PublicInvitationStatus;
use App\Models\Event;
use App\Models\EventSlugRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PublicInvitationResolver
{
    /**
     * Resolve /e/{slug} for the main invitation (or ticket landing) page.
     */
    public function resolveInvitationPage(string $slug): Event|View|RedirectResponse
    {
        $lookup = $this->lookup($slug);

        if ($lookup instanceof RedirectResponse) {
            return $lookup;
        }

        if ($lookup === null) {
            abort(404);
        }

        $event = $lookup;

        if ($event->trashed()) {
            return $this->statusView($event, PublicInvitationStatus::Gone);
        }

        if ($event->isCancelled()) {
            return $this->statusView($event, PublicInvitationStatus::Cancelled);
        }

        if ($event->isInvitationPaused()) {
            return $this->statusView($event, PublicInvitationStatus::Unavailable);
        }

        if (! $event->is_published) {
            abort(404);
        }

        if (! $event->is_public) {
            abort(403);
        }

        if ($event->isLocked()) {
            return $this->statusView($event, PublicInvitationStatus::Ended);
        }

        return $event;
    }

    /**
     * JSON-friendly sibling of resolveInvitationPage() for GET /api/v1/events/{slug} — same gate
     * order and outcomes, just returns the PublicInvitationStatus enum instead of building a
     * Blade status view. resolveInvitationPage() itself is left untouched (byte-for-byte) rather
     * than refactored to share a private helper, matching this class's existing idiom of one
     * independent method per route/use-case (see resolveSibling/resolveForTickets/
     * resolveForContributions/resolveOpenRsvp below, each restating its own short gate chain).
     * abort(404)/abort(403) already render correct JSON for a request that expectsJson().
     */
    public function resolveInvitationPageForApi(string $slug): Event|PublicInvitationStatus|RedirectResponse
    {
        $lookup = $this->lookup($slug);

        if ($lookup instanceof RedirectResponse) {
            return $lookup;
        }

        if ($lookup === null) {
            abort(404);
        }

        $event = $lookup;

        if ($event->trashed()) {
            return PublicInvitationStatus::Gone;
        }

        if ($event->isCancelled()) {
            return PublicInvitationStatus::Cancelled;
        }

        if ($event->isInvitationPaused()) {
            return PublicInvitationStatus::Unavailable;
        }

        if (! $event->is_published) {
            abort(404);
        }

        if (! $event->is_public) {
            abort(403);
        }

        if ($event->isLocked()) {
            return PublicInvitationStatus::Ended;
        }

        return $event;
    }

    /**
     * Resolve slug routes that stay open after the event date (gallery, table upload).
     * Still blocks deleted / cancelled / paused / draft / private.
     */
    public function resolveSibling(string $slug): Event|RedirectResponse
    {
        $lookup = $this->lookup($slug);

        if ($lookup instanceof RedirectResponse) {
            return $lookup;
        }

        if ($lookup === null || ! $lookup->invitationIsGuestAccessible()) {
            abort(404);
        }

        if (! $lookup->is_public) {
            abort(403);
        }

        return $lookup;
    }

    /**
     * Resolve ticket picker / checkout — also refuses past events.
     */
    public function resolveForTickets(string $slug): Event|RedirectResponse
    {
        $lookup = $this->lookup($slug);

        if ($lookup instanceof RedirectResponse) {
            return $lookup;
        }

        if ($lookup === null || ! $lookup->invitationIsGuestAccessible()) {
            abort(404);
        }

        if (! $lookup->is_public) {
            abort(403);
        }

        if ($lookup->isLocked()) {
            abort(404);
        }

        abort_unless($lookup->ticketSalesAreApproved(), 404);

        return $lookup;
    }

    /**
     * Resolve the contribute / pay-an-installment pages — same posture as
     * resolveForTickets: refuses past events, private events, and events the
     * admin hasn't enabled for contributions.
     */
    public function resolveForContributions(string $slug): Event|RedirectResponse
    {
        $lookup = $this->lookup($slug);

        if ($lookup instanceof RedirectResponse) {
            return $lookup;
        }

        if ($lookup === null || ! $lookup->invitationIsGuestAccessible()) {
            abort(404);
        }

        if (! $lookup->is_public) {
            abort(403);
        }

        if ($lookup->isLocked()) {
            abort(404);
        }

        abort_unless($lookup->acceptsContributions(), 404);

        return $lookup;
    }

    /**
     * Resolve open RSVP page — returns status/closed views when the window is shut.
     *
     * @return array{event: Event, status: ?PublicInvitationStatus}|RedirectResponse
     */
    public function resolveOpenRsvp(string $slug): array|RedirectResponse
    {
        $lookup = $this->lookup($slug);

        if ($lookup instanceof RedirectResponse) {
            return $lookup;
        }

        if ($lookup === null) {
            abort(404);
        }

        $event = $lookup;

        if ($event->trashed()) {
            return ['event' => $event, 'status' => PublicInvitationStatus::Gone];
        }

        if ($event->isCancelled()) {
            return ['event' => $event, 'status' => PublicInvitationStatus::Cancelled];
        }

        if ($event->isInvitationPaused()) {
            return ['event' => $event, 'status' => PublicInvitationStatus::Unavailable];
        }

        if (! $event->is_published || ! $event->isInvitation()) {
            abort(404);
        }

        if (! $event->is_public) {
            abort(403);
        }

        if ($event->isLocked()) {
            return ['event' => $event, 'status' => PublicInvitationStatus::Ended];
        }

        return ['event' => $event, 'status' => null];
    }

    /**
     * Status for a token RSVP (or any already-loaded event), ignoring publish/public.
     * Personal links still honour cancelled / paused / deleted / ended.
     */
    public function statusForLoadedEvent(Event $event): ?PublicInvitationStatus
    {
        return $event->publicInvitationStatus();
    }

    public function statusView(Event $event, PublicInvitationStatus $status): View
    {
        return view('events.invitation-status', [
            'event' => $event,
            'status' => $status,
        ]);
    }

    /**
     * Find by current slug (including soft-deleted) or historical redirect.
     */
    public function lookup(string $slug): Event|RedirectResponse|null
    {
        $event = Event::withTrashed()->where('slug', $slug)->first();

        if ($event !== null) {
            return $event;
        }

        $redirect = EventSlugRedirect::query()->where('slug', $slug)->first();

        if ($redirect === null) {
            return null;
        }

        $target = Event::withTrashed()->find($redirect->event_id);

        if ($target === null || ! filled($target->slug)) {
            return null;
        }

        return redirect()->route('events.public', ['slug' => $target->slug], 301);
    }
}
