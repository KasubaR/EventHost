# Invitation page: long text and missing content

Scenarios: a very long event name, a very long venue name, no description, no location, and RSVP not available.
Findings are from reading the code (nothing was run). Status: **all phases built.**

## What the code does today

**Long text**
- The only `overflow-wrap` rules in `public/css` are in `guest-pass.css`. No invitation, RSVP or ticket-landing stylesheet has one, so a
  single long word or an unbroken string (a 255-character name is allowed: `StoreEventRequest` `max:255` on `name`, `venue`,
  `location_name`) pushes the page wider than the phone and the guest scrolls sideways. Spaced text wraps, but a 255-character
  heading at 38px (`clamp(26px, 5vw, 38px)`) fills several screens.
- Places that print the name or venue with no wrapping or size rule: `.evt-public-title` and `.evt-detail-list li`
  (generic layout), `mm-hero-name` / `mm-hero-location`, the wedding heroes and detail tiles (`*-detail-value`), `bfa-detail-value`,
  `tile-value` and `hero-school` (botanical), the noir RSVP line, and the venue `li` on `rsvp/open-show`, `rsvp/group-show` and
  `tickets/purchase`.
- Already safe: the pass card (`overflow-wrap: anywhere`, one-page PDF guard), the public event cards (ellipsis / line-clamp).
- `InvitationNames::split()` turns any name containing " and ", " for " or "&" into two couple names around an "&". A long name like
  "Annual Leadership and Innovation Summit" prints as two names with an ampersand. Template choice is not limited by event type, so
  this is reachable.
- `EventIcsDocument` never folds lines. RFC 5545 caps a line at 75 octets, and Outlook / Apple Calendar can truncate or reject longer
  `SUMMARY`, `LOCATION` and `DESCRIPTION` lines (the description is allowed 2000 characters).
- The host gets no hint that a name is too long for a layout.

**No description**
- Generic and botanical layouts print nothing, correctly. Modern Minimal, Midnight Gold, Dusty Blue, base wedding, Noir and Ivory
  substitute **a fixed wedding sentence** ("We joyfully invite you to celebrate the union of two souls...") when the description is
  empty. That is copy the host never wrote, and wrong for a birthday, church or memorial event that uses one of those layouts.
- Beauty for Ashes falls back to "You are warmly invited"; link-preview text falls back to "Name · date" (fine).
- No host notice says the invitation has no description.

**No location**
- `venue` and `location_name` are both optional. The detail tiles and hero lines simply disappear, so a guest cannot tell "to be
  announced" from "forgot to fill in". The ICS `LOCATION:` line is written empty. A pin with no name shows a Location tile with only map
  links.
- A venue typed but no pin gives no map link at all (the map links need coordinates).
- No host notice before publishing.

**RSVP not available**
- There is no RSVP on/off switch. The RSVP section is forced visible on every invitation event whose layout has one
  (`rsvpSectionRequired`), so "no RSVP" really means one of: the layout has no RSVP section, RSVP is closed (deadline, event day,
  cancelled, paused), or a private event opened without a personal link.
- When closed or without a link the section is a banner with no `id="rsvp"`. The buttons that jump there (`#rsvp` in the event_invite
  hero and Noir's "Reserve Your Seat") still show and scroll nowhere. Modern Minimal also repeats `id="rsvp"` (its section and the
  included form wrapper) so the id is duplicated.
- The closed banner and the "Your host will send you a personal RSVP link" banner do not show the host's contact number, unlike the RSVP
  pages (`rsvp/partials/host-contact`).
- A layout with no RSVP section gives guests no way to answer and the host no warning.

## Decisions I would make (change any of these)

1. **Keep the 255 limit in the database and the rules** (shrinking it would fail saves of existing events), but make every layout
   survive it, and warn the host softly from 70 characters (name) / 80 (venue).
2. **Wrapping is global, size is per length.** One shared rule for guest text (`overflow-wrap: anywhere`, `min-width: 0` on flex and
   grid children); the title shrinks in two steps by length class set on the server, instead of per-layout font tweaks.
