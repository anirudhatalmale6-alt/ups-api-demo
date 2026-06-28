<?php
/**
 * PAGE 2: All Available USPS Shipping Options with Delivery Dates
 *
 * Shows every USPS mail class available for a given origin/destination with:
 *   - Service name and mail class code
 *   - Retail and/or commercial rate
 *   - Estimated delivery date
 *   - Zone used for pricing
 *   - SKU code
 *
 * Uses the base-rates-list/search endpoint to get rates for multiple mail
 * classes in a single API call, then fetches service standard estimates
 * for delivery dates.
 *
 * APIs used:
 *   Prices:    POST /prices/v3/base-rates-list/search
 *   Standards: GET  /service-standards/v3/estimates
 *
 * Docs: https://developers.usps.com/apis
 */
require_once __DIR__ . '/usps_config.php';

$result   = null;
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && usps_credentials_set()) {
    $auth = usps_get_token();
    if (isset($auth['error'])) {
        $errorMsg = $auth['error'];
    } else {
        $token = $auth['token'];

        $mailingDate = usps_clean($_POST['mailing_date'] ?? date('Y-m-d'));
        $priceType   = usps_clean($_POST['price_type'] ?? 'RETAIL');
        $originZip   = usps_clean($_POST['from_zip'] ?? '');
        $destZip     = usps_clean($_POST['to_zip'] ?? '');
        $weight      = max(0.1, (float)($_POST['weight'] ?? 80));
        $length      = max(0.1, (float)($_POST['pkg_length'] ?? 10));
        $width       = max(0.1, (float)($_POST['pkg_width'] ?? 8));
        $height      = max(0.1, (float)($_POST['pkg_height'] ?? 6));

        $allMailClasses = array_keys(USPS_MAIL_CLASSES);

        // Strategy: try base-rates-list first (multiple classes at once),
        // then fall back to individual base-rates calls per class.
        $payload = [
            'originZIPCode'      => $originZip,
            'destinationZIPCode' => $destZip,
            'weight'             => $weight,
            'length'             => $length,
            'width'              => $width,
            'height'             => $height,
            'mailClasses'        => $allMailClasses,
            'priceType'          => $priceType,
            'mailingDate'        => $mailingDate,
            'destinationEntryFacilityType' => 'NONE',
        ];

        if ($priceType === 'COMMERCIAL' && USPS_ACCOUNT_NUMBER) {
            $payload['accountType']   = 'EPS';
            $payload['accountNumber'] = USPS_ACCOUNT_NUMBER;
        }

        $listResult = usps_api_post(USPS_RATES_LIST_URL, $payload, $token);

        $allRates = [];
        $rawResponses = [];

        if (!isset($listResult['error'])) {
            // base-rates-list succeeded - parse the response
            $rawResponses['base-rates-list'] = $listResult['data'];
            $listData = $listResult['data'];

            // Handle different response structures
            if (isset($listData['rateOptions'])) {
                $allRates = $listData['rateOptions'];
            } elseif (isset($listData['rates'])) {
                $allRates = is_array($listData['rates']) ? $listData['rates'] : [$listData['rates']];
            } elseif (isset($listData[0])) {
                $allRates = $listData;
            } else {
                // Response might be keyed differently - store as single entry
                $allRates = [$listData];
            }
        } else {
            // Fallback: call base-rates individually per class
            $rawResponses['base-rates-list-error'] = $listResult;

            foreach ($allMailClasses as $mc) {
                $singlePayload = [
                    'originZIPCode'      => $originZip,
                    'destinationZIPCode' => $destZip,
                    'weight'             => $weight,
                    'length'             => $length,
                    'width'              => $width,
                    'height'             => $height,
                    'mailClass'          => $mc,
                    'processingCategory' => 'MACHINABLE',
                    'rateIndicator'      => 'SP',
                    'destinationEntryFacilityType' => 'NONE',
                    'priceType'          => $priceType,
                    'mailingDate'        => $mailingDate,
                ];

                if ($priceType === 'COMMERCIAL' && USPS_ACCOUNT_NUMBER) {
                    $singlePayload['accountType']   = 'EPS';
                    $singlePayload['accountNumber'] = USPS_ACCOUNT_NUMBER;
                }

                $singleResult = usps_api_post(USPS_BASE_RATES_URL, $singlePayload, $token);

                if (!isset($singleResult['error'])) {
                    $rateEntry = $singleResult['data'];
                    $rateEntry['_mailClass'] = $mc;
                    $allRates[] = $rateEntry;
                    $rawResponses['base-rates'][$mc] = $singleResult['data'];
                } else {
                    $rawResponses['base-rates-errors'][$mc] = $singleResult;
                }
            }
        }

        // Fetch delivery estimates for each mail class
        $estimates = [];
        foreach ($allMailClasses as $mc) {
            $estResult = usps_api_get(USPS_ESTIMATES_URL, [
                'originZIPCode'      => $originZip,
                'destinationZIPCode' => $destZip,
                'acceptanceDate'     => $mailingDate,
                'mailClass'          => $mc,
            ], $token);

            if (!isset($estResult['error'])) {
                $estimates[$mc] = $estResult['data'];
            }
        }
        $rawResponses['estimates'] = $estimates;

        $result = [
            'rates'     => $allRates,
            'estimates' => $estimates,
            'raw'       => $rawResponses,
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>USPS All Shipping Options &amp; Delivery Dates</title>
    <style><?= usps_common_css() ?></style>
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
        .option-header h3 { color: #004B87; font-size: 1.1em; margin: 0; }
        .option-price { font-size: 1.4em; font-weight: 700; color: #27ae60; }
        .option-details {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 8px; font-size: 0.9em;
        }
        .detail-item { display: flex; flex-direction: column; }
        .detail-label { font-size: 0.8em; color: #888; font-weight: 600; text-transform: uppercase; }
        .detail-value { font-weight: 500; }
        .summary-bar {
            display: flex; gap: 20px; flex-wrap: wrap; padding: 15px 20px;
            background: #004B87; color: #fff; border-radius: 8px; margin-bottom: 20px;
            font-size: 0.95em;
        }
        .summary-bar .stat { display: flex; flex-direction: column; }
        .summary-bar .stat-label { font-size: 0.75em; opacity: 0.7; text-transform: uppercase; }
        .summary-bar .stat-value { font-weight: 700; font-size: 1.1em; }
    </style>
</head>
<body>
<div class="container">
    <h1>USPS All Shipping Options</h1>
    <p class="subtitle">
        Page 2 of 2 &mdash; Every available service with cost &amp; delivery date
        <span class="env-badge <?= USPS_SANDBOX ? 'env-sandbox' : 'env-production' ?>">
            <?= USPS_SANDBOX ? 'TEST' : 'PRODUCTION' ?>
        </span>
    </p>
    <div class="nav">
        <a href="usps_rate_check.php">Rate Check &amp; Address</a>
        <a href="usps_shipping_options.php" class="active">All Shipping Options</a>
    </div>

    <?php if (!usps_credentials_set()): ?>
        <div class="alert alert-warn">
            Open <code>usps_config.php</code> and set your USPS credentials first.
            <br><small>Register at <a href="https://developers.usps.com" target="_blank">developers.usps.com</a></small>
        </div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <div class="alert alert-error"><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="POST">
            <h2>Shipment Details</h2>
            <div class="form-grid">
                <div class="form-group"><label>Origin ZIP Code</label>
                    <input name="from_zip" value="<?= usps_clean($_POST['from_zip'] ?? '10001') ?>" maxlength="5" required>
                    <span class="hint">5-digit ZIP</span></div>
                <div class="form-group"><label>Destination ZIP Code</label>
                    <input name="to_zip" value="<?= usps_clean($_POST['to_zip'] ?? '90001') ?>" maxlength="5" required>
                    <span class="hint">5-digit ZIP</span></div>
            </div>

            <div class="form-grid" style="margin-top:20px">
                <div class="form-group full" style="grid-column:1/-1"><label style="font-size:1em;color:#004B87">PACKAGE DETAILS</label></div>
                <div class="form-group"><label>Weight (oz)</label>
                    <input name="weight" type="number" step="0.1" min="0.1" value="<?= usps_clean($_POST['weight'] ?? '80') ?>" required>
                    <span class="hint">In ounces (16 oz = 1 lb)</span></div>
                <div class="form-group"><label>Length (in)</label>
                    <input name="pkg_length" type="number" step="0.1" value="<?= usps_clean($_POST['pkg_length'] ?? '10') ?>"></div>
                <div class="form-group"><label>Width (in)</label>
                    <input name="pkg_width" type="number" step="0.1" value="<?= usps_clean($_POST['pkg_width'] ?? '8') ?>"></div>
                <div class="form-group"><label>Height (in)</label>
                    <input name="pkg_height" type="number" step="0.1" value="<?= usps_clean($_POST['pkg_height'] ?? '6') ?>"></div>
                <div class="form-group"><label>Mailing Date</label>
                    <input name="mailing_date" type="date" value="<?= usps_clean($_POST['mailing_date'] ?? date('Y-m-d')) ?>"></div>
                <div class="form-group"><label>Price Type</label>
                    <select name="price_type">
                        <?php foreach (USPS_PRICE_TYPES as $code => $name): ?>
                            <option value="<?= $code ?>" <?= ($_POST['price_type'] ?? 'RETAIL') === $code ? 'selected' : '' ?>>
                                <?= htmlspecialchars($name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="hint">RETAIL = standard. COMMERCIAL = with EPS account.</span></div>
            </div>

            <button type="submit" class="btn" <?= !usps_credentials_set() ? 'disabled' : '' ?>>Show All Shipping Options</button>
        </form>
    </div>

    <!-- ================================================================ -->
    <!-- RESULTS                                                          -->
    <!-- ================================================================ -->
    <?php if ($result): ?>
        <?php
            $allRates  = $result['rates'] ?? [];
            $estimates = $result['estimates'] ?? [];
            $rawData   = $result['raw'] ?? [];

            // Normalize rates into a consistent structure for display
            $displayRates = [];
            foreach ($allRates as $rate) {
                // The rate might be nested differently
                $entry = [
                    'mailClass'   => '',
                    'description' => '',
                    'price'       => 0,
                    'zone'        => '',
                    'SKU'         => '',
                    'dimWeight'   => '',
                    'weight'      => '',
                    'fees'        => [],
                    'raw'         => $rate,
                ];

                // Try various field names the API might use
                $entry['mailClass']   = $rate['mailClass'] ?? $rate['_mailClass'] ?? $rate['mail_class'] ?? '';
                $entry['description'] = $rate['description'] ?? $rate['mailClassName'] ?? '';
                $entry['zone']        = $rate['zone'] ?? '';
                $entry['SKU']         = $rate['SKU'] ?? $rate['sku'] ?? '';
                $entry['dimWeight']   = $rate['dimWeight'] ?? $rate['dimensionalWeight'] ?? '';
                $entry['weight']      = $rate['weight'] ?? '';
                $entry['fees']        = $rate['fees'] ?? [];

                // Price - try multiple fields
                $entry['price'] = $rate['totalBasePrice'] ?? $rate['totalPrice'] ?? $rate['price']
                    ?? $rate['basePrice'] ?? $rate['rate'] ?? 0;

                if (!$entry['description'] && $entry['mailClass']) {
                    $entry['description'] = usps_mail_class_name($entry['mailClass']);
                }

                if ($entry['mailClass'] || $entry['price'] > 0) {
                    $displayRates[] = $entry;
                }
            }

            // Sort by price ascending
            usort($displayRates, fn($a, $b) => ((float)$a['price']) <=> ((float)$b['price']));

            // Summary stats
            $totalOptions = count($displayRates);
            $cheapest = $displayRates ? '$' . number_format((float)$displayRates[0]['price'], 2) : 'N/A';
            $fastest = 'N/A';
        ?>

        <?php if (empty($displayRates)): ?>
            <div class="alert alert-warn">
                No rates returned. This can happen if the package dimensions/weight don't match any available service,
                or if the API credentials don't have access to the Prices endpoint.
                Check the raw JSON below for details.
            </div>
        <?php else: ?>
            <div class="summary-bar">
                <div class="stat"><span class="stat-label">Services Found</span><span class="stat-value"><?= $totalOptions ?></span></div>
                <div class="stat"><span class="stat-label">Cheapest</span><span class="stat-value"><?= htmlspecialchars($cheapest) ?></span></div>
                <div class="stat"><span class="stat-label">Mailing Date</span><span class="stat-value"><?= htmlspecialchars(usps_format_date(usps_clean($_POST['mailing_date'] ?? date('Y-m-d')))) ?></span></div>
                <div class="stat"><span class="stat-label">Route</span><span class="stat-value"><?= usps_clean($_POST['from_zip'] ?? '') ?> &rarr; <?= usps_clean($_POST['to_zip'] ?? '') ?></span></div>
            </div>

            <?php foreach ($displayRates as $idx => $r): ?>
                <?php
                    $mc = $r['mailClass'];
                    $est = $estimates[$mc] ?? [];
                    $deliveryDate = usps_extract_delivery_date($est);
                    $deliveryDays = usps_extract_delivery_days($est);
                    $price = (float)$r['price'];
                ?>
                <div class="option-card">
                    <div class="option-header">
                        <div>
                            <h3><?= htmlspecialchars($r['description'] ?: usps_mail_class_name($mc)) ?></h3>
                            <span style="font-size:0.85em;color:#888">Mail Class: <?= htmlspecialchars($mc) ?></span>
                        </div>
                        <div style="text-align:right">
                            <div class="option-price">$<?= number_format($price, 2) ?></div>
                        </div>
                    </div>

                    <div class="option-details">
                        <div class="detail-item">
                            <span class="detail-label">Est. Delivery</span>
                            <span class="detail-value"><?= htmlspecialchars($deliveryDate) ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Transit Days</span>
                            <span class="detail-value"><?= htmlspecialchars($deliveryDays) ?> day(s)</span>
                        </div>
                        <?php if ($r['zone']): ?>
                            <div class="detail-item">
                                <span class="detail-label">Zone</span>
                                <span class="detail-value"><?= htmlspecialchars($r['zone']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($r['SKU']): ?>
                            <div class="detail-item">
                                <span class="detail-label">SKU</span>
                                <span class="detail-value"><?= htmlspecialchars($r['SKU']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($r['dimWeight']): ?>
                            <div class="detail-item">
                                <span class="detail-label">Dim. Weight</span>
                                <span class="detail-value"><?= htmlspecialchars($r['dimWeight']) ?> oz</span>
                            </div>
                        <?php endif; ?>
                        <?php if ($r['weight']): ?>
                            <div class="detail-item">
                                <span class="detail-label">Weight</span>
                                <span class="detail-value"><?= htmlspecialchars($r['weight']) ?> oz</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($r['fees']) && is_array($r['fees'])): ?>
                        <details style="margin-top:8px; font-size:0.85em; color:#666">
                            <summary style="cursor:pointer; font-weight:600; color:#004B87">Fees (<?= count($r['fees']) ?>)</summary>
                            <div style="margin-top:5px; padding-left:15px">
                                <?php foreach ($r['fees'] as $fee): ?>
                                    <div style="margin-bottom:4px">
                                        <?= htmlspecialchars($fee['name'] ?? $fee['feeType'] ?? 'Fee') ?>:
                                        $<?= htmlspecialchars(number_format((float)($fee['price'] ?? $fee['amount'] ?? 0), 2)) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <!-- Quick Comparison Table -->
            <div class="card" style="margin-top:20px">
                <h2>Quick Comparison Table</h2>
                <div style="overflow-x:auto">
                    <table class="results">
                        <thead>
                            <tr>
                                <th>Service</th>
                                <th>Mail Class</th>
                                <th>Price</th>
                                <th>Est. Delivery</th>
                                <th>Days</th>
                                <th>Zone</th>
                                <th>SKU</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($displayRates as $r): ?>
                            <?php
                                $mc2 = $r['mailClass'];
                                $est2 = $estimates[$mc2] ?? [];
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($r['description'] ?: usps_mail_class_name($mc2)) ?></td>
                                <td><?= htmlspecialchars($mc2) ?></td>
                                <td class="price">$<?= number_format((float)$r['price'], 2) ?></td>
                                <td><?= htmlspecialchars(usps_extract_delivery_date($est2)) ?></td>
                                <td><?= htmlspecialchars(usps_extract_delivery_days($est2)) ?></td>
                                <td><?= htmlspecialchars($r['zone'] ?: '-') ?></td>
                                <td><?= htmlspecialchars($r['SKU'] ?: '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <p class="raw-toggle" onclick="toggleRaw('raw-json')">&#9654; Show raw JSON response (for integration reference)</p>
        <pre class="raw-json" id="raw-json"><?= htmlspecialchars(json_encode($rawData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>

        <?php if (!empty($estimates)): ?>
            <p class="raw-toggle" onclick="toggleRaw('raw-est')">&#9654; Show Service Standards response (debug)</p>
            <pre class="raw-json" id="raw-est"><?= htmlspecialchars(json_encode($estimates, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
        <?php endif; ?>
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
