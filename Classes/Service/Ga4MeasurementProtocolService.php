<?php
declare(strict_types=1);

namespace Torresani\Redirectanalytics\Service;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class Ga4MeasurementProtocolService
{
    private const GA4_ENDPOINT = 'https://www.google-analytics.com/mp/collect';

    private string $measurementId;
    private string $apiSecret;
    private bool $debugMode;
    private int $timeoutSeconds;
    private LoggerInterface $logger;

    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {
        $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
        $config = $this->loadExtensionConfig();
        $this->measurementId  = (string)($config['measurementId'] ?? '');
        $this->apiSecret      = (string)($config['apiSecret'] ?? '');
        $this->debugMode      = (bool)($config['debugMode'] ?? false);
        $this->timeoutSeconds = (int)($config['timeoutSeconds'] ?? 2);
    }

    public function sendRedirectHit(string $sourceUrl, string $targetUrl, ServerRequestInterface $request): bool
    {
        if (!$this->isConfigured()) {
            $this->logger->info('RedirectAnalytics: extension not configured, skipping.');
            return false;
        }

        $clientId = $this->extractClientId($request);
        if ($clientId === '') {
            $clientId = $this->generateAnonymousClientId($request);
        }

        // Extract the session ID (new fundamental step)
        $sessionId = $this->extractSessionId($request);

        $payload = $this->buildPayload($clientId, $sessionId, $sourceUrl, $targetUrl, $request);
        return $this->dispatch($payload);
    }

    private function isConfigured(): bool
    {
        return $this->measurementId !== '' && $this->apiSecret !== '';
    }

    private function extractClientId(ServerRequestInterface $request): string
    {
        $cookies = $request->getCookieParams();
        $gaCookie = $cookies['_ga'] ?? '';
        if ($gaCookie === '') return '';

        $parts = explode('.', $gaCookie);
        if (count($parts) >= 4) {
            return $parts[2] . '.' . $parts[3];
        }
        return '';
    }

    /**
     * Extracts the session_id from the GA4 cookie to avoid orphaned events
     *
     */
    private function extractSessionId(ServerRequestInterface $request): string
    {
        $cookies = $request->getCookieParams();
        // Look for the stream-specific cookie, e.g.: _ga_KTRDSE4GBS
        $streamId = str_replace('G-', '', $this->measurementId);
        $sessionCookie = $cookies['_ga_' . $streamId] ?? '';

        if ($sessionCookie !== '') {
            // Format: GS1.1.1772807259.1.0.0.0 -> The timestamp (session_id) is the third party
            $parts = explode('.', $sessionCookie);
            if (isset($parts[2]) && is_numeric($parts[2])) {
                return $parts[2];
            }
        }

        // Fallback: if not, we pass the current timestamp so GA4 creates a session
        return (string)time();
    }

    private function generateAnonymousClientId(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        $ip = $serverParams['REMOTE_ADDR'] ?? '';
        $ua = $serverParams['HTTP_USER_AGENT'] ?? '';
        $hash = crc32($ip . $ua . date('Ymd'));
        return sprintf('%u.%u', abs($hash), time());
    }

    private function buildPayload(string $clientId, string $sessionId, string $sourceUrl, string $targetUrl, ServerRequestInterface $request): array
    {
        $serverParams = $request->getServerParams();

        $params = [
            'source_url'           => $sourceUrl,
            'destination_url'      => $targetUrl,
            'engagement_time_msec' => 100,
            'page_location'        => $sourceUrl,
            'page_referrer'        => $serverParams['HTTP_REFERER'] ?? '',
            'session_id'           => $sessionId,
        ];

        if ($this->debugMode) {
            $params['debug_mode'] = 1;
        }

        // Calculate the exact timestamp in microseconds
        $timestampMicros = (int)(microtime(true) * 1000000);

        return [
            'client_id'            => $clientId,
            'timestamp_micros'     => $timestampMicros,
            'non_personalized_ads' => false,
            'events' => [
                [
                    'name'   => 'redirect_hit',
                    'params' => $params,
                ],
            ],
        ];
    }
    private function dispatch(array $payload): bool
    {
        $url = sprintf(
            '%s?measurement_id=%s&api_secret=%s',
            self::GA4_ENDPOINT,
            urlencode($this->measurementId),
            urlencode($this->apiSecret)
        );

        // Extracts the User-Agent from the payload
        // If it's missing, we'll set up a fake generic browser to avoid anti-bot filters.
        $userAgent = $payload['user_agent'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

        // Remove the user_agent from the JSON body because GA4 doesn't need it there.
        unset($payload['user_agent']);

        try {
            $response = $this->requestFactory->request(
                $url,
                'POST',
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'User-Agent'   => $userAgent,
                    ],
                    'body'    => json_encode($payload, JSON_THROW_ON_ERROR),
                    'timeout' => $this->timeoutSeconds,
                    'http_errors' => false,
                ]
            );

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info('RedirectAnalytics: event SUCCESS', ['payload' => $payload]);
                return true;
            }

            $this->logger->notice('RedirectAnalytics: GA4 Error', ['status' => $statusCode]);
            return false;

        } catch (\Throwable $e) {
            $this->logger->notice('RedirectAnalytics: exception', ['msg' => $e->getMessage()]);
            return false;
        }
    }

    private function loadExtensionConfig(): array
    {
        try {
            $extConfig = GeneralUtility::makeInstance(ExtensionConfiguration::class);
            return $extConfig->get('redirectanalytics') ?? [];
        } catch (\Throwable) {
            return [];
        }
    }
}