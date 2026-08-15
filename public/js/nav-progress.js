/**
 * Lightweight loading feedback for full page navigations.
 *
 * This app is a traditional Blade MPA — clicking a sidebar link (Overview,
 * Billing, ...) or submitting a form reloads the whole document. Without any
 * feedback, the previous page just sits frozen for the entire round trip
 * before the browser swaps in the new one. This shows a thin progress line
 * plus a pulsing EventHost mark (the same icon used as the favicon) so the
 * click visibly registers while the next page loads.
 *
 * Dependency-free, same pattern as datetime-picker.js / custom-select.js.
 * Pair with the #nav-progress-bar / #nav-progress-badge rules in
 * dashboard-shell.css.
 */
(function () {
  var bar = document.createElement('div');
  bar.id = 'nav-progress-bar';
  bar.setAttribute('aria-hidden', 'true');

  var badge = document.createElement('div');
  badge.id = 'nav-progress-badge';
  badge.setAttribute('aria-hidden', 'true');
  // Same monogram as public/images/logo/EventHost Logo_Icon.svg, inlined so
  // it can be styled/animated without an extra request.
  badge.innerHTML =
    '<svg viewBox="0 0 540 540" xmlns="http://www.w3.org/2000/svg">' +
    '<path fill="#e00e4f" d="m465.72,74.28v195.72c0,108.3-87.42,195.72-195.72,195.72s-195.72-87.42-195.72-195.72,88.07-195.72,195.72-195.72h195.72Z"/>' +
    '<g fill="#fff">' +
    '<path d="m379.3,290.27v59.21h30.23v-76.93c-7.58,9.06-18.18,15.48-30.23,17.72Zm-98.14,59.21h30.23v-58.34h-30.23v58.34Z"/>' +
    '<path d="m186.97,322.73v-27.17c0-9.01,7.3-16.31,16.31-16.31h62.76v-26.97h-61.46c-26.42,0-47.84,21.42-47.84,47.84v49.82h87.2c15.03,0,27.21-12.18,27.21-27.21h0s-84.18,0-84.18,0Z"/>' +
    '<path d="m269.3,209.71v-27.21h-85.35c-15.03,0-27.21,12.18-27.21,27.21h0s112.55,0,112.55,0Z"/>' +
    '<rect x="209.27" y="252.28" width="112.55" height="26.97"/>' +
    '<path d="m409.54,182.34v57.07c0,21.92-17.77,39.68-39.68,39.68h-88.69v-96.75h30.23v69.93h55.62c6.78,0,12.28-5.5,12.28-12.28v-57.65h30.23Z"/>' +
    '</g></svg>';

  document.addEventListener('DOMContentLoaded', function () {
    document.body.appendChild(bar);
    document.body.appendChild(badge);
  });

  var creepTimer = null;

  function start() {
    clearInterval(creepTimer);

    bar.style.transition = 'none';
    bar.style.width = '0%';
    bar.style.opacity = '1';
    // Force a reflow so the width change below animates instead of jumping.
    void bar.offsetWidth;
    bar.style.transition = 'width 0.4s ease, opacity 0.2s ease';
    bar.style.width = '30%';

    badge.classList.add('is-visible');

    var width = 30;
    creepTimer = setInterval(function () {
      // Ease toward 90% and stall — the real completion is the browser
      // swapping the document, not anything this script can detect.
      width += (90 - width) * 0.1;
      bar.style.width = Math.min(width, 90) + '%';
    }, 300);
  }

  function reset() {
    clearInterval(creepTimer);
    bar.style.transition = 'none';
    bar.style.width = '0%';
    bar.style.opacity = '0';
    badge.classList.remove('is-visible');
  }

  function shouldSkip(link) {
    if (!link.href) return true;
    if (link.target && link.target !== '_self') return true;
    if (link.hasAttribute('download')) return true;
    if (link.origin !== window.location.origin) return true;
    // Same-page hash link (e.g. an in-page anchor) never navigates away.
    if (link.pathname === window.location.pathname && link.search === window.location.search && link.hash) return true;
    return false;
  }

  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var link = e.target.closest('a[href]');
    if (!link || shouldSkip(link)) return;
    start();
  });

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.target && form.target !== '_self') return;
    start();
  });

  // Bfcache restore (e.g. hitting Back) can leave the bar/badge stuck
  // mid-animation from before the user navigated away.
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) reset();
  });
})();
