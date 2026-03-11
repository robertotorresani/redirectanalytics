<?php
declare(strict_types=1);

namespace Torresani\Redirectanalytics\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Checks whether the user has given analytics consent.
 *
 * Strategy (configurable via Extension Manager):
 *
 *  - 'always'       → always track (no consent check, use only if no consent banner)
 *  - 'cookie'       → check for a specific cookie name/value (CookieBot, Klaro, custom)
 *  - 'cookiebot'    → native CookieBot: checks CookieConsent cookie for statistics:true
 *  - 'none'         → never track (disable extension without uninstalling)
 *
 * The strategy is set in the Extension Manager field "consentStrategy".
 */
class ConsentCheckerService
{
    private string $strategy;
    private string $cookieName;
    private string $cookieValue;

    public function __construct()
    {
        $config = $this->loadExtensionConfig();
        $this->strategy    = (string)($config['consentStrategy'] ?? 'always');
        $this->cookieName  = (string)($config['consentCookieName'] ?? 'analytics_consent');
        $this->cookieValue = (string)($config['consentCookieValue'] ?? '1');
    }

    /**
     * Returns true if analytics tracking is allowed for this request.
     */
    public function isTrackingAllowed(ServerRequestInterface $request): bool
    {
        return match ($this->strategy) {
            'always'    => true,
            'none'      => false,
            'cookiebot' => $this->checkCookieBot($request),
            'cookie'    => $this->checkGenericCookie($request),
            default     => true,
        };
    }

    // -------------------------------------------------------------------------
    // Strategy implementations
    // -------------------------------------------------------------------------

    /**
     * CookieBot: the CookieConsent cookie contains a URL-encoded string like:
     *   necessary:true|preferences:true|statistics:true|marketing:false
     * We look for "statistics:true".
     */
    private function checkCookieBot(ServerRequestInterface $request): bool
    {
        $cookies = $request->getCookieParams();
        $raw = $cookies['CookieConsent'] ?? '';

        if ($raw === '') {
            return false;
        }

        $decoded = urldecode($raw);
        return str_contains($decoded, 'statistics:true');
    }

    /**
     * Generic cookie strategy: checks that a named cookie equals the expected value.
     * Works with Klaro (cookie value "true") or any custom implementation.
     */
    private function checkGenericCookie(ServerRequestInterface $request): bool
    {
        $cookies = $request->getCookieParams();
        $raw = $cookies[$this->cookieName] ?? null;

        if ($raw === null) {
            return false;
        }

        $decoded = json_decode(urldecode($raw), true);
        if (!is_array($decoded)) {
            return false;
        }

        return ($decoded[$this->cookieValue] ?? false) === true;
    }
    private function loadExtensionConfig(): array
    {
        try {
            /** @var ExtensionConfiguration $extConfig */
            $extConfig = GeneralUtility::makeInstance(ExtensionConfiguration::class);
            return $extConfig->get('redirectanalytics') ?? [];
        } catch (\Throwable) {
            return [];
        }
    }
}
