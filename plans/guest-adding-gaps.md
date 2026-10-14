# Adding guests: remaining gaps

**Status: Phases 1 to 4 are BUILT** (tests: `GuestExportAndPhoneTest`, `GuestNamesAndEmailTypoTest`, `GuestPrintableAndLandlineTest`); Phase 5 (docs) was done alongside each phase. **Phase 4 changed from the plan:** no Noto font was bundled. DejaVu already draws Latin, Greek, Cyrillic, Armenian, Georgian, Hebrew and Arabic letters, so Noto Sans would have added almost nothing; the real gap is CJK, Thai, Indic and emoji, which need a ~10 MB font each. Those now print as `?` (`PrintableText`) and the guest list flags the name. Two notes from building Phase 2: international numbers display as `+447700900123` (no country table to space them), and `GuestPhone::display()` is also used by the CSV, the PDF and the API's additive `phone_display`.

Scope: the "Guest Management / Adding Guests" edge-case list.

- Guest has no email, no phone, or both missing
- Two guests have the same name, email or phone
- Guest has an extremely long name, or uses non-English characters
- Guest has multiple phone numbers, or a badly formatted one

Follows the guest contact rules already built (`8d08439`: `GuestPhone`, `GuestCreator`, duplicate checks under the event
lock, import problem report, 191-character limit, `Guest::cleanName()`). That work covers every case on the list at the
point of **entry**; this plan covers what is left around it. Code read: `GuestPhone`, `GuestCreator`, `Guest`
(`cleanName`, `matchingPhone`, `scopeSearch`), `StoreGuestRequest`, `UpdateGuestRequest`, `StoreOpenRsvpRequest`,
`EventGuestsImport`, both guest CSV exports, `export-pdf.blade.php`, the pass PDF/PNG services, `CommunicationService`
and `GuestBulkActionController` (WhatsApp paths). Nothing has been run; "by reading" below means read from the code.

## What is already handled

| Case | Today |
|---|---|
| No email / no phone / both missing | Allowed; `guest_unreachable` flash, "No contact details" on the list, bulk sends skip and report. |
| Same email | Unique per event (case-insensitive), re-checked under the event lock. |
| Same phone | Compared by `GuestPhone::key()` on every add path, under the lock. |
| Same name | Allowed; `guest_same_name` flash; QR downloads named by id. |
| Long name | 191-character limit in the form, API and import. |
| Non-English | Whitespace collapsed, NFC-normalised, `dir="auto"` / `<bdi>` on the list and pass card. |
| Several phone numbers | Refused ("Enter one phone number only."). |
| Badly formatted phone | Refused with a specific message; a legacy phone stays saveable when only the name changes. |

## Gaps

Severity: **H** = harms the host's machine or corrupts what they send on, **M** = wrong or lossy outcome, **L** = polish.

| # | Sev | Gap |
|---|---|---|
| P1 | **H** | **Spreadsheet formula injection in the guest export.** Guests type their own name and message on the open RSVP form. `GuestController::export` and `Api\V1\GuestController::export` write them straight into the CSV, so `=HYPERLINK(...)`, `+cmd`, `-2+3` or `@SUM(...)` becomes a live formula when the host opens the file in Excel. The ticket and contribution exports carry buyer-typed names and phones too (by reading). |
| P2 | M | **Non-English names are garbled in Excel.** The CSV is UTF-8 with no byte-order mark, so Excel reads it as ANSI and shows accents and non-Latin scripts as mojibake. The host then sends that file to a caterer. |
| P3 | M | **A shared phone number is a hard refusal.** A household often shares one number, and the duplicate rule has no override. The host has to leave the phone blank, which loses the WhatsApp invitation. |
| P4 | M | **Non-Latin names don't print.** The guest-list PDF, the pass PDF and the pass PNG use DejaVu Sans, which cannot draw CJK, Arabic or emoji, so those names come out as blank boxes. Listed as "not covered" in `CLAUDE.md`. |
| P5 | M | **Phone search misses other spellings.** `Guest::scopeSearch` is a `LIKE` on the stored text. Searching `0971234567` does not find a guest stored as `+260 97 123 4567`, though the duplicate check treats them as the same number. |
| P6 | M | **A typo'd email is saved.** `email:rfc` checks syntax only, so `john@gmail`, `john@gmial.com` and `john@.com` variants that pass are stored and every email to them fails silently. |
| P7 | L | **Phones are stored as typed.** The list, exports and API show a mix of `0971…`, `+260 97 …`, `971…` for the same kind of number. |
| P8 | L | **A landline is accepted but WhatsApp can't deliver to it** (Zambian `2x` prefix). The host sees the invitation as sent. |
| P9 | L | **Invisible characters survive `cleanName()`.** Zero-width spaces and bidi overrides pass the whitespace collapse, so a name of only those is "non-empty" yet looks blank, and a bidi override can reorder text around it in the list. |
| P10 | L | **International duplicates can slip through.** `+44 (0) 7700 900123` keeps the `0` in its key and no longer matches `+447700900123`. |
| P11 | L | **The phone duplicate check loads every phone for the event** on each add (`matchingPhone` gets the whole list and compares in PHP). Fine for a few hundred guests; an import of thousands does it per row. |

## Decisions needed

