import DateTimePicker from '@typo3/backend/date-time-picker.js';

function initialize() {
  document.querySelectorAll('[data-matomo-date]').forEach((input) => {
    DateTimePicker.initialize(input);
    input.form.addEventListener('formdata', (event) => {
      event.formData.set(input.name, input.value.split('T')[0]);
    });
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initialize, { once: true });
} else {
  initialize();
}
