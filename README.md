# UPS API Demo - Rates & Address Validation

Single-file PHP page that connects to the UPS REST API (OAuth 2.0) and demonstrates:

1. **Rate Shopping** - Submit a shipment and get cost, transit time, and all available UPS services
2. **Address Validation** - Verify whether a US address is valid, with residential/commercial classification

## Requirements

- PHP 7.4 or higher
- `curl` extension (usually enabled by default)
- `json` extension (usually enabled by default)

To check:
```bash
php -m | grep -E 'curl|json'
```

## Setup

### 1. Get UPS API Credentials

1. Go to [UPS Developer Portal](https://developer.ups.com) and create an account
2. Create a new application to get your **Client ID** and **Client Secret**
3. Note your 6-digit UPS **Shipper/Account Number**

### 2. Configure the File

Open `ups_api_demo.php` and edit the top section:

```php
$UPS_CLIENT_ID     = 'your-client-id-here';
$UPS_CLIENT_SECRET = 'your-client-secret-here';
$UPS_ACCOUNT_NUMBER = '123456';  // Your 6-digit shipper number
```

### 3. Sandbox vs Production

The file defaults to sandbox mode for safe testing:

```php
$USE_SANDBOX = true;   // true = sandbox (test data), false = production (live rates)
```

| Mode       | Base URL                        | Notes                        |
|------------|---------------------------------|------------------------------|
| Sandbox    | `https://wwwcie.ups.com`        | Test data, no real charges   |
| Production | `https://onlinetools.ups.com`   | Live rates, real API calls   |

### 4. Run

Drop the file on any PHP-enabled web server, or test locally:

```bash
php -S localhost:8080
```

Then open `http://localhost:8080/ups_api_demo.php` in your browser.

## API Endpoints Used

### OAuth 2.0 Token (Client Credentials)
```
POST /security/v1/oauth/token
Content-Type: application/x-www-form-urlencoded
Authorization: Basic base64(client_id:client_secret)
Body: grant_type=client_credentials
```

**Response:**
```json
{
  "token_type": "Bearer",
  "issued_at": "1719504000000",
  "client_id": "...",
  "access_token": "eyJra...",
  "expires_in": "14399",
  "status": "approved"
}
```

### Rate Shopping
```
POST /api/rating/v1/Shop
Authorization: Bearer {access_token}
Content-Type: application/json
```

Uses the `/Shop` endpoint (not `/Rate`) to return ALL available service options for the given origin/destination/package.

**Sample Request Body:**
```json
{
  "RateRequest": {
    "Request": {
      "SubVersion": "2205",
      "TransactionReference": {
        "CustomerContext": "Rate Shopping Demo"
      }
    },
    "Shipment": {
      "Shipper": {
        "Name": "My Company",
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
        "Name": "Customer",
        "Address": {
          "AddressLine": ["456 Oak Ave"],
          "City": "Los Angeles",
          "StateProvinceCode": "CA",
          "PostalCode": "90001",
          "CountryCode": "US"
        }
      },
      "ShipFrom": { "..." : "same as Shipper address" },
      "Package": {
        "PackagingType": { "Code": "02" },
        "PackageWeight": {
          "UnitOfMeasurement": { "Code": "LBS" },
          "Weight": "5"
        },
        "Dimensions": {
          "UnitOfMeasurement": { "Code": "IN" },
          "Length": "10", "Width": "8", "Height": "6"
        }
      }
    }
  }
}
```

**Sample Response (per rated shipment):**
```json
{
  "Service": { "Code": "03", "Description": "" },
  "TotalCharges": { "CurrencyCode": "USD", "MonetaryValue": "12.45" },
  "GuaranteedDelivery": { "BusinessDaysInTransit": "5" }
}
```

### Address Validation
```
POST /api/addressvalidation/v1/1?regionalrequestindicator=false&maximumcandidatelistsize=10
Authorization: Bearer {access_token}
Content-Type: application/json
```

The `/1` in the path = request option 1 (Address Validation). Options: 1 = validate, 2 = classify only, 3 = validate + classify.

**Sample Request Body:**
```json
{
  "XAVRequest": {
    "AddressKeyFormat": {
      "AddressLine": ["1 Wall St"],
      "PoliticalDivision2": "New York",
      "PoliticalDivision1": "NY",
      "PostcodePrimaryLow": "10005",
      "CountryCode": "US"
    }
  }
}
```

**Sample Response:**
```json
{
  "XAVResponse": {
    "ValidAddressIndicator": "",
    "AddressClassification": {
      "Code": "1",
      "Description": "Commercial"
    },
    "Candidate": {
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
  }
}
```

## Error Handling

The page distinguishes between:

| Error Type           | What It Means                                                |
|----------------------|--------------------------------------------------------------|
| **cURL error**       | Network problem (DNS, timeout, SSL). Check your server.      |
| **OAuth error**      | Bad Client ID/Secret, or expired credentials.                |
| **UPS API error**    | Bad input (invalid ZIP, missing fields). Check error code.   |

Common UPS error codes:

- `250003` - Invalid Access License / authentication
- `111210` - Missing or invalid shipper number
- `111100` - Invalid address fields
- `9110101` - Address not found (validation)

## Notes

- The OAuth token is valid for ~4 hours. This demo fetches a new one per request for simplicity. In production, cache it.
- Packaging type `02` = Customer Supplied Package. Other codes: `01` = UPS Letter, `03` = Tube, `04` = Pak.
- Address Validation currently works for US addresses only.
- The page uses `POST` for both APIs as required by the UPS REST specification.
- All form inputs are sanitized with `htmlspecialchars()` to prevent XSS.
