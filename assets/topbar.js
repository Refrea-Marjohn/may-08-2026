// Global profile dropdown behavior (safe on pages without the dropdown)
(function () {
  function getEls() {
    const icon = document.querySelector('.profile-trigger');
    const dropdown = document.getElementById('profileDropdown');
    return { icon, dropdown };
  }

  window.toggleProfileDropdown = function toggleProfileDropdown() {
    const { dropdown } = getEls();
    if (!dropdown) return;
    dropdown.classList.toggle('active');
  };

  document.addEventListener('click', function (event) {
    const { icon, dropdown } = getEls();
    if (!icon || !dropdown) return;
    if (!icon.contains(event.target)) dropdown.classList.remove('active');
  });

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    const { dropdown } = getEls();
    if (!dropdown) return;
    dropdown.classList.remove('active');
  });

})();

// Sidebar hover-expand behavior + runtime label wrappers (shared across roles)
(function () {
  const sidebar = document.querySelector('.sidebar');
  if (!sidebar) return;

  const root = document.documentElement;

  // Wrap raw text labels inside sidebar links.
  sidebar.querySelectorAll('.sidebar-link').forEach((link) => {
    if (link.querySelector('.sidebar-label')) return;

    const labelText = Array.from(link.childNodes)
      .filter((node) => node.nodeType === Node.TEXT_NODE)
      .map((node) => node.textContent.trim())
      .filter(Boolean)
      .join(' ')
      .trim();

    if (!labelText) return;

    Array.from(link.childNodes).forEach((node) => {
      if (node.nodeType === Node.TEXT_NODE && node.textContent.trim() !== '') {
        link.removeChild(node);
      }
    });

    const label = document.createElement('span');
    label.className = 'sidebar-label';
    label.textContent = labelText;

    const badge = link.querySelector('.sidebar-badge');
    if (badge) link.insertBefore(label, badge);
    else link.appendChild(label);
  });

  function syncCollapsedSidebarWidth() {
    const header = sidebar.querySelector('.sidebar-header');
    const logo = sidebar.querySelector('.sidebar-logo');
    if (!header || !logo) return;

    const style = window.getComputedStyle(header);
    const padLeft = parseFloat(style.paddingLeft) || 0;
    const padRight = parseFloat(style.paddingRight) || 0;
    const logoWidth = logo.getBoundingClientRect().width || logo.offsetWidth || 0;
    const computed = Math.round(logoWidth + padLeft + padRight);
    const clamped = Math.max(72, Math.min(120, computed));
    root.style.setProperty('--sidebar-width-collapsed', `${clamped}px`);
  }

  const expand = () => document.body.classList.add('sidebar-expanded');
  const collapse = () => document.body.classList.remove('sidebar-expanded');

  sidebar.addEventListener('mouseenter', expand);
  sidebar.addEventListener('mouseleave', collapse);

  syncCollapsedSidebarWidth();
  collapse(); // start collapsed by default
  window.addEventListener('resize', syncCollapsedSidebarWidth);
})();

