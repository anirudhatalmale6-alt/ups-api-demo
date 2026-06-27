<?php
/**
 * PAGE 1: UPS Rate Check & Address Validation
 *
 * - Enter origin + destination + package weight to get a quick rate quote
 * - Enter any US address to validate it and see residential/commercial classification
 *
 * API specs used:
 *   Rating:     https://github.com/UPS-API/api-documentation/blob/main/Rating.yaml
 *   Address:    https://github.com/UPS-API/api-documentation/blob/main/AddressValidation.yaml
 */
require_once __DIR__ . '/ups_config.php';

$rateResult    = null;
$addressResult = null;
$activeTab     = $_POST['action'] ?? 'rates';
if ($activeTab === 'validate_address') $activeTab = 'address';
if ($activeTab === 'get_rates') $activeTab = 'rates';
$errorMsg = '';

// ============================================================================
// PROCESS FORM SUBMISSIONS
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ups_credentials_set()) {
    $auth = ups_get_token();
    if (isset($auth['error'])) {
        $errorMsg = $auth['error'];
    } else {
        $token  = $auth['token'];
        $action = $_POST['action'] ?? '';

        // --- RATE CHECK ---
        if ($action === 'get_rates') {
            $activeTab = 'rates';
            $payload = [
                'RateRequest' => [
                    'Request' => [
                        'SubVersion' => '2403',
                        'TransactionReference' => ['CustomerContext' => 'Rate Check'],
                    ],
                    'Shipment' => [
                        'Shipper' => [
                            'Name' => ups_clean($_POST['from_name'] ?? 'Sender'),
                            'ShipperNumber' => UPS_ACCOUNT_NUMBER,
                            'Address' => [
                                'AddressLine'       => [ups_clean($_POST['from_street'] ?? '')],
                                'City'              => ups_clean($_POST['from_city'] ?? ''),
                                'StateProvinceCode' => ups_clean($_POST['from_state'] ?? ''),
                                'PostalCode'        => ups_clean($_POST['from_zip'] ?? ''),
                                'CountryCode'       => ups_clean($_POST['from_country'] ?? 'US'),
                            ],
                        ],
                        'ShipTo' => [
                            'Name' => ups_clean($_POST['to_name'] ?? 'Receiver'),
                            'Address' => [
                                'AddressLine'       => [ups_clean($_POST['to_street'] ?? '')],
                                'City'              => ups_clean($_POST['to_city'] ?? ''),
                                'StateProvinceCode' => ups_clean($_POST['to_state'] ?? ''),
                                'PostalCode'        => ups_clean($_POST['to_zip'] ?? ''),
                                'CountryCode'       => ups_clean($_POST['to_country'] ?? 'US'),
                            ],
                        ],
                        'ShipFrom' => [
                            'Name' => ups_clean($_POST['from_name'] ?? 'Sender'),
                            'Address' => [
                                'AddressLine'       => [ups_clean($_POST['from_street'] ?? '')],
                                'City'              => ups_clean($_POST['from_city'] ?? ''),
                                'StateProvinceCode' => ups_clean($_POST['from_state'] ?? ''),
                                'PostalCode'        => ups_clean($_POST['from_zip'] ?? ''),
                                'CountryCode'       => ups_clean($_POST['from_country'] ?? 'US'),
                            ],
                        ],
                        'Package' => [
                            'PackagingType' => ['Code' => ups_clean($_POST['pkg_type'] ?? '02')],
                            'Dimensions' => [
                                'UnitOfMeasurement' => ['Code' => 'IN'],
                                'Length' => ups_clean($_POST['pkg_length'] ?? '10'),
                                'Width'  => ups_clean($_POST['pkg_width'] ?? '8'),
                                'Height' => ups_clean($_POST['pkg_height'] ?? '6'),
                            ],
                            'PackageWeight' => [
                                'UnitOfMeasurement' => ['Code' => 'LBS'],
                                'Weight' => (string) max(0.1, (float)($_POST['weight'] ?? 5)),
                            ],
                        ],
                        'ShipmentRatingOptions' => [
                            'NegotiatedRatesIndicator' => '',
                        ],
                    ],
                ],
            ];

            $rateResult = ups_api_call(UPS_RATING_URL, $payload, $token);
        }

        // --- ADDRESS VALIDATION ---
        if ($action === 'validate_address') {
            $activeTab = 'address';
            $payload = [
                'XAVRequest' => [
                    'AddressKeyFormat' => [
                        'ConsigneeName'      => ups_clean($_POST['addr_name'] ?? ''),
                        'AddressLine'        => [ups_clean($_POST['addr_street'] ?? '')],
                        'PoliticalDivision2' => ups_clean($_POST['addr_city'] ?? ''),
                        'PoliticalDivision1' => ups_clean($_POST['addr_state'] ?? ''),
                        'PostcodePrimaryLow' => ups_clean($_POST['addr_zip'] ?? ''),
                        'CountryCode'        => ups_clean($_POST['addr_country'] ?? 'US'),
                    ],
                ],
            ];

            $url = UPS_ADDRESS_URL . '?regionalrequestindicator=false&maximumcandidatelistsize=15';
            $addressResult = ups_api_call($url, $payload, $token);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UPS Rate Check &amp; Address Validation</title>
    <style><?= ups_common_css() ?></style>
    <style>
        .tabs { display: flex; gap: 0; margin-bottom: 0; }
        .tab {
            flex: 1; padding: 14px 20px; text-align: center; background: #ddd;
            border: none; cursor: pointer; font-size: 1em; font-weight: 600;
            color: #555; border-radius: 8px 8px 0 0; transition: background 0.2s;
        }
        .tab:hover { background: #ccc; }
        .tab.active { background: #fff; color: #351C15; }
        .panel { display: none; background: #fff; padding: 25px;
            border-radius: 0 0 8px 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .panel.active { display: block; }
    </style>
</head>
<body>
<div class="container">
    <h1>UPS API - Rate Check &amp; Address Validation</h1>
    <p class="subtitle">
        Page 1 of 2 &mdash;
        <span class="env-badge <?= UPS_SANDBOX ? 'env-sandbox' : 'env-production' ?>">
            <?= UPS_SANDBOX ? 'SANDBOX' : 'PRODUCTION' ?>
        </span>
    </p>
    <div class="nav">
        <a href="ups_rate_check.php" class="active">Rate Check &amp; Address</a>
        <a href="ups_shipping_options.php">All Shipping Options</a>
    </div>

    <?php if (!ups_credentials_set()): ?>
        <div class="alert alert-warn">
            Open <code>ups_config.php</code> and replace <code>YOUR_CLIENT_ID</code>,
            <code>YOUR_CLIENT_SECRET</code>, and <code>YOUR_ACCOUNT_NUMBER</code> with your UPS credentials.
        </div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <div class="alert alert-error"><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <div class="tabs">
        <button class="tab <?= $activeTab === 'rates' ? 'active' : '' ?>" onclick="switchTab('rates')">Rate Check</button>
        <button class="tab <?= $activeTab === 'address' ? 'active' : '' ?>" onclick="switchTab('address')">Address Validation</button>
    </div>

    <!-- ================================================================ -->
    <!-- TAB 1: RATE CHECK                                                -->
    <!-- ================================================================ -->
    <div id="panel-rates" class="panel <?= $activeTab === 'rates' ? 'active' : '' ?>">
        <form method="POST">
            <input type="hidden" name="action" value="get_rates">

            <h2>Ship From (Origin)</h2>
            <div class="form-grid">
                <div class="form-group full"><label>Company / Name</label>
                    <input name="from_name" value="<?= ups_clean($_POST['from_name'] ?? 'My Company') ?>"></div>
                <div class="form-group full"><label>Street Address</label>
                    <input name="from_street" value="<?= ups_clean($_POST['from_street'] ?? '123 Main St') ?>" required></div>
                <div class="form-group"><label>City</label>
                    <input name="from_city" value="<?= ups_clean($_POST['from_city'] ?? 'New York') ?>" required></div>
                <div class="form-group"><label>State</label>
                    <input name="from_state" value="<?= ups_clean($_POST['from_state'] ?? 'NY') ?>" maxlength="2" required></div>
                <div class="form-group"><label>ZIP Code</label>
                    <input name="from_zip" value="<?= ups_clean($_POST['from_zip'] ?? '10001') ?>" required></div>
                <div class="form-group"><label>Country</label>
                    <input name="from_country" value="<?= ups_clean($_POST['from_country'] ?? 'US') ?>" maxlength="2"></div>
            </div>

            <h2 style="margin-top:20px">Ship To (Destination)</h2>
            <div class="form-grid">
                <div class="form-group full"><label>Recipient Name</label>
                    <input name="to_name" value="<?= ups_clean($_POST['to_name'] ?? 'Customer') ?>"></div>
                <div class="form-group full"><label>Street Address</label>
                    <input name="to_street" value="<?= ups_clean($_POST['to_street'] ?? '456 Oak Ave') ?>" required></div>
                <div class="form-group"><label>City</label>
                    <input name="to_city" value="<?= ups_clean($_POST['to_city'] ?? 'Los Angeles') ?>" required></div>
                <div class="form-group"><label>State</label>
                    <input name="to_state" value="<?= ups_clean($_POST['to_state'] ?? 'CA') ?>" maxlength="2" required></div>
                <div class="form-group"><label>ZIP Code</label>
                    <input name="to_zip" value="<?= ups_clean($_POST['to_zip'] ?? '90001') ?>" required></div>
                <div class="form-group"><label>Country</label>
                    <input name="to_country" value="<?= ups_clean($_POST['to_country'] ?? 'US') ?>" maxlength="2"></div>
            </div>

            <h2 style="margin-top:20px">Package Details</h2>
            <div class="form-grid">
                <div class="form-group"><label>Weight (lbs)</label>
                    <input name="weight" type="number" step="0.1" min="0.1" value="<?= ups_clean($_POST['weight'] ?? '5') ?>" required></div>
                <div class="form-group"><label>Packaging Type</label>
                    <select name="pkg_type">
                        <?php foreach (UPS_PACKAGING as $code => $name): ?>
                            <option value="<?= $code ?>" <?= ($_POST['pkg_type'] ?? '02') === $code ? 'selected' : '' ?>>
                                <?= htmlspecialchars($name) ?> (<?= $code ?>)
                            </option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="form-group"><label>Length (in)</label>
                    <input name="pkg_length" type="number" step="0.1" value="<?= ups_clean($_POST['pkg_length'] ?? '10') ?>"></div>
                <div class="form-group"><label>Width (in)</label>
                    <input name="pkg_width" type="number" step="0.1" value="<?= ups_clean($_POST['pkg_width'] ?? '8') ?>"></div>
                <div class="form-group"><label>Height (in)</label>
                    <input name="pkg_height" type="number" step="0.1" value="<?= ups_clean($_POST['pkg_height'] ?? '6') ?>"></div>
            </div>

            <button type="submit" class="btn" <?= !ups_credentials_set() ? 'disabled' : '' ?>>Get Shipping Rate</button>
        </form>

        <?php if ($rateResult): ?>
            <hr class="sep">
            <?php if (isset($rateResult['error'])): ?>
                <div class="alert alert-error"><strong>Rate Request Failed:</strong> <?= htmlspecialchars($rateResult['error']) ?></div>
            <?php else: ?>
                <?php
                    $resp = $rateResult['data']['RateResponse'] ?? [];
                    $rated = $resp['RatedShipment'] ?? [];
                    if (isset($rated['Service'])) $rated = [$rated];
                ?>
                <div class="alert alert-success">Found <strong><?= count($rated) ?></strong> available service(s)</div>
                <?php if ($rated): ?>
                    <table class="results">
                        <thead><tr><th>Service</th><th>Code</th><th>Total Cost</th><th>Negotiated</th><th>Currency</th><th>Transit</th><th>Guaranteed</th></tr></thead>
                        <tbody>
                        <?php foreach ($rated as $s): ?>
                            <?php
                                $code = $s['Service']['Code'] ?? '';
                                $total = $s['TotalCharges']['MonetaryValue'] ?? 'N/A';
                                $curr  = $s['TotalCharges']['CurrencyCode'] ?? 'USD';
                                $neg   = $s['NegotiatedRateCharges']['TotalCharge']['MonetaryValue'] ?? '-';
                                $days  = $s['GuaranteedDelivery']['BusinessDaysInTransit']
                                    ?? $s['TimeInTransit']['ServiceSummary']['EstimatedArrival']['BusinessDaysInTransit']
                                    ?? '-';
                                $guar  = isset($s['GuaranteedDelivery']) ? 'Yes' : 'No';
                            ?>
                            <tr>
                                <td><?= htmlspecialchars(ups_service_name($code)) ?></td>
                                <td><?= htmlspecialchars($code) ?></td>
                                <td class="price">$<?= htmlspecialchars($total) ?></td>
                                <td><?= $neg !== '-' ? '<span class="price">$' . htmlspecialchars($neg) . '</span>' : '-' ?></td>
                                <td><?= htmlspecialchars($curr) ?></td>
                                <td><?= htmlspecialchars($days) ?> day(s)</td>
                                <td><span class="badge <?= $guar === 'Yes' ? 'badge-yes' : '' ?>"><?= $guar ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>
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
            <h2>Enter Address to Validate</h2>
            <div class="form-grid">
                <div class="form-group full"><label>Name / Business (optional)</label>
                    <input name="addr_name" value="<?= ups_clean($_POST['addr_name'] ?? '') ?>"></div>
                <div class="form-group full"><label>Street Address</label>
                    <input name="addr_street" value="<?= ups_clean($_POST['addr_street'] ?? '1 Wall St') ?>" required></div>
                <div class="form-group"><label>City</label>
                    <input name="addr_city" value="<?= ups_clean($_POST['addr_city'] ?? 'New York') ?>" required></div>
                <div class="form-group"><label>State</label>
                    <input name="addr_state" value="<?= ups_clean($_POST['addr_state'] ?? 'NY') ?>" maxlength="2" required></div>
                <div class="form-group"><label>ZIP Code</label>
                    <input name="addr_zip" value="<?= ups_clean($_POST['addr_zip'] ?? '10005') ?>" required></div>
                <div class="form-group"><label>Country</label>
                    <input name="addr_country" value="<?= ups_clean($_POST['addr_country'] ?? 'US') ?>" maxlength="2"></div>
            </div>
            <button type="submit" class="btn" <?= !ups_credentials_set() ? 'disabled' : '' ?>>Validate Address</button>
        </form>

        <?php if ($addressResult): ?>
            <hr class="sep">
            <?php if (isset($addressResult['error'])): ?>
                <div class="alert alert-error"><strong>Validation Failed:</strong> <?= htmlspecialchars($addressResult['error']) ?></div>
            <?php else: ?>
                <?php
                    $xav = $addressResult['data']['XAVResponse'] ?? [];
                    $isValid = isset($xav['ValidAddressIndicator']);
                    $isAmbig = isset($xav['AmbiguousAddressIndicator']);
                    $noCand  = isset($xav['NoCandidatesIndicator']);
                    $classDesc = $xav['AddressClassification']['Description'] ?? 'Unknown';
                    $classCode = $xav['AddressClassification']['Code'] ?? '0';
                    $candidates = $xav['Candidate'] ?? [];
                    if (isset($candidates['AddressKeyFormat'])) $candidates = [$candidates];
                ?>

                <?php if ($isValid): ?>
                    <div class="alert alert-success">
                        <strong>VALID ADDRESS</strong> &mdash; Classification:
                        <span class="badge <?= $classCode === '1' ? 'badge-com' : 'badge-res' ?>"><?= htmlspecialchars($classDesc) ?></span>
                    </div>
                <?php elseif ($isAmbig): ?>
                    <div class="alert alert-warn"><strong>AMBIGUOUS ADDRESS</strong> &mdash; Multiple matches found. See candidates below.</div>
                <?php elseif ($noCand): ?>
                    <div class="alert alert-error"><strong>INVALID ADDRESS</strong> &mdash; No matching addresses found in UPS database.</div>
                <?php endif; ?>

                <div class="alert alert-info" style="font-size:0.9em">
                    <strong>What the codes mean:</strong><br>
                    Code 0 = Unknown | Code 1 = Commercial | Code 2 = Residential<br>
                    ValidAddressIndicator = exact match found |
                    AmbiguousAddressIndicator = multiple possible matches |
                    NoCandidatesIndicator = no match at all
                </div>

                <?php if ($candidates): ?>
                    <h3 style="margin-top:15px">Candidate Address(es) (<?= count($candidates) ?> found):</h3>
                    <?php foreach ($candidates as $i => $c): ?>
                        <?php
                            $akf   = $c['AddressKeyFormat'] ?? [];
                            $lines = (array)($akf['AddressLine'] ?? []);
                            $city  = $akf['PoliticalDivision2'] ?? '';
                            $state = $akf['PoliticalDivision1'] ?? '';
                            $zip   = $akf['PostcodePrimaryLow'] ?? '';
                            $zip4  = $akf['PostcodeExtendedLow'] ?? '';
                            $full  = $zip . ($zip4 ? "-$zip4" : '');
                            $cc    = $c['AddressClassification']['Code'] ?? '0';
                            $cd    = $c['AddressClassification']['Description'] ?? '';
                        ?>
                        <div class="candidate">
                            <p><strong>#<?= $i + 1 ?>:</strong> <?= htmlspecialchars(implode(', ', $lines)) ?></p>
                            <p><?= htmlspecialchars("$city, $state $full") ?></p>
                            <?php if ($cd): ?>
                                <p>Type: <span class="badge <?= $cc === '1' ? 'badge-com' : 'badge-res' ?>"><?= htmlspecialchars($cd) ?></span></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
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
