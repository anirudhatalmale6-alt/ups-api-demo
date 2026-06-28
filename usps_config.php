<?php
/**
 * USPS API Configuration & Helper Functions
 *
 * Pure PHP implementation using cURL - NO packages to install.
 * USPS does not provide an official PHP SDK.
 *
 * API reference: https://developers.usps.com/apis
 * Examples:      https://github.com/USPS/api-examples
 *
 * Set your credentials here ONCE - both pages use this file.
 */

// ============================================================================
// CREDENTIALS - Replace these with your USPS Developer Portal values
// Register at: https://developers.usps.com
// ============================================================================
define('USPS_CLIENT_ID',      'YOUR_CLIENT_ID');       // OAuth Consumer Key
define('USPS_CLIENT_SECRET',  'YOUR_CLIENT_SECRET');    // OAuth Consumer Secret
define('USPS_ACCOUNT_NUMBER', '');                      // EPS Account # (optional, for COMMERCIAL pricing)
define('USPS_CRID',           '');                      // Customer Registration ID (optional)
define('USPS_MID',            '');                      // Mailer ID (optional)

// true = test environment (apis-tem.usps.com), false = production (apis.usps.com)
define('USPS_SANDBOX', true);

// ============================================================================
// API ENDPOINTS
// Test:       https://apis-tem.usps.com
// Production: https://apis.usps.com
// ============================================================================
define('USPS_BASE_URL',       USPS_SANDBOX ? 'https://apis-tem.usps.com' : 'https://apis.usps.com');
define('USPS_OAUTH_URL',      USPS_BASE_URL . '/oauth2/v3/token');
define('USPS_ADDRESS_URL',    USPS_BASE_URL . '/addresses/v3/address');
define('USPS_CITYSTATE_URL',  USPS_BASE_URL . '/addresses/v3/city-state');
define('USPS_ZIPCODE_URL',    USPS_BASE_URL . '/addresses/v3/zipcode');
define('USPS_BASE_RATES_URL', USPS_BASE_URL . '/prices/v3/base-rates/search');
define('USPS_RATES_LIST_URL', USPS_BASE_URL . '/prices/v3/base-rates-list/search');
define('USPS_TOTAL_RATES_URL',USPS_BASE_URL . '/prices/v3/total-rates/search');
define('USPS_ESTIMATES_URL',  USPS_BASE_URL . '/service-standards/v3/estimates');
define('USPS_STANDARDS_URL',  USPS_BASE_URL . '/service-standards/v3/standards');

// ============================================================================
// MAIL CLASS DEFINITIONS
// ============================================================================
define('USPS_MAIL_CLASSES', [
    'PRIORITY_MAIL_EXPRESS'  => 'Priority Mail Express',
    'PRIORITY_MAIL'          => 'Priority Mail',
    'USPS_GROUND_ADVANTAGE'  => 'USPS Ground Advantage',
    'FIRST_CLASS_MAIL'       => 'First-Class Mail',
    'PARCEL_SELECT'          => 'Parcel Select',
    'MEDIA_MAIL'             => 'Media Mail',
    'LIBRARY_MAIL'           => 'Library Mail',
]);

define('USPS_PROCESSING_CATEGORIES', [
    'MACHINABLE'     => 'Machinable',
    'NON_MACHINABLE' => 'Non-Machinable',
    'IRREGULAR'      => 'Irregular',
    'PARCELS'        => 'Parcels',
]);

define('USPS_RATE_INDICATORS', [
    'SP' => 'Single Piece',
    'DR' => 'Dimensional Rectangular',
    'DN' => 'Dimensional Non-Rectangular',
    'CP' => 'Content-Based / Cubic',
]);

define('USPS_PRICE_TYPES', [
    'RETAIL'     => 'Retail',
    'COMMERCIAL' => 'Commercial',
]);

// ============================================================================
// AUTHENTICATION - OAuth 2.0 Client Credentials
// USPS uses JSON body (not Basic auth header like UPS)
// ============================================================================

