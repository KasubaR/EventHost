# Vendored front-end libraries

Served from our own server so a guest's invitation never depends on a third-party host. Loaded only on pages whose
invitation has gallery photos (`resources/views/events/invitations/partials/gallery-assets.blade.php`).

| Library | Version | Files | Licence | Source |
|---|---|---|---|---|
| Swiper | 11.2.10 | `swiper/swiper-bundle.min.js`, `swiper/swiper-bundle.min.css` | MIT | https://cdn.jsdelivr.net/npm/swiper@11.2.10/ |
| GLightbox | 3.3.1 | `glightbox/glightbox.min.js`, `glightbox/glightbox.min.css` | MIT | https://cdn.jsdelivr.net/npm/glightbox@3.3.1/dist/ |

Files are the unmodified published builds, except that the trailing `sourceMappingURL` comment was removed from
`swiper-bundle.min.js` (the map is not shipped, so browsers would request a file that is not there).

To upgrade, download the new build from the same place, replace the files, update the versions above **and the two
`$swiperV` / `$glightboxV` values in `events/invitations/partials/gallery-assets.blade.php`** (they are the `?v=` on each URL,
which is what makes a long cache lifetime on `/vendor` safe), and run
`php artisan test --filter=InvitationResilienceTest` (it checks the version banners). Check a gallery on every layout
afterwards: the standard layout uses Swiper and GLightbox; the wedding, modern-minimal, botanical and dusty-blue
layouts use GLightbox only.
