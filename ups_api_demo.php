<?php
/**
 * UPS API Demo Page - Rates & Address Validation
 *
 * Single-file PHP demo using the UPS REST API (OAuth 2.0).
 * Drop in your credentials below, upload to any PHP 7.4+ server, and test.
 *
 * Capabilities:
 *   1. Rate Shopping  - get cost, transit time, and all available services for a shipment
 *   2. Address Check   - validate any US address (residential + commercial classification)
 *
 * Requirements: PHP 7.4+, cURL extension, JSON extension
 */

// ============================================================================
// CREDENTIALS - Replace with your UPS Developer Portal values
// ============================================================================
$UPS_CLIENT_ID     = 'YOUR_CLIENT_ID';      // From UPS Developer Portal
$UPS_CLIENT_SECRET = 'YOUR_CLIENT_SECRET';   // From UPS Developer Portal
$UPS_ACCOUNT_NUMBER = 'YOUR_ACCOUNT_NUMBER'; // Your 6-digit UPS shipper number

// Set to true for sandbox (testing), false for production (live rates)
$USE_SANDBOX = true;

// ============================================================================
// API ENDPOINTS
// ============================================================================
$BASE_URL = $USE_SANDBOX
    ? 'https://wwwcie.ups.com'
    : 'https://onlinetools.ups.com';

$OAUTH_URL   = $BASE_URL . '/security/v1/oauth/token';
$RATING_URL  = $BASE_URL . '/api/rating/v1/Shop';        // "Shop" returns ALL services
$ADDRESS_URL = $BASE_URL . '/api/addressvalidation/v1/1'; // requestOption 1 = Address Validation

// UPS service code lookup
$UPS_SERVICES = [
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
    '96' => 'UPS Worldwide Express Freight',
];


// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Get an OAuth 2.0 access token using client credentials grant.
 * Token is valid for ~4 hours; in production you would cache it.
 */
function getAccessToken(string $oauthUrl, string $clientId, string $clientSecret): array
{
    $ch = curl_init($oauthUrl);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
            // Basic auth = base64(client_id:client_secret)
            'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['error' => "cURL error: $curlError"];
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200 || empty($data['access_token'])) {
        $msg = $data['error_description'] ?? $data['error'] ?? "HTTP $httpCode - Authentication failed";
        return ['error' => "OAuth error: $msg"];
    }

    return ['token' => $data['access_token']];
}

/**
 * Make an authenticated request to a UPS REST endpoint.
 */
function upsRequest(string $url, array $payload, string $token, string $method = 'POST'): array
{
    $ch = curl_init($url);

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
        'transId: demo-' . uniqid(),        // optional transaction ID for debugging
        'transactionSrc: ups-php-demo',      // identifies your integration
    ];

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ];

    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    }

    curl_setopt_array($ch, $opts);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['error' => "cURL error: $curlError"];
    }

    $data = json_decode($response, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        // Extract UPS-specific error details when available
        $errMsg = '';
        if (isset($data['response']['errors'])) {
            foreach ($data['response']['errors'] as $e) {
                $errMsg .= "[{$e['code']}] {$e['message']} ";
            }
        } elseif (isset($data['Fault'])) {
            $errMsg = $data['Fault']['detail']['Errors']['ErrorDetail']['PrimaryErrorCode']['Description'] ?? 'Unknown UPS fault';
        } else {
            $errMsg = "HTTP $httpCode";
        }
        return ['error' => trim($errMsg), 'raw' => $data];
    }

    return ['data' => $data];
}

/**
 * Build the JSON payload for a Rate Shop request.
 */
