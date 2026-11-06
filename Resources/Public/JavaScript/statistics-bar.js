import AjaxRequest from '@typo3/core/ajax/ajax-request.js';

const selector = '.tx-analytics-matomo';

async function load(section, days) {
  section.classList.add('tx-analytics-matomo--loading');
  section.setAttribute('aria-busy', 'true');

  try {
    const response = await new AjaxRequest(section.dataset.url).withQueryArguments({ days }).get();
    const data = await response.resolve();
    const template = document.createElement('template');
    template.innerHTML = data.html.trim();
    const newSection = template.content.firstElementChild;
    if (!newSection) {
      section.remove();
      return;
    }
    newSection.dataset.url = section.dataset.url;
    section.replaceWith(newSection);
  } catch {
    // No statistics for this page (404) or the request failed: hide the bar instead of a broken one
    section.remove();
  }
}

function init() {
  document.querySelectorAll(`${selector}[data-url]`).forEach((section) => load(section, section.dataset.days));
}

document.addEventListener('change', (event) => {
  const select = event.target;
  if (!(select instanceof HTMLSelectElement) || !select.matches('[data-analytics-matomo-days]')) {
    return;
  }
  const section = select.closest(selector);
  if (section) {
    load(section, select.value);
  }
});

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
