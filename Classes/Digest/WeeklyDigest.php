<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Digest;

use MaikSchneider\AnalyticsMatomo\Authorization\StatisticsPermission;
use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfigurationResolver;
use MaikSchneider\AnalyticsMatomo\Dashboard\DashboardSelectionResolver;
use MaikSchneider\AnalyticsMatomo\Dashboard\SiteStatisticsService;
use MaikSchneider\AnalyticsMatomo\Dashboard\TopPage;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Module\AllSitesService;
use MaikSchneider\AnalyticsMatomo\Statistics\PageUrlMapper;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Mime\Address;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Mail\FluidEmail;
use TYPO3\CMS\Core\Routing\BackendEntryPointResolver;
use TYPO3\CMS\Core\Routing\InvalidRouteArgumentsException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Weekly mail with aggregated statistics of the sites a backend user may see.
 * Only members of a backend group with the weekly digest option receive it.
 */
final readonly class WeeklyDigest
{
    private const TOP_PAGES = 5;

    public function __construct(
        private ConnectionPool $connectionPool,
        private DashboardSelectionResolver $selectionResolver,
        private MatomoConfigurationResolver $configurationResolver,
        private SiteStatisticsService $statisticsService,
        private AllSitesService $allSitesService,
        private PageUrlMapper $pageUrlMapper,
        private LanguageServiceFactory $languageServiceFactory,
        private BackendEntryPointResolver $entryPointResolver,
    ) {
    }

    /**
     * Active users with an email address in a group with the weekly digest option
     *
     * @return list<BackendUserAuthentication>
     */
    public function getRecipients(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $uids = $queryBuilder->select('uid')
            ->from('be_users')
            ->where($queryBuilder->expr()->neq('email', $queryBuilder->createNamedParameter('')))
            ->orderBy('uid')
            ->executeQuery()
            ->fetchFirstColumn();
        $recipients = [];
        foreach ($uids as $uid) {
            $user = GeneralUtility::makeInstance(BackendUserAuthentication::class);
            $user->setBeUserByUid((int)$uid);
            $user->fetchGroupData();
            if (GeneralUtility::inList((string)($user->groupData['custom_options'] ?? ''), 'analytics_matomo:' . StatisticsPermission::WEEKLY_DIGEST)
                && GeneralUtility::validEmail((string)($user->user['email'] ?? ''))
            ) {
                $recipients[] = $user;
            }
        }
        return $recipients;
    }

    /**
     * The mail for the week before $today, or null if the user may see no site.
     * Sites whose statistics fail are left out; their errors are returned.
     *
     * @return array{FluidEmail|null, list<string>}
     */
    public function create(BackendUserAuthentication $user, \DateTimeImmutable $today): array
    {
        $previousUser = $GLOBALS['BE_USER'] ?? null;
        // Page access checks of the core read the global backend user
        $GLOBALS['BE_USER'] = $user;
        try {
            return $this->build($user, Period::lastDays(7, $today->modify('-1 day')));
        } finally {
            $GLOBALS['BE_USER'] = $previousUser;
        }
    }

    /**
     * @return array{FluidEmail|null, list<string>}
     */
    private function build(BackendUserAuthentication $user, Period $period): array
    {
        $sites = [];
        $errors = [];
        $requestSite = null;
        foreach ($this->selectionResolver->getSites($user) as $site) {
            $configuration = $this->configurationResolver->resolve($site);
            if ($configuration === null) {
                continue;
            }
            try {
                $performance = $this->statisticsService->getPerformance($configuration, $period);
                // The site URL in the mail footer, preferably with a domain
                if ($requestSite === null || $requestSite->getBase()->getHost() === '') {
                    $requestSite = $site;
                }
                $sites[] = [
                    'title' => $this->allSitesService->title($site),
                    'visits' => $performance->current->visits,
                    'visitsChange' => $this->change($performance->current->visits, $performance->previous->visits),
                    'pageViews' => $performance->current->pageViews,
                    'pageViewsChange' => $this->change($performance->current->pageViews, $performance->previous->pageViews),
                    'topPages' => $this->topPages($site, $user, $this->statisticsService->getTopPages($configuration, $period)),
                    'moduleUrl' => $this->moduleUrl($site),
                ];
            } catch (MatomoApiException $e) {
                $errors[] = $site->getIdentifier() . ': ' . $e->getMessage();
            }
        }
        if ($requestSite === null) {
            return [null, $errors];
        }

        $language = (string)($user->user['lang'] ?? '');
        $languageService = $this->languageServiceFactory->create($language === '' ? 'default' : $language);
        $email = new FluidEmail();
        $email->setRequest($this->siteRequest($requestSite))
            ->to(new Address((string)($user->user['email'] ?? ''), (string)($user->user['realName'] ?? '')))
            ->subject((string)$languageService->sL('LLL:EXT:analytics_matomo/Resources/Private/Language/locallang.xlf:digest.subject'))
            ->setTemplate('WeeklyDigest')
            ->assignMultiple([
                'headline' => $languageService->sL('LLL:EXT:analytics_matomo/Resources/Private/Language/locallang.xlf:digest.subject'),
                'language' => $languageService->lang,
                'from' => $period->from->format('Y-m-d'),
                'to' => $period->to->format('Y-m-d'),
                'sites' => $sites,
            ]);
        return [$email, $errors];
    }

    /**
     * Visible pages by views. Matomo counts URLs with query strings separately;
     * they are summed per page so no tracked parameter ends up in a mail.
     *
     * @param list<TopPage> $pages
     * @return list<array{url: string, title: string, views: int}>
     */
    private function topPages(Site $site, BackendUserAuthentication $user, array $pages): array
    {
        $visible = [];
        foreach ($pages as $page) {
            $match = $this->pageUrlMapper->match($site, $page->url);
            if ($match === null || $match->pageId === null || !$user->checkLanguageAccess($match->language->getLanguageId())) {
                continue;
            }
            $record = BackendUtility::readPageAccess($match->pageId, $user->getPagePermsClause(Permission::PAGE_SHOW));
            if ($record === false) {
                continue;
            }
            try {
                $url = (string)$site->getRouter()->generateUri($match->pageId, ['_language' => $match->language]);
            } catch (InvalidRouteArgumentsException) {
                continue;
            }
            $visible[$url] ??= ['url' => $url, 'title' => (string)($record['title'] ?? ''), 'views' => 0];
            $visible[$url]['views'] += $page->views;
        }
        usort($visible, static fn (array $a, array $b): int => $b['views'] <=> $a['views']);
        return array_slice($visible, 0, self::TOP_PAGES);
    }

    private function change(int $current, int $previous): ?string
    {
        if ($previous === 0) {
            return null;
        }
        return sprintf('%+.1f %%', ($current / $previous - 1) * 100);
    }

    /**
     * Backend login that leads to the visitors overview, assuming the backend
     * shares the site's domain unless an absolute backend entry point is configured
     */
    private function moduleUrl(Site $site): string
    {
        if ($site->getBase()->getHost() === '') {
            return '';
        }
        return (string)$this->entryPointResolver->getUriFromRequest($this->siteRequest($site))
            ->withQuery(http_build_query(['redirect' => 'analytics_matomo_visitors']));
    }

    private function siteRequest(Site $site): ServerRequestInterface
    {
        $base = $site->getBase();
        $request = new ServerRequest($base, 'GET', null, [], [
            'HTTP_HOST' => $base->getAuthority(),
            'HTTPS' => $base->getScheme() === 'https' ? 'on' : 'off',
            'SCRIPT_NAME' => '/index.php',
        ]);
        // As if requested through the web entry point, not the CLI script
        $normalizedParams = new NormalizedParams($request->getServerParams(), $GLOBALS['TYPO3_CONF_VARS']['SYS'], Environment::getPublicPath() . '/index.php', Environment::getPublicPath());
        return $request->withAttribute('normalizedParams', $normalizedParams)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
    }
}
