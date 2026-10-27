# Marking payouts as made (full or partial), with an optional private proof

Status: **ticket payouts built.** Contribution payouts (the twin) are still planned.

## What was there

An admin with `ticketing.payouts.manage` could already record a payout on `admin/ticketing/revenue/{event}`
(`TicketPayoutService::recordPayout()`): amount up to the pending balance (so a part payment already worked),
date, optional note. It writes a `ticket_payouts` row and a negative `payout` ledger entry under the event row lock.

## Decisions taken

1. **Proof of payment is optional and private.** An image (jpg, png, webp) or PDF up to 5 MB, stored on the
   `local` (private) disk under `payout-proofs/{event}`, never under `public/`. Served only through two
   authorised routes (below). It is never emailed.
2. The host is emailed on every payout (full or part), gated by a new `email_payout_updates` preference (default on).
3. **No reversals.** A wrong payout is not undone in the app; the ledger stays append-only. If one is ever needed it is
   a deliberate, separate piece of work.
4. Contribution payouts: not decided yet. Left out of this build.

## Built

- **Form** (`admin/ticketing/revenue/{event}`): "Pay in full" (the amount is the balance read under the lock, so no
  stray ngwee) or "Part payment" (amount required, at most the balance), date, note, optional proof. The flash says
  "Paid in full" or "Part payment recorded. K… is still pending". `payment_type` missing with an amount posted counts
  as a part payment, so the older form shape keeps working.
- **Service**: `recordPayout(..., ?UploadedFile $proof = null, bool $settleInFull = false)`. The file is stored first and
  deleted again if the payout is rejected, so a failed payout leaves nothing behind.
- **Status**: `TicketRevenueLedgerService::payoutStateFor()` returns `none | partial | paid`, derived, never stored.
  Shown on the admin payouts heading and the host's Payouts page.
- **Host page** (`/public-events/{event}/tickets/payouts`): status, pending amount, and when to expect payment:
  `Event::expectedPayoutDate()` = `agreed_payout_on`, else 5 business days after the event (terms section 12). It is the
  date payment is initiated, not when it arrives, and the page says so.
- **Email**: `TicketPayoutRecordedNotification` via `CommunicationService::notifyHostPayoutRecorded()`, keyed on the
  payout id, sent after the payout commits; a failure is reported, never allowed to look like a failed payout.
- **Proof routes**: `admin.ticketing.revenue.payouts.proof` (`ticketing.view`) and
  `public-events.tickets.payouts.proof` (the event's owner and co-hosts, `update` policy). Both check the payout
  belongs to the event in the URL, send `nosniff` and `no-store`.
- Migration `2026_10_28_100000`: `ticket_payouts.proof_path`, `proof_original_name`.
- Tests: `AdminPayoutMarkingTest`.

## Still to do

- Contribution payouts (`ContributionPayoutService`, `admin/contributions/revenue`): same full/part form, status, email
  and optional proof. Not started.
- Payout holds and reserves with a stated reason and next review date (terms section 12). Its own plan.
- Proof files are not cleaned up when an event is purged; `invitation:prune-orphaned-files` does not know about them.
  Decide whether purge should delete `payout-proofs/{event}` (the payout rows themselves survive a purge).