| # | Question | Recommendation |
|---|---|---|
| Q1 | A shared phone number (P3): keep it a hard rule, or let the host allow it? | **Allow with an explicit tick** ("This is a different person who shares this number"). The first guest on the number stays the one the duplicate rule protects against *accidental* repeats; an override is logged on the add. WhatsApp sends to a shared number go to both, which is what a household wants. The open RSVP and group link keep the hard rule (a stranger can't claim someone's number). |
| Q2 | A formula-looking value in an export (P1): escape it or refuse it on entry? | **Escape on export only.** Prefix `'` to any cell that starts with `=`, `+`, `-`, `@`, tab or carriage return. A guest named "-Mwila" or "+1 Banda" is legitimate, so refusing on entry would turn real people away. |
| Q3 | Non-Latin glyphs in PDF/PNG (P4): bundle a wider font, or fall back? | **Bundle Noto Sans** for Latin Extended and Arabic (small, one file each) and fall back to a transliterated or "…" form for scripts still not covered. CJK needs a ~10 MB font; ship it only if hosts ask. A pass with a missing glyph should never print a box: say so on the guest list instead. |
| Q4 | A suspected email typo (P6): block, or warn? | **Warn, never block.** A "Did you mean john@gmail.com?" flash on add for the common domains (gmail, yahoo, outlook, hotmail, icloud) at edit distance 1–2. Save as typed. |
| Q5 | Store phones normalised (P7)? | **No, normalise on display only.** Rewriting stored values changes what a host sees they typed and complicates the legacy rows the rules already tolerate; format at output (`GuestPhone::display()`). |

## Phases

### Phase 1: make the export safe and readable (P1, P2)
1. One helper `App\Support\SpreadsheetCell::safe(mixed)` that returns strings starting with `=`, `+`, `-`, `@`, tab or CR with a leading `'`, and leaves numbers and dates alone.
2. Use it on every cell of both guest exports, the ticket-buyer exports and the contribution export; the **import** template is left alone.
3. Write a UTF-8 byte-order mark first on each CSV so Excel reads the encoding.
4. Tests: a name `=1+1`, `+cmd`, `-2`, `@x`, a tab-led value and a message are escaped; ordinary names (including `O'Brien` and `Chanda-Banda`) are untouched; BOM present; a name with `é` and `Ж` round-trips through `str_getcsv`.

### Phase 2: shared numbers, search and display (P3, P5, P7, P10, P11)
1. `GuestPhone::key()` drops a `(0)` trunk digit after a country code (P10).
2. `Guest::scopeSearch` also matches on the key: when the term has 5+ digits, search guests whose phone key contains the term's key (or the last 9 digits). Keep the plain `LIKE` for names and emails.
3. `GuestPhone::display()` formats a stored phone for the list, exports and API (`097 123 4567`, `+260 97 123 4567`, `+44 7700 900123`); the stored value is unchanged (Q5).
4. `matchingPhone()` narrows in SQL first (a `LIKE` on the last 7 digits) and compares keys only on those rows (P11).
5. Shared-number override (Q1): `allow_shared_phone` on the host add/edit form and the API; `GuestCreator` skips the phone-duplicate refusal when ticked, flashes `guest_shared_phone`, and the guest edit page shows a "shares a number with …" note. Not offered on the open RSVP or group link.
6. Tests: search by each spelling; display formats; override on and off; an override is refused on the public paths; the duplicate check is still exact.

### Phase 3: names and email (P6, P9)
1. `Guest::cleanName()` removes zero-width characters (U+200B to U+200D, U+2060, U+FEFF) and bidi controls (U+202A to U+202E, U+2066 to U+2069); a name that is empty afterwards is "required" again.
2. `App\Support\EmailTypo::suggest()` for the common-domain case (Q4); the add and edit forms flash `email_typo` with the suggestion and a one-click "Use this" link; the API adds an additive `email_suggestion`.
3. Tests: an all-zero-width name is refused; a bidi override is removed; `john@gmial.com`, `john@gmail` and `john@hotmal.com` suggest the right domain; a real unusual domain is left alone.

### Phase 4: fonts for non-Latin names (P4, P8)
1. Add Noto Sans (regular and bold) beside DejaVu; the guest-list PDF and both pass renderers pick a font per name: DejaVu for Latin, Noto for Latin-extended, Arabic and Cyrillic, and fall back to DejaVu with the unsupported characters replaced by `?` rather than a box.
2. The guest list shows a "this name may not print correctly" note for a name with characters no bundled font covers.
3. Landline numbers (P8): `GuestPhone::whatsAppDigits()` returns null for a Zambian landline (prefix `2`), so WhatsApp paths treat the guest like one with no WhatsApp number, and the guest list tags the number "landline: no WhatsApp".
4. Tests: render a PDF and a PNG for Latin, Cyrillic, Arabic and CJK names without exceptions and with no box glyph for the supported ones; a landline guest is skipped by `prepare_whatsapp_share` and reported.

### Phase 5: docs
Update the "Adding guests" section of `CLAUDE.md`: export escaping and BOM, the shared-number override and who can use it, search and display, the typo hint, the font coverage and landline behaviour.

## Out of scope

- Fuzzy "this looks like the same person" matching on names (names may repeat by design).
- A CJK font (revisit on request, Q3).
- Email deliverability checks (DNS or mailbox verification).
- Changing the 191-character limit.
