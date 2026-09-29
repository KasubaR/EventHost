# Feature Plan: Google Maps venue picker, embed and directions

Status: **Code complete, gated off by default.** Everything below ships behind
`config('services.google_maps.key')` (env `GOOGLE_MAPS_API_KEY`) being set — while it's blank,
every surface falls back exactly to its pre-existing behaviour (Leaflet/OSM picker on the edit
form, plain "Open in Google Maps" text link on public pages), so this merged safely before a real
API key existed. Turning it on is a one-line env change once Google Cloud billing/API/key setup
(external, not code) is done — see §5.

Supersedes nothing in [map-location-input.md](map-location-input.md) — the pasted-link parsing
(`parseGoogleMapsCoords`, `MapLinkController`/`GoogleMapsLinkParser`) and the Leaflet/Nominatim
picker it describes are **kept as the no-key fallback**, not replaced.

---

## 1. What changed

| Surface | Before | After (when `GOOGLE_MAPS_API_KEY` is set) |
|---|---|---|
| Event edit/create form | Leaflet map, Nominatim address search + reverse-geocode | Google Maps JS map, Places Autocomplete search, `google.maps.Geocoder` reverse-geocode |
| Public invitation / ticket landing "Location" section | Plain "Open in Google Maps" text link | Embedded map iframe (Maps Embed API) + "Open in Google Maps" + "Get Directions" links |
| Compact per-field tile layouts (beauty_for_ashes, botanical_graduation, pro_magazine, the six `@include`-based wedding layouts) | Same plain text link | "Open in Google Maps" + "Get Directions" links, no embed (kept compact) |

## 2. Schema

Migration `2026_09_29_115124_add_google_place_fields_to_events_table` adds two nullable columns
alongside the existing `venue`/`location_name`/`latitude`/`longitude`:

- `google_place_id` — Google's stable id for the venue, used to build the Directions deep link
  (`destination_place_id`) and, when present, to query the embed by place id instead of raw
  coordinates (more accurate for venues with multiple entrances/buildings).
- `formatted_address` — Google's canonical address string, used as the Directions destination
  text and to prefill `location_name` when empty.

Both are additive and machine-derived — they never overwrite the host's own `venue`/
`location_name` text, and stay `null` for any event created before this shipped or pinned via the
paste-a-link fallback that predates Places. `Event::$fillable`,
`StoreEventRequest`/`UpdateEventRequest` (`prepareForValidation()` + `rules()`) all treat them as
`nullable|string`, same posture as every other optional location field.

## 3. Edit form (`events-form.js`)

`initMap()` is now a dispatcher reading `data-provider` off `#evt-map`
([form-fields.blade.php](../resources/views/events/partials/form-fields.blade.php)), which the
blade sets server-side from the config key's presence:

- `data-provider="leaflet"` → `initLeafletMap()`, the original function body, unchanged.
- `data-provider="google"` → `initGoogleMap()`, new. The Google Maps JS script tag loads
  `async` with `callback=initEventGoogleMap`; `tryInitGoogleMap()` only runs once **both**
  `DOMContentLoaded` has fired **and** that callback has, since either can happen first.

`initGoogleMap()` mirrors `initLeafletMap()`'s behaviour field-for-field (same hidden
`latitude`/`longitude` inputs, same "fill `location_name`/`venue` only if empty" courtesy) and
additionally writes the new hidden `google_place_id`/`formatted_address` inputs from whichever
input path fired:

- **Places Autocomplete** selection → all four fields from the `PlaceResult`.
- **Map click or marker drag** → `latitude`/`longitude` set immediately, then
  `google.maps.Geocoder` reverse-geocodes to backfill `place_id`/`formatted_address` — a manually
  placed pin isn't a second-class citizen next to a search pick.
- **Pasted Google Maps link** (short or long) → unchanged `parseGoogleMapsCoords` /
  `/maps/resolve-link` path fills `latitude`/`longitude` first, then the same reverse-geocode call
  backfills `place_id`/`formatted_address`, so all three input methods end up with equivalent data.

Enter-key handling: the search input's `keydown` only calls `preventDefault()` (stops the whole
event form submitting). Selecting a suggestion via Enter is left entirely to Autocomplete's own
`place_changed` event — Google fires it with a name-only, geometry-less result when Enter is
pressed with nothing highlighted, which `applyPlaceResult()` detects and falls through to
`doSearch()`'s Geocoder-address path. No custom Enter handling races Autocomplete's.

## 4. Public display (`events.invitations.partials.map-link`)

One shared partial, now `@include`d everywhere a map/directions link renders (it already was in
six wedding-family layouts; beauty_for_ashes, botanical_graduation, pro_magazine, the generic
`events/invitations/sections/details.blade.php`, and the ticket landing page's Location card were
converted to it as part of this work instead of duplicating the markup).

Params:

- `class` (optional) — applied to both links, so each template keeps its own link styling
  (`bfa-inline-link`, `wi-detail-link`, etc.).
- `embed` (optional, default `false`) — only passed `true` from the two sections with room for an
  iframe (the generic details section, the ticket landing page's dedicated Location card). The
  compact tile-card layouts stay link-only to avoid blowing out a small fixed-size tile.

Query construction: prefers `place_id:{google_place_id}` over `{lat},{lng}` for both the embed
and the "Open in Google Maps" link when a place id is stored; Directions always sends
`destination_place_id` alongside a text destination when available, falling back to
coordinate-only directions otherwise.

CSS (`.evt-map-embed`, `.evt-map-links`) lives in
[events-public.css](../public/css/events-public.css) specifically because that's the one file
confirmed loaded on **both** the invitation renderer and the ticket landing page — putting it in
`events-invitation.css` (invitation-only) would leave the ticket landing page's embed unstyled.

## 5. Turning it on (no code changes needed)

1. Google Cloud Console → new project → enable billing (Maps Platform's $200/month free tier
   covers this app's expected volume, but a billing account must still be attached).
2. Enable exactly **Maps JavaScript API**, **Places API**, **Geocoding API**, and the Maps Embed
   API is enabled implicitly with a Maps Platform project (no separate toggle).
3. Create an API key, restrict it to those APIs and to the production/dev HTTP referrers.
4. Set `GOOGLE_MAPS_API_KEY` in `.env`, `php artisan config:cache`.
5. Manually QA the edit-form picker (search, click, drag, paste) and one public page's embed +
   Directions link before relying on it — the interactive JS path has no automated coverage
   (matches `events-form.js`'s existing untested state, see
   [map-location-input.md §5](map-location-input.md)) and was written without a live key to test
   against.

## 6. Testing

- `EventManagementTest::test_google_place_id_and_formatted_address_persist_through_store` /
  `..._through_update` — the two new columns round-trip through create/update the same way
  `latitude`/`longitude` already do.
- Everything under `plans/map-location-input.md §5` (paste-link parsing, `MapLinkController`)
  is unchanged and still covered by `MapLinkResolveTest` / `GoogleMapsLinkParserTest`.
- Not covered (see §5 above): the live Google Maps JS integration itself (Autocomplete, Geocoder,
  embed rendering with a real key) — no CI secret exists for this and none should be added just
  for tests; verify manually after Phase 5 setup.
