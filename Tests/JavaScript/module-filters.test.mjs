import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync('Resources/Public/JavaScript/module-filters.js', 'utf8');

test('core date pickers submit date-only values for the custom period', () => {
    const inputs = ['from', 'to'].map((name) => ({ name, value: '2026-10-01T00:00:00', form: { addEventListener(event, callback) { this[event] = callback; } } }));
    const initialized = [];
    runInNewContext(source.replace(/^import .*;\n/, ''), {
        DateTimePicker: { initialize: (input) => initialized.push(input) },
        document: { readyState: 'complete', querySelectorAll: () => inputs, querySelector: () => null },
    });
    assert.equal(initialized.length, 2);
    for (const input of inputs) {
        const formData = new Map();
        input.form.formdata({ formData });
        assert.equal(formData.get(input.name), '2026-10-01');
    }
});

test('a changed page tree setting refreshes the page tree once', () => {
    const dispatched = [];
    const run = (marker) => runInNewContext(source.replace(/^import .*;\n/, ''), {
        DateTimePicker: { initialize: () => {} },
        CustomEvent: class { constructor(type) { this.type = type; } },
        document: { readyState: 'complete', querySelectorAll: () => [], querySelector: (selector) => selector === '[data-refresh-page-tree]' ? marker : null },
        top: { document: { dispatchEvent: (event) => dispatched.push(event.type) } },
    });
    run(null);
    assert.deepEqual(dispatched, []);
    run({});
    assert.deepEqual(dispatched, ['typo3:pagetree:refresh']);
});
