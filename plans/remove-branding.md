# Remove EventHost Branding (paid add-on)

Status: **built** (2026-09-11). See CLAUDE.md's "Remove Branding (paid add-on)" section for the
condensed reference; this file keeps the fuller rationale. The two open questions below were
resolved during the build: per-event (not per-account), and the Pro+ "Custom branding" / "White-label
invitations" bullets were left as-is rather than retired — worth a follow-up decision on whether to
still update them now that a concrete K250 add-on exists at every tier, not just Pro+.

## What this is

A K250 one-time add-on, available on **any** plan (Base, Pro, Pro+, Enterprise — no tier
requirement), that removes the "EventHost" bar from the top of an event's public-facing pages.
This directly answers the "Custom branding / white-label" gap flagged in the pricing ledger — but
narrower than that ledger entry's full promise. See "What this does *not* cover" below before
treating this as closing that item outright.

## What's actually being sold

Grepping every public view for the branding bar and the phrase "Get started free" turns up **one**
component, copy-pasted verbatim into four different files — there's no shared partial today:

| File | Page |
|---|---|
| [resources/views/events/public.blade.php](resources/views/events/public.blade.php) | The main public invitation (`/e/{slug}`) |
| [resources/views/events/tickets/landing.blade.php](resources/views/events/tickets/landing.blade.php) | Ticketed event public landing page |
| [resources/views/rsvp/token-show.blade.php](resources/views/rsvp/token-show.blade.php) | A guest's personal RSVP link |
| [resources/views/events/invitation-status.blade.php](resources/views/events/invitation-status.blade.php) | Paused / cancelled / ended status page |

Each copy is identical:

```blade
<div class="evt-host-bar">
    <a href="{{ url('/') }}" class="evt-host-bar-logo" target="_blank" rel="noopener noreferrer">
        <img src="{{ asset('images/logo/EventHost Logo_Icon.svg') }}" alt="{{ config('app.name') }}" width="22" height="22">
        <span>{{ config('app.name') }}</span>
    </a>
    <p class="evt-host-bar-tagline">Create beautiful event invitations &amp; track RSVPs in one place.</p>
    <a href="{{ url('/') }}" class="evt-host-bar-cta" target="_blank" rel="noopener noreferrer">
        Get started free <i class="fa-solid fa-arrow-right"></i>
    </a>
</div>
```

Styled by `.evt-host-bar*` in [public/css/events-public.css](public/css/events-public.css), loaded
by all four pages already. Host-only views (`events/preview.blade.php`,
`templates/preview.blade.php`) never show it, and neither do the gallery, table-upload,
contribute, or contribution-status pages — there's nothing to hide there.

## What this does *not* cover

"Custom branding" on the homepage's Pro+ card, and "white-label invitations," read as a bigger
promise than one bar: no EventHost mention anywhere (page `<title>`, meta tags, email footers,
possibly a custom domain). This plan is scoped to exactly what was asked — **remove the top bar** —
not a full white-label system. Recommend either:

- Shipping this now as its own thing, and leaving "Custom branding" / "White-label invitations" on
  the Pro+ card as a **separate**, still-unbuilt promise (the ledger keeps flagging it), or
- Retiring those two Pro+ bullets now that there's a concrete, purchasable feature to point at
  instead — a line like "Remove EventHost branding — K250 add-on" replacing both.

**Flagging, not deciding** — this changes what the homepage says, so it's worth confirming before
build.

## Open questions to confirm before building

1. **Per-event or per-account?** Assuming **per event** below — K250 removes the bar on *that*
   event's pages only, same unit every other paid thing in this app sells in (event credits,
   ticket commission, contributions). A host with three events wanting all three branding-free
   pays K250 × 3. If the intent was a one-time, account-wide unlock instead, the data model below
   changes (a flag on `User`, not `Event`).
2. **Does this retire the Pro+ "Custom branding" / "White-label invitations" bullets**, per above?
3. Is K250 the final price, and does it round-trip through the same Lenco mobile-money/bank-transfer
   checkout as everything else (assumed yes below)?

## Data model

One column: `events.branding_removed` (boolean, default false). No new table — this is a single
paid flag per event, not a recurring thing with its own lifecycle the way contributions or tickets
are.

## Payment plumbing — reuses the existing self-checkout pipeline, doesn't fork it

