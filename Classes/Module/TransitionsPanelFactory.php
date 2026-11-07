<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Module;

use MaikSchneider\AnalyticsMatomo\Statistics\LinkAccess;
use MaikSchneider\AnalyticsMatomo\Statistics\PageRecordResolver;
use MaikSchneider\AnalyticsMatomo\Statistics\PageUrlMapper;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * View data of the Transitions panel: page labels become pages of the site the
 * user may see, like in the URL reports, and clicked links are filtered like the
 * outlinks and downloads reports.
 */
final readonly class TransitionsPanelFactory
{
    public function __construct(
        private PageUrlMapper $mapper,
        private PageRecordResolver $pageRecordResolver,
        private UriBuilder $uriBuilder,
        private LinkAccess $linkAccess,
    ) {
    }

    /**
     * @param array<string, mixed> $transitions answer of Transitions.getTransitionsForAction
     * @return array<string, mixed>
     */
    public function create(Site $site, array $transitions, BackendUserAuthentication $user): array
    {
        $referrers = [];
        foreach ($this->list($transitions['referrers'] ?? null) as $referrer) {
            $details = $this->list($referrer['details'] ?? null);
            if (($referrer['shortName'] ?? '') === 'website') {
                $details = array_values(array_filter($details, fn (array $detail): bool => $this->linkAccess->isVisible($site, (string)($detail['label'] ?? ''), $user)));
            }
            $referrers[] = [
                'label' => (string)($referrer['label'] ?? ''),
                'visits' => (int)($referrer['visits'] ?? 0),
                'details' => array_slice(array_map(static fn (array $detail): array => ['label' => (string)($detail['label'] ?? ''), 'visits' => (int)($detail['referrals'] ?? 0)], $details), 0, 5),
            ];
        }
        return [
            'referrers' => $referrers,
            'previousPages' => $this->pages($site, $this->list($transitions['previousPages'] ?? null), $user),
            'followingPages' => $this->pages($site, $this->list($transitions['followingPages'] ?? null), $user),
            'outlinks' => $this->clicks($site, $this->list($transitions['outlinks'] ?? null), $user),
            'downloads' => $this->clicks($site, $this->list($transitions['downloads'] ?? null), $user),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{label: string, title: string, url: string, flag: string, visits: int}>
     */
    private function pages(Site $site, array $rows, BackendUserAuthentication $user): array
    {
        $pages = [];
        foreach ($rows as $row) {
            $label = (string)($row['label'] ?? '');
            $page = ['label' => $label, 'title' => '', 'url' => '', 'flag' => '', 'visits' => (int)($row['referrals'] ?? 0)];
            if (PageUrlMapper::normalize($label) === null && !str_contains($label, '/')) {
                // "Others" sums up the rest; it names no page
                $pages[] = $page;
                continue;
            }
            $match = $this->mapper->match($site, $label);
            if ($match !== null && !$user->checkLanguageAccess($match->language->getLanguageId())) {
                continue;
            }
            $record = $match?->pageId === null ? false : BackendUtility::readPageAccess($match->pageId, $user->getPagePermsClause(Permission::PAGE_SHOW));
            if ($record === false) {
                // URLs without a readable page, e.g. of other systems on the same domain, only for admins
                if ($user->isAdmin()) {
                    $pages[] = $page;
                }
                continue;
            }
            $translated = $this->pageRecordResolver->resolve($match->pageId, $match->language);
            $page['title'] = (string)(is_array($translated) ? $translated['title'] : $record['title']);
            $page['flag'] = $match->language->getFlagIdentifier();
            $page['url'] = (string)$this->uriBuilder->buildUriFromRoute('web_layout', ['id' => $match->pageId]);
            $pages[] = $page;
        }
        return $pages;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{label: string, visits: int}>
     */
    private function clicks(Site $site, array $rows, BackendUserAuthentication $user): array
    {
        $clicks = [];
        foreach ($rows as $row) {
            $label = (string)($row['label'] ?? '');
            if ($this->linkAccess->isVisible($site, $label, $user)) {
                $clicks[] = ['label' => $label, 'visits' => (int)($row['referrals'] ?? 0)];
            }
        }
        return $clicks;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function list(mixed $rows): array
    {
        return is_array($rows) ? array_values(array_filter($rows, is_array(...))) : [];
    }
}
