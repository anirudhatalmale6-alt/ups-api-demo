<?php
/**
 * UPS API Configuration & Helper Functions
 *
 * Uses the OFFICIAL UPS PHP SDK for OAuth authentication:
 *   https://github.com/UPS-API/UPS-SDKs (PHP branch)
 *
 * The UPS SDK provides the ClientCredentialService class for OAuth token
 * generation. Rating and Address Validation are REST API calls (UPS does
 * not provide PHP classes for those - only OpenAPI specs):
 *   https://github.com/UPS-API/api-documentation
 *
 * Set your credentials here ONCE - both pages use this file.
 */

// ============================================================================
// LOAD THE OFFICIAL UPS PHP SDK
// Source: https://github.com/UPS-API/UPS-SDKs/tree/PHP/UPS_PHP_ClientCredential_Sdk
// ============================================================================
require_once __DIR__ . '/UPS_PHP_ClientCredential_Sdk/src/ClientCredentialConstants.php';
require_once __DIR__ . '/UPS_PHP_ClientCredential_Sdk/src/HttpClient.php';
require_once __DIR__ . '/UPS_PHP_ClientCredential_Sdk/src/TokenInfo.php';
require_once __DIR__ . '/UPS_PHP_ClientCredential_Sdk/src/UPSOauthResponse.php';
require_once __DIR__ . '/UPS_PHP_ClientCredential_Sdk/src/ClientCredentialService.php';

use UpsPhpClientCredentialSdk\ClientCredentialService;
use UpsPhpClientCredentialSdk\HttpClient;

// ============================================================================
// CREDENTIALS - Replace these with your UPS Developer Portal values
// ============================================================================
define('UPS_CLIENT_ID',      'YOUR_CLIENT_ID');       // OAuth Client ID
define('UPS_CLIENT_SECRET',  'YOUR_CLIENT_SECRET');    // OAuth Client Secret
define('UPS_ACCOUNT_NUMBER', 'YOUR_ACCOUNT_NUMBER');   // 6-digit UPS shipper number

// true = sandbox (test), false = production (live rates)
define('UPS_SANDBOX', true);

// ============================================================================
// API ENDPOINTS
// The UPS SDK hardcodes the production OAuth URL. For sandbox, we override it
// by subclassing. Rating & Address Validation endpoints set here.
// Docs: https://github.com/UPS-API/api-documentation
// ============================================================================
define('UPS_BASE_URL',    UPS_SANDBOX ? 'https://wwwcie.ups.com' : 'https://onlinetools.ups.com');
define('UPS_OAUTH_URL',   UPS_BASE_URL . '/security/v1/oauth/token');
define('UPS_RATING_URL',  UPS_BASE_URL . '/api/rating/v1/Shop');          // Returns ALL services
define('UPS_RATE_URL',    UPS_BASE_URL . '/api/rating/v1/Rate');          // Single service
define('UPS_ADDRESS_URL', UPS_BASE_URL . '/api/addressvalidation/v1/3');  // 3 = validate + classify
define('UPS_TIT_URL',     UPS_BASE_URL . '/api/shipments/v1/transittimes');

// ============================================================================
// UPS SERVICE CODES
// From: https://github.com/UPS-API/api-documentation/blob/main/Rating.yaml
// ============================================================================
define('UPS_SERVICES', [
    '01' => 'UPS Next Day Air',
    '02' => 'UPS 2nd Day Air',
    '03' => 'UPS Ground',
    '07' => 'UPS Worldwide Express',
    '08' => 'UPS Worldwide Expedited',
    '11' => 'UPS Standard',
    '12' => 'UPS 3 Day Select',
    '13' => 'UPS Next Day Air Saver',
    '14' => 'UPS Next Day Air Early',
    '54' => 'UPS Worldwide Express Plus',
    '59' => 'UPS 2nd Day Air A.M.',
    '65' => 'UPS Worldwide Saver',
    '82' => 'UPS Today Standard',
    '83' => 'UPS Today Dedicated Courier',
    '84' => 'UPS Today Intercity',
    '85' => 'UPS Today Express',
    '86' => 'UPS Today Express Saver',
    '92' => 'UPS SurePost Less than 1LB',
    '93' => 'UPS SurePost 1LB or Greater',
    '94' => 'UPS SurePost Bound Printed Matter',
    '95' => 'UPS SurePost Media Mail',
    '96' => 'UPS Worldwide Express Freight',
]);

