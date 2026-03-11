# redirectanalytics – TYPO3 Extension

TYPO3 13 extension for server-side redirect tracking via **Google Analytics 4**.

Every time a URL managed by the TYPO3 Redirects module is hit, the extension sends an event directly to GA4 via Measurement Protocol, **before** the browser executes the redirect. This means the hit is counted even for redirects to external sites, with no dependency on any client-side JavaScript tag.

---

## Requirements

| Requirement | Version |
|---|---|
| TYPO3 | 13.x |
| PHP | 8.2+ |
| ext:redirects | included in TYPO3 core |

---

## Installation

Copy the `redirectanalytics/` folder into your project, for example under `packages/redirectanalytics/`, then add the local repository to the root `composer.json`:

```json
"repositories": [
    {
        "type": "path",
        "url": "./packages/redirectanalytics"
    }
]
```

Then install and activate:

```bash
composer require torresani/redirectanalytics:@dev
vendor/bin/typo3 extension:activate redirectanalytics
vendor/bin/typo3 cache:flush
```

---

## Configuration

### 1. Get your GA4 credentials

**Measurement ID**: go to Google Analytics → Admin → Data Streams → select your web stream. The Measurement ID is shown at the top in the format `G-XXXXXXXXXX`.

**API Secret**: on the same Data Stream page, scroll down to **Measurement Protocol API secrets** → Create → give it any name → copy the generated value. Save it immediately: it will not be shown again.

### 2. Configure the extension

Go to **Admin Tools → Settings → Extension Configuration → redirectanalytics** and fill in the fields:

**Google Analytics 4 section**

| Field | Description |
|---|---|
| `measurementId` | Your GA4 property Measurement ID, e.g. `G-XXXXXXXXXX` |
| `apiSecret` | The Measurement Protocol secret created above |
| `debugMode` | When enabled, sends to the GA4 debug endpoint and logs the response. Use only during testing — disable in production |
| `timeoutSeconds` | Maximum HTTP timeout for the call to GA4. Default is 2 seconds: a low value ensures a network issue never slows down the redirect |

**Privacy / Consent section**

| Field | Description |
|---|---|
| `consentStrategy` | Consent verification strategy (see below) |
| `consentCookieName` | Name of the cookie to check (only for `cookie` strategy) |
| `consentCookieValue` | Expected cookie value (only for `cookie` strategy) |

### 3. Consent strategy

| Strategy | Behaviour |
|---|---|
| `always` | Always track, no cookie check. Use only if you have no consent banner |
| `cookiebot` | Reads the CookieBot `CookieConsent` cookie and tracks only if `statistics:true` |
| `cookie` | Checks that a specific cookie (configurable name and value) is present. Compatible with Klaro and custom banners |
| `none` | Tracking completely disabled without uninstalling the extension |

#### Klaro note

Klaro saves consent in **localStorage** by default, which is not accessible server-side. To make it work, add `storageMethod: 'cookie'` to your Klaro configuration so it also writes a `klaro` cookie:

```javascript
var klaroConfig = {
    storageMethod: 'cookie',
    cookieName: 'klaro',
    cookieExpiresAfterDays: 365,
    // ...
};
```

Then set the extension fields as follows:

| Field | Value |
|---|---|
| `consentStrategy` | `cookie` |
| `consentCookieName` | `klaro` |
| `consentCookieValue` | `google-analytics-7` (or the exact service name used in your Klaro config) |

---

## How it works

```
User hits a redirect URL
            │
            ▼
    TYPO3 ext:redirects
    dispatches RedirectWasHitEvent
            │
            ▼
    RedirectAnalyticsListener
            │
            ├─ ConsentCheckerService
            │    consent denied → skip, normal redirect
            │    consent granted ↓
            ▼
    Ga4MeasurementProtocolService
            │
            ├─ extracts client_id from _ga cookie
            ├─ extracts session_id from _ga_STREAMID cookie
            ├─ extracts real browser User-Agent
            │
            └─ POST /mp/collect → Google Analytics 4
            │
            ▼
    TYPO3 completes HTTP 307 redirect
```

The listener never blocks the redirect: it uses a configurable timeout and catches all exceptions internally, ensuring any GA4 or network issue is completely transparent to the user.

---

## GA4 event

The event is named `redirect_hit` and includes the following parameters:

| Parameter | Content |
|---|---|
| `source_url` | Original URL that received the redirect |
| `destination_url` | Destination URL |
| `page_location` | Same as source_url |
| `page_referrer` | HTTP referrer of the request |
| `session_id` | Extracted from the GA4 stream cookie to associate the event with the correct session |
| `debug_mode` | Present only when debugMode is enabled |

To find it in GA4: **Reports → Configure → Events** or **Explore → Event Explorer**, filtering by `event_name = redirect_hit`. In DebugView it appears in real time during testing.

---

## Enabling debug logs

Add the following to `config/system/additional.php`:

```php
$GLOBALS['TYPO3_CONF_VARS']['LOG']['Torresani']['Redirectanalytics'] = [
    'writerConfiguration' => [
        \TYPO3\CMS\Core\Log\LogLevel::DEBUG => [
            \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [
                'logFileInfix' => 'redirectanalytics',
            ],
        ],
    ],
];
```

Logs are written to `var/log/typo3_redirectanalytics_*.log`. Monitor them in real time with:

```bash
tail -f var/log/typo3_redirectanalytics_*.log
```

Possible log messages:

| Message | Meaning |
|---|---|
| `extension not configured` | Measurement ID or API Secret missing |
| `tracking skipped – no consent` | Consent not given, normal redirect proceeds |
| `event sent to GA4` | Everything ok, event registered |
| `GA4 returned non-2xx status` | GA4 responded with an error |
| `exception while sending` | Network or firewall issue |

---

## GDPR notes

The user's IP address is never sent to GA4: the `user_ip_address` parameter is intentionally omitted. GA4 will use the server IP for any aggregate geographic processing. The `client_id` is extracted from the `_ga` cookie when available; if absent, an anonymous hash-based ID is generated that does not persist across sessions. To respect user consent, use the `cookiebot` or `cookie` strategy.

---

## File structure

```
redirectanalytics/
├── Classes/
│   ├── EventListener/
│   │   └── RedirectAnalyticsListener.php      ← PSR-14 hook on RedirectWasHitEvent
│   └── Service/
│       ├── ConsentCheckerService.php           ← cookie consent verification
│       └── Ga4MeasurementProtocolService.php  ← HTTP Measurement Protocol call
├── Configuration/
│   ├── Services.yaml                           ← listener registration in DI container
│   └── ExtensionConfiguration.yaml            ← Extension Manager field definitions
├── ext_conf_template.txt                       ← Extension Manager configuration
├── ext_emconf.php
├── composer.json
└── README.md
```