function buildRatePayload(
    string $accountNumber,
    array $fromAddress,
    array $toAddress,
    float $weightLbs
): array {
    return [
        'RateRequest' => [
            'Request' => [
                'SubVersion' => '2205',
                'TransactionReference' => [
                    'CustomerContext' => 'Rate Shopping Demo',
                ],
            ],
            'Shipment' => [
                'Shipper' => [
                    'Name' => $fromAddress['name'] ?? 'Sender',
                    'ShipperNumber' => $accountNumber,
                    'Address' => [
                        'AddressLine'       => [$fromAddress['street']],
                        'City'              => $fromAddress['city'],
                        'StateProvinceCode' => $fromAddress['state'],
                        'PostalCode'        => $fromAddress['zip'],
                        'CountryCode'       => $fromAddress['country'] ?? 'US',
                    ],
                ],
                'ShipTo' => [
                    'Name' => $toAddress['name'] ?? 'Receiver',
                    'Address' => [
                        'AddressLine'       => [$toAddress['street']],
                        'City'              => $toAddress['city'],
                        'StateProvinceCode' => $toAddress['state'],
                        'PostalCode'        => $toAddress['zip'],
                        'CountryCode'       => $toAddress['country'] ?? 'US',
                    ],
                ],
                'ShipFrom' => [
                    'Name' => $fromAddress['name'] ?? 'Sender',
                    'Address' => [
                        'AddressLine'       => [$fromAddress['street']],
                        'City'              => $fromAddress['city'],
                        'StateProvinceCode' => $fromAddress['state'],
                        'PostalCode'        => $fromAddress['zip'],
                        'CountryCode'       => $fromAddress['country'] ?? 'US',
                    ],
                ],
                'Package' => [
                    'PackagingType' => [
                        'Code' => '02', // 02 = Customer Supplied Package
                    ],
                    'PackageWeight' => [
                        'UnitOfMeasurement' => [
                            'Code' => 'LBS',
                        ],
                        'Weight' => (string) $weightLbs,
                    ],
                    'Dimensions' => [
                        'UnitOfMeasurement' => [
                            'Code' => 'IN',
                        ],
                        'Length' => '10',
                        'Width'  => '8',
                        'Height' => '6',
                    ],
                ],
            ],
        ],
    ];
}

/**
 * Build the JSON payload for an Address Validation request.
 */
function buildAddressPayload(array $address): array
{
    return [
        'XAVRequest' => [
            'AddressKeyFormat' => [
                'AddressLine'        => [$address['street']],
                'PoliticalDivision2' => $address['city'],        // City
                'PoliticalDivision1' => $address['state'],       // State abbreviation
                'PostcodePrimaryLow' => $address['zip'],         // ZIP code
                'CountryCode'        => $address['country'] ?? 'US',
            ],
        ],
    ];
}

/**
 * Sanitize user input.
 */
function clean(string $value): string
{
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}


// ============================================================================
// REQUEST PROCESSING
// ============================================================================
$rateResult    = null;
$addressResult = null;
$activeTab     = 'rates';
$errorMsg      = '';

