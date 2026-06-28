<?php
/**
 * PAGE 2: All Available Shipping Options with Delivery Dates
 *
 * Shows every UPS service available for a given origin/destination with:
 *   - Service name and code
 *   - Published rate and negotiated rate (if available)
 *   - Estimated delivery date (based on shipping today)
 *   - Business days in transit
 *   - Guaranteed delivery indicator
 *   - Billing weight used for pricing
 *   - Surcharges breakdown
 *
 * Uses the /Shop endpoint with DeliveryTimeInformation to get transit data
 * alongside rates in a single API call.
 *
 * API spec: https://github.com/UPS-API/api-documentation/blob/main/Rating.yaml
 */
require_once __DIR__ . '/ups_config.php';

$result   = null;
$errorMsg = '';
$shipDate = date('Ymd'); // default: ship today

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ups_credentials_set()) {
    $auth = ups_get_token();
    if (isset($auth['error'])) {
        $errorMsg = $auth['error'];
    } else {
        $token = $auth['token'];

        // Build the ship date from user input
        $userDate = $_POST['ship_date'] ?? date('Y-m-d');
        $shipDate = str_replace('-', '', $userDate); // Convert YYYY-MM-DD to YYYYMMDD
        $shipTime = ups_clean($_POST['ship_time'] ?? '1000') . '00'; // HHMMSS

        $payload = [
            'RateRequest' => [
                'Request' => [
                    'SubVersion' => '2403',
                    'TransactionReference' => ['CustomerContext' => 'Shipping Options'],
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
                            'ResidentialAddressIndicator' => isset($_POST['residential']) ? '' : null,
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
                    // This is what triggers delivery time estimates in the response
                    'DeliveryTimeInformation' => [
                        'PackageBillType' => '03', // 03 = Non-Document, 01 = Document, 02 = WWD
                        'Pickup' => [
                            'Date' => $shipDate,
                            'Time' => $shipTime,
                        ],
                    ],
                ],
            ],
        ];

        // Remove null values (ResidentialAddressIndicator when unchecked)
        if (!isset($_POST['residential'])) {
            unset($payload['RateRequest']['Shipment']['ShipTo']['Address']['ResidentialAddressIndicator']);
        }

        $result = ups_api_call(UPS_RATING_URL, $payload, $token);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UPS All Shipping Options &amp; Delivery Dates</title>
    <style><?= ups_common_css() ?></style>
    <style>
        .option-card {
            background: #fff; border: 1px solid #e0e0e0; border-radius: 8px;
            padding: 18px 22px; margin-bottom: 12px; transition: box-shadow 0.2s;
        }
        .option-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .option-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 10px;
        }
        .option-header h3 { color: #351C15; font-size: 1.1em; margin: 0; }
        .option-price { font-size: 1.4em; font-weight: 700; color: #27ae60; }
        .option-details {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 8px; font-size: 0.9em;
        }
        .detail-item { display: flex; flex-direction: column; }
        .detail-label { font-size: 0.8em; color: #888; font-weight: 600; text-transform: uppercase; }
        .detail-value { font-weight: 500; }
        .surcharges { margin-top: 8px; font-size: 0.85em; color: #666; }
        .surcharges summary { cursor: pointer; font-weight: 600; color: #351C15; }
        .surcharge-list { margin-top: 5px; padding-left: 15px; }
        .checkbox-group { display: flex; align-items: center; gap: 8px; margin-top: 4px; }
        .checkbox-group input[type="checkbox"] { width: 18px; height: 18px; }
        .summary-bar {
            display: flex; gap: 20px; flex-wrap: wrap; padding: 15px 20px;
            background: #351C15; color: #fff; border-radius: 8px; margin-bottom: 20px;
            font-size: 0.95em;
        }
        .summary-bar .stat { display: flex; flex-direction: column; }
        .summary-bar .stat-label { font-size: 0.75em; opacity: 0.7; text-transform: uppercase; }
        .summary-bar .stat-value { font-weight: 700; font-size: 1.1em; }
    </style>
</head>
<body>
<div class="container">
    <h1>UPS All Shipping Options</h1>
    <p class="subtitle">
        Page 2 of 2 &mdash; Every available service with cost &amp; delivery date
        <span class="env-badge <?= UPS_SANDBOX ? 'env-sandbox' : 'env-production' ?>">
            <?= UPS_SANDBOX ? 'SANDBOX' : 'PRODUCTION' ?>
        </span>
    </p>
    <div class="nav">
        <a href="ups_rate_check.php">Rate Check &amp; Address</a>
        <a href="ups_shipping_options.php" class="active">All Shipping Options</a>
    </div>

    <?php if (!ups_credentials_set()): ?>
        <div class="alert alert-warn">
            Open <code>ups_config.php</code> and set your UPS credentials first.
        </div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <div class="alert alert-error"><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="POST">
            <h2>Shipment Details</h2>
            <div class="form-grid">
                <div class="form-group full" style="grid-column:1/-1"><label style="font-size:1em;color:#351C15">ORIGIN</label></div>
                <div class="form-group full"><label>Company / Name</label>
                    <input name="from_name" value="<?= ups_clean($_POST['from_name'] ?? 'My Company') ?>"></div>
                <div class="form-group full"><label>Street Address</label>
                    <input name="from_street" value="<?= ups_clean($_POST['from_street'] ?? '123 Main St') ?>" required></div>
                <div class="form-group"><label>City</label>
                    <input name="from_city" value="<?= ups_clean($_POST['from_city'] ?? 'New York') ?>" required></div>
                <div class="form-group"><label>State</label>
                    <input name="from_state" value="<?= ups_clean($_POST['from_state'] ?? 'NY') ?>" maxlength="2" required></div>
                <div class="form-group"><label>ZIP</label>
                    <input name="from_zip" value="<?= ups_clean($_POST['from_zip'] ?? '10001') ?>" required></div>
                <div class="form-group"><label>Country</label>
                    <input name="from_country" value="<?= ups_clean($_POST['from_country'] ?? 'US') ?>" maxlength="2"></div>
            </div>

            <div class="form-grid" style="margin-top:20px">
                <div class="form-group full" style="grid-column:1/-1"><label style="font-size:1em;color:#351C15">DESTINATION</label></div>
                <div class="form-group full"><label>Recipient Name</label>
                    <input name="to_name" value="<?= ups_clean($_POST['to_name'] ?? 'Customer') ?>"></div>
                <div class="form-group full"><label>Street Address</label>
                    <input name="to_street" value="<?= ups_clean($_POST['to_street'] ?? '456 Oak Ave') ?>" required></div>
                <div class="form-group"><label>City</label>
                    <input name="to_city" value="<?= ups_clean($_POST['to_city'] ?? 'Los Angeles') ?>" required></div>
                <div class="form-group"><label>State</label>
                    <input name="to_state" value="<?= ups_clean($_POST['to_state'] ?? 'CA') ?>" maxlength="2" required></div>
                <div class="form-group"><label>ZIP</label>
                    <input name="to_zip" value="<?= ups_clean($_POST['to_zip'] ?? '90001') ?>" required></div>
                <div class="form-group"><label>Country</label>
                    <input name="to_country" value="<?= ups_clean($_POST['to_country'] ?? 'US') ?>" maxlength="2"></div>
                <div class="form-group full">
                    <div class="checkbox-group">
                        <input type="checkbox" name="residential" id="residential" <?= isset($_POST['residential']) ? 'checked' : '' ?>>
                        <label for="residential" style="margin:0">Residential address (adds residential surcharge to rates)</label>
                    </div>
                </div>
            </div>

            <div class="form-grid" style="margin-top:20px">
                <div class="form-group full" style="grid-column:1/-1"><label style="font-size:1em;color:#351C15">PACKAGE &amp; SHIP DATE</label></div>
                <div class="form-group"><label>Weight (lbs)</label>
                    <input name="weight" type="number" step="0.1" min="0.1" value="<?= ups_clean($_POST['weight'] ?? '5') ?>" required></div>
                <div class="form-group"><label>Packaging</label>
                    <select name="pkg_type">
                        <?php foreach (UPS_PACKAGING as $code => $name): ?>
                            <option value="<?= $code ?>" <?= ($_POST['pkg_type'] ?? '02') === $code ? 'selected' : '' ?>>
                                <?= htmlspecialchars($name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="form-group"><label>Length (in)</label>
                    <input name="pkg_length" type="number" step="0.1" value="<?= ups_clean($_POST['pkg_length'] ?? '10') ?>"></div>
                <div class="form-group"><label>Width (in)</label>
                    <input name="pkg_width" type="number" step="0.1" value="<?= ups_clean($_POST['pkg_width'] ?? '8') ?>"></div>
                <div class="form-group"><label>Height (in)</label>
                    <input name="pkg_height" type="number" step="0.1" value="<?= ups_clean($_POST['pkg_height'] ?? '6') ?>"></div>
                <div class="form-group"><label>Ship Date</label>
                    <input name="ship_date" type="date" value="<?= ups_clean($_POST['ship_date'] ?? date('Y-m-d')) ?>"></div>
                <div class="form-group"><label>Pickup Time (HHMM, 24hr)</label>
                    <input name="ship_time" value="<?= ups_clean($_POST['ship_time'] ?? '1000') ?>" maxlength="4" placeholder="1000"></div>
            </div>

            <button type="submit" class="btn" <?= !ups_credentials_set() ? 'disabled' : '' ?>>Show All Shipping Options</button>
        </form>
    </div>

    <!-- ================================================================ -->
    <!-- RESULTS                                                          -->
    <!-- ================================================================ -->
    <?php if ($result): ?>
        <?php if (isset($result['error'])): ?>
            <div class="alert alert-error"><strong>Request Failed:</strong> <?= htmlspecialchars($result['error']) ?></div>
        <?php else: ?>
            <?php
                $resp  = $result['data']['RateResponse'] ?? [];
                $rated = $resp['RatedShipment'] ?? [];
                if (isset($rated['Service'])) $rated = [$rated];

                // Sort by total cost ascending
                usort($rated, function($a, $b) {
                    return ((float)($a['TotalCharges']['MonetaryValue'] ?? 0))
                       <=> ((float)($b['TotalCharges']['MonetaryValue'] ?? 0));
                });

                // Compute summary stats
                $cheapest = $rated ? '$' . ($rated[0]['TotalCharges']['MonetaryValue'] ?? '?') : 'N/A';
                $fastest  = 'N/A';
                $minDays  = PHP_INT_MAX;
                foreach ($rated as $s) {
                    $d = (int)($s['GuaranteedDelivery']['BusinessDaysInTransit']
                        ?? $s['TimeInTransit']['ServiceSummary']['EstimatedArrival']['BusinessDaysInTransit']
                        ?? 999);
                    if ($d < $minDays) {
                        $minDays = $d;
                        $fastest = ups_service_name($s['Service']['Code'] ?? '');
                    }
                }
            ?>

            <div class="summary-bar">
                <div class="stat"><span class="stat-label">Total Options</span><span class="stat-value"><?= count($rated) ?></span></div>
                <div class="stat"><span class="stat-label">Cheapest</span><span class="stat-value"><?= htmlspecialchars($cheapest) ?></span></div>
                <div class="stat"><span class="stat-label">Fastest</span><span class="stat-value"><?= htmlspecialchars($fastest) ?></span></div>
                <div class="stat"><span class="stat-label">Ship Date</span><span class="stat-value"><?= ups_format_date($shipDate) ?></span></div>
            </div>

            <?php foreach ($rated as $idx => $s): ?>
                <?php
                    $code      = $s['Service']['Code'] ?? '';
                    $name      = ups_service_name($code);
                    $total     = $s['TotalCharges']['MonetaryValue'] ?? 'N/A';
                    $currency  = $s['TotalCharges']['CurrencyCode'] ?? 'USD';
                    $baseChg   = $s['TransportationCharges']['MonetaryValue'] ?? '-';
                    $svcChg    = $s['ServiceOptionsCharges']['MonetaryValue'] ?? '-';
                    $negTotal  = $s['NegotiatedRateCharges']['TotalCharge']['MonetaryValue'] ?? null;
                    $billWt    = $s['BillingWeight']['Weight'] ?? '-';
                    $billWtU   = $s['BillingWeight']['UnitOfMeasurement']['Code'] ?? 'LBS';

                    // Transit time data
                    $guarDays  = $s['GuaranteedDelivery']['BusinessDaysInTransit'] ?? null;
                    $tit       = $s['TimeInTransit']['ServiceSummary']['EstimatedArrival'] ?? [];
                    $titDays   = $tit['BusinessDaysInTransit'] ?? $guarDays ?? '-';
                    $titDay    = $tit['DayOfWeek'] ?? '';
                    $titPickup = $tit['Pickup']['Date'] ?? '';

                    $deliveryStr = ups_get_delivery_string($s, $shipDate);
                    $isGuaranteed = isset($s['GuaranteedDelivery']);

                    // Surcharges
                    $surcharges = [];
                    $rawSurcharges = $s['RatedShipmentAlert'] ?? [];
                    if (isset($rawSurcharges['Code'])) $rawSurcharges = [$rawSurcharges];
                    $itemCharges = $s['ItemizedCharges'] ?? [];
                    if (isset($itemCharges['Code'])) $itemCharges = [$itemCharges];
                ?>
                <div class="option-card">
                    <div class="option-header">
                        <div>
                            <h3><?= htmlspecialchars($name) ?></h3>
                            <span style="font-size:0.85em;color:#888">Service Code: <?= htmlspecialchars($code) ?></span>
                        </div>
                        <div style="text-align:right">
                            <div class="option-price"><?= htmlspecialchars($currency) ?> $<?= htmlspecialchars($total) ?></div>
                            <?php if ($negTotal): ?>
                                <div style="font-size:0.85em;color:#1565c0;font-weight:600">Negotiated: $<?= htmlspecialchars($negTotal) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="option-details">
                        <div class="detail-item">
                            <span class="detail-label">Estimated Delivery</span>
                            <span class="detail-value"><?= htmlspecialchars($deliveryStr) ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Business Days</span>
                            <span class="detail-value"><?= htmlspecialchars($titDays) ?> day(s)</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Guaranteed</span>
                            <span class="detail-value">
                                <span class="badge <?= $isGuaranteed ? 'badge-yes' : 'badge-warn' ?>">
                                    <?= $isGuaranteed ? 'Yes' : 'No' ?>
                                </span>
                            </span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Billing Weight</span>
                            <span class="detail-value"><?= htmlspecialchars($billWt) ?> <?= htmlspecialchars($billWtU) ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Transportation</span>
                            <span class="detail-value">$<?= htmlspecialchars($baseChg) ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Service Options</span>
                            <span class="detail-value">$<?= htmlspecialchars($svcChg) ?></span>
                        </div>
                        <?php if ($titPickup): ?>
                            <div class="detail-item">
                                <span class="detail-label">Pickup Date</span>
                                <span class="detail-value"><?= ups_format_date($titPickup) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($titDay): ?>
                            <div class="detail-item">
                                <span class="detail-label">Delivery Day</span>
                                <span class="detail-value"><?= htmlspecialchars($titDay) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($rawSurcharges || $itemCharges): ?>
                        <details class="surcharges">
                            <summary>Alerts &amp; Itemized Charges (<?= count($rawSurcharges) + count($itemCharges) ?>)</summary>
                            <div class="surcharge-list">
                                <?php foreach ($rawSurcharges as $alert): ?>
                                    <div style="margin-bottom:4px">
                                        [<?= htmlspecialchars($alert['Code'] ?? '') ?>]
                                        <?= htmlspecialchars($alert['Description'] ?? '') ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php foreach ($itemCharges as $ic): ?>
                                    <div style="margin-bottom:4px">
                                        Charge <?= htmlspecialchars($ic['Code'] ?? '') ?>:
                                        $<?= htmlspecialchars($ic['MonetaryValue'] ?? '0') ?>
                                        <?= !empty($ic['SubType']) ? '(' . htmlspecialchars($ic['SubType']) . ')' : '' ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <!-- Comparison table (for quick integration reference) -->
            <div class="card" style="margin-top:20px">
                <h2>Quick Comparison Table</h2>
                <div style="overflow-x:auto">
                    <table class="results">
                        <thead>
                            <tr>
                                <th>Service</th>
                                <th>Code</th>
                                <th>Published Rate</th>
                                <th>Negotiated</th>
                                <th>Days</th>
                                <th>Est. Delivery</th>
                                <th>Guaranteed</th>
                                <th>Bill Weight</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rated as $s): ?>
                            <?php
                                $c2 = $s['Service']['Code'] ?? '';
                                $t2 = $s['TotalCharges']['MonetaryValue'] ?? '-';
                                $n2 = $s['NegotiatedRateCharges']['TotalCharge']['MonetaryValue'] ?? '-';
                                $d2 = $s['GuaranteedDelivery']['BusinessDaysInTransit']
                                    ?? $s['TimeInTransit']['ServiceSummary']['EstimatedArrival']['BusinessDaysInTransit'] ?? '-';
                                $del2 = ups_get_delivery_string($s, $shipDate);
                                $g2 = isset($s['GuaranteedDelivery']);
                                $bw2 = ($s['BillingWeight']['Weight'] ?? '-') . ' ' . ($s['BillingWeight']['UnitOfMeasurement']['Code'] ?? '');
                            ?>
                            <tr>
                                <td><?= htmlspecialchars(ups_service_name($c2)) ?></td>
                                <td><?= htmlspecialchars($c2) ?></td>
                                <td class="price">$<?= htmlspecialchars($t2) ?></td>
                                <td><?= $n2 !== '-' ? '<span class="price">$' . htmlspecialchars($n2) . '</span>' : '-' ?></td>
                                <td><?= htmlspecialchars($d2) ?></td>
                                <td><?= htmlspecialchars($del2) ?></td>
                                <td><span class="badge <?= $g2 ? 'badge-yes' : 'badge-warn' ?>"><?= $g2 ? 'Yes' : 'No' ?></span></td>
                                <td><?= htmlspecialchars($bw2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <p class="raw-toggle" onclick="toggleRaw('raw-json')">&#9654; Show raw JSON response (for integration reference)</p>
        <pre class="raw-json" id="raw-json"><?= htmlspecialchars(json_encode($result['data'] ?? $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
    <?php endif; ?>
</div>

<script>
function toggleRaw(id) {
    const el = document.getElementById(id);
    el.style.display = el.style.display === 'block' ? 'none' : 'block';
}
</script>
</body>
</html>