// ============================================================================
// PACKAGING TYPE CODES
// ============================================================================
define('UPS_PACKAGING', [
    '00' => 'Unknown',
    '01' => 'UPS Letter',
    '02' => 'Customer Supplied Package',
    '03' => 'Tube',
    '04' => 'PAK',
    '21' => 'UPS Express Box',
    '24' => 'UPS 25KG Box',
    '25' => 'UPS 10KG Box',
    '30' => 'Pallet',
    '2a' => 'Small Express Box',
    '2b' => 'Medium Express Box',
    '2c' => 'Large Express Box',
]);


// ============================================================================
// AUTHENTICATION USING OFFICIAL UPS SDK
// ============================================================================

/**
 * Get an OAuth 2.0 access token using the official UPS ClientCredentialService.
 *
 * The SDK's ClientCredentialService handles the OAuth flow internally.
 * We wrap it here to add session-based caching and sandbox support.
 *
 * SDK source: https://github.com/UPS-API/UPS-SDKs/tree/PHP/UPS_PHP_ClientCredential_Sdk
 */
function ups_get_token(): array
{
    // Session-based token caching (token valid ~4 hours)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!empty($_SESSION['ups_token']) && ($_SESSION['ups_token_expires'] ?? 0) > time()) {
        return ['token' => $_SESSION['ups_token']];
    }

    // The official SDK's ClientCredentialService uses ClientCredentialConstants::BASE_URL
    // which is hardcoded to production. For sandbox support, we make a direct call
    // using the SDK's HttpClient class with our configured URL.
    $httpClient = new HttpClient();

    if (UPS_SANDBOX) {
        // Sandbox: use SDK's HttpClient directly with sandbox OAuth URL
        $authorization = "Basic " . base64_encode(UPS_CLIENT_ID . ':' . UPS_CLIENT_SECRET);
        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: ' . $authorization,
        ];
        $postFields = 'grant_type=client_credentials&scope=public';
        $response = $httpClient->post(UPS_OAUTH_URL, $headers, $postFields);

        if ($response['status_code'] == 200) {
            $data = json_decode($response['response'], true);
            if (!empty($data['access_token'])) {
                $_SESSION['ups_token'] = $data['access_token'];
                $_SESSION['ups_token_expires'] = time() + (int)($data['expires_in'] ?? 14399) - 60;
                return [
                    'token'      => $data['access_token'],
                    'expires_in' => $data['expires_in'] ?? '',
                    'issued_at'  => $data['issued_at'] ?? '',
                    'source'     => 'UPS SDK HttpClient (sandbox)',
                ];
            }
        }
        $errData = json_decode($response['response'], true);
        $msg = $errData['error_description'] ?? $errData['error'] ?? "HTTP {$response['status_code']}";
        return ['error' => "AUTH ERROR: $msg (check Client ID / Secret)"];
    }

    // Production: use the full official SDK ClientCredentialService
    $service = new ClientCredentialService($httpClient);
    $oauthResponse = $service->getAccessToken(UPS_CLIENT_ID, UPS_CLIENT_SECRET, [], []);

    $tokenInfo = $oauthResponse->getResponse();
    if ($tokenInfo && $tokenInfo->getAccessToken()) {
        $_SESSION['ups_token'] = $tokenInfo->getAccessToken();
        $_SESSION['ups_token_expires'] = time() + (int)($tokenInfo->getExpiresIn() ?? 14399) - 60;
        return [
            'token'      => $tokenInfo->getAccessToken(),
            'expires_in' => $tokenInfo->getExpiresIn(),
            'issued_at'  => $tokenInfo->getIssuedAt(),
            'status'     => $tokenInfo->getStatus(),
            'source'     => 'UPS SDK ClientCredentialService (production)',
        ];
    }

    // Auth failed - extract error from SDK response
    $error = $oauthResponse->getError();
    $msg = $error ? $error->getMessage() : 'Unknown authentication error';
    return ['error' => "AUTH ERROR: $msg (check Client ID / Secret on UPS Developer Portal)"];
}


