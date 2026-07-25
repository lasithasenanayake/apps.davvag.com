# Travel Destinations API integration

This guide explains how an authenticated `web_user` can log in to DAVVAG and submit a destination for moderation.

## How the API works

- Service URL format: `/components/{app}/{component}/service/{operation}`
- Travel service base: `/components/travel-destinations/api/service`
- Responses use the envelope `{"success": true|false, "result": ...}`.
- Authentication is cookie/session based. Login sets a PHP session cookie and a `securityToken` cookie. Keep and resend both cookies.
- The API does not read an `Authorization: Bearer` header.
- A login account must have a linked, active tenant `profile`. A valid auth session without a profile cannot submit.
- A traveler cannot publish directly. Submission is a required two-step process:
  1. `SaveDestinationDraft`
  2. `SubmitDestination`, using the returned draft `id`

Successful submission changes the destination status to `Pending Review`. An administrator must approve/publish it separately.

## Prerequisites

1. Start Apache and MySQL in XAMPP.
2. Confirm the DAVVAG application base URL. With the default XAMPP document root used by this repository it is normally:

   ```text
   http://localhost/davvag-core
   ```

   If a virtual host points directly at `C:\xampp\htdocs\davvag-core`, use `http://localhost` instead.
3. Use a DAVVAG account in the `web_user` group with a linked tenant profile.
4. Ensure the Travel Destinations permissions and reference data have been installed. From the framework root:

   ```powershell
   php .\davvag-core\localhost\apps\travel-destinations\install-permissions.php
   ```

## Endpoints used

| Purpose | Method | Endpoint |
|---|---:|---|
| Login | GET | `/components/userapp/login-handler/service/login` |
| Check session/profile | GET | `/components/travel-destinations/api/service/Capabilities` |
| List valid categories | GET | `/components/travel-destinations/api/service/GetCategories` |
| List valid amenities | GET | `/components/travel-destinations/api/service/GetAmenities` |
| Save the required draft | POST | `/components/travel-destinations/api/service/SaveDestinationDraft` |
| Submit the saved draft | POST | `/components/travel-destinations/api/service/SubmitDestination` |
| Check the submitted record | POST | `/components/travel-destinations/api/service/GetMySubmissions` |

Operation names should be sent with the capitalization shown above because permission records use these names.

## Complete PowerShell example

This is the recommended example on the current Windows/XAMPP installation. `WebRequestSession` retains all cookies between requests.

### 1. Configure the connection and log in

```powershell
$BaseUrl = "http://localhost/davvag-core"
$Email = "traveler@example.com"
$Password = "replace-with-the-account-password"
$Domain = ([uri]$BaseUrl).Host

$Session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$LoginUrl = "$BaseUrl/components/userapp/login-handler/service/login" +
    "?email=$([uri]::EscapeDataString($Email))" +
    "&password=$([uri]::EscapeDataString($Password))" +
    "&domain=$([uri]::EscapeDataString($Domain))"

$Login = Invoke-RestMethod `
    -Method Get `
    -Uri $LoginUrl `
    -WebSession $Session

if (-not $Login.success -or -not $Login.result.token) {
    throw "Login failed: $($Login.result | ConvertTo-Json -Compress)"
}

"Logged in as $($Login.result.email), group $($Login.result.group)"
```

The legacy DAVVAG login service accepts credentials with `GET`, so the values are placed in the URL. Use HTTPS outside local development and do not paste a real production password into logs, tickets, or shell history.

### 2. Verify that the session has an active profile

```powershell
$Capabilities = Invoke-RestMethod `
    -Method Get `
    -Uri "$BaseUrl/components/travel-destinations/api/service/Capabilities" `
    -WebSession $Session

if (-not $Capabilities.success) {
    throw "Capabilities request failed: $($Capabilities.result | ConvertTo-Json -Compress)"
}

if (-not $Capabilities.result.authenticated -or -not $Capabilities.result.profileId) {
    throw "The account is logged in but has no active linked tenant profile."
}

$Capabilities.result
```

Expected fields include:

```json
{
  "authenticated": true,
  "profileId": 123,
  "administrator": false
}
```

### 3. Get a valid category ID

At least one category is mandatory when submitting. Do not guess database IDs; retrieve them from the API.

```powershell
$CategoryResponse = Invoke-RestMethod `
    -Method Get `
    -Uri "$BaseUrl/components/travel-destinations/api/service/GetCategories" `
    -WebSession $Session

if (-not $CategoryResponse.success -or -not $CategoryResponse.result) {
    throw "No destination categories are available."
}

$CategoryResponse.result | Select-Object id, name, slug

$Category = $CategoryResponse.result |
    Where-Object { $_.slug -eq "waterfall" } |
    Select-Object -First 1

