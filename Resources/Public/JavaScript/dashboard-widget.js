import AjaxRequest from '@typo3/core/ajax/ajax-request.js';

// All Matomo widgets share one site and period: store the choice, then reload every widget.
// If storing fails, the reload shows the selection that is still stored.
document.addEventListener('change', async (event) => {
  const form = event.target instanceof HTMLSelectElement ? event.target.closest('form[data-analytics-matomo-selection]') : null;
  if (!form) {
    return;
  }
  try {
    await new AjaxRequest(TYPO3.settings.ajaxUrls.analytics_matomo_dashboard_selection).post(new FormData(form));
  } finally {
    document.querySelectorAll('.tx-analytics-matomo-widget').forEach((widget) => {
      widget.dispatchEvent(new Event('widgetRefresh', { bubbles: true }));
    });
  }
});
