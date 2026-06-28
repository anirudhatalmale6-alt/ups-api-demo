<?php
/**
 * PAGE 1: USPS Rate Check & Address Validation
 *
 * - Enter origin + destination ZIP + package details to get a rate quote
 * - Enter any US address to validate and standardize it (ZIP+4, DPV, etc.)
 *
 * APIs used:
 *   Prices:    POST /prices/v3/base-rates/search
 *   Addresses: GET  /addresses/v3/address
 *
 * Docs: https://developers.usps.com/apis
 */
require_once __DIR__ . '/usps_config.php';

$rateResult    = null;
$addressResult = null;
$activeTab     = $_POST['action'] ?? 'rates';
if ($activeTab === 'validate_address') $activeTab = 'address';
if ($activeTab === 'get_rates') $activeTab = 'rates';
$errorMsg = '';

// ============================================================================
// PROCESS FORM SUBMISSIONS
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && usps_credentials_set()) {
    $auth = usps_get_token();
    if (isset($auth['error'])) {
        $errorMsg = $auth['error'];
    } else {
        $token  = $auth['token'];
        $action = $_POST['action'] ?? '';

        // --- RATE CHECK ---
        if ($action === 'get_rates') {
            $activeTab = 'rates';

            $mailClass = usps_clean($_POST['mail_class'] ?? 'PRIORITY_MAIL');
            $payload   = usps_build_rate_payload($_POST, $mailClass);

            $rateResult = usps_api_post(USPS_BASE_RATES_URL, $payload, $token);

            // Also fetch delivery estimate for this mail class
            if (!isset($rateResult['error'])) {
                $estResult = usps_api_get(USPS_ESTIMATES_URL, [
                    'originZIPCode'      => usps_clean($_POST['from_zip'] ?? ''),
                    'destinationZIPCode' => usps_clean($_POST['to_zip'] ?? ''),
                    'acceptanceDate'     => usps_clean($_POST['mailing_date'] ?? date('Y-m-d')),
                    'mailClass'          => $mailClass,
                ], $token);
                $GLOBALS['_usps_estimate_debug'] = $estResult;
                if (!isset($estResult['error'])) {
                    $rateResult['_estimate'] = $estResult['data'];
                }
            }
        }

        // --- ADDRESS VALIDATION ---
        if ($action === 'validate_address') {
            $activeTab = 'address';

            $addressResult = usps_api_get(USPS_ADDRESS_URL, [
                'streetAddress'    => usps_clean($_POST['addr_street'] ?? ''),
                'secondaryAddress' => usps_clean($_POST['addr_street2'] ?? ''),
                'city'             => usps_clean($_POST['addr_city'] ?? ''),
                'state'            => usps_clean($_POST['addr_state'] ?? ''),
                'ZIPCode'          => usps_clean($_POST['addr_zip'] ?? ''),
            ], $token);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>USPS Rate Check &amp; Address Validation</title>
    <style><?= usps_common_css() ?></style>
    <style>
        .tabs { display: flex; gap: 0; margin-bottom: 0; }
        .tab {
            flex: 1; padding: 14px 20px; text-align: center; background: #ddd;
            border: none; cursor: pointer; font-size: 1em; font-weight: 600;
            color: #555; border-radius: 8px 8px 0 0; transition: background 0.2s;
        }
        .tab:hover { background: #ccc; }
        .tab.active { background: #fff; color: #004B87; }
        .panel { display: none; background: #fff; padding: 25px;
            border-radius: 0 0 8px 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .panel.active { display: block; }
    </style>
</head>
<body>
<div class="container">
    <h1>USPS API - Rate Check &amp; Address Validation</h1>
    <p class="subtitle">
        Page 1 of 2 &mdash;
        <span class="env-badge <?= USPS_SANDBOX ? 'env-sandbox' : 'env-production' ?>">
            <?= USPS_SANDBOX ? 'TEST' : 'PRODUCTION' ?>
        </span>
    </p>
    <div class="nav">
        <a href="usps_rate_check.php" class="active">Rate Check &amp; Address</a>
        <a href="usps_shipping_options.php">All Shipping Options</a>
    </div>

    <?php if (!usps_credentials_set()): ?>
        <div class="alert alert-warn">
            Open <code>usps_config.php</code> and replace <code>YOUR_CLIENT_ID</code> and
            <code>YOUR_CLIENT_SECRET</code> with your USPS Developer Portal credentials.
            <br><small>Register at <a href="https://developers.usps.com" target="_blank">developers.usps.com</a></small>
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

            <h2>Origin &amp; Destination</h2>
            <div class="form-grid">
                <div class="form-group"><label>Origin ZIP Code</label>
                    <input name="from_zip" value="<?= usps_clean($_POST['from_zip'] ?? '10001') ?>" maxlength="5" required>
                    <span class="hint">5-digit ZIP</span></div>
                <div class="form-group"><label>Destination ZIP Code</label>
                    <input name="to_zip" value="<?= usps_clean($_POST['to_zip'] ?? '90001') ?>" maxlength="5" required>
                    <span class="hint">5-digit ZIP</span></div>
            </div>

            <h2 style="margin-top:20px">Package Details</h2>
            <div class="form-grid">
                <div class="form-group"><label>Weight (oz)</label>
                    <input name="weight" type="number" step="0.1" min="0.1" value="<?= usps_clean($_POST['weight'] ?? '80') ?>" required>
                    <span class="hint">In ounces (16 oz = 1 lb)</span></div>
                <div class="form-group"><label>Mail Class</label>
                    <select name="mail_class">
                        <?php foreach (USPS_MAIL_CLASSES as $code => $name): ?>
                            <option value="<?= $code ?>" <?= ($_POST['mail_class'] ?? 'PRIORITY_MAIL') === $code ? 'selected' : '' ?>>
                                <?= htmlspecialchars($name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="form-group"><label>Length (in)</label>
                    <input name="pkg_length" type="number" step="0.1" value="<?= usps_clean($_POST['pkg_length'] ?? '10') ?>"></div>
                <div class="form-group"><label>Width (in)</label>
                    <input name="pkg_width" type="number" step="0.1" value="<?= usps_clean($_POST['pkg_width'] ?? '8') ?>"></div>
                <div class="form-group"><label>Height (in)</label>
                    <input name="pkg_height" type="number" step="0.1" value="<?= usps_clean($_POST['pkg_height'] ?? '6') ?>"></div>
                <div class="form-group"><label>Mailing Date</label>
                    <input name="mailing_date" type="date" value="<?= usps_clean($_POST['mailing_date'] ?? date('Y-m-d')) ?>"></div>
            </div>

            <h2 style="margin-top:20px">Pricing Options</h2>
            <div class="form-grid">
                <div class="form-group"><label>Price Type</label>
                    <select name="price_type">
                        <?php foreach (USPS_PRICE_TYPES as $code => $name): ?>
                            <option value="<?= $code ?>" <?= ($_POST['price_type'] ?? 'RETAIL') === $code ? 'selected' : '' ?>>
                                <?= htmlspecialchars($name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="hint">RETAIL = no account needed. COMMERCIAL = requires EPS account.</span></div>
                <div class="form-group"><label>Rate Indicator</label>
                    <select name="rate_indicator">
                        <?php foreach (USPS_RATE_INDICATORS as $code => $name): ?>
                            <option value="<?= $code ?>" <?= ($_POST['rate_indicator'] ?? 'SP') === $code ? 'selected' : '' ?>>
                                <?= htmlspecialchars($name) ?> (<?= $code ?>)
                            </option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="form-group"><label>Processing Category</label>
                    <select name="processing_category">
                        <?php foreach (USPS_PROCESSING_CATEGORIES as $code => $name): ?>
                            <option value="<?= $code ?>" <?= ($_POST['processing_category'] ?? 'MACHINABLE') === $code ? 'selected' : '' ?>>
                                <?= htmlspecialchars($name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select></div>
            </div>

            <button type="submit" class="btn" <?= !usps_credentials_set() ? 'disabled' : '' ?>>Get Shipping Rate</button>
        </form>

        <?php if ($rateResult): ?>
            <hr class="sep">
            <?php if (isset($rateResult['error'])): ?>
                <div class="alert alert-error"><strong>Rate Request Failed:</strong> <?= htmlspecialchars($rateResult['error']) ?></div>
                <?php if (!empty($rateResult['raw'])): ?>
                    <p class="raw-toggle" onclick="toggleRaw('raw-rate-err')">&#9654; Show error details</p>
                    <pre class="raw-json" id="raw-rate-err"><?= htmlspecialchars(json_encode($rateResult['raw'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
                <?php endif; ?>
            <?php else: ?>
                <?php
                    $rateData = $rateResult['data'] ?? [];
                    $estData  = $rateResult['_estimate'] ?? [];
                    $deliveryDate = usps_extract_delivery_date($estData);
                    $deliveryDays = usps_extract_delivery_days($estData);
                    $mailClass = usps_clean($_POST['mail_class'] ?? 'PRIORITY_MAIL');
                ?>
                <div class="alert alert-success">
                    <strong><?= htmlspecialchars(usps_mail_class_name($mailClass)) ?></strong> rate retrieved successfully
                </div>

                <table class="results">
                    <thead><tr><th>Field</th><th>Value</th></tr></thead>
                    <tbody>
                        <tr><td>Mail Class</td><td><strong><?= htmlspecialchars(usps_mail_class_name($mailClass)) ?></strong></td></tr>
                        <?php
                            // Display all rate fields from response
                            $displayFields = [
                                'totalBasePrice' => 'Base Price',
                                'totalPrice'     => 'Total Price',
                                'price'          => 'Price',
                                'SKU'            => 'SKU',
                                'zone'           => 'Zone',
                                'dimWeight'      => 'Dimensional Weight',
                                'weight'         => 'Weight (oz)',
                                'fees'           => 'Fees',
                                'extraServices'  => 'Extra Services',
                            ];
                            // Handle nested rates array or flat response
                            $rateInfo = $rateData;
                            if (isset($rateData['rates']) && is_array($rateData['rates'])) {
                                $rateInfo = $rateData['rates'][0] ?? $rateData;
                            } elseif (isset($rateData['rateOptions']) && is_array($rateData['rateOptions'])) {
                                $rateInfo = $rateData['rateOptions'][0] ?? $rateData;
                            }

                            foreach ($rateInfo as $key => $val):
                                if (is_array($val)) continue;
                                $label = $displayFields[$key] ?? ucwords(str_replace(['_', 'total'], [' ', 'Total '], $key));
                                $isPrice = stripos($key, 'price') !== false || stripos($key, 'Price') !== false;
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($label) ?></td>
                                <td><?php if ($isPrice): ?><span class="price">$<?= htmlspecialchars(number_format((float)$val, 2)) ?></span><?php else: ?><?= htmlspecialchars($val) ?><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if ($deliveryDate !== '-'): ?>
                            <tr><td>Est. Delivery</td><td><strong><?= htmlspecialchars($deliveryDate) ?></strong></td></tr>
                        <?php endif; ?>
                        <?php if ($deliveryDays !== '-'): ?>
                            <tr><td>Transit Days</td><td><?= htmlspecialchars($deliveryDays) ?> day(s)</td></tr>
                        <?php endif; ?>

                        <?php
                            // Show fees if present
                            $fees = $rateInfo['fees'] ?? [];
                            if (is_array($fees) && !empty($fees)):
                                foreach ($fees as $fee):
                        ?>
                            <tr>
                                <td>Fee: <?= htmlspecialchars($fee['name'] ?? $fee['feeType'] ?? 'Fee') ?></td>
                                <td class="price">$<?= htmlspecialchars(number_format((float)($fee['price'] ?? $fee['amount'] ?? 0), 2)) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p class="raw-toggle" onclick="toggleRaw('raw-rate')">&#9654; Show raw JSON response</p>
            <pre class="raw-json" id="raw-rate"><?= htmlspecialchars(json_encode($rateResult['data'] ?? $rateResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>

            <?php if (!empty($GLOBALS['_usps_estimate_debug'])): ?>
                <p class="raw-toggle" onclick="toggleRaw('raw-est')">&#9654; Show Service Standards response (debug)</p>
                <pre class="raw-json" id="raw-est"><?= htmlspecialchars(json_encode($GLOBALS['_usps_estimate_debug'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
            <?php endif; ?>
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
                <div class="form-group full"><label>Street Address</label>
                    <input name="addr_street" value="<?= usps_clean($_POST['addr_street'] ?? '1600 Pennsylvania Ave') ?>" required></div>
                <div class="form-group full"><label>Apt / Suite / Unit (optional)</label>
                    <input name="addr_street2" value="<?= usps_clean($_POST['addr_street2'] ?? '') ?>" placeholder="Suite 100, Apt 2B, etc."></div>
                <div class="form-group"><label>City</label>
                    <input name="addr_city" value="<?= usps_clean($_POST['addr_city'] ?? 'Washington') ?>" required></div>
                <div class="form-group"><label>State</label>
                    <input name="addr_state" value="<?= usps_clean($_POST['addr_state'] ?? 'DC') ?>" maxlength="2" required></div>
                <div class="form-group"><label>ZIP Code</label>
                    <input name="addr_zip" value="<?= usps_clean($_POST['addr_zip'] ?? '20500') ?>">
                    <span class="hint">Optional - API can look it up</span></div>
            </div>
            <button type="submit" class="btn" <?= !usps_credentials_set() ? 'disabled' : '' ?>>Validate Address</button>
        </form>

        <?php if ($addressResult): ?>
            <hr class="sep">
            <?php if (isset($addressResult['error'])): ?>
                <div class="alert alert-error"><strong>Validation Failed:</strong> <?= htmlspecialchars($addressResult['error']) ?></div>
                <?php if (!empty($addressResult['raw'])): ?>
                    <p class="raw-toggle" onclick="toggleRaw('raw-addr-err')">&#9654; Show error details</p>
                    <pre class="raw-json" id="raw-addr-err"><?= htmlspecialchars(json_encode($addressResult['raw'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
                <?php endif; ?>
            <?php else: ?>
                <?php
                    $addrData = $addressResult['data'] ?? [];

                    // Extract address fields (handle different response structures)
                    $addr = $addrData['address'] ?? $addrData;
                    $addrInfo = $addrData['addressAdditionalInfo'] ?? $addrData['additionalInfo'] ?? [];

                    $street = $addr['streetAddress'] ?? $addr['streetAddressAbbreviation'] ?? '';
                    $secondary = $addr['secondaryAddress'] ?? '';
                    $city = $addr['city'] ?? $addr['cityAbbreviation'] ?? '';
                    $state = $addr['state'] ?? '';
                    $zip = $addr['ZIPCode'] ?? $addr['zipCode'] ?? '';
                    $zip4 = $addr['ZIPPlus4'] ?? $addr['zipPlus4'] ?? '';
                    $fullZip = $zip . ($zip4 ? "-$zip4" : '');

                    // DPV and classification info
                    $dpv = $addrInfo['DPVConfirmation'] ?? $addrInfo['dpvConfirmation'] ?? '';
                    $dpvCMRA = $addrInfo['DPVCMRA'] ?? $addrInfo['dpvCMRA'] ?? '';
                    $business = $addrInfo['business'] ?? '';
                    $vacant = $addrInfo['vacant'] ?? '';
                    $carrierRoute = $addrInfo['carrierRoute'] ?? '';
                    $deliveryPoint = $addrInfo['deliveryPoint'] ?? '';

                    // Corrections and footnotes
                    $corrections = $addrData['corrections'] ?? [];
                    $footnotes = $addrData['footnotes'] ?? [];
                    $matches = $addrData['matches'] ?? [];
                ?>

                <?php if ($dpv === 'Y' || $street): ?>
                    <div class="alert alert-success">
                        <strong>ADDRESS VALIDATED</strong>
                        <?php if ($dpv): ?>
                            &mdash; DPV: <span class="badge badge-dpv"><?= htmlspecialchars($dpv) ?></span>
                            <?php if ($dpv === 'Y'): ?>(Confirmed deliverable)
                            <?php elseif ($dpv === 'D'): ?>(Primary confirmed, secondary missing)
                            <?php elseif ($dpv === 'S'): ?>(Secondary not confirmed)
                            <?php elseif ($dpv === 'N'): ?>(Not confirmed)
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warn"><strong>ADDRESS RESPONSE</strong> &mdash; Review the results below.</div>
                <?php endif; ?>

                <div class="card" style="margin-top:15px">
                    <h2>Standardized Address</h2>
                    <table class="results">
                        <tbody>
                            <?php if ($street): ?>
                                <tr><td>Street</td><td><strong><?= htmlspecialchars($street) ?></strong></td></tr>
                            <?php endif; ?>
                            <?php if ($secondary): ?>
                                <tr><td>Secondary</td><td><?= htmlspecialchars($secondary) ?></td></tr>
                            <?php endif; ?>
                            <?php if ($city): ?>
                                <tr><td>City</td><td><?= htmlspecialchars($city) ?></td></tr>
                            <?php endif; ?>
                            <?php if ($state): ?>
                                <tr><td>State</td><td><?= htmlspecialchars($state) ?></td></tr>
                            <?php endif; ?>
                            <?php if ($fullZip): ?>
                                <tr><td>ZIP Code</td><td><strong><?= htmlspecialchars($fullZip) ?></strong></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($addrInfo): ?>
                    <div class="card" style="margin-top:15px">
                        <h2>Additional Info</h2>
                        <table class="results">
                            <tbody>
                                <?php if ($dpv): ?>
                                    <tr><td>DPV Confirmation</td><td>
                                        <span class="badge <?= $dpv === 'Y' ? 'badge-yes' : 'badge-warn' ?>"><?= htmlspecialchars($dpv) ?></span>
                                        <?php
                                            $dpvDesc = [
                                                'Y' => 'Confirmed - address is deliverable',
                                                'D' => 'Primary number confirmed, secondary missing',
                                                'S' => 'Primary confirmed, secondary not confirmed',
                                                'N' => 'Address not confirmed',
                                            ];
                                            echo htmlspecialchars($dpvDesc[$dpv] ?? '');
                                        ?>
                                    </td></tr>
                                <?php endif; ?>
                                <?php if ($dpvCMRA): ?>
                                    <tr><td>CMRA (Commercial Mail Receiving Agency)</td>
                                        <td><span class="badge <?= $dpvCMRA === 'Y' ? 'badge-warn' : 'badge-yes' ?>"><?= htmlspecialchars($dpvCMRA) ?></span>
                                        <?= $dpvCMRA === 'Y' ? 'Yes (e.g., UPS Store, Mailbox Etc.)' : 'No' ?></td></tr>
                                <?php endif; ?>
                                <?php if ($business): ?>
                                    <tr><td>Business</td>
                                        <td><span class="badge <?= $business === 'Y' ? 'badge-com' : 'badge-res' ?>"><?= $business === 'Y' ? 'Commercial' : 'Residential' ?></span></td></tr>
                                <?php endif; ?>
                                <?php if ($vacant): ?>
                                    <tr><td>Vacant</td>
                                        <td><span class="badge <?= $vacant === 'Y' ? 'badge-vacant' : 'badge-yes' ?>"><?= $vacant === 'Y' ? 'VACANT' : 'Occupied' ?></span></td></tr>
                                <?php endif; ?>
                                <?php if ($carrierRoute): ?>
                                    <tr><td>Carrier Route</td><td><?= htmlspecialchars($carrierRoute) ?></td></tr>
                                <?php endif; ?>
                                <?php if ($deliveryPoint): ?>
                                    <tr><td>Delivery Point</td><td><?= htmlspecialchars($deliveryPoint) ?></td></tr>
                                <?php endif; ?>
                                <?php
                                    // Show any other fields in the additional info
                                    $shownFields = ['DPVConfirmation','dpvConfirmation','DPVCMRA','dpvCMRA','business','vacant','carrierRoute','deliveryPoint'];
                                    foreach ($addrInfo as $key => $val):
                                        if (in_array($key, $shownFields) || is_array($val)) continue;
                                ?>
                                    <tr><td><?= htmlspecialchars(ucwords(str_replace(['_', 'dpv', 'cmra'], [' ', 'DPV', 'CMRA'], $key))) ?></td>
                                        <td><?= htmlspecialchars($val) ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if ($corrections): ?>
                    <div class="card" style="margin-top:15px">
                        <h2>Corrections Applied</h2>
                        <?php foreach ((array)$corrections as $c): ?>
                            <div class="candidate">
                                <?php if (is_array($c)): ?>
                                    <p><?= htmlspecialchars(json_encode($c)) ?></p>
                                <?php else: ?>
                                    <p><?= htmlspecialchars($c) ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($footnotes): ?>
                    <div class="card" style="margin-top:15px">
                        <h2>Footnotes</h2>
                        <?php foreach ((array)$footnotes as $fn): ?>
                            <div class="candidate">
                                <?php if (is_array($fn)): ?>
                                    <p><?= htmlspecialchars(json_encode($fn)) ?></p>
                                <?php else: ?>
                                    <p><?= htmlspecialchars($fn) ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="alert alert-info" style="font-size:0.9em; margin-top:15px">
                    <strong>DPV Confirmation Codes:</strong><br>
                    Y = Confirmed deliverable | D = Primary OK, secondary missing |
                    S = Primary OK, secondary not confirmed | N = Not confirmed<br>
                    <strong>CMRA:</strong> Y = Commercial Mail Receiving Agency (UPS Store, etc.) | N = Regular address
                </div>
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
