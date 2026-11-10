<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Redirect;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Finds and creates sys_redirect records (EXT:redirects) for paths of a site
 */
final readonly class RedirectWriter
{
    public const STATUS_CODE = 301;

    public function __construct(
        private ConnectionPool $connectionPool,
        private LinkService $linkService,
        private Context $context,
    ) {
    }

    public function mayCreate(BackendUserAuthentication $user): bool
    {
        return $user->check('tables_modify', 'sys_redirect');
    }

    /**
     * On the root level like new records of the Redirects module, so write access to redirects is enough;
     * the target is the page in the language, like core's slug redirects
     *
     * @return int the new redirect
     * @throws \RuntimeException if DataHandler refuses it
     */
    public function create(Site $site, string $path, RedirectCandidate $target, BackendUserAuthentication $user): int
    {
        $id = StringUtility::getUniqueId('NEW');
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['sys_redirect' => [$id => [
            'pid' => 0,
            'source_host' => self::sourceHost($site),
            'source_path' => $path,
            'target' => $this->linkService->asString(['type' => 'page', 'pageuid' => $target->pageId, 'parameters' => '_language=' . $target->languageId]),
            'target_statuscode' => self::STATUS_CODE,
        ]]], [], $user);
        $dataHandler->process_datamap();
        $uid = (int)($dataHandler->substNEWwithIDs[$id] ?? 0);
        if ($dataHandler->errorLog !== [] || $uid === 0) {
            throw new \RuntimeException('The redirect was not created: ' . implode(' ', $dataHandler->errorLog), 1794300001);
        }
        return $uid;
    }

    /**
     * Active redirects of the site's host or of all hosts, by the paths they redirect
     *
     * @param list<string> $paths decoded paths without query
     * @return array<string, int> redirect by path
     */
    public function findExisting(Site $site, array $paths): array
    {
        if ($paths === []) {
            return [];
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_redirect');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $now = (int)$this->context->getPropertyFromAspect('date', 'timestamp');
        $rows = $queryBuilder
            ->select('uid', 'source_path', 'is_regexp')
            ->from('sys_redirect')
            ->where(
                $queryBuilder->expr()->in('source_host', $queryBuilder->createNamedParameter(array_values(array_unique([self::sourceHost($site), '*'])), Connection::PARAM_STR_ARRAY)),
                $queryBuilder->expr()->eq('disabled', 0),
                $queryBuilder->expr()->lte('starttime', $queryBuilder->createNamedParameter($now, Connection::PARAM_INT)),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('endtime', 0),
                    $queryBuilder->expr()->gte('endtime', $queryBuilder->createNamedParameter($now, Connection::PARAM_INT)),
                ),
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();
        $existing = [];
        foreach ($paths as $path) {
            foreach ($rows as $row) {
                $source = (string)$row['source_path'];
                // Matched like EXT:redirects does: flat paths regardless of a trailing slash
                $matches = $row['is_regexp']
                    ? @preg_match($source, $path) === 1
                    : rtrim($source, '/') === rtrim($path, '/');
                if ($matches) {
                    $existing[$path] = (int)$row['uid'];
                    break;
                }
            }
        }
        return $existing;
    }

    public static function sourceHost(Site $site): string
    {
        return $site->getBase()->getHost() ?: '*';
    }
}
