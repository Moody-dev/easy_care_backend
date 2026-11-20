# Frontend integration guide

This is the Laravel API reference for the shared Railway testing environment. All endpoint paths below include `/api/v1`; do not add that prefix twice. Set the Postman `baseUrl` to the server origin, without `/api/v1`. The hospital installation continues to use Laravel and MySQL. Supabase is the temporary testing database; frontend clients call Laravel rather than Supabase tables.

## Session login and CSRF

Authentication uses a staff session cookie, not a bearer token or Supabase Auth. Obtain an authorized test account from the backend team; there is no public signup. Documentation access does not grant API access.

1. Send `GET /api/v1/auth/csrf-token`. Retain its cookies and the `data.csrf_token` value.
2. Send `POST /api/v1/auth/login` with JSON `{"email_or_username":"YOUR_TEST_USERNAME","password":"YOUR_TEST_PASSWORD"}` and header `X-CSRF-TOKEN` containing the bootstrap token. Send the same session cookies.
3. Login rotates the session and returns a fresh `data.csrf_token`. Replace the saved token and retain the updated cookie. `GET /api/v1/auth/me` restores the current user, roles, effective permissions and session information.
4. Send the latest CSRF token and cookies with every POST, PUT, PATCH or DELETE. Send `Accept: application/json`; JSON requests also use `Content-Type: application/json`.
5. `POST /api/v1/auth/logout` signs out and returns 204 with no body. On 401/419, restore the session or repeat login rather than retrying a write blindly.

In browser code use `credentials: "include"`. The deployed session cookie is Secure, HttpOnly and SameSite=Lax. The planned Vercel frontend proxy must forward the request cookies, CSRF headers, response Set-Cookie headers and appropriate cookie attributes for its same-origin route. Direct cross-site browser login from Vercel to Railway needs a separately reviewed cookie setup; permissive CORS alone does not enable it. Frontend integration remains the frontend team's work.

In Postman keep the cookie jar enabled. Import `collection.json`; the collection captures `data.csrf_token` from bootstrap/login/session responses into its `csrf_token` variable. Supply your own test credentials in the login request. No working credentials are included in the downloads.

## Permissions and visibility

Each endpoint lists its route permission checks. Staff need active accounts and explicit grants; administrator or department membership does not automatically grant patient, clinical, stock, billing or reporting access. Restricted or merged records, clinician assignment, store membership and workflow state can further limit access. An options response describes supported choices; it does not grant permission to save them. A 404 can conceal an inaccessible record.

## Writes, versions and retries

Endpoints marked as commands require `Idempotency-Key`: 16–128 characters, starting with a letter/digit and containing letters, digits, dots, colons, underscores or hyphens. Generate a UUID for each new intent and set the Postman `idempotency_key` variable before sending it. If the response is lost, retry the identical method, path, payload and key as the same staff member. Do not automatically generate a new key on every retry. Changed payloads require a new intent; old keys expire and are not permanent business identifiers.

Supply the latest saved `version`, related record versions, `workflow_version`, `account_version` or `expected_version` fields required by that particular endpoint. A conflict requires refreshing the affected records before resubmitting an intentional change. Never guess versions or increment them client-side. The `Idempotency-Replayed` response header indicates a retained command replay; current permissions and visibility still apply.

Password confirmation uses `current_password` where required. Approval routes may require a different authorized staff member; a visible button is not authorization. Clinical templates, diagnostic schemas and tariffs are versioned. Load published options/forms before constructing the related payload. Signed and posted records retain their originals; use supported correction workflows.

## Responses and data formats

Read each endpoint's request schema and examples. Examples are synthetic and are not hospital policy, stock, medication recommendations, prices or real records. Error examples show the common envelope; not every failure is possible in every workflow. Success examples are included only where explicitly documented, rather than invented from live records.

Errors use `{"message":"...","code":"..."}`; validation errors add an `errors` map of field names to message arrays. Common statuses are 401 (sign in), 403 (permission/workflow), 404 (missing or hidden), 409 (state/version conflict), 419 (session/CSRF), 422 (invalid fields), 429 (rate limit; honor `Retry-After`), and 503 (temporarily unavailable). Conflict codes can be more specific than `conflict`.

Paginated resources provide `data`, `links` and `meta`; some billing, stock and reporting endpoints return Laravel's direct paginator shape instead. Follow the returned links rather than assuming every list has the same wrapper. Supply supported `page` and `per_page` query fields; ordinary defaults are 25 and a maximum of 100 unless the endpoint says otherwise.

Use ISO 8601 timestamps with an explicit UTC offset where requested. Hospital business-day rules use Africa/Lagos. Send exact decimal quantities and money as strings where the schema requires strings; do not use floating-point calculations for prices or balances. NGN transaction totals are stored as exact kobo. Physical POS/cash/bank payments happen outside the platform; the API records their details and confirmations.

Uploads use multipart/form-data with the endpoint's file field; let the browser/client generate the multipart boundary. Protected files remain permission controlled. Report exports are private, expiring saved artifacts and may become unavailable if source access or versions change. Do not assume a download URL is public or permanent.

## Frontend connection sequence

Start with health, CSRF, login, session restore and logout. Then implement options, lists, details and paginated search before writes. Handle validation messages and permission denials, then add idempotency, version conflicts and workflow approvals. Verify the session proxy and each module using authorized synthetic test data. This reference does not change any frontend code or connect its dummy screens.