function usps_get_token(): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!empty($_SESSION['usps_token']) && ($_SESSION['usps_token_expires'] ?? 0) > time()) {
        return ['token' => $_SESSION['usps_token']];
    }

    $payload = json_encode([
        'client_id'     => USPS_CLIENT_ID,
        'client_secret' => USPS_CLIENT_SECRET,
        'grant_type'    => 'client_credentials',
    ]);

    $ch = curl_init(USPS_OAUTH_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['error' => "NETWORK ERROR: $curlErr"];
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200) {
        $msg = $data['error_description'] ?? $data['error'] ?? "HTTP $httpCode";
        return ['error' => "AUTH ERROR: $msg (check Client ID / Secret)"];
    }

    if (empty($data['access_token'])) {
        return ['error' => 'AUTH ERROR: No access_token in response'];
    }

    $_SESSION['usps_token'] = $data['access_token'];
    $_SESSION['usps_token_expires'] = time() + (int)($data['expires_in'] ?? 3599) - 60;

    return [
        'token'      => $data['access_token'],
        'token_type' => $data['token_type'] ?? 'Bearer',
        'expires_in' => $data['expires_in'] ?? '',
        'source'     => USPS_SANDBOX ? 'USPS OAuth (test)' : 'USPS OAuth (production)',
    ];
}


// ============================================================================
// REST API HELPERS
// ============================================================================

/**
 * POST request to a USPS API endpoint.
 */
function usps_api_post(string $url, array $payload, string $token): array
{
    $jsonBody = json_encode($payload, JSON_UNESCAPED_SLASHES);

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
    ];
    if (USPS_CRID) {
        $headers[] = 'X-User-Id: ' . USPS_CRID;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $jsonBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['error' => "NETWORK ERROR: $curlErr"];
    }

    $data = json_decode($response, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        $errMsg = "HTTP $httpCode";
        if (isset($data['error']['message'])) {
            $errMsg = $data['error']['message'];
        } elseif (isset($data['errors'])) {
            $parts = [];
            foreach ((array)$data['errors'] as $e) {
                $parts[] = ($e['title'] ?? '') . ': ' . ($e['detail'] ?? $e['message'] ?? '');
            }
            $errMsg = implode(' | ', $parts);
        } elseif (isset($data['message'])) {
            $errMsg = $data['message'];
        } elseif (isset($data['error'])) {
            $errMsg = is_string($data['error']) ? $data['error'] : json_encode($data['error']);
        }
        return ['error' => $errMsg, 'http_code' => $httpCode, 'raw' => $data];
    }

    return ['data' => $data, 'http_code' => $httpCode];
}

/**
 * GET request to a USPS API endpoint with query parameters.
 */
function usps_api_get(string $url, array $params, string $token): array
{
    $queryStr = http_build_query(array_filter($params, fn($v) => $v !== '' && $v !== null));
    $fullUrl  = $url . ($queryStr ? '?' . $queryStr : '');

    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
    ];
    if (USPS_CRID) {
        $headers[] = 'X-User-Id: ' . USPS_CRID;
    }

    $ch = curl_init($fullUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['error' => "NETWORK ERROR: $curlErr"];
    }

    $data = json_decode($response, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        $errMsg = "HTTP $httpCode";
        if (isset($data['error']['message'])) {
            $errMsg = $data['error']['message'];
        } elseif (isset($data['errors'])) {
            $parts = [];
            foreach ((array)$data['errors'] as $e) {
                $parts[] = ($e['title'] ?? '') . ': ' . ($e['detail'] ?? $e['message'] ?? '');
            }
            $errMsg = implode(' | ', $parts);
        } elseif (isset($data['message'])) {
            $errMsg = $data['message'];
        }
        return ['error' => $errMsg, 'http_code' => $httpCode, 'raw' => $data];
    }

    return ['data' => $data, 'http_code' => $httpCode];
}


// ============================================================================
// SERVICE STANDARDS / DELIVERY ESTIMATES
// ============================================================================

/**
 * Get delivery estimates for all mail classes between two ZIP codes.
 * Returns associative array keyed by mail class code.
 */
