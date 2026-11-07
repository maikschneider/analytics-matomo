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
     * @param Report $report
     * @param Report|null $previous
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function create(array $report, ?array $previous, array $query, string $baseUrl, ?Site $site = null, int $languageId = -1, int $selectedPageId = 0): array
    {
        $rows = $this->rows($report);
        $truncated = false;
        if ($site !== null) {
            $mapped = $this->mapper->mapReport($site, $rows);
            $truncated = $mapped->isTruncated;
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
        $sort = (string)($query['sort'] ?? '');
        $direction = ($query['dir'] ?? '') === 'asc' ? 'asc' : 'desc';
        if (isset($report['columns'][$sort])) {
            usort($rows, static function (array $a, array $b) use ($sort, $direction): int {
                $left = $a[$sort] ?? '';
                $right = $b[$sort] ?? '';
                $order = is_numeric($left) && is_numeric($right) ? (float)$left <=> (float)$right : strnatcasecmp((string)$left, (string)$right);
                return $direction === 'asc' ? $order : -$order;
            });
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
                $type = (string)($report['metadata']['metricTypes'][$key] ?? $report['metadata']['metricTypesGoal'][$key] ?? 'number');
                $change = '–';
                if (is_numeric($value) && isset($old[$key]) && is_numeric($old[$key]) && (float)$old[$key] > 0) {
                    $ratio = ((float)$value - (float)$old[$key]) / (float)$old[$key];
                    $change = ($ratio > 0 ? '+' : '') . $this->formatter->format($ratio, 'percent');
                }
                $cells[] = ['value' => $key === 'label' ? (string)$value : $this->formatter->format($value, $type), 'metric' => $key !== 'label', 'change' => $change];
            }
            $renderedRows[] = ['cells' => $cells, 'actions' => $row['_actions'] ?? []];
        }
        parse_str((string)parse_url($baseUrl, PHP_URL_QUERY), $searchParameters);
        return [
            'searchParameters' => $searchParameters, 'truncated' => $truncated, 'urlRows' => $site !== null,
            'title' => (string)($report['metadata']['name'] ?? ''), 'columns' => $columns, 'rows' => $renderedRows,
            'compare' => $previous !== null, 'total' => $total, 'page' => $page, 'pages' => $pages, 'query' => $search,
            'searchUrl' => $baseUrl, 'sort' => $sort, 'direction' => $direction,
            'previousUrl' => $page > 1 ? $link(['page' => $page - 1]) : '', 'nextUrl' => $page < $pages ? $link(['page' => $page + 1]) : '',
        ];
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