if (-not $Category) {
    $Category = $CategoryResponse.result | Select-Object -First 1
}
```

Amenities are optional. They can be retrieved in the same way from `GetAmenities`.

### 4. Build the destination payload

The minimum complete submission needs a name, summary, description, valid coordinates, and at least one category ID.

```powershell
$Destination = [pscustomobject]@{
    id                            = $null
    name                          = "Example Waterfall"
    short_summary                 = "A scenic waterfall reached by a short forest walk."
    description_markdown          = @"
## About

Example Waterfall is a demonstration API submission.

Visitors should check weather and local access conditions before travelling.
"@
    primary_language              = "en"
    tags                          = "waterfall, nature, hiking"
    category_ids                  = @([int]$Category.id)
    amenity_ids                   = @()
    latitude                      = 7.2906000
    longitude                     = 80.6337000
    coordinate_accuracy           = 10
    location_privacy              = "exact_public"
    province                      = "Central Province"
    district                      = "Kandy"
    nearest_town                  = "Kandy"
    village                       = ""
    location_description          = "Use the signed entrance near the main road."
    access_road_description       = "The final section is a narrow local road."
    public_transport_instructions = ""
    parking_information           = ""
    distance_from_town_km         = 5
    road_condition                = "Paved road followed by a walking path."
    requires_4wd                  = $false
    walking_distance_km           = 1.2
    stay_subtype                  = ""
    price_range                   = ""
    responsible_travel_markdown   = "Carry out all waste and respect nearby residents."
    safety_warnings               = "Rocks may be slippery after rain."
    camping_info                  = [pscustomobject]@{}
    hiking_info                   = [pscustomobject]@{}
    stay_info                     = [pscustomobject]@{}
    village_info                  = [pscustomobject]@{}
}
```

Allowed `location_privacy` values are:

- `exact_public`
- `approximate_public`
- `hidden_sensitive`
- `approved_only`

Latitude must be from `-90` through `90`; longitude must be from `-180` through `180`.

### 5. Save the draft

```powershell
$Draft = Invoke-RestMethod `
    -Method Post `
    -Uri "$BaseUrl/components/travel-destinations/api/service/SaveDestinationDraft" `
    -WebSession $Session `
    -ContentType "application/json" `
    -Body ($Destination | ConvertTo-Json -Depth 10)

if (-not $Draft.success -or -not $Draft.result.id) {
    throw "Draft save failed: $($Draft.result | ConvertTo-Json -Compress)"
}

$Destination.id = [int]$Draft.result.id
"Draft saved with ID $($Destination.id)"
```

The draft response should contain `status: "Draft"`.

### 6. Submit that draft for review

Send the complete payload again, including the draft `id`. Do not send only the ID: submission validation reprocesses the destination fields.

```powershell
$Submission = Invoke-RestMethod `
    -Method Post `
    -Uri "$BaseUrl/components/travel-destinations/api/service/SubmitDestination" `
    -WebSession $Session `
    -ContentType "application/json" `
    -Body ($Destination | ConvertTo-Json -Depth 10)

if (-not $Submission.success) {
    throw "Submission failed: $($Submission.result | ConvertTo-Json -Compress)"
}

if ($Submission.result.status -ne "Pending Review") {
    throw "Unexpected status: $($Submission.result.status)"
}

$Submission.result | Select-Object id, name, slug, status
```

The API assigns ownership from the authenticated profile. Do not send or rely on `created_by_profile_id`, `status`, approval fields, rating fields, or publication fields.

### 7. Optionally verify it under the current account

```powershell
$Mine = Invoke-RestMethod `
    -Method Post `
    -Uri "$BaseUrl/components/travel-destinations/api/service/GetMySubmissions" `
    -WebSession $Session `
    -ContentType "application/json" `
    -Body '{"page":0,"pageSize":20}'

$Mine.result.items |
    Where-Object { $_.id -eq $Destination.id } |
    Select-Object id, name, status
```

### 8. Remove credentials from the PowerShell session

```powershell
$Password = $null
$LoginUrl = $null
```

## `curl.exe` cookie pattern

If another client is used, the important requirement is to retain the cookies returned by login. On Windows PowerShell, call `curl.exe` explicitly because `curl` may be an alias for `Invoke-WebRequest`.

```powershell
$BaseUrl = "http://localhost/davvag-core"
$CookieJar = Join-Path $PWD "davvag-api.cookies.txt"

curl.exe -sS -c $CookieJar -b $CookieJar -G `
  "$BaseUrl/components/userapp/login-handler/service/login" `
  --data-urlencode "email=traveler@example.com" `
  --data-urlencode "password=replace-with-the-account-password" `
  --data-urlencode "domain=localhost"

curl.exe -sS -c $CookieJar -b $CookieJar `
  "$BaseUrl/components/travel-destinations/api/service/Capabilities"
```

For POST requests, send `Content-Type: application/json`, keep using `-b $CookieJar`, and post the complete JSON payload to `SaveDestinationDraft`, then to `SubmitDestination` after adding the returned `id`.

The cookie jar contains an active login token. Delete it securely when the integration run is complete:

```powershell
Remove-Item -LiteralPath $CookieJar
```

## Common errors

| Result/error | Cause and resolution |
|---|---|
| `UnAutherized call... anonymous` | Login cookies were not retained/sent, or the login failed. Reuse the same `WebRequestSession` or cookie jar. |
| `Sign in with an active profile to continue.` | The auth user has no linked tenant `profile`. Create/link the profile through the existing user/profile administration flow. |
| `Save your own draft before submitting it.` | Call `SaveDestinationDraft` first, keep the returned ID, remain in the same authenticated account, and add that ID to the submit payload. |
| `Summary, description and at least one category are required.` | Send non-empty `short_summary`, `description_markdown`, and `category_ids`. |
| Coordinate range error | Supply both latitude and longitude within the documented ranges. |
| `Only Draft or Returned for Changes submissions can be submitted.` | The record is already pending, approved, rejected, published, or otherwise locked. Do not resubmit it as a new transition. |
| HTTP 500 with `success: false` | DAVVAG converts service validation failures to HTTP 500. Read the JSON `result` for the user-safe error message. |
| Connection refused | Apache is not running, the port is different, or `$BaseUrl` is incorrect. |

## Browser application access

The same workflow is available through the DAVVAG UI:

```text
{BASE_URL}/admin#/app/travel-destinations/submit
```

Sign in through the existing DAVVAG user login first. The form itself calls `Capabilities`, loads categories/amenities, saves a draft, and then submits that draft for moderation.