function usps_get_all_estimates(string $originZip, string $destZip, string $acceptDate, string $token): array
{
    $estimates = [];
    foreach (array_keys(USPS_MAIL_CLASSES) as $mailClass) {
        $result = usps_api_get(USPS_ESTIMATES_URL, [
            'originZIPCode'      => $originZip,
            'destinationZIPCode' => $destZip,
            'acceptanceDate'     => $acceptDate,
            'mailClass'          => $mailClass,
        ], $token);

        if (!isset($result['error']) && !empty($result['data'])) {
            $estimates[$mailClass] = $result['data'];
        }
    }
    return $estimates;
}

/**
 * Extract estimated delivery date from service standards response.
 */
function usps_extract_delivery_date(array $estimateData): string
{
    // Try different response structures
    if (isset($estimateData['delivery']['scheduledDeliveryDateTime'])) {
        $dt = new DateTime($estimateData['delivery']['scheduledDeliveryDateTime']);
        return $dt->format('D, M j, Y');
    }
    if (isset($estimateData['estimatedDeliveryDate'])) {
        $dt = new DateTime($estimateData['estimatedDeliveryDate']);
        return $dt->format('D, M j, Y');
    }
    // Array of estimates
    if (isset($estimateData[0]['estimatedDeliveryDate'])) {
        $dt = new DateTime($estimateData[0]['estimatedDeliveryDate']);
        return $dt->format('D, M j, Y');
    }
    // Nested under mailClass key
    foreach ($estimateData as $entry) {
        if (is_array($entry) && isset($entry['estimatedDeliveryDate'])) {
            $dt = new DateTime($entry['estimatedDeliveryDate']);
            return $dt->format('D, M j, Y');
        }
        if (is_array($entry) && isset($entry['delivery']['scheduledDeliveryDateTime'])) {
            $dt = new DateTime($entry['delivery']['scheduledDeliveryDateTime']);
            return $dt->format('D, M j, Y');
        }
    }
    return '-';
}

/**
 * Extract delivery days from estimate data.
 */
function usps_extract_delivery_days(array $estimateData): string
{
    if (isset($estimateData['delivery']['deliveryDays'])) {
        return (string)$estimateData['delivery']['deliveryDays'];
    }
    if (isset($estimateData['deliveryDays'])) {
        return (string)$estimateData['deliveryDays'];
    }
    if (isset($estimateData[0]['deliveryDays'])) {
        return (string)$estimateData[0]['deliveryDays'];
    }
    foreach ($estimateData as $entry) {
        if (is_array($entry) && isset($entry['deliveryDays'])) {
            return (string)$entry['deliveryDays'];
        }
        if (is_array($entry) && isset($entry['delivery']['deliveryDays'])) {
            return (string)$entry['delivery']['deliveryDays'];
        }
    }
    return '-';
}


// ============================================================================
// UTILITY FUNCTIONS
// ============================================================================

