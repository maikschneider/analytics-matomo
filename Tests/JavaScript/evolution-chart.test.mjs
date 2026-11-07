import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync('Resources/Public/JavaScript/evolution-chart.js', 'utf8');

function render(compare, readyState = 'complete') {
    let chart;
    let change;
    let loaded;
    const picker = { value: 'visits', selectedOptions: [{ textContent: 'Visits' }], addEventListener: (name, callback) => { change = callback; } };
    const payload = {
        labels: ['2026-10-27', '2026-10-28'],
        previousLabels: ['2026-10-20', '2026-10-21'],
        metrics: {
            visits: { type: 'number', current: [12, null], ...(compare ? { previous: [8, 0] } : {}) },
            bounceRate: { type: 'percent', current: [0.72, 0], ...(compare ? { previous: [0.5, null] } : {}) },
        },
    };
    const canvas = {};
    const element = {
        dataset: { evolutionChart: JSON.stringify(payload), currentLabel: 'Selected period', previousLabel: 'Previous period' },
        querySelector: (selector) => selector === 'canvas' ? canvas : picker,
    };
    class Chart {
        static register() {}
        constructor(target, config) { assert.equal(target, canvas); Object.assign(this, config); chart = this; }
        update() { this.updated = true; }
    }
    runInNewContext(source.replace(/^import .*;\n/, ''), {
        Chart,
        ...Object.fromEntries(['LineController', 'LineElement', 'PointElement', 'CategoryScale', 'LinearScale', 'Legend', 'Tooltip'].map((name) => [name, {}])),
        document: { readyState, querySelectorAll: () => [element], addEventListener: (name, callback) => { assert.equal(name, 'DOMContentLoaded'); loaded = callback; } },
        getComputedStyle: () => ({ color: '#333' }),
    });
    return { getChart: () => chart, picker, change: () => change(), loaded: () => loaded() };
}

test('renders current series with gaps, no previous series without comparison', () => {
    const { getChart } = render(false);
    const chart = getChart();
    assert.equal(chart.data.datasets.length, 1);
    assert.equal(chart.data.datasets[0].data[1], null);
    assert.equal(chart.data.datasets[0].spanGaps, false);
});

test('comparison is dashed and metric changes update locally', () => {
    const view = render(true);
    const chart = view.getChart();
    assert.equal(chart.data.datasets[1].borderDash.join(','), '6,4');
    assert.equal(chart.options.plugins.tooltip.callbacks.title([{ datasetIndex: 1, dataIndex: 0 }])[0], '2026-10-20');
    view.picker.value = 'bounceRate';
    view.picker.selectedOptions[0].textContent = 'Bounce rate';
    view.change();
    assert.equal(chart.updated, true);
    assert.equal(chart.data.datasets[0].data[0], 0.72);
    assert.equal(chart.data.datasets[1].data[0], 0.5);
    assert.equal(chart.options.scales.y.ticks.callback(0.72), '72.0 %');
    assert.match(chart.options.plugins.tooltip.callbacks.label({ dataset: { label: 'Unknown' }, raw: null }), /–$/);
});

test('waits for DOM readiness when loaded early', () => {
    const view = render(false, 'loading');
    assert.equal(view.getChart(), undefined);
    view.loaded();
    assert.equal(view.getChart().type, 'line');
});

test('the installed EXT:dashboard exports every imported Chart.js component', async () => {
    const library = readFileSync('vendor/typo3/cms-dashboard/Resources/Public/JavaScript/Contrib/chartjs.js', 'utf8');
    const exports = await import(`data:text/javascript;base64,${Buffer.from(library).toString('base64')}`);
    const names = source.match(/^import \{ (.*) \} from/)[1].split(', ');
    for (const name of names) {
        assert.ok(exports[name], `Missing Chart.js export: ${name}`);
    }
    exports.Chart.register(...names.filter((name) => name !== 'Chart').map((name) => exports[name]));
    assert.ok(exports.Chart.registry.getController('line'));
});
