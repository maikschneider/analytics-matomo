<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Module;

use MaikSchneider\AnalyticsMatomo\Statistics\PageRecordResolver;
use MaikSchneider\AnalyticsMatomo\Statistics\PageUrlMapper;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

/**
 * @phpstan-type Report array{metadata: array<string, mixed>, columns: array<string, string>, reportData: list<array<string, mixed>>, reportMetadata: array<int, array<string, mixed>>, reportTotal: array<string, mixed>}
 */
final readonly class ReportTableFactory
{
    public function __construct(
        private MetricFormatter $formatter,
        private PageUrlMapper $mapper,
        private UriBuilder $uriBuilder,
        private PageRecordResolver $pageRecordResolver,
    ) {
    }

    /**
     * A report of raw API rows, for views Matomo has no processed report for; columns come from the catalog options
     *
     * @param list<array<string, mixed>> $rows
     * @return Report
     */
    public static function fromRows(array $rows): array
    {
        return ['metadata' => [], 'columns' => ['label' => ''], 'reportData' => $rows, 'reportMetadata' => [], 'reportTotal' => []];
    }

    /**
     * @param Report $report
     * @param Report|null $previous
     * @param array<string, mixed> $query
     * @param array{title?: string, columns?: array<string, array{string, string}>, minimum?: array{string, int}, sort?: string, empty?: string, truncated?: bool, index?: int, export?: array{url: string, metric: string, value: string}} $options
     *        catalog options: own title, own columns (name and metric type, e.g. raw metrics), minimum value of a column, default sort column, message for a report without rows,
     *        rows built from a report Matomo truncated;
     *        position of the table on its page; translated extra headers to get all rows with raw values for a CSV export instead of the view
     * @return array<string, mixed>
     */
    public function create(array $report, ?array $previous, array $query, string $baseUrl, ?Site $site = null, int $languageId = -1, int $selectedPageId = 0, array $options = []): array
    {
        $exportUrl = $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . http_build_query(array_intersect_key($query, ['q' => true, 'sort' => true, 'dir' => true]) + ['export' => $options['index'] ?? 0]);
        if (!isset($report['columns']['label'])) {
            if (isset($options['export'])) {
                $row = $report['reportData'][0] ?? [];
                return ['csv' => [
                    'header' => [$options['export']['metric'], $options['export']['value']],
                    'rows' => array_map(static fn (string $key, string $label): array => [$label, $row[$key] ?? null], array_keys($report['columns']), $report['columns']),
                ]];
            }
            return $this->createMetricList($report, $previous) + ['exportUrl' => $exportUrl];
        }
        if (isset($options['columns'])) {
            $report['columns'] = ['label' => $options['columns']['label'][0] ?? $report['columns']['label']] + array_map(static fn (array $column): string => $column[0], $options['columns']);
            $report['metadata']['metricTypes'] = array_map(static fn (array $column): string => $column[1], $options['columns']) + (array)($report['metadata']['metricTypes'] ?? []);
        }
        $rows = $this->rows($report);
        if (isset($options['minimum'])) {
            [$column, $minimum] = $options['minimum'];
            $rows = array_values(array_filter($rows, static fn (array $row): bool => PageUrlMapper::isSummaryRow($row) || (float)($row[$column] ?? 0) >= $minimum));
        }
        $truncated = false;
        if ($site !== null) {
            $mapped = $this->mapper->mapReport($site, $rows);
            $truncated = $mapped->isTruncated || ($options['truncated'] ?? false);
            $rows = [];
            /** @var BackendUserAuthentication $user */
            $user = $GLOBALS['BE_USER'];
            foreach ($mapped->rows as $mappedRow) {
                $match = $mappedRow->match;
                $language = $match->language->getLanguageId();
                if (($languageId !== -1 && $languageId !== $language) || !$user->checkLanguageAccess($language)) {
                    continue;
                }
                $record = $match->pageId === null ? false : BackendUtility::readPageAccess($match->pageId, $user->getPagePermsClause(Permission::PAGE_SHOW));
                if ($record === false && !$user->isAdmin()) {
                    continue;
                }
                if ($selectedPageId > 0 && $selectedPageId !== $site->getRootPageId()) {
                    if ($match->pageId === null || !in_array($selectedPageId, array_map(intval(...), array_column(GeneralUtility::makeInstance(RootlineUtility::class, $match->pageId)->get(), 'uid')), true)) {
                        continue;
                    }
                }
                $row = $mappedRow->row;
                $row['_actions'] = [];
                if ($record !== false) {
                    $id = $match->pageId;
                    $row['_actions'] = [
                        ['key' => 'details', 'url' => (string)$this->uriBuilder->buildUriFromRoute('analytics_matomo_page', ['id' => $id, 'language' => $language])],
                        ['key' => 'layout', 'url' => (string)$this->uriBuilder->buildUriFromRoute('web_layout', ['id' => $id, 'language' => $language])],
                        ['key' => 'edit', 'url' => (string)$this->uriBuilder->buildUriFromRoute('record_edit', ['edit' => ['pages' => [$id => 'edit']], 'returnUrl' => $baseUrl])],
                        ['key' => 'view', 'url' => (string)$site->getRouter()->generateUri($this->pageRecordResolver->resolve($id, $match->language), ['_language' => $match->language])],
                    ];
                }
                $rows[] = $row;
            }
        }
        $previousRows = [];
        foreach ($previous === null ? [] : $this->rows($previous) as $row) {
            $previousRows[$this->identity($row)] = $row;
        }
        $search = trim((string)($query['q'] ?? ''));
        if ($search !== '') {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => mb_stripos((string)($row['label'] ?? ''), $search) !== false));
        }
        $sort = (string)($query['sort'] ?? '') !== '' ? (string)$query['sort'] : ($options['sort'] ?? '');
        $direction = ($query['dir'] ?? '') === 'asc' ? 'asc' : 'desc';
        if (isset($report['columns'][$sort])) {
            usort($rows, static function (array $a, array $b) use ($sort, $direction): int {
                $left = $a[$sort] ?? '';
                $right = $b[$sort] ?? '';
                $order = is_numeric($left) && is_numeric($right) ? (float)$left <=> (float)$right : strnatcasecmp((string)$left, (string)$right);
                return $direction === 'asc' ? $order : -$order;
            });
        }
        if (isset($options['export'])) {
            $header = array_values($report['columns']);
            $csvRows = [];
            foreach ($rows as $row) {
                $values = [];
                foreach (array_keys($report['columns']) as $key) {
                    $values[] = $row[$key] ?? null;
                }
                $csvRows[] = $site === null ? $values : [...$values, $row['url'] ?? null];
            }
            return ['csv' => ['header' => $site === null ? $header : [...$header, $options['export']['url']], 'rows' => $csvRows]];
        }
        $total = count($rows);
        $pages = max(1, (int)ceil($total / 25));
        $page = min($pages, max(1, (int)($query['page'] ?? 1)));
        $parameters = ['q' => $search, 'sort' => $sort, 'dir' => $direction];
        $link = static fn (array $changes): string => $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . http_build_query(array_replace($parameters, $changes));
        $columns = [];
        foreach ($report['columns'] as $key => $label) {
            $columns[] = ['key' => $key, 'label' => $label, 'documentation' => (string)($report['metadata']['metricsDocumentation'][$key] ?? ''), 'url' => $link(['sort' => $key, 'dir' => $sort === $key && $direction === 'desc' ? 'asc' : 'desc', 'page' => 1]), 'metric' => $key !== 'label'];
        }
        $renderedRows = [];
        foreach (array_slice($rows, ($page - 1) * 25, 25) as $row) {
            $cells = [];
            $old = $previousRows[$this->identity($row)] ?? [];
            foreach ($report['columns'] as $key => $label) {
                $value = $row[$key] ?? null;
                $cells[] = ['value' => $key === 'label' ? (string)$value : $this->formatter->format($value, $this->type($report, $key)), 'metric' => $key !== 'label', 'change' => $this->change($value, $old[$key] ?? null)];
            }
            $renderedRows[] = ['cells' => $cells, 'actions' => $row['_actions'] ?? []];
        }
        parse_str((string)parse_url($baseUrl, PHP_URL_QUERY), $searchParameters);
        return [
            'searchParameters' => $searchParameters, 'truncated' => $truncated, 'hasActions' => $site !== null || array_filter(array_column($rows, '_actions')) !== [],
            'title' => $options['title'] ?? (string)($report['metadata']['name'] ?? ''), 'columns' => $columns, 'rows' => $renderedRows,
            'emptyMessage' => $search === '' ? $options['empty'] ?? '' : '',
            'compare' => $previous !== null, 'total' => $total, 'page' => $page, 'pages' => $pages, 'query' => $search,
            'searchUrl' => $baseUrl, 'sort' => $sort, 'direction' => $direction, 'exportUrl' => $exportUrl,
            'previousUrl' => $page > 1 ? $link(['page' => $page - 1]) : '', 'nextUrl' => $page < $pages ? $link(['page' => $page + 1]) : '',
        ];
    }

    /**
     * One row per metric for reports without a dimension
     *
     * @param Report $report
     * @param Report|null $previous
     * @return array<string, mixed>
     */
    private function createMetricList(array $report, ?array $previous): array
    {
        $row = $report['reportData'][0] ?? [];
        $old = $previous['reportData'][0] ?? [];
        $rows = [];
        foreach ($report['columns'] as $key => $label) {
            $value = $row[$key] ?? null;
            $rows[] = ['cells' => [
                ['value' => $label, 'metric' => false, 'change' => ''],
                ['value' => $this->formatter->format($value, $this->type($report, $key)), 'metric' => true, 'change' => $this->change($value, $old[$key] ?? null)],
            ], 'actions' => []];
        }
        return [
            'metricList' => true, 'title' => (string)($report['metadata']['name'] ?? ''), 'rows' => $row === [] ? [] : $rows,
            'compare' => $previous !== null, 'hasActions' => false, 'truncated' => false,
        ];
    }

    /** @param Report $report */
    private function type(array $report, string $key): string
    {
        return (string)($report['metadata']['metricTypes'][$key] ?? $report['metadata']['metricTypesGoal'][$key] ?? 'number');
    }

    private function change(mixed $value, mixed $old): string
    {
        if (!is_numeric($value) || !is_numeric($old) || (float)$old <= 0) {
            return '–';
        }
        $ratio = ((float)$value - (float)$old) / (float)$old;
        return ($ratio > 0 ? '+' : '') . $this->formatter->format($ratio, 'percent');
    }

    /**
     * @param Report $report
     * @return list<array<string, mixed>>
     */
    private function rows(array $report): array
    {
        $rows = [];
        foreach ($report['reportData'] as $index => $row) {
            $rows[] = $row + ($report['reportMetadata'][$index] ?? []);
        }
        return $rows;
    }

    /** @param array<string, mixed> $row */
    private function identity(array $row): string
    {
        return (string)($row['url'] ?? $row['label'] ?? '');
    }
}