function usps_clean(string $value): string
{
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

function usps_credentials_set(): bool
{
    return USPS_CLIENT_ID !== 'YOUR_CLIENT_ID'
        && USPS_CLIENT_SECRET !== 'YOUR_CLIENT_SECRET';
}

function usps_mail_class_name(string $code): string
{
    return USPS_MAIL_CLASSES[$code] ?? str_replace('_', ' ', ucwords(strtolower($code), '_'));
}

function usps_format_date(string $date): string
{
    try {
        $dt = new DateTime($date);
        return $dt->format('D, M j, Y');
    } catch (Exception $e) {
        return $date;
    }
}

/**
 * Build a base-rates payload from form inputs.
 */
function usps_build_rate_payload(array $post, string $mailClass = ''): array
{
    $payload = [
        'originZIPCode'      => usps_clean($post['from_zip'] ?? ''),
        'destinationZIPCode' => usps_clean($post['to_zip'] ?? ''),
        'weight'             => max(0.1, (float)($post['weight'] ?? 16)),
        'length'             => max(0.1, (float)($post['pkg_length'] ?? 10)),
        'width'              => max(0.1, (float)($post['pkg_width'] ?? 8)),
        'height'             => max(0.1, (float)($post['pkg_height'] ?? 6)),
        'mailingDate'        => usps_clean($post['mailing_date'] ?? date('Y-m-d')),
        'priceType'          => usps_clean($post['price_type'] ?? 'RETAIL'),
    ];

    if ($mailClass) {
        $payload['mailClass'] = $mailClass;
    }

    $procCat = usps_clean($post['processing_category'] ?? 'MACHINABLE');
    if ($procCat) {
        $payload['processingCategory'] = $procCat;
    }

    $rateInd = usps_clean($post['rate_indicator'] ?? 'SP');
    if ($rateInd) {
        $payload['rateIndicator'] = $rateInd;
    }

    $payload['destinationEntryFacilityType'] = 'NONE';

    $priceType = usps_clean($post['price_type'] ?? 'RETAIL');
    if ($priceType === 'COMMERCIAL' && USPS_ACCOUNT_NUMBER) {
        $payload['accountType']   = 'EPS';
        $payload['accountNumber'] = USPS_ACCOUNT_NUMBER;
    }

    return $payload;
}


// ============================================================================
// COMMON CSS (USPS-branded)
// ============================================================================

function usps_common_css(): string
{
    return <<<'CSS'
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        background: #f0f2f5; color: #333; line-height: 1.6;
    }
    .container { max-width: 1040px; margin: 0 auto; padding: 20px; }
    h1 { text-align: center; color: #004B87; margin-bottom: 5px; font-size: 1.8em; }
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
    .nav a.active { background: #004B87; color: #fff; }
    .nav a:not(.active) { background: #e0e0e0; color: #555; }
    .nav a:not(.active):hover { background: #ccc; }
    .card {
        background: #fff; padding: 25px; border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-bottom: 20px;
    }
    .card h2 { color: #004B87; margin-bottom: 15px; font-size: 1.2em;
        padding-bottom: 8px; border-bottom: 2px solid #f0f2f5; }
    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .form-group { display: flex; flex-direction: column; }
    .form-group.full { grid-column: 1 / -1; }
    label { font-size: 0.85em; font-weight: 600; color: #555; margin-bottom: 4px; }
    input, select {
        padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px;
        font-size: 0.95em; transition: border-color 0.2s;
    }
    input:focus, select:focus { outline: none; border-color: #004B87; }
    .btn {
        display: block; width: 100%; padding: 14px; background: #004B87;
        color: #fff; border: none; border-radius: 6px; font-size: 1.05em;
        font-weight: 600; cursor: pointer; margin-top: 15px; transition: background 0.2s;
    }
    .btn:hover { background: #003a6a; }
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
    table.results th { background: #f8f9fa; font-weight: 600; color: #004B87; font-size: 0.9em; }
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
    .badge-valid { background: #e8f5e9; color: #2e7d32; }
    .badge-dpv { background: #e3f2fd; color: #1565c0; }
    .badge-vacant { background: #fdecea; color: #b71c1c; }
    .candidate {
        background: #f8f9fa; border: 1px solid #e0e0e0;
        border-radius: 6px; padding: 12px 16px; margin-top: 10px;
    }
    .candidate p { margin-bottom: 4px; }
    .raw-toggle { margin-top: 15px; font-size: 0.85em; color: #888; cursor: pointer; }
    .raw-toggle:hover { color: #004B87; }
    .raw-json {
        display: none; background: #1e1e1e; color: #d4d4d4; padding: 15px;
        border-radius: 6px; margin-top: 8px; font-family: 'Consolas', monospace;
        font-size: 0.82em; overflow-x: auto; white-space: pre-wrap;
        max-height: 400px; overflow-y: auto;
    }
    .sep { margin: 25px 0; border: none; border-top: 1px solid #eee; }
    .hint { font-size: 0.8em; color: #888; margin-top: 2px; }
    @media (max-width: 600px) { .form-grid { grid-template-columns: 1fr; } }
CSS;
}