// Check for placeholder credentials
$credentialsSet = ($UPS_CLIENT_ID !== 'YOUR_CLIENT_ID' && $UPS_CLIENT_SECRET !== 'YOUR_CLIENT_SECRET');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $credentialsSet) {
    $action = $_POST['action'] ?? '';
    $activeTab = ($action === 'validate_address') ? 'address' : 'rates';

    // Authenticate first
    $auth = getAccessToken($OAUTH_URL, $UPS_CLIENT_ID, $UPS_CLIENT_SECRET);
    if (isset($auth['error'])) {
        $errorMsg = $auth['error'];
    } else {
        $token = $auth['token'];

        // --- RATE SHOPPING ---
        if ($action === 'get_rates') {
            $from = [
                'name'    => clean($_POST['from_name'] ?? 'Sender'),
                'street'  => clean($_POST['from_street'] ?? ''),
                'city'    => clean($_POST['from_city'] ?? ''),
                'state'   => clean($_POST['from_state'] ?? ''),
                'zip'     => clean($_POST['from_zip'] ?? ''),
                'country' => clean($_POST['from_country'] ?? 'US'),
            ];
            $to = [
                'name'    => clean($_POST['to_name'] ?? 'Receiver'),
                'street'  => clean($_POST['to_street'] ?? ''),
                'city'    => clean($_POST['to_city'] ?? ''),
                'state'   => clean($_POST['to_state'] ?? ''),
                'zip'     => clean($_POST['to_zip'] ?? ''),
                'country' => clean($_POST['to_country'] ?? 'US'),
            ];
            $weight = max(0.1, (float) ($_POST['weight'] ?? 5));

            $payload = buildRatePayload($UPS_ACCOUNT_NUMBER, $from, $to, $weight);
            $rateResult = upsRequest($RATING_URL, $payload, $token);
        }

        // --- ADDRESS VALIDATION ---
        if ($action === 'validate_address') {
            $addr = [
                'street'  => clean($_POST['addr_street'] ?? ''),
                'city'    => clean($_POST['addr_city'] ?? ''),
                'state'   => clean($_POST['addr_state'] ?? ''),
                'zip'     => clean($_POST['addr_zip'] ?? ''),
                'country' => clean($_POST['addr_country'] ?? 'US'),
            ];

            $payload = buildAddressPayload($addr);
            $queryParams = '?regionalrequestindicator=false&maximumcandidatelistsize=10';
            $addressResult = upsRequest($ADDRESS_URL . $queryParams, $payload, $token);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UPS API Demo - Rates &amp; Address Validation</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            color: #333;
            line-height: 1.6;
        }
        .container { max-width: 960px; margin: 0 auto; padding: 20px; }
        h1 {
            text-align: center;
            color: #351C15;
            margin-bottom: 5px;
            font-size: 1.8em;
        }
        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 25px;
            font-size: 0.95em;
        }
        .env-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 0.75em;
            font-weight: 600;
            color: #fff;
        }
        .env-sandbox { background: #e67e22; }
        .env-production { background: #27ae60; }

        /* Tabs */
        .tabs { display: flex; gap: 0; margin-bottom: 0; }
        .tab {
            flex: 1;
            padding: 14px 20px;
            text-align: center;
            background: #ddd;
            border: none;
            cursor: pointer;
            font-size: 1em;
            font-weight: 600;
            color: #555;
            border-radius: 8px 8px 0 0;
            transition: background 0.2s;
        }
        .tab:hover { background: #ccc; }
        .tab.active { background: #fff; color: #351C15; }

        /* Panels */
        .panel {
            display: none;
            background: #fff;
            padding: 25px;
            border-radius: 0 0 8px 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .panel.active { display: block; }

        /* Forms */
        .form-section { margin-bottom: 20px; }
        .form-section h3 {
            color: #351C15;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 2px solid #f0f2f5;
        }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .form-group { display: flex; flex-direction: column; }
        .form-group.full { grid-column: 1 / -1; }
        label {
            font-size: 0.85em;
            font-weight: 600;
            color: #555;
            margin-bottom: 4px;
        }
        input, select {
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.95em;
            transition: border-color 0.2s;
        }
        input:focus, select:focus {
            outline: none;
            border-color: #351C15;
        }
        button[type="submit"] {
            display: block;
            width: 100%;
            padding: 14px;
            background: #351C15;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 1.05em;
            font-weight: 600;
            cursor: pointer;
            margin-top: 15px;
            transition: background 0.2s;
        }
        button[type="submit"]:hover { background: #4a2920; }

        /* Results */
        .alert {
            padding: 14px 18px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        .alert-error { background: #fdecea; color: #b71c1c; border-left: 4px solid #b71c1c; }
        .alert-warn { background: #fff8e1; color: #e65100; border-left: 4px solid #e67e22; }
        .alert-success { background: #e8f5e9; color: #2e7d32; border-left: 4px solid #27ae60; }

        .results-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .results-table th, .results-table td {
            padding: 10px 14px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .results-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #351C15;
            font-size: 0.9em;
        }
        .results-table tr:hover td { background: #fafafa; }
        .price { font-weight: 700; color: #27ae60; }
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.8em;
            font-weight: 600;
        }
        .badge-res { background: #e3f2fd; color: #1565c0; }
        .badge-com { background: #f3e5f5; color: #7b1fa2; }
        .badge-valid { background: #e8f5e9; color: #2e7d32; }
        .badge-ambig { background: #fff8e1; color: #e65100; }
        .badge-invalid { background: #fdecea; color: #b71c1c; }

        .candidate {
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 12px 16px;
            margin-top: 10px;
        }
        .candidate p { margin-bottom: 4px; }

        .raw-toggle {
            margin-top: 15px;
            font-size: 0.85em;
            color: #666;
            cursor: pointer;
        }
        .raw-json {
            display: none;
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 15px;
            border-radius: 6px;
            margin-top: 8px;
            font-family: 'Fira Code', 'Consolas', monospace;
            font-size: 0.82em;
            overflow-x: auto;
            white-space: pre-wrap;
            max-height: 400px;
            overflow-y: auto;
        }

        @media (max-width: 600px) {
            .form-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1>UPS API Demo</h1>
    <p class="subtitle">
        Rate Shopping &amp; Address Validation
        <span class="env-badge <?= $USE_SANDBOX ? 'env-sandbox' : 'env-production' ?>">
            <?= $USE_SANDBOX ? 'SANDBOX' : 'PRODUCTION' ?>
        </span>
    </p>

    <?php if (!$credentialsSet): ?>
        <div class="alert alert-warn">
            Credentials not configured. Open this file and replace <code>YOUR_CLIENT_ID</code>,
            <code>YOUR_CLIENT_SECRET</code>, and <code>YOUR_ACCOUNT_NUMBER</code> at the top.
        </div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <div class="alert alert-error"><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <!-- Tab buttons -->
    <div class="tabs">
        <button class="tab <?= $activeTab === 'rates' ? 'active' : '' ?>" onclick="switchTab('rates')">
            Rate Shopping
        </button>
        <button class="tab <?= $activeTab === 'address' ? 'active' : '' ?>" onclick="switchTab('address')">
            Address Validation
        </button>
    </div>

    <!-- ================================================================ -->
    <!-- TAB 1: RATE SHOPPING                                             -->
    <!-- ================================================================ -->
    <div id="panel-rates" class="panel <?= $activeTab === 'rates' ? 'active' : '' ?>">
        <form method="POST">
            <input type="hidden" name="action" value="get_rates">

            <div class="form-section">
                <h3>Ship From</h3>
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Name</label>
                        <input name="from_name" value="<?= clean($_POST['from_name'] ?? 'My Company') ?>">
                    </div>
                    <div class="form-group full">
                        <label>Street Address</label>
                        <input name="from_street" value="<?= clean($_POST['from_street'] ?? '123 Main St') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>City</label>
                        <input name="from_city" value="<?= clean($_POST['from_city'] ?? 'New York') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>State</label>
                        <input name="from_state" value="<?= clean($_POST['from_state'] ?? 'NY') ?>" maxlength="2" required>
                    </div>
                    <div class="form-group">
                        <label>ZIP Code</label>
                        <input name="from_zip" value="<?= clean($_POST['from_zip'] ?? '10001') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Country</label>
                        <input name="from_country" value="<?= clean($_POST['from_country'] ?? 'US') ?>" maxlength="2">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h3>Ship To</h3>
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Name</label>
                        <input name="to_name" value="<?= clean($_POST['to_name'] ?? 'Customer') ?>">
                    </div>
                    <div class="form-group full">
                        <label>Street Address</label>
                        <input name="to_street" value="<?= clean($_POST['to_street'] ?? '456 Oak Ave') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>City</label>
                        <input name="to_city" value="<?= clean($_POST['to_city'] ?? 'Los Angeles') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>State</label>
                        <input name="to_state" value="<?= clean($_POST['to_state'] ?? 'CA') ?>" maxlength="2" required>
                    </div>
                    <div class="form-group">
                        <label>ZIP Code</label>
                        <input name="to_zip" value="<?= clean($_POST['to_zip'] ?? '90001') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Country</label>
                        <input name="to_country" value="<?= clean($_POST['to_country'] ?? 'US') ?>" maxlength="2">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h3>Package</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Weight (lbs)</label>
                        <input name="weight" type="number" step="0.1" min="0.1"
                               value="<?= clean($_POST['weight'] ?? '5') ?>" required>
                    </div>
                </div>
            </div>

            <button type="submit" <?= !$credentialsSet ? 'disabled' : '' ?>>Get Shipping Rates</button>
        </form>

        <?php if ($rateResult): ?>
            <hr style="margin: 25px 0; border: none; border-top: 1px solid #eee;">

            <?php if (isset($rateResult['error'])): ?>
                <div class="alert alert-error">
                    <strong>Rate Request Failed:</strong> <?= htmlspecialchars($rateResult['error']) ?>
                </div>
            <?php else: ?>
                <?php
                    $rateResponse = $rateResult['data']['RateResponse'] ?? [];
                    $ratedShipments = $rateResponse['RatedShipment'] ?? [];
                    // Normalize to array of shipments (single result comes as object)
                    if (isset($ratedShipments['Service'])) {
                        $ratedShipments = [$ratedShipments];
                    }
                ?>
                <div class="alert alert-success">
                    Found <strong><?= count($ratedShipments) ?></strong> available shipping option(s).
                </div>

                <?php if (!empty($ratedShipments)): ?>
                    <table class="results-table">
                        <thead>
                            <tr>
                                <th>Service</th>
                                <th>Code</th>
                                <th>Cost</th>
                                <th>Currency</th>
                                <th>Transit Days</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($ratedShipments as $shipment): ?>
                            <?php
                                $serviceCode = $shipment['Service']['Code'] ?? '??';
                                $serviceName = $UPS_SERVICES[$serviceCode] ?? "Service $serviceCode";
                                $totalCharge = $shipment['TotalCharges']['MonetaryValue'] ?? 'N/A';
                                $currency    = $shipment['TotalCharges']['CurrencyCode'] ?? 'USD';
                                $transitDays = $shipment['GuaranteedDelivery']['BusinessDaysInTransit']
                                    ?? $shipment['TimeInTransit']['ServiceSummary']['EstimatedArrival']['BusinessDaysInTransit']
                                    ?? '-';
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($serviceName) ?></td>
                                <td><?= htmlspecialchars($serviceCode) ?></td>
                                <td class="price">$<?= htmlspecialchars($totalCharge) ?></td>
                                <td><?= htmlspecialchars($currency) ?></td>
                                <td><?= htmlspecialchars($transitDays) ?> day(s)</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Raw JSON toggle -->
            <p class="raw-toggle" onclick="toggleRaw('raw-rate')">&#9654; Show raw JSON response</p>
            <pre class="raw-json" id="raw-rate"><?= htmlspecialchars(json_encode($rateResult['data'] ?? $rateResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
        <?php endif; ?>
    </div>

    <!-- ================================================================ -->
    <!-- TAB 2: ADDRESS VALIDATION                                        -->
    <!-- ================================================================ -->
    <div id="panel-address" class="panel <?= $activeTab === 'address' ? 'active' : '' ?>">
        <form method="POST">
            <input type="hidden" name="action" value="validate_address">

            <div class="form-section">
                <h3>Address to Validate</h3>
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Street Address</label>
                        <input name="addr_street" value="<?= clean($_POST['addr_street'] ?? '1 Wall St') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>City</label>
                        <input name="addr_city" value="<?= clean($_POST['addr_city'] ?? 'New York') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>State</label>
                        <input name="addr_state" value="<?= clean($_POST['addr_state'] ?? 'NY') ?>" maxlength="2" required>
                    </div>
                    <div class="form-group">
                        <label>ZIP Code</label>
                        <input name="addr_zip" value="<?= clean($_POST['addr_zip'] ?? '10005') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Country</label>
                        <input name="addr_country" value="<?= clean($_POST['addr_country'] ?? 'US') ?>" maxlength="2">
                    </div>
                </div>
            </div>

            <button type="submit" <?= !$credentialsSet ? 'disabled' : '' ?>>Validate Address</button>
        </form>

        <?php if ($addressResult): ?>
            <hr style="margin: 25px 0; border: none; border-top: 1px solid #eee;">

            <?php if (isset($addressResult['error'])): ?>
                <div class="alert alert-error">
                    <strong>Validation Failed:</strong> <?= htmlspecialchars($addressResult['error']) ?>
                </div>
            <?php else: ?>
                <?php
                    $xavResponse = $addressResult['data']['XAVResponse'] ?? [];

                    // Determine validation outcome
                    $isValid    = isset($xavResponse['ValidAddressIndicator']);
                    $isAmbig    = isset($xavResponse['AmbiguousAddressIndicator']);
                    $noCandidate = isset($xavResponse['NoCandidatesIndicator']);

                    // Classification
                    $classification = $xavResponse['AddressClassification']['Description'] ?? 'Unknown';
                    $classCode      = $xavResponse['AddressClassification']['Code'] ?? '0';

                    // Candidate addresses
                    $candidates = $xavResponse['Candidate'] ?? [];
                    if (isset($candidates['AddressKeyFormat'])) {
                        $candidates = [$candidates]; // single candidate
                    }
                ?>

                <?php if ($isValid): ?>
                    <div class="alert alert-success">
                        <strong>Valid Address</strong> - Classification:
                        <span class="badge <?= $classCode === '1' ? 'badge-com' : 'badge-res' ?>">
                            <?= htmlspecialchars($classification) ?>
                        </span>
                    </div>
                <?php elseif ($isAmbig): ?>
                    <div class="alert alert-warn">
                        <strong>Ambiguous Address</strong> - Multiple candidates found. See suggestions below.
                    </div>
                <?php elseif ($noCandidate): ?>
                    <div class="alert alert-error">
                        <strong>Invalid Address</strong> - No matching addresses found.
                    </div>
                <?php endif; ?>

                <?php if (!empty($candidates)): ?>
                    <h4 style="margin-top: 15px;">Candidate Address(es):</h4>
                    <?php foreach ($candidates as $i => $candidate): ?>
                        <?php
                            $akf = $candidate['AddressKeyFormat'] ?? [];
                            $lines = (array) ($akf['AddressLine'] ?? []);
                            $city  = $akf['PoliticalDivision2'] ?? '';
                            $state = $akf['PoliticalDivision1'] ?? '';
                            $zip   = $akf['PostcodePrimaryLow'] ?? '';
                            $zip4  = $akf['PostcodeExtendedLow'] ?? '';
                            $fullZip = $zip . ($zip4 ? "-$zip4" : '');

                            $candClass = $candidate['AddressClassification']['Description'] ?? '';
                            $candCode  = $candidate['AddressClassification']['Code'] ?? '0';
                        ?>
                        <div class="candidate">
                            <p><strong>#<?= $i + 1 ?>:</strong> <?= htmlspecialchars(implode(', ', $lines)) ?></p>
                            <p><?= htmlspecialchars("$city, $state $fullZip") ?></p>
                            <?php if ($candClass): ?>
                                <p>
                                    Type:
                                    <span class="badge <?= $candCode === '1' ? 'badge-com' : 'badge-res' ?>">
                                        <?= htmlspecialchars($candClass) ?>
                                    </span>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Raw JSON toggle -->
            <p class="raw-toggle" onclick="toggleRaw('raw-addr')">&#9654; Show raw JSON response</p>
            <pre class="raw-json" id="raw-addr"><?= htmlspecialchars(json_encode($addressResult['data'] ?? $addressResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
        <?php endif; ?>
    </div>
</div>

<script>
    function switchTab(tab) {
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
        document.getElementById('panel-' + tab).classList.add('active');
        event.target.classList.add('active');
    }
    function toggleRaw(id) {
        const el = document.getElementById(id);
        el.style.display = el.style.display === 'block' ? 'none' : 'block';
    }
</script>
</body>
</html>
