# Guest groups gaps

What happens to groups when one is missing, deleted with guests in it, duplicated, empty, very large, moved between, or reached
from another event. Found by reading the code; nothing has been reproduced yet, so Phase 0 proves each gap with a test before
anything is fixed. Companion to `plans/guest-deletion-gaps.md` (same shape: one service, a record, then the guest-facing page).

## Where things stand

- `guest_groups` has a unique `(event_id, name)` index; `guests.guest_group_id` is `nullOnDelete`. A group may also carry a shared RSVP link
  and seat pool (`rsvp_token`, `seat_limit`, `rsvp_link_closed_at`, plan `plans/group-rsvp-links.md`)
- Four paths change or remove a group, none shared: `GuestGroupController::destroy`, `Api\V1\GuestGroupController::destroy`,
  `GuestGroupLinkController::destroy` (turn the link off) and the API's `seat_limit: null`. Creating and renaming have web, API and import twins
- Names are only trimmed. Requests allow 255 characters; the column is 191 on MySQL (`Schema::defaultStringLength(191)`)
- `RsvpSubmissionService` holds a link joiner for review only while `$hasSeatPool && $guest->group_link_joined_at !== null`

## Phase 0: tests first (BUILT for Phases 0 and 1; the rest arrive with their phases)

`GuestGroupEdgeCasesTest`, one test per gap below, written to fail today where the gap is real.

- delete a group with a pending link joiner, then have that guest raise their seats: assert it is still reviewed
- turn the link off, same check
- delete a group: assert history rows for its members and the flash counts
- GET the old `/g/{token}` of a deleted group
- two simultaneous creates of the same name (second must be a validation error, not a 500)
- names: double space, zero-width character, 192 to 255 characters, whitespace-only and invisible-only
- 2,000 guests in one group: group delete, bulk move, groups page query count
- another event's group on every route; 403 vs 404 for a group the user does not own

## Phase 1: one group-delete path with honest consequences (BUILT)

- New `App\Services\GuestGroupDeleter` (twin of `GuestDeleter`): locks the event row (the lock group sign-ups take, so a sign-up cannot hit the
  foreign key mid-delete), counts members, link joiners and pending requests, deletes, and returns those counts. Web and API both call it. The link-off paths
  (`disableLink`, API `seat_limit: null`) go through it too
- **Held rule (decision 1).** Change the hold to `$guest->group_link_joined_at !== null`, dropping `$hasSeatPool`. The flag already means "signed up
  through a group link, held for the host", a hand move already clears it, and then deleting the group or turning the link off can no longer free someone from review
- **History.** Each member gets a `guest_edits` row (`group: [old name, null]`, plus `link_closed` / `group_deleted` flag) in one multi-row insert, so a
  group with thousands of members is one query, not thousands
- **Confirm text** from the same counts: "N guests are in this group, M signed up through its link and K are waiting for you. Their link stops working.
  Guests stay on the list". The flash after repeats the numbers

