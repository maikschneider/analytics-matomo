<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics\Store;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * One connection per Matomo site (URL without trailing slash + site ID). Token
 * and segment are not part of it: the stored data is the site's, access is
 * checked when it is read.
 */
final readonly class ConnectionRepository
{
    private const TABLE = 'tx_analyticsmatomo_connection';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {
    }

    public function find(MatomoConfiguration $configuration): ?int
    {
        $uid = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->select(['uid'], self::TABLE, ['identifier' => self::identifier($configuration)])
            ->fetchOne();
        return $uid === false ? null : (int)$uid;
    }

    public function getOrCreate(MatomoConfiguration $configuration): int
    {
        return $this->find($configuration) ?? $this->create($configuration);
    }

    /**
     * Inserts the connection; returns the existing one if another process inserted it first.
     *
     * @internal use getOrCreate(); public to test the race with another process
     */
    public function create(MatomoConfiguration $configuration): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        try {
            $connection->insert(
                self::TABLE,
                ['identifier' => self::identifier($configuration), 'url' => self::url($configuration), 'id_site' => $configuration->idSite],
                [Connection::PARAM_STR, Connection::PARAM_STR, Connection::PARAM_INT],
            );
            return (int)$connection->lastInsertId();
        } catch (UniqueConstraintViolationException $exception) {
            return $this->find($configuration) ?? throw $exception;
        }
    }

    private static function identifier(MatomoConfiguration $configuration): string
    {
        return hash('sha256', self::url($configuration) . "\n" . $configuration->idSite);
    }

    private static function url(MatomoConfiguration $configuration): string
    {
        return rtrim($configuration->url, '/');
    }
}
