<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\StagedMedia;
use App\Services\InvitationCustomizationService;
use App\Support\InvitationLayoutVariant;
use App\Support\InvitationMediaStager;
use App\Support\InvitationPalettes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EventPreviewController extends Controller
{
    /**
     * Host-only view of the event's real, current invitation design —
     * regardless of is_published/is_public. Those flags gate the public
     * /e/{slug} route; this route is gated on ownership instead, so a host
     * can review a draft before publishing, or a private event that never
     * gets a public link at all.
     */
    public function show(Request $request, Event $event, InvitationCustomizationService $customizationService): View|RedirectResponse
    {
        $this->authorize('view', $event);

        // Ticketed events have one fixed public page — there is no invitation
        // layout to preview, so send the host back to the edit form.
        if ($event->isTicketed()) {
            return redirect()->route('events.edit', $event);
        }

        // The preview link lives on both the edit page and the (read-only) show
        // page, so "back" has to return wherever the host actually came from —
        // otherwise a host who never opened the edit form lands there anyway.
        $back = $request->query('from') === 'show'
            ? ['route' => route('events.show', $event), 'label' => 'Back to event']
            : ['route' => route('events.edit', $event), 'label' => 'Back to edit'];

        if ($event->invitation_template_id === null) {
            return redirect()->route('events.choose-template', $event)
                ->with('status', 'pick-layout-to-preview');
        }

        $rsvpOpen = $event->isRsvpOpen();
        // Ticketed events already redirected above, so this is always an
        // invitation event — private ones now get the same open-RSVP preview
        // as public ones. See PublicEventController::show()/PublicInvitationResolver.
        $rsvpPublicAvailable = $rsvpOpen;
        $invitation = $customizationService->merge($event);
        $invitation = $this->overlayPendingUploads($event, $request, $invitation);

        // ?palette= recolours this render only — nothing is saved. Open to every
        // tier on purpose: previewing is the upsell, applying stays Pro+ in
        // UpdateInvitationDesignRequest::validatePalette().
        $previewPalette = $this->previewPalette($request, $event, $invitation);
        if ($previewPalette !== null) {
            $invitation['theme']['primary'] = $previewPalette['primary'];
            $invitation['theme']['accent'] = $previewPalette['accent'];
            $invitation['theme']['background'] = $previewPalette['background'];
        }

        // Deliberately does not touch invitation_views_count — that counter is
        // real guest traffic, and the host reviewing their own draft is not a view.
        return view('events.preview', compact('event', 'rsvpOpen', 'rsvpPublicAvailable', 'invitation', 'back', 'previewPalette'));
    }

    /**
     * Lets a host see images/audio they've picked on the edit form but not yet saved —
     * without this, "upload on pick" stages the file immediately but Preview kept showing
     * the last-saved design, which reads as "my upload didn't work". Only ever affects this
     * render; nothing here is persisted. See InvitationMediaStager::overlayPending().
     *
     * @param  array<string, mixed>  $invitation
     * @return array<string, mixed>
     */
    private function overlayPendingUploads(Event $event, Request $request, array $invitation): array
    {
        $userId = $request->user()?->id;
        if ($userId === null) {
            return $invitation;
        }

        $staged = StagedMedia::query()
            ->ownedBy($event->id, $userId)
            ->where('created_at', '>=', now()->subMinutes(max(1, (int) config('invitations.staged_media_ttl_minutes', 1440))))
            ->get();

        if ($staged->isEmpty()) {
            return $invitation;
        }

        $overlay = InvitationMediaStager::overlayPending(
            $invitation['media'],
            $invitation['effects']['audio_track'] ?? null,
            $invitation['layout_variant'] ?? '',
            $staged
        );

        $invitation['media'] = $overlay['media'];
        $invitation['effects']['audio_track'] = $overlay['audio_track'];

        // cover_image lives on the Event model itself, not in $invitation — mutating it
        // in-memory here (never saved) is the same non-persisted-preview-attribute pattern
        // Event::getCoverImageUrlAttribute() already uses for template preview samples.
        if ($overlay['cover'] !== null) {
            $event->cover_image = $overlay['cover'];
        }

        return $invitation;
    }

    /**
     * The requested palette, or null when it doesn't exist, the layout ignores
     * theme colours, or it belongs to the other light/dark set than this
     * template — the same rules the design form's save path applies. An
     * unusable key falls back to the saved colours rather than erroring.
     *
     * @param  array<string, mixed>  $invitation
     * @return array{label: string, mode: string, primary: string, accent: string, background: string}|null
     */
    private function previewPalette(Request $request, Event $event, array $invitation): ?array
    {
        $key = $request->query('palette');
        if (! is_string($key) || $key === '') {
            return null;
        }

        $template = $event->invitationTemplate;
        $palette = InvitationPalettes::resolve($key, $template?->default_theme);
        if ($palette === null) {
            return null;
        }

        if (($invitation['layout_variant'] ?? null) === InvitationLayoutVariant::BEAUTY_FOR_ASHES) {
            return null;
        }

        if ($key === InvitationPalettes::TEMPLATE_DEFAULT_KEY && $template !== null) {
            $palette['label'] = $template->name;
        }

        $templateMode = InvitationPalettes::modeForBackground(
            (string) ($template?->default_theme['background'] ?? '#ffffff')
        );

        return $palette['mode'] === $templateMode ? $palette : null;
    }
}
