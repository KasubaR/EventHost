# Feature Plan: Easier location input on the event form

Status: **Shipped** (2026-08-15). Both phases done, matching the plan closely. 17 new tests
(`tests/Unit/Support/GoogleMapsLinkParserTest.php`, `tests/Feature/MapLinkResolveTest.php`), full
suite green aside from 4 pre-existing failures in an unrelated in-progress Pro+ palette feature
already sitting uncommitted in the working tree before this work started.

One addition beyond the plan: `GoogleMapsLinkParser::isAllowedHop()` is checked before *every* hop
of the redirect chain, not just validated once up front — §3b said "hostname allowlisted" but the
implementation detail (re-checking each `Location` header's host before following it, not just the
user's original URL) is what actually closes the SSRF hole, so it's called out explicitly here.

Two independent, additive features for the "Location pin" block in
[form-fields.blade.php:127-155](../resources/views/events/partials/form-fields.blade.php), both driven from
[events-form.js](../public/js/events-form.js). Either can ship alone; recommended order is §3 then §4.

1. **Paste a Google Maps link** into the existing search box instead of retyping an address.
2. **"Use my current location"** button that drops the pin at the host's GPS position.

Both reuse the Leaflet map, the `#latitude`/`#longitude` inputs, and the existing
`placeMarker()`/`syncCoords()` helpers already in `events-form.js` — no new fields, no new columns.
`latitude`/`longitude` already post as ordinary form fields validated by `StoreEventRequest` /
`UpdateEventRequest` ([StoreEventRequest.php:49](../app/Http/Requests/StoreEventRequest.php)), so neither
feature touches persistence at all.

---

## 1. Why the current box falls short

The search input sends whatever is typed to Nominatim as a plain-text address query
([events-form.js:120-157](../public/js/events-form.js)). Two common real-world inputs fail there:

| What the user has | What happens today |
|---|---|
| A Google Maps link copied from the app/browser (`.../@lat,lng,17z...`, `?q=lat,lng`, or a `maps.app.goo.gl/...` short link) | Sent verbatim to Nominatim, which doesn't parse URLs → "no result" |
| Standing at the venue on their phone, no address handy | Has to type something, hope it geocodes correctly |

---

## 2. Decisions taken

| Question | Decision |
|---|---|
| New input field for the pasted link? | **No** — reuse `#evt-map-search`. Detect on submit whether the value looks like a URL/coordinate pair vs. a plain address, branch accordingly. One box, no new UI to learn. |
| Where do full-link coordinates get parsed? | **Client-side, no request.** A full Google Maps URL already contains `@lat,lng,zoom` or `!3d{lat}!4d{lng}`; regex extraction is instant and needs no server round-trip. |
| Where do short-link (`maps.app.goo.gl`, `goo.gl/maps`) coordinates get parsed? | **Server-side.** Short links carry no coordinates in the text itself — they only appear after Google's redirect resolves — and that redirect can't be followed from the browser (Google sends no CORS headers). A tiny backend endpoint follows it. |
| Is the resolver endpoint safe to expose? | Restrict it to a **fixed hostname allowlist** (`maps.app.goo.gl`, `goo.gl`) checked before any outbound request, and read only the redirect `Location` header — never fetch or return the destination page body. This is the SSRF guard; without it the endpoint is "fetch any URL the server can reach," which it must never become. |
| How does "use my location" get a place name, not just coordinates? | Reverse-geocode through the **same Nominatim host** already used for forward search (`/reverse` instead of `/search`), mirroring how forward search fills `location_name` today. |
| Geolocation permission UX | Standard browser permission prompt via `navigator.geolocation`. No new consent copy needed — the browser's own prompt is the consent. |

---

## 3. Phase 1 — paste a Google Maps link

All changes inside `initMap()` / `doSearch()` in `events-form.js`; no blade changes needed for this phase.

### 3a. Client-side parsing (full links + raw coordinates) — no backend involved

Before falling through to the existing Nominatim call, check the trimmed search value against, in order:

1. **`!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)`** — the actual pin position on a Google "place" link. Preferred over
   `@` when present, because `@lat,lng` is just the map's *viewport center*, which can differ from the pin
   once the user has panned before copying the link.
2. **`@(-?\d+\.\d+),(-?\d+\.\d+)`** — viewport center, the common case for links copied from the address
   bar.
3. **`[?&]q=(-?\d+\.\d+),(-?\d+\.\d+)`** — the older `?q=lat,lng` share-link format.
4. **Raw pasted coordinates**, e.g. `-15.4067, 28.2871` — free to support once the regex branch exists, and
   covers users who copy just the numbers instead of a link. Pattern:
   `^(-?\d{1,3}(?:\.\d+)?),\s*(-?\d{1,3}(?:\.\d+)?)$`.

On a match: `flyTo`/`placeMarker`/`syncCoords` exactly as the existing Nominatim success path does — no new
code path for "what happens once we have coordinates," just a new way of getting them.

**Bonus, same pass:** for `/maps/place/{name}/@...` links, the `{name}` segment is the URL-encoded place
name. Decode it (`+` → space, `decodeURIComponent`) and use it to prefill `location_name` when that field is
empty, the same courtesy the Nominatim branch already does with `display_name`.

### 3b. Server-side resolution (short links only)

**Detection:** hostname is exactly `maps.app.goo.gl` or `goo.gl` (path starting `/maps/` for the latter,
since bare `goo.gl` also serves unrelated short links).

**Route** (near the events group, not scoped to `{event}` — the create page has no event id yet and this
call carries no event data):

```
POST /maps/resolve-link   → MapLinkController@resolve
```

Placed inside the existing `auth`+`verified` group in [routes/web.php](../routes/web.php), e.g. just above
`Route::resource('events', ...)` at `:181`, with its own limiter:

```php
RateLimiter::for('map-link-resolve', function (Request $request): Limit {
    return Limit::perMinute(20)->by($request->user()->id);
});
```

(new entry in [AppServiceProvider.php](../app/Providers/AppServiceProvider.php), alongside
`invitation-media` at `:48`)

**`MapLinkController@resolve`** (new, thin — no service class needed for one HTTP call):

1. Validate `url` is present and its host is in the allowlist. Reject anything else with 422 — this is the
   SSRF boundary, not a nicety.
2. `Http::withOptions(['allow_redirects' => false])->timeout(5)->get($url)` and read the `Location` header
   directly — **never** follow into the destination and fetch its body. Loop up to 5 hops (short links
   sometimes chain), re-checking nothing but the `Location` header each time.
3. Once the final `Location` is a `google.com/maps/...` URL, run the **same regex extraction as §3a** (share
   the pattern/helper — do not fork it) server-side to pull `lat`/`lng` out of the resolved URL.
4. Return `{ latitude, longitude }` on success, `422 { message }` on anything else (non-Google destination,
   too many hops, timeout, no coordinates in the resolved URL).

**JS side:** when the short-link pattern matches, `POST` the pasted value to `/maps/resolve-link` (CSRF
token already available — the page has one for the main form), then treat the response exactly like a §3a
match. Button shows the same spinner state `doSearch()` already uses for the Nominatim call.

---

## 4. Phase 2 — "use my current location"

**Markup:** one button in `form-fields.blade.php`, next to `#evt-map-search-btn` or directly under the map
hint — e.g. `id="evt-map-locate-btn"` with a crosshairs icon, reusing `.evt-btn-outline`.

**JS**, same file:

```js
locateBtn.addEventListener('click', () => {
    if (!navigator.geolocation) { /* hide button entirely on unsupported browsers, checked at init */ }
    setLocating(true);
    navigator.geolocation.getCurrentPosition(
        (pos) => {
            const { latitude, longitude } = pos.coords;
            map.flyTo([latitude, longitude], 15);
            placeMarker(latitude, longitude);
            syncCoords(latitude, longitude);
            reverseGeocode(latitude, longitude); // fills location_name if empty, best-effort
            setLocating(false);
        },
        (err) => { showLocateError(err); setLocating(false); },
        { enableHighAccuracy: true, timeout: 10000 }
    );
});
```

`reverseGeocode()` calls `https://nominatim.openstreetmap.org/reverse?lat=..&lon=..&format=json` — same
host, same no-key setup as forward search, and only fills `location_name` when it's currently empty, mirroring
the existing forward-search courtesy at `events-form.js:143-146`.

**Error states** (permission denied / unavailable / timeout): reuse the `--no-result` shake/flash treatment
already on the search input (`evt-map-search--no-result`), applied to the button instead, rather than
inventing a new visual language for "this didn't work."

**Feature-detect at init**, not at click time: if `navigator.geolocation` is undefined, don't render the
button in the first place (or hide it) — cleaner than a button that always fails on old browsers.

---

## 5. Testing

- **JS (manual, no test harness exists for `events-form.js` today — matches its current untested state)**:
  - Paste a full `.../@lat,lng,17z` link → pin lands, coordinates match.
  - Paste a `.../place/Some+Venue/@lat,lng,...!3d{lat2}!4d{lng2}` link → pin uses the `!3d/!4d` pair, not
    `@`; `location_name` prefills to "Some Venue" when empty.
  - Paste a `maps.app.goo.gl/...` short link → resolves via the endpoint, pin lands.
  - Paste raw `-15.4067, 28.2871` → parsed without a network call.
  - Paste a normal address → unchanged Nominatim path still works (regression check).
  - Click "use my location" → browser permission prompt, pin lands on grant, error state shown on deny.
- **Feature (`MapLinkController`)**:
  - A `maps.app.goo.gl` URL that redirects to a `google.com/maps/@lat,lng` URL resolves to the right pair.
  - A URL whose host isn't on the allowlist → 422, and — assert this explicitly — **no HTTP request is made**
    (mock/fake the HTTP client and assert zero calls, not just check the response code).
  - A redirect chain longer than 5 hops → 422, not an infinite loop.
  - Unauthenticated request → redirected/blocked by the `auth` middleware, same as every other route in that
    group.
  - Rate limit: 21st call in a minute from one user → 429.

---

## 6. Known gotchas to carry into implementation

1. **`!3d/!4d` beats `@`.** They encode different things (pin vs. viewport center) and can legitimately
   disagree. Checking `@` first would silently give the less accurate answer on exactly the links most likely
   to have it (a "place" page the user panned around before copying).
2. **The resolver must never fetch the redirect target's body** — reading only the `Location` header on a
   `allow_redirects: false` request is what keeps this from becoming an open URL-fetch proxy. Do not "simplify"
   this later to `Http::get($url)` with redirects followed automatically.
3. **Geolocation requires a secure context.** It silently fails (or the API is simply absent) over plain
   `http://` — fine on the production HTTPS domain, but note it if testing over `http://` on a LAN IP during
   local dev; `http://localhost` itself is exempted and works.
4. **Both features must degrade to "nothing happens," never a JS error**, on browsers/permissions that don't
   support them — this form has no test coverage today, so silent, defensive failure is what protects the
   base "type an address" path from a regression.
5. **Reuse, don't fork, the coordinate regexes** between `events-form.js` (§3a) and `MapLinkController`
   (§3b step 3) — they parse the same URL shapes. If either changes format handling, the other must move
   with it. In this codebase (blade + vanilla JS, no shared JS/PHP module boundary), that most likely means
   a comment pointing each implementation at the other rather than a literal shared import.