// ============================================================================
// REST API HELPER (Rating, Address Validation, etc.)
//
// UPS does not provide PHP SDK classes for these APIs - only OpenAPI specs:
// https://github.com/UPS-API/api-documentation
//
// We use the SDK's HttpClient for consistent HTTP handling.
// ============================================================================

/**
 * Make an authenticated POST request to a UPS REST API endpoint.
 * Uses the UPS SDK's HttpClient internally for HTTP transport.
 */
function ups_api_call(string $url, array $payload, string $token): array
{
    $httpClient = new HttpClient(30);
    $jsonBody = json_encode($payload);

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
        'transId: ' . uniqid('ups-demo-'),
        'transactionSrc: ups-php-demo',
    ];

    try {
        $response = $httpClient->post($url, $headers, $jsonBody);
    } catch (\Exception $e) {
        return ['error' => "NETWORK ERROR: " . $e->getMessage()];
    }

    $data = json_decode($response['response'], true);

    if ($response['status_code'] < 200 || $response['status_code'] >= 300) {
        $errMsg = "HTTP {$response['status_code']}";
        if (isset($data['response']['errors'])) {
            $parts = [];
            foreach ($data['response']['errors'] as $e) {
                $parts[] = "[{$e['code']}] {$e['message']}";
            }
            $errMsg = implode(' | ', $parts);
        } elseif (isset($data['Fault']['detail']['Errors']['ErrorDetail'])) {
            $detail = $data['Fault']['detail']['Errors']['ErrorDetail'];
            $errMsg = "[{$detail['PrimaryErrorCode']['Code']}] " . ($detail['PrimaryErrorCode']['Description'] ?? 'Unknown');
        }
        return ['error' => $errMsg, 'http_code' => $response['status_code'], 'raw' => $data];
    }

    return ['data' => $data, 'http_code' => $response['status_code']];
}


// ============================================================================
// UTILITY FUNCTIONS
// ============================================================================

