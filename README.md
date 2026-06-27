# UPS API PHP Demo - Rates, Shipping Options & Address Validation

Two-page PHP demo that connects to the UPS REST API (OAuth 2.0) using the official
API specs from **https://github.com/UPS-API/api-documentation**.

## Files

| File | Purpose |
|------|---------|
| `ups_config.php` | Shared config - credentials, endpoints, helper functions |
| `ups_rate_check.php` | **Page 1** - Rate lookup + Address Validation (tabbed) |
| `ups_shipping_options.php` | **Page 2** - All shipping options with cost & delivery dates |
| `ups_api_demo.php` | Legacy single-file version (standalone, kept for reference) |

## Quick Setup

### 1. Get UPS API Credentials

1. Register at [UPS Developer Portal](https://developer.ups.com)
2. Create an application - you'll get a **Client ID** and **Client Secret**
3. Note your 6-digit **UPS Account/Shipper Number**

### 2. Configure

Open `ups_config.php` and set these three values:

```php
define('UPS_CLIENT_ID',      'your-client-id');
define('UPS_CLIENT_SECRET',  'your-client-secret');
define('UPS_ACCOUNT_NUMBER', '123456');
```

### 3. Sandbox vs Production

```php
define('UPS_SANDBOX', true);   // true = test environment, false = live production
```

| Mode       | Base URL                        | Use For                      |
|------------|---------------------------------|------------------------------|
| Sandbox    | `https://wwwcie.ups.com`        | Testing, no real charges     |
| Production | `https://onlinetools.ups.com`   | Live rates, real API calls   |

### 4. Run

```bash
# Local testing
php -S localhost:8080

# Then open:
# http://localhost:8080/ups_rate_check.php      (Page 1)
# http://localhost:8080/ups_shipping_options.php (Page 2)
```

Or drop the files on any PHP 7.4+ web server with cURL and JSON extensions.

## Requirements

- PHP 7.4+
- `curl` extension (usually enabled by default)
- `json` extension (usually enabled by default)

Check with: `php -m | grep -E 'curl|json'`

---

## API Reference (from https://github.com/UPS-API)

All API specs are published as OpenAPI/YAML files at:
https://github.com/UPS-API/api-documentation

### Authentication - OAuth 2.0 Client Credentials

**Spec:** [OAuthClientCredentials.yaml](https://github.com/UPS-API/api-documentation/blob/main/OAuthClientCredentials.yaml)

```
POST /security/v1/oauth/token
Content-Type: application/x-www-form-urlencoded
Authorization: Basic base64(CLIENT_ID:CLIENT_SECRET)

Body: grant_type=client_credentials
```

**Response:**
```json
{
  "token_type": "Bearer",
  "access_token": "eyJra...",
  "expires_in": "14399",
  "status": "approved"
}
```

Token is valid for ~4 hours. In production, cache it and refresh before expiry.

**How to use the token:** Include in all subsequent API calls:
```
Authorization: Bearer {access_token}
```

### Rating API - Rate Check & Shop

**Spec:** [Rating.yaml](https://github.com/UPS-API/api-documentation/blob/main/Rating.yaml)

Two main endpoints:
- `POST /api/rating/v1/Rate` - Get rate for a SINGLE specified service
- `POST /api/rating/v1/Shop` - Get rates for ALL available services (used by this demo)

**Request Headers:**
```
Content-Type: application/json
Accept: application/json
Authorization: Bearer {token}
transId: unique-id-for-debugging
transactionSrc: your-app-name
```

**Request Body (Rate Shop):**
```json
{
  "RateRequest": {
    "Request": {
      "SubVersion": "2403",
      "TransactionReference": {
        "CustomerContext": "your reference string"
      }
    },
    "Shipment": {
      "Shipper": {
        "Name": "Company Name",
        "ShipperNumber": "123456",
        "Address": {
          "AddressLine": ["123 Main St"],
          "City": "New York",
          "StateProvinceCode": "NY",
          "PostalCode": "10001",
          "CountryCode": "US"
        }
      },
      "ShipTo": {
        "Name": "Recipient",
        "Address": {
          "AddressLine": ["456 Oak Ave"],
          "City": "Los Angeles",
          "StateProvinceCode": "CA",
          "PostalCode": "90001",
          "CountryCode": "US",
          "ResidentialAddressIndicator": ""
        }
      },
      "ShipFrom": {
        "Name": "Company Name",
        "Address": { "...same as Shipper..." }
      },
      "Package": {
        "PackagingType": { "Code": "02" },
        "Dimensions": {
          "UnitOfMeasurement": { "Code": "IN" },
          "Length": "10",
          "Width": "8",
          "Height": "6"
        },
        "PackageWeight": {
          "UnitOfMeasurement": { "Code": "LBS" },
          "Weight": "5"
        }
      },
      "ShipmentRatingOptions": {
        "NegotiatedRatesIndicator": ""
      },
      "DeliveryTimeInformation": {
        "PackageBillType": "03",
        "Pickup": {
          "Date": "20260627",
          "Time": "100000"
        }
      }
    }
  }
}
```

**Key fields explained:**

| Field | Values | Notes |
|-------|--------|-------|
| `SubVersion` | `"2403"` | API sub-version, use latest |
| `PackagingType.Code` | `00-30` | 02=Customer Package, 01=Letter, 04=PAK |
| `PackageWeight.UnitOfMeasurement.Code` | `LBS` or `KGS` | |
| `Dimensions.UnitOfMeasurement.Code` | `IN` or `CM` | |
| `CountryCode` | ISO 3166 | US, CA, GB, etc |
| `ShipperNumber` | 6-digit | Your UPS account number |
| `ResidentialAddressIndicator` | `""` (present) or omit | Adds residential surcharge |
| `NegotiatedRatesIndicator` | `""` (present) | Include negotiated/discounted rates |
| `DeliveryTimeInformation` | See below | Include to get delivery date estimates |
| `PackageBillType` | `01`=Document, `02`=WWD, `03`=Non-document | For time-in-transit calculation |

**Response (per RatedShipment):**
```json
{
  "Service": { "Code": "03", "Description": "" },
  "RatedShipmentAlert": [
    { "Code": "110971", "Description": "Your invoice may vary..." }
  ],
  "BillingWeight": {
    "UnitOfMeasurement": { "Code": "LBS", "Description": "Pounds" },
    "Weight": "5.0"
  },
  "TransportationCharges": { "CurrencyCode": "USD", "MonetaryValue": "10.50" },
  "ServiceOptionsCharges": { "CurrencyCode": "USD", "MonetaryValue": "0.00" },
  "TotalCharges": { "CurrencyCode": "USD", "MonetaryValue": "10.50" },
  "NegotiatedRateCharges": {
    "TotalCharge": { "CurrencyCode": "USD", "MonetaryValue": "8.75" }
  },
  "GuaranteedDelivery": {
    "BusinessDaysInTransit": "5",
    "DeliveryByTime": ""
  },
  "TimeInTransit": {
    "ServiceSummary": {
      "Service": { "Description": "UPS Ground" },
      "EstimatedArrival": {
        "Arrival": { "Date": "20260702", "Time": "233000" },
        "BusinessDaysInTransit": "3",
        "Pickup": { "Date": "20260627", "Time": "190000" },
        "DayOfWeek": "THU",
        "CustomerCenterCutoff": "160000",
        "TotalTransitDays": "3"
      },
      "GuaranteedIndicator": ""
    }
  }
}
```

**Important response fields for your application:**

| Path | What It Contains |
|------|-----------------|
| `RatedShipment[].Service.Code` | Service code (see table below) |
| `RatedShipment[].TotalCharges.MonetaryValue` | Published rate |
| `RatedShipment[].NegotiatedRateCharges.TotalCharge.MonetaryValue` | Your discounted rate |
| `RatedShipment[].TransportationCharges.MonetaryValue` | Base transportation cost |
| `RatedShipment[].ServiceOptionsCharges.MonetaryValue` | Additional service charges |
| `RatedShipment[].BillingWeight.Weight` | Actual weight used for billing (may differ from actual if dimensional) |
| `RatedShipment[].GuaranteedDelivery.BusinessDaysInTransit` | Guaranteed transit days |
| `RatedShipment[].TimeInTransit.ServiceSummary.EstimatedArrival.Arrival.Date` | Estimated delivery date (YYYYMMDD) |
| `RatedShipment[].TimeInTransit.ServiceSummary.EstimatedArrival.Arrival.Time` | Estimated delivery time (HHMMSS) |
| `RatedShipment[].TimeInTransit.ServiceSummary.EstimatedArrival.DayOfWeek` | Day of week |
| `RatedShipment[].RatedShipmentAlert` | Warnings/alerts about the rate |

### Address Validation API

**Spec:** [AddressValidation.yaml](https://github.com/UPS-API/api-documentation/blob/main/AddressValidation.yaml)

```
POST /api/addressvalidation/v1/{requestOption}
```

| requestOption | Behavior |
|---------------|----------|
| `1` | Address Validation only |
| `2` | Address Classification only (Residential/Commercial) |
| `3` | Address Validation + Classification (recommended) |

**Query Parameters:**
- `regionalrequestindicator` - `true` for non-US, `false` for US addresses
- `maximumcandidatelistsize` - Max candidates to return (1-15)

**Request Body:**
```json
{
  "XAVRequest": {
    "AddressKeyFormat": {
      "ConsigneeName": "Company Name",
      "AddressLine": ["1 Wall St"],
      "PoliticalDivision2": "New York",
      "PoliticalDivision1": "NY",
      "PostcodePrimaryLow": "10005",
      "CountryCode": "US"
    }
  }
}
```

**Response Indicators:**

| Field | Meaning |
|-------|---------|
| `ValidAddressIndicator` | Present = address is valid, exact match |
| `AmbiguousAddressIndicator` | Present = multiple possible matches |
| `NoCandidatesIndicator` | Present = no matching address found |

**Classification Codes:**

| Code | Description |
|------|-------------|
| `0` | Unknown |
| `1` | Commercial |
| `2` | Residential |

**Response Body:**
```json
{
  "XAVResponse": {
    "Response": { "ResponseStatus": { "Code": "1", "Description": "Success" } },
    "ValidAddressIndicator": "",
    "AddressClassification": { "Code": "1", "Description": "Commercial" },
    "Candidate": [
      {
        "AddressClassification": { "Code": "1", "Description": "Commercial" },
        "AddressKeyFormat": {
          "AddressLine": ["1 WALL ST"],
          "PoliticalDivision2": "NEW YORK",
          "PoliticalDivision1": "NY",
          "PostcodePrimaryLow": "10005",
          "PostcodeExtendedLow": "1401",
          "CountryCode": "US"
        }
      }
    ]
  }
}
```

## UPS Service Codes

| Code | Service Name |
|------|-------------|
| `01` | UPS Next Day Air |
| `02` | UPS 2nd Day Air |
| `03` | UPS Ground |
| `07` | UPS Worldwide Express |
| `08` | UPS Worldwide Expedited |
| `11` | UPS Standard |
| `12` | UPS 3 Day Select |
| `13` | UPS Next Day Air Saver |
| `14` | UPS Next Day Air Early |
| `54` | UPS Worldwide Express Plus |
| `59` | UPS 2nd Day Air A.M. |
| `65` | UPS Worldwide Saver |
| `92` | UPS SurePost Less than 1LB |
| `93` | UPS SurePost 1LB or Greater |
| `96` | UPS Worldwide Express Freight |

## Packaging Type Codes

| Code | Type |
|------|------|
| `00` | Unknown |
| `01` | UPS Letter |
| `02` | Customer Supplied Package |
| `03` | Tube |
| `04` | PAK |
| `21` | UPS Express Box |
| `24` | UPS 25KG Box |
| `25` | UPS 10KG Box |
| `30` | Pallet |

## Error Handling

The demo distinguishes three error categories:

| Type | How to Identify | Common Causes |
|------|-----------------|---------------|
| **NETWORK ERROR** | cURL fails | DNS, timeout, SSL cert issues |
| **AUTH ERROR** | OAuth returns non-200 | Wrong Client ID/Secret, expired app |
| **API ERROR** | UPS returns error code | Invalid address, bad account number |

**Common UPS Error Codes:**

| Code | Meaning | Fix |
|------|---------|-----|
| `250003` | Invalid authentication | Check Client ID / Secret |
| `111210` | Invalid shipper number | Verify 6-digit account number |
| `111100` | Invalid address fields | Check required address fields |
| `9110101` | Address not found | Address doesn't exist in UPS database |
| `110971` | Rate may vary | Warning only, not an error |

## Integration Tips

1. **Cache the OAuth token** - it's valid for ~4 hours. Don't request a new one per API call.

2. **Use `/Shop` not `/Rate`** - `/Shop` returns all services in one call. `/Rate` requires you to specify which service code you want.

3. **Include `DeliveryTimeInformation`** in your rate request to get delivery dates in the same call (no need for a separate Time In Transit API call).

4. **Include `NegotiatedRatesIndicator`** to see your negotiated/discounted rates alongside published rates.

5. **Include `ResidentialAddressIndicator`** in ShipTo when shipping to homes - this adds the residential surcharge to the rate so your quoted price is accurate.

6. **Address Validation first** - validate the destination address before requesting rates. Invalid addresses can cause rate lookups to fail or return inaccurate results.

7. **Raw JSON** - both pages include a "Show raw JSON response" toggle. Use this to see exactly what UPS returns so you know what fields to parse in your application.

8. **SubVersion** - use `"2403"` or later. Older sub-versions may not include all response fields.

## Official Resources

- **API Documentation (OpenAPI/YAML):** https://github.com/UPS-API/api-documentation
- **Developer Portal:** https://developer.ups.com
- **API Reference:** https://developer.ups.com/api/reference
- **OAuth Migration Guide:** https://developer.ups.com/oauth-developer-guide
- **Postman Collection:** https://www.postman.com/ups-api/ups-apis/overview
