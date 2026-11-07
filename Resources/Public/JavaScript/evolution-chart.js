import { Chart, LineController, LineElement, PointElement, CategoryScale, LinearScale, Legend, Tooltip } from '@typo3/dashboard/contrib/chartjs.js';

Chart.register(LineController, LineElement, PointElement, CategoryScale, LinearScale, Legend, Tooltip);

function initialize() {
  document.querySelectorAll('[data-evolution-chart]').forEach((element) => {
    const payload = JSON.parse(element.dataset.evolutionChart);
    const picker = element.querySelector('[data-evolution-metric]');
    const color = getComputedStyle(element).color;
    const format = (value, type) => {
      if (value === null || value === undefined) {
        return '–';
      }
      if (type === 'percent') {
        return `${(value * 100).toFixed(1)} %`;
      }
      if (type === 'duration_s') {
        return `${Math.floor(value / 3600)}:${String(Math.floor(value / 60) % 60).padStart(2, '0')}:${String(Math.floor(value) % 60).padStart(2, '0')}`;
      }
      return value.toLocaleString();
    };
    const datasets = () => {
      const metric = payload.metrics[picker.value];
      const label = picker.selectedOptions[0].textContent;
      const series = [{ label: `${label} (${element.dataset.currentLabel})`, data: metric.current, borderColor: '#2878c8', backgroundColor: '#2878c8', spanGaps: false }];
      if (metric.previous) {
        series.push({ label: `${label} (${element.dataset.previousLabel})`, data: metric.previous, borderColor: '#a56a25', backgroundColor: '#a56a25', borderDash: [6, 4], spanGaps: false });
      }
      return series;
    };
    const chart = new Chart(element.querySelector('canvas'), {
      type: 'line',
      data: { labels: payload.labels, datasets: datasets() },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        color,
        scales: {
          x: { ticks: { color } },
          y: { beginAtZero: true, ticks: { color, callback: (value) => format(value, payload.metrics[picker.value].type) } },
        },
        plugins: {
          legend: { labels: { color } },
          tooltip: { callbacks: {
            title: (items) => items.map((item) => item.datasetIndex === 1 ? (payload.previousLabels[item.dataIndex] ?? '–') : payload.labels[item.dataIndex]),
            label: (item) => `${item.dataset.label}: ${format(item.raw, payload.metrics[picker.value].type)}`,
          } },
        },
      },
    });
    picker.addEventListener('change', () => {
      chart.data.datasets = datasets();
      chart.update();
    });
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initialize, { once: true });
} else {
  initialize();
}