function ups_clean(string $value): string
{
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

function ups_credentials_set(): bool
{
    return UPS_CLIENT_ID !== 'YOUR_CLIENT_ID'
        && UPS_CLIENT_SECRET !== 'YOUR_CLIENT_SECRET'
        && UPS_ACCOUNT_NUMBER !== 'YOUR_ACCOUNT_NUMBER';
}

function ups_service_name(string $code): string
{
    return UPS_SERVICES[$code] ?? "UPS Service $code";
}

function ups_format_date(string $upsDate): string
{
    if (strlen($upsDate) !== 8) return $upsDate;
    $dt = DateTime::createFromFormat('Ymd', $upsDate);
    return $dt ? $dt->format('D, M j, Y') : $upsDate;
}

function ups_format_time(string $upsTime): string
{
    if (strlen($upsTime) < 4) return $upsTime;
    $h = substr($upsTime, 0, 2);
    $m = substr($upsTime, 2, 2);
    return date('g:i A', mktime((int)$h, (int)$m));
}

/**
 * Common CSS used across both pages.
 */
function ups_common_css(): string
{
    return <<<'CSS'
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        background: #f0f2f5; color: #333; line-height: 1.6;
    }
    .container { max-width: 1040px; margin: 0 auto; padding: 20px; }
    h1 { text-align: center; color: #351C15; margin-bottom: 5px; font-size: 1.8em; }
    .subtitle { text-align: center; color: #666; margin-bottom: 25px; font-size: 0.95em; }
    .env-badge {
        display: inline-block; padding: 2px 10px; border-radius: 12px;
        font-size: 0.75em; font-weight: 600; color: #fff;
    }
    .env-sandbox { background: #e67e22; }
    .env-production { background: #27ae60; }
    .nav { display: flex; gap: 10px; justify-content: center; margin-bottom: 20px; }
    .nav a {
        padding: 8px 20px; border-radius: 6px; text-decoration: none;
        font-weight: 600; font-size: 0.9em; transition: all 0.2s;
    }
    .nav a.active { background: #351C15; color: #fff; }
    .nav a:not(.active) { background: #e0e0e0; color: #555; }
    .nav a:not(.active):hover { background: #ccc; }
    .card {
        background: #fff; padding: 25px; border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-bottom: 20px;
    }
    .card h2 { color: #351C15; margin-bottom: 15px; font-size: 1.2em;
        padding-bottom: 8px; border-bottom: 2px solid #f0f2f5; }
    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .form-group { display: flex; flex-direction: column; }
    .form-group.full { grid-column: 1 / -1; }
    label { font-size: 0.85em; font-weight: 600; color: #555; margin-bottom: 4px; }
    input, select {
        padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px;
        font-size: 0.95em; transition: border-color 0.2s;
    }
    input:focus, select:focus { outline: none; border-color: #351C15; }
    .btn {
        display: block; width: 100%; padding: 14px; background: #351C15;
        color: #fff; border: none; border-radius: 6px; font-size: 1.05em;
        font-weight: 600; cursor: pointer; margin-top: 15px; transition: background 0.2s;
    }
    .btn:hover { background: #4a2920; }
    .btn:disabled { background: #aaa; cursor: not-allowed; }
    .alert { padding: 14px 18px; border-radius: 6px; margin-bottom: 20px; font-weight: 500; }
    .alert-error { background: #fdecea; color: #b71c1c; border-left: 4px solid #b71c1c; }
    .alert-warn { background: #fff8e1; color: #e65100; border-left: 4px solid #e67e22; }
    .alert-success { background: #e8f5e9; color: #2e7d32; border-left: 4px solid #27ae60; }
    .alert-info { background: #e3f2fd; color: #1565c0; border-left: 4px solid #1565c0; }
    table.results { width: 100%; border-collapse: collapse; margin-top: 15px; }
    table.results th, table.results td {
        padding: 10px 14px; text-align: left; border-bottom: 1px solid #eee;
    }
    table.results th { background: #f8f9fa; font-weight: 600; color: #351C15; font-size: 0.9em; }
    table.results tr:hover td { background: #fafafa; }
    .price { font-weight: 700; color: #27ae60; }
    .badge {
        display: inline-block; padding: 3px 8px; border-radius: 4px;
        font-size: 0.8em; font-weight: 600;
    }
    .badge-res { background: #e3f2fd; color: #1565c0; }
    .badge-com { background: #f3e5f5; color: #7b1fa2; }
    .badge-yes { background: #e8f5e9; color: #2e7d32; }
    .badge-no { background: #fdecea; color: #b71c1c; }
    .badge-warn { background: #fff8e1; color: #e65100; }
    .candidate {
        background: #f8f9fa; border: 1px solid #e0e0e0;
        border-radius: 6px; padding: 12px 16px; margin-top: 10px;
    }
    .candidate p { margin-bottom: 4px; }
    .raw-toggle { margin-top: 15px; font-size: 0.85em; color: #888; cursor: pointer; }
    .raw-toggle:hover { color: #351C15; }
    .raw-json {
        display: none; background: #1e1e1e; color: #d4d4d4; padding: 15px;
        border-radius: 6px; margin-top: 8px; font-family: 'Consolas', monospace;
        font-size: 0.82em; overflow-x: auto; white-space: pre-wrap;
        max-height: 400px; overflow-y: auto;
    }
    .sep { margin: 25px 0; border: none; border-top: 1px solid #eee; }
    @media (max-width: 600px) { .form-grid { grid-template-columns: 1fr; } }
CSS;
}
