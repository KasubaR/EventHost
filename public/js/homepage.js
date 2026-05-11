document.querySelectorAll('.filter-tab').forEach((tab) => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.filter-tab').forEach((t) => t.classList.remove('active'));
    tab.classList.add('active');
  });
});

document.querySelectorAll('.faq-q').forEach((btn) => {
  btn.addEventListener('click', () => {
    const item = btn.closest('.faq-item');
    if (!item) return;
    const isOpen = item.classList.contains('open');
    document.querySelectorAll('.faq-item').forEach((i) => i.classList.remove('open'));
    if (!isOpen) item.classList.add('open');
  });
});

const observer = new IntersectionObserver(
  (entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        entry.target.querySelectorAll('.bar').forEach((bar, i) => {
          setTimeout(() => {
            bar.style.opacity = '1';
          }, i * 80);
        });
      }
    });
  },
  { threshold: 0.3 }
);

const dashMockup = document.querySelector('.dash-mockup');
if (dashMockup) observer.observe(dashMockup);

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
