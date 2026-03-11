<?php
declare(strict_types=1);

namespace Torresani\Redirectanalytics\EventListener;

use Psr\Log\LoggerInterface;
use Torresani\Redirectanalytics\Service\ConsentCheckerService;
use Torresani\Redirectanalytics\Service\Ga4MeasurementProtocolService;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Redirects\Event\RedirectWasHitEvent;

/**
 * Listens to RedirectWasHitEvent and sends a server-side hit to GA4.
 *
 */
class RedirectAnalyticsListener
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly Ga4MeasurementProtocolService $ga4Service,
        private readonly ConsentCheckerService $consentChecker,
    ) {
        $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
    }

    public function __invoke(RedirectWasHitEvent $event): void
    {
        try {
            $request = $event->getRequest();

            // 1. Consent check
            if (!$this->consentChecker->isTrackingAllowed($request)) {
                $this->logger->debug('RedirectAnalytics: tracking skipped – no consent.');
                return;
            }

            // 2. Extract source and target URLs
            $record    = $event->getMatchedRedirect();
            $sourceUrl = $this->buildSourceUrl($request);
            $targetUrl = $this->resolveTargetUrl($event, $record);

            // 3. Send to GA4 (non-blocking; errors are caught inside the service)
            $this->ga4Service->sendRedirectHit($sourceUrl, $targetUrl, $request);

        } catch (\Throwable $e) {
            // This listener must NEVER break the redirect, so catch everything.
            $this->logger->error('RedirectAnalytics: unexpected error in listener', [
                'message' => $e->getMessage(),
                'class'   => get_class($e),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Reconstructs the full source URL from the current request.
     */
    private function buildSourceUrl(mixed $request): string
    {
        $uri = $request->getUri();
        return (string)$uri;
    }

    /**
     * Resolves the final target URL.
     *
     */
    private function resolveTargetUrl(RedirectWasHitEvent $event, array $record): string
    {
        // getTargetUrl() is available on RedirectWasHitEvent in TYPO3 13
        $targetUrl = (string)$event->getTargetUrl();

        if ($targetUrl === '') {
            // Fallback: use raw record target
            $targetUrl = (string)($record['target'] ?? '');
        }

        return $targetUrl;
    }
}