3. **A missing description shows nothing, not invented copy.** The fixed wedding sentence goes; a layout that looks bare without it
   gets a short neutral line chosen by event type, written once in one place.
4. **A missing location reads "Venue to be announced"** in the details section and hero line, and the ICS omits `LOCATION` entirely
   instead of writing it empty.
5. **Closed or unavailable RSVP removes its own buttons.** The CTA is rendered only while the form exists; otherwise the banner
   (with the host number) is all there is.
6. **No new RSVP switch.** The scenario is handled by making the not-available states clear, not by adding a setting.

## Phases

### Phase 1: Long text cannot break a guest page (CSS) — built
- A shared block in `events-invitation.css` (loaded by every invitation layout) plus the RSVP / status stylesheets: wrapping for the
  title, venue and location text selectors listed above, and `min-width: 0` on the flex / grid parents so a long value cannot stretch
  its column.
- A server-side length class (`evt-name--long` over 40 characters, `--xlong` over 80) from one helper, applied to the hero and detail
  titles, which steps the font size down; no per-layout JS.
- Fallback-before-modern rule and the existing `GuestCssFallbacksTest` still apply to anything added.
- Built as: `overflow-wrap` + `min-width: 0` on `.evt-invitation` (inherited by every layout), `.rsvp-page`, `.evt-status-page`, `.evt-public-inner`, `.tkc-page`, `.tev-wrap`; `App\Support\InvitationTextLength::nameClass()` adds `evt-name--long` (over 40) / `--xlong` (over 80) to the renderer root and the headings step down with `zoom`. Checked in Chrome at 360px on all 11 layouts with a 120-character name and a 240-character unbroken name and venue: page width 345px everywhere (before: 2040-2893px on the layouts sampled). Remaining overflow reported is the section-nav strip (scrolls by design) and clipped decoration.
- Check in a real browser at 360px with a 255-character name and venue, spaced and unspaced, on every layout (the computed-style parity
  harness from the compatibility work can be reused): no horizontal scroll, text readable, nothing clipped by `overflow: hidden`.

### Phase 2: Name handling and host guidance — built
- `InvitationNames::split()` only splits when the whole name is short (60 characters or fewer) and each side is at most 4 words (`MAX_LENGTH`, `MAX_WORDS_PER_SIDE`); a long
  name stays on one line.
- Character counters with a soft warning on the name and venue fields (create and edit forms), pointing to the preview. Warns, never
  blocks.
- `InvitationDesignNotices` / event page notice when the name is over 80 characters: "A long name can look crowded on some layouts.
  Check the preview."

### Phase 3: No description — built
- Built as `App\Support\InvitationDescriptionFallback::for($event, $weddingWording)`: the host's text if written; for a **wedding** the layout's own wedding sentence (kept, passed in by each of the five wedding description sections); every other type a short neutral line by type. Generic and botanical layouts still print nothing. Still wedding-specific and untouched: Noir's formal/body defaults, the "Two hearts, one story" captions and Midnight Gold's "We're getting married!" tag (design copy, not the description).
- Host notice: "Your invitation has no description" on the edit and event pages, dismissible by adding one.
- No change to the link-preview text.

### Phase 4: No location — built
- Details tiles and hero lines print "Venue to be announced" when venue, location name and pin are all empty; a pin with no name keeps
  its map links. Same wording on the open RSVP page, group page and pass card via one helper.
- Offer a "Search on Google Maps" link from the venue text when there is a name but no pin.
- `EventIcsDocument`: skip `LOCATION` when empty, and **fold lines at 75 octets** (UTF-8 safe, so a multi-byte character is never split)
  for `SUMMARY`, `LOCATION` and `DESCRIPTION`.
