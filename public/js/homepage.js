document.querySelectorAll('.faq-q').forEach((btn) => {
  btn.addEventListener('click', () => {
    const item = btn.closest('.faq-item');
    if (!item) return;
    const isOpen = item.classList.contains('open');
    document.querySelectorAll('.faq-item').forEach((i) => i.classList.remove('open'));
    if (!isOpen) item.classList.add('open');
  });
});

// Hamburger menu
const navHamburger = document.querySelector('.nav-hamburger');
const navMobileMenu = document.getElementById('nav-mobile-menu');

if (navHamburger && navMobileMenu) {
  navHamburger.addEventListener('click', (e) => {
    e.stopPropagation();
    const isOpen = navHamburger.classList.toggle('is-open');
    navHamburger.setAttribute('aria-expanded', String(isOpen));
    navMobileMenu.hidden = !isOpen;
  });

  document.addEventListener('click', (e) => {
    if (!navMobileMenu.hidden && !navMobileMenu.contains(e.target) && !navHamburger.contains(e.target)) {
      navHamburger.classList.remove('is-open');
      navHamburger.setAttribute('aria-expanded', 'false');
      navMobileMenu.hidden = true;
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !navMobileMenu.hidden) {
      navHamburger.classList.remove('is-open');
      navHamburger.setAttribute('aria-expanded', 'false');
      navMobileMenu.hidden = true;
      navHamburger.focus();
    }
  });
}

// Nav account dropdown toggle
const navAccountToggle = document.getElementById('nav-account-toggle');
const navAccountDropdown = document.getElementById('nav-account-dropdown');
if (navAccountToggle && navAccountDropdown) {
  navAccountToggle.addEventListener('click', (e) => {
    e.stopPropagation();
    const isOpen = !navAccountDropdown.hidden;
    navAccountDropdown.hidden = isOpen;
    navAccountToggle.setAttribute('aria-expanded', String(!isOpen));
  });
  document.addEventListener('click', () => {
    navAccountDropdown.hidden = true;
    navAccountToggle.setAttribute('aria-expanded', 'false');
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !navAccountDropdown.hidden) {
      navAccountDropdown.hidden = true;
      navAccountToggle.setAttribute('aria-expanded', 'false');
      navAccountToggle.focus();
    }
  });
}

// Password visibility toggle
document.querySelectorAll('.auth-eye').forEach((btn) => {
  btn.addEventListener('click', () => {
    const input = document.getElementById(btn.dataset.target);
    if (!input) return;
    const isHidden = input.type === 'password';
    input.type = isHidden ? 'text' : 'password';
    btn.querySelector('i').className = isHidden ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
  });
});

// Block double submission of the auth forms.
// Logging in regenerates the session (and with it the CSRF token), so a second
// send of the same rendered form arrives with a stale token and fails as a 419
// even though the first send already signed the user in.
const authForms = document.querySelectorAll('.auth-form-card form');

authForms.forEach((form) => {
  form.addEventListener('submit', (e) => {
    // Let other handlers cancel first (e.g. a confirm dialog).
    if (e.defaultPrevented) return;

    if (form.dataset.submitting === '1') {
      e.preventDefault();
      return;
    }
    form.dataset.submitting = '1';

    const btn = form.querySelector('[type="submit"]');
    // Disable on the next tick so the button is still enabled while the browser
    // serializes the form.
    if (btn) window.setTimeout(() => { btn.disabled = true; }, 0);
  });
});

// Restore the button when the page is reopened from the back/forward cache.
window.addEventListener('pageshow', (e) => {
  if (!e.persisted) return;
  authForms.forEach((form) => {
    form.dataset.submitting = '';
    const btn = form.querySelector('[type="submit"]');
    if (btn) btn.disabled = false;
  });
});