**As built.** `App\Services\GuestGroupDeleter` (`delete()`, `turnOffLink()`) is called by the web and API group deletes, the web "turn off link" and the API's
`seat_limit: null`. It locks the event row, then the group row (a second delete is a quiet no-op, another event's group is ignored), counts members, link joiners
and pending requests, writes the history in chunks of 500 (`guest_edits`: `group: [name, null]` for a delete, `link_closed: true` for joiners; a link-off writes
rows for joiners only), then deletes the group or calls `disableLink()`. It returns `{name, members, joiners, pending}`. The held rule in
`RsvpSubmissionService` is now `$guest->group_link_joined_at !== null` alone, and `Event::hasRsvpApprovalQueue()` is true while any joiner exists, so the
"awaiting approval" filter survives the group. `GuestGroup::removalWarning()` / `linkOffWarning()` feed the two confirm dialogs (counts come from
`withCount` on the groups page, no extra queries), and `partials/removed-note` shows the result. `GuestEdit::lines()` reads the new flag. Tests:
`GuestGroupEdgeCasesTest` (20, including a 1,200-guest delete in under 30 queries). The API's DELETE still returns 204.

## Phase 2: a closed link says so (BUILT)

- `group_link_removals` (migration `2026_10_15_110000`; `event_id`, SHA-256 of the token, `reason` = `group_deleted` | `link_off`, `removed_at`) and
  `App\Models\GroupLinkRemoval`, written by `GuestGroupDeleter` just before the group or link goes (`recordFor()`; a group with no link writes nothing). No
  group name is stored, so the page can never name one. Cascades with the event
- The guest-link 404 handler in `bootstrap/app.php` looks `group-rsvp.show` / `group-rsvp.store` tokens up here once the link failed to match, and renders
  `rsvp/group-link-closed.blade.php` with **410**: names the event, says a request already sent is still with the host, shows the host number. A POST gets the same
  page and creates nobody. An unknown token keeps `link-not-found` (404). A link the host only *closed* (`rsvp_link_closed_at`) is a different state and
  unchanged. **Changed from the plan:** there is no API for group links, so no `link_closed` code was added
- Turning the link off and on again mints a new token, so the old address stays closed. Privacy §3 and §7 describe the fingerprint (the hash only)
- `GroupRsvpLinkTest` now expects 410 (not 404) for the old address after "turn off link". Tests: 10 more in `GuestGroupEdgeCasesTest`

## Phase 3: names (BUILT)

- `GuestGroup::cleanName()` reuses `Guest::cleanName()` (trim, collapse whitespace, Unicode NFC, invisible characters removed) and is used by the web form, API,
  rename and the import, so all four compare equal. `GuestGroup::NAME_MAX = Guest::NAME_MAX` (191), used by every request, the import and the form `maxlength`
- Empty after cleaning is "required" again, so an invisible-only name is refused
- **Duplicates under the lock.** `App\Services\GuestGroupNamer` (`create()`, `rename()`; named for what it does rather than "Creator") re-checks the name under the event
  row lock and turns a final unique-index violation into the same validation error ("The name has already been taken."), web and API. Both controllers use it
- **One meaning of "same name":** `GuestGroup::nameKey()` (cleaned, lower-cased), used by the namer, the new `App\Rules\GuestGroupNameAvailable` rule (which replaces
  `Rule::unique`, and ignores the group being renamed) and the import, so SQLite and MySQL agree and a legacy name with odd spacing still blocks its clean twin. A
  rename that only changes case is allowed. Accents the MySQL collation treats as equal are still caught by the unique index and reported the same way
- **Import:** `EventGuestsImport::groupId()` cleans the label, finds an existing group by `nameKey()` and otherwise `createOrFirst()`s it, so "Family ", "family" and a name with a
  zero-width character are one group, and a group-name collision is never mistaken for a duplicate guest
- Tests: 10 more in `GuestGroupEdgeCasesTest` (cleaning, case and spacing, composed vs decomposed accents, invisible-only, 191 vs 192 and 255, rename, API, a simulated
  simultaneous create, import)

## Phase 4: scale (BUILT)

- **Groups page.** `GuestGroupController::index` pages 50 at a time (`{{ $groups->links() }}`) and calls `GuestGroup::preloadSeatTotals()`, one grouped query for the page's pooled
  groups; `seatsTaken()` / `seatsPending()` answer from that (a private property, never an attribute, so it can't be saved). 30 pooled groups load in under 60 queries in total
- **Cap (decision 2): `GuestGroup::MAX_PER_EVENT = 200`.** `GuestGroupNamer::create()` refuses the 201st, under the lock, with "An event can have up to 200 groups. Remove one you no
  longer need first." (web and API). Renaming and deleting are never blocked, and an event already over the cap keeps its groups. The **import refuses the row** (reported with its
  spreadsheet row number, not imported) when its new group would pass the cap, rather than importing the guest ungrouped; a name the event already has, or one an earlier row of the
  batch reserved, is always fine (`groupRefusal()` / `knownGroupKeys`)
- **Bulk move.** `GuestUpdater::moveManyToGroup()` reads the pool once and counts it down in memory while walking the guests, then writes in chunks of 500: one `update` and one
  `guest_edits` multi-row insert per chunk (`writeMoves()`), clearing `group_link_joined_at` as before. A 256-guest move is under 40 queries. The refusal sentence is shared with the
  single move (`refusalMessage()`); counts and the "Move them from their own page" reason are unchanged
- **Bulk input (decision 3): `GuestBulkActionRequest::MAX_GUESTS = 500`** ("Select up to 500 guests at a time."). The per-id `exists` rule is gone; `after()` checks that every id belongs to
  the event with one count, so a bad id is a single `guest_ids` error. Web and API share it
- **Members link:** the member count on the groups page opens the guest list filtered to that group
- Tests: 10 more in `GuestGroupEdgeCasesTest` (cap on web, API, rename/delete at and over the cap, import at the cap, paging, seat totals and query count, members link, bulk move
  fill and skip, bulk move history, the 500 ceiling, the other-event check)

## Phase 5: access

- The group routes check `group.event_id === event.id` (404) **before** authorising, in the web update request and controller, so a group the user does not own
  and an id that does not exist look the same. API already does this; add tests for every route

## Phase 6: docs

- A "Guest groups" section in CLAUDE.md (or fold into the guest-adding section), Privacy §3 for the link tombstone, API additions listed as additive

## Decisions (made)

1. **Held rule:** link joiners stay under review for good (built in Phase 1)
2. **Cap on groups per event:** 200 (Phase 4)
3. **`guest_ids` ceiling:** 500 per bulk request (Phase 4)

## Not covered

- Undo for a deleted group (the history rows say who was in it, but there is no restore)
- Nested groups, a group-member page, moving all members of one group to another in one click
- Lawyer review of the Privacy wording

## Order and size

Phase 0 and 1 first: Phase 1 is the real defect (people escaping review) and is one service plus one rule change. Phases 2 and 3 are independent and
small. Phase 4 is the largest and only matters for big events. Phase 5 is a few lines.
