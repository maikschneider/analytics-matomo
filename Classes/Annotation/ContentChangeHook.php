<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Annotation;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Messenger\MessageBusInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Reports changes visitors will see: saving pages and content elements in the
 * live workspace, deleting content elements and publishing from a workspace.
 * DataHandler has no PSR-14 event for these on TYPO3 13 and 14.
 */
#[Autoconfigure(public: true)]
final readonly class ContentChangeHook
{
    private const TABLES = ['pages', 'tt_content'];

    public function __construct(
        private MessageBusInterface $bus,
        private SiteFinder $siteFinder,
        private Context $context,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_afterDatabaseOperations(string $status, string $table, int|string $id, array $fieldArray, DataHandler $dataHandler): void
    {
        if ($dataHandler->BE_USER->workspace !== 0 || ($table === 'pages' && $status === 'new')) {
            return;
        }
        // Saving a record also refreshes the translation state of its translations, which changes nothing visible
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        $bookkeeping = [(string)($ctrl['tstamp'] ?? ''), (string)($ctrl['transOrigDiffSourceField'] ?? ''), 'l10n_state'];
        if (array_diff_key($fieldArray, array_flip($bookkeeping)) === []) {
            return;
        }
        $this->dispatch($table, (int)($dataHandler->substNEWwithIDs[$id] ?? $id), $dataHandler);
    }

    /**
     * @param array<string, mixed>|mixed $value
     */
    public function processCmdmap_postProcess(string $command, string $table, int|string $id, mixed $value, DataHandler $dataHandler): void
    {
        // Publishing a single record uses "publish", publishing a whole workspace "swap"
        $isPublish = $command === 'version' && is_array($value) && in_array($value['action'] ?? '', ['publish', 'swap'], true);
        $isLiveDelete = $command === 'delete' && $table === 'tt_content' && $dataHandler->BE_USER->workspace === 0;
        if ($isPublish || $isLiveDelete) {
            $this->dispatch($table, (int)$id, $dataHandler);
        }
    }

    private function dispatch(string $table, int $uid, DataHandler $dataHandler): void
    {
        if (!in_array($table, self::TABLES, true)) {
            return;
        }
        $record = BackendUtility::getRecord($table, $uid, '*', '', false);
        if ($record === null) {
            return;
        }
        $languageId = (int)($record['sys_language_uid'] ?? 0);
        $pageId = $table === 'pages' ? ($languageId > 0 ? (int)$record['l10n_parent'] : $uid) : (int)$record['pid'];
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
        } catch (SiteNotFoundException) {
            return;
        }
        if (!AnnotationService::isEnabled($site)) {
            return;
        }
        $languageIds = $languageId === -1
            ? array_map(static fn (SiteLanguage $language): int => $language->getLanguageId(), $site->getLanguages())
            : [$languageId];
        /** @var \DateTimeImmutable $now */
        $now = $this->context->getPropertyFromAspect('date', 'full');
        foreach ($languageIds as $id) {
            try {
                $this->bus->dispatch(new ContentChangedMessage(
                    $site->getIdentifier(),
                    $pageId,
                    $id,
                    (string)($dataHandler->BE_USER->user['username'] ?? ''),
                    $now->format('Y-m-d'),
                ));
            } catch (\Throwable $e) {
                $this->logger->warning('Reporting a content change for a Matomo annotation failed: {message}', ['message' => $e->getMessage(), 'pageId' => $pageId]);
            }
        }
    }
}