- Host notice before publishing and on the event page when no venue is set.
- Built as `App\Support\EventPlace` (`isUnknown()`, `searchUrl()`, the two wording constants). "To be announced" tile in every layout's details section (event_invite's hero shows it in place of `-`), "Venue to be announced" on the open and group RSVP pages, a `searchOnly` mode in `partials/map-link` for the venue tile. Deliberately left alone: the other heroes' decorative venue lines, the pass card / PDF / PNG / API `venue` (null stays null, so the Android contract and the cache fingerprint do not change), and the ticketed pages (venue is required there). The ICS `LOCATION` is omitted when empty and lines fold at 75 octets (`EventIcsDocument::fold()`). Checked in Chrome-less HTTP renders of all 11 layouts: the tile appears with no place, the search link with a venue and no pin.

### Phase 5: RSVP not available — built
- The jump buttons (`event_invite`, Noir, Modern Minimal) render only while the form is on the page; the banners get an `id` so any
  remaining `#rsvp` link lands on them. Remove the duplicated `id="rsvp"` in Modern Minimal.
- The closed and "personal link" banners include `rsvp.partials.host-contact` (renders nothing without a number).
- Host notice when the chosen layout has no RSVP section ("Guests cannot RSVP on this layout") on the edit and event pages, via
  `InvitationTemplateNotices`.
- The section nav keeps linking to a banner-only RSVP section (it still rendered markup).

### Phase 6: Other places a long name appears — built
- Verify and fix: email subject lines, the WhatsApp invitation text, the PNG pass (GD text does not wrap by itself), host dashboard
  cards, guest list heading and admin tables. The pass card and PDF are already guarded.
- Truncate in the one helper per channel (`Str::limit` with an ellipsis) rather than in each view.

### Phase 7: Tests and docs — built
- `InvitationContentLimitsTest`: a 255-character unbroken name and venue render on every layout without error; the shared wrap
  selectors exist in the committed CSS; the length class is applied; the split guard; no-description and no-venue wording; the ICS has
  no line over 75 octets and no empty `LOCATION`; closed RSVP renders no jump button and shows the host number.
- A Node check is not needed: nothing here is script behaviour.
- A new "Long text and missing content" section in `CLAUDE.md`, and the real-device checklist in `docs/deployment.md` §3d gets a
  255-character event added.
- Run the full suite once after the last phase (standing instruction).

## Not covered
- Truncating a long name with an ellipsis on the invitation itself (guests should read the whole name).
- Right-to-left names and scripts, emoji-only names, and very tall names in the pass PDF beyond what its one-page guard already does.
- An event-level RSVP on/off switch.
- Online or "location TBA" as a first-class event setting (the venue field stays free text).

Phase 5 as built: `App\Support\InvitationRsvpState::formShown()` says whether the page really has a form; the jump buttons in event_invite, Noir's hero and Modern Minimal render only then. The generic RSVP section drops its own `id="rsvp"` when the layout's wrapper already has one (`rsvpWrapperHasId`: Modern Minimal, event_invite, Dusty Blue, Midnight Gold, Noir), and its banners now carry the id otherwise, so `#rsvp` is unique and always lands somewhere. The closed, ended and "personal link" banners (generic and botanical) include `rsvp.partials.host-contact`. `InvitationTemplateNotices` warns when a layout has no RSVP section (none of the 11 seeded layouts is in that state today). Checked by rendering all 11 layouts closed and open: one `id="rsvp"`, no jump button when closed, host number shown on every closed banner.

Phase 6 as built: `App\Support\ShortText` (`subject()` 60 characters, `whatsapp()` 100, whitespace collapsed to one line) is used by all 15 notification subjects that carry an event name and by the three WhatsApp template senders in `CommunicationService` (name and venue variables). The full name stays in the message body. Already fine, no change: the PNG pass (`GuestPassImageService::wrap()` breaks long words and ends in an ellipsis at three lines) and the PDF pass (one-page guard). Host screens: `dashboard-shell.css` now wraps `.dash-main` text (`break-word`, so table columns do not collapse) and the page headings and event cards (`anywhere`). Not changed: email body layout for an unbroken 255-character word (clients wrap it differently) and the admin tables beyond the `.dash-main` rule.