The app already has one Lenco-backed self-checkout flow
([app/Http/Controllers/PaymentController.php](app/Http/Controllers/PaymentController.php)) driven
by a `plan_key` on `Payment`. It already special-cases `plan_key === 'enterprise'` to skip the
normal "grant credits + raise tier from `config('billing.plans')`" behavior and pull its price from
a `CustomQuote` instead — proof this pipeline already tolerates a `plan_key` that doesn't behave
like a subscription tier. Branding removal is a **third** such case, following the same shape:

- New config: `config('billing.addons.remove_branding.amount')` = `250.00` (a new `addons` array
  in [config/billing.php](config/billing.php), sitting next to `plans` — not inside it, since this
  isn't a subscription tier and has no `credits`/`tier`/`guest_limit_default`).
- `PaymentController::initiate()` gets a branch for `plan_key === 'remove_branding'` (alongside the
  existing `enterprise` branch): validates the event belongs to the requesting user and doesn't
  already have `branding_removed = true`, reads the amount from the new config key, and stores
  `event_id` in `Payment.metadata` — exactly how `quote_id` is already stored there for Enterprise.
- `PaymentCompletionService::complete()` gets a third branch: for `plan_key === 'remove_branding'`,
  skip `credits->grant()` and the tier-raise entirely, and instead lock the referenced `Event` row
  and set `branding_removed = true`. Idempotent the same way the rest of that method already is
  (checked-and-set under `lockForUpdate()`, safe against a replayed webhook).
- `PaymentCompletionService::reverse()` gets the matching branch: a chargeback/failed-settlement on
  a branding-removal payment flips `branding_removed` back to `false`, mirroring how a reversed
  Enterprise payment cancels its quote.
- `App\Support\BillingPlan::labelForPlanKey()` / `tierForPlan()` get a `remove_branding` case so the
  admin payments list shows "Remove Branding" instead of the raw key, and nothing calls
  `tierForPlan()` for it in the first place (same reasoning as `enterprise`'s early return).

## Guest-facing bar → shared component

Before wiring the toggle, factor the four copy-pasted blocks into one partial —
`components/event-host-bar.blade.php` (or a Blade component) taking the event as a prop — so the
"skip rendering when paid for" check lives in **one** place instead of four, and can't be missed
on the next page that adds this bar. Something like:

```blade
@unless (($event ?? null)?->branding_removed)
    <div class="evt-host-bar"> ... </div>
@endunless
```

Each of the four views swaps its inline block for `@include('components.event-host-bar', ['event' => $event])` (or `<x-event-host-bar :event="$event" />`). `invitation-status.blade.php` needs checking for
whether `$event` is already in scope there (it renders off `PublicInvitationStatus`, may need the
prop passed explicitly from `PublicInvitationResolver::statusView()`).

## Where a host buys it

A "Remove EventHost branding" card on the event edit or show page (open to a **new** small,
event-scoped checkout view — reusing `billing/checkout.blade.php`'s tier-grid layout doesn't fit
well, since this is a single K250 line item, not a set of plans to compare). Once
`branding_removed` is true, that card shows "Branding removed ✓" instead of a buy button — never a
second purchase for the same event.

## Admin visibility

- A fact on the admin event show page (`admin/events/{event}`): "Branding removed: Yes/No" —
  read-only, matches how other event-state facts are shown there.
- Nothing new needed for `admin/payments` beyond the `BillingPlan::labelForPlanKey()` fix above —
  that list already renders any `Payment` row generically.

## Test coverage to add

- Checkout: buying `remove_branding` for an owned event sets `branding_removed` on completion, not
  on initiate; a second purchase attempt for an already-removed event is refused; buying it for an
  event you don't own is refused.
- Webhook reversal flips `branding_removed` back to `false`.
- The shared bar partial renders for a normal event and is absent once `branding_removed` is true,
  across at least the two most-visited surfaces (public invitation, RSVP token page).

## Phased build order

1. Migration + `Event::$fillable`/`$casts` for `branding_removed`.
2. Shared `event-host-bar` component, swapped into all four views (behavior-neutral on its own —
   nothing is `branding_removed` yet, so every page renders exactly as it does today).
3. Config + `PaymentController::initiate()` branch + `PaymentCompletionService::complete()`/`reverse()`
   branches + `BillingPlan` label/tier cases.
4. Event-scoped checkout view + the "Remove branding" card on the event edit/show page.
5. Admin fact on the event show page.
6. Tests.
