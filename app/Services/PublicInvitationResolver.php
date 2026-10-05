<?php

namespace App\Services;

use App\Enums\PublicInvitationStatus;
use App\Models\Event;
use App\Models\EventSlugRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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

        // First, before any status page: an event that was never published has nothing to tell the public,
        // and "no longer available" / "cancelled" with its name would confirm a draft exists. A published
        // event that is later deleted, cancelled or paused keeps is_published, so it still gets its page.
        if (! $event->is_published) {
            abort(404);
        }

        if ($event->trashed()) {
            return $this->statusView($event, PublicInvitationStatus::Gone);
        }

        if ($event->isCancelled()) {
            return $this->statusView($event, PublicInvitationStatus::Cancelled);
        }

        if ($event->isInvitationPaused()) {
            return $this->statusView($event, PublicInvitationStatus::Unavailable);
        }

        // A private invitation event still renders here — it's just never listed
        // anywhere (Discover, /events.publiclyListed()), so the slug only reaches
        // anyone the host actually shared it with. Ticketed (and free-registration
        // public) events keep the is_public gate as defence in depth: a ticketed
        // row is always forced public on save, so this should never fire for one,
        // but a raw DB write bypassing the model hook must still 403 rather than
        // leak a commerce page — see the "private ticketed event 403s" tests.
        if (! $event->isInvitation() && ! $event->is_public) {
            abort(403);
        }

        if ($event->isLocked()) {
            return $this->statusView($event, PublicInvitationStatus::Ended);
        }

        if ($this->lacksInvitationLayout($event)) {
            return $this->statusView($event, PublicInvitationStatus::Unavailable);
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

        // Same order as resolveInvitationPage(): a never-published event is a 404 before any status.
        if (! $event->is_published) {
            abort(404);
        }

        if ($event->trashed()) {
            return PublicInvitationStatus::Gone;
        }

        if ($event->isCancelled()) {
            return PublicInvitationStatus::Cancelled;
        }

        if ($event->isInvitationPaused()) {
            return PublicInvitationStatus::Unavailable;
        }

        // Kept byte-for-byte in step with resolveInvitationPage() above — see its
        // comment on this same line for why a private invitation event now passes.
        if (! $event->isInvitation() && ! $event->is_public) {
            abort(403);
        }

        if ($event->isLocked()) {
            return PublicInvitationStatus::Ended;
        }

        if ($this->lacksInvitationLayout($event)) {
            return PublicInvitationStatus::Unavailable;
        }

        return $event;
    }

    /**
     * An invitation whose template cannot be loaded has nothing of the host's to show: no template id, or an
     * id whose row is gone. Rendering would borrow the catalogue's first template
     * (InvitationCustomizationService::resolvedTemplate()), so it reads as unavailable until the host picks
     * one. Ticketed events render one fixed page and need none. A retired (inactive) template still loads,
     * so it keeps rendering. The relation is loaded here and reused by the render, so this costs no extra query.
     */
    public function lacksInvitationLayout(Event $event): bool
    {
        if (! $event->isInvitation()) {
            return false;
        }

        if ($event->invitation_template_id !== null) {
            $event->loadMissing('invitationTemplate');

            if ($event->invitationTemplate !== null) {
                return false;
            }
        }

        // A live invitation with no loadable layout is something to know about, but not on every page view.
        if (Cache::add('invitation-layout-missing:'.$event->getKey(), true, 3600)) {
            Log::warning('Public invitation has no loadable template.', [
                'event_id' => $event->getKey(),
                'invitation_template_id' => $event->invitation_template_id,
            ]);
        }

        return true;
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

        // Same order as resolveInvitationPage(): a never-published event is a 404 before any status.
        if (! $event->is_published) {
            abort(404);
        }

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

        // Unlike resolveInvitationPage()/resolveForTickets()/etc., every caller of
        // this method already narrowed to isInvitation() above, so there is no
        // ticketed/commerce page left to defend — a private invitation event's
        // open-RSVP page is just as reachable-by-slug-only as its main page now is.

        if ($event->isLocked()) {
            return ['event' => $event, 'status' => PublicInvitationStatus::Ended];
        }

        // The invitation page says "unavailable" for a missing layout; the form on its own must agree.
        if ($this->lacksInvitationLayout($event)) {
            return ['event' => $event, 'status' => PublicInvitationStatus::Unavailable];
        }

        return ['event' => $event, 'status' => null];
    }

    /**
     * Status for a token RSVP (or any already-loaded event), ignoring is_public — a
     * personal link is the host's own invite, so a private event still opens.
     * Personal links still honour cancelled / paused / deleted / ended, and an
     * unpublished event reads as Unavailable (same as GroupRsvpResolver), so a link
     * copied off a draft's guest list can't show the invitation before it's paid for.
     */
    public function statusForLoadedEvent(Event $event): ?PublicInvitationStatus
    {
        $status = $event->publicInvitationStatus();

        if ($status === null && ! $event->is_published) {
            return PublicInvitationStatus::Unavailable;
        }

        return $status;
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
