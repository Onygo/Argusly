# Google OAuth Setup

This guide configures Google OAuth for GA4 and Search Console integrations in Argusly. The current implementation supports connecting Google accounts, discovering GA4 properties and Search Console sites, selecting brand mappings, syncing analytics data, and using those signals in content lifecycle scoring.

## Google Cloud Project Setup

1. Open the Google Cloud Console.
2. Create a new project or select the project used for Argusly integrations.
3. Confirm billing and organization policies allow the project to use Google APIs.
4. Configure the OAuth consent screen before creating production credentials.
5. Add the users or domains that are allowed to test the app while the consent screen is in testing mode.

## Enable APIs

Enable these APIs in the Google Cloud project:

- Google Analytics Data API
- Google Analytics Admin API
- Google Search Console API

The Analytics Admin API is used for account and property discovery. The Analytics Data API is used for GA4 reporting sync. The Search Console API is used for verified site discovery and Search Analytics performance sync.

## OAuth Client Setup

1. In Google Cloud Console, open **APIs & Services > Credentials**.
2. Create an **OAuth client ID**.
3. Choose **Web application**.
4. Add the authorized redirect URI for each environment:

   ```text
   {APP_URL}/settings/integrations/google/callback
   ```

5. Replace `{APP_URL}` with the exact app URL for the environment, including scheme and port when applicable.
6. Copy the client ID and client secret into the app environment.

The redirect URI must match exactly. Google treats `http`, `https`, host, port, path, and trailing slash differences as different redirect URIs.

## Required Scopes

Argusly requests these initial Google scopes:

```text
https://www.googleapis.com/auth/analytics.readonly
https://www.googleapis.com/auth/webmasters.readonly
```

Short names:

```text
analytics.readonly
webmasters.readonly
```

Optional future identity scopes:

```text
openid
email
profile
```

The integration requests offline access where Google allows it so refresh tokens can be stored and used for background sync.

## Environment Variables

Set these in the app environment:

```env
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI="${APP_URL}/settings/integrations/google/callback"
```

`GOOGLE_REDIRECT_URI` must exactly match one of the authorized redirect URIs configured in Google Cloud Console.

After changing environment values, clear or rebuild Laravel config:

```bash
php artisan config:clear
```

## Local Development

1. Set `APP_URL` to the URL used in the browser.
2. For plain local development, register a redirect URI such as:

   ```text
   http://localhost:8000/settings/integrations/google/callback
   ```

3. If Google blocks or complicates local redirects for your setup, use a stable tunnel URL and set `APP_URL` to that tunnel URL.
4. Add the same local or tunnel redirect URI to the OAuth client in Google Cloud Console.
5. Keep the OAuth consent screen in testing mode until production review is ready.
6. Add local test users to the OAuth consent screen test user list.
7. Visit **Settings > Integrations > Google** and use **Connect Google**.

Google may only return a refresh token on the first consent for a user and client combination. During local testing, revoke the app from the Google Account permissions page or force a new consent prompt if you need to test refresh-token storage again.

## Production Checklist

- Google Cloud project is owned by the production organization.
- OAuth consent screen is configured with app name, support email, authorized domains, privacy policy, and terms links.
- Required APIs are enabled:
  - Google Analytics Data API
  - Google Analytics Admin API
  - Google Search Console API
- Production redirect URI is configured exactly:

  ```text
  {APP_URL}/settings/integrations/google/callback
  ```

- `APP_URL`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, and `GOOGLE_REDIRECT_URI` are set in production.
- Laravel config cache is rebuilt after env changes.
- Required scopes are approved and visible on the consent screen.
- Token health command is scheduled:

  ```bash
  php artisan google:check-token-health
  ```

- GA4 sync command is scheduled for selected properties.
- Search Console sync command is scheduled for selected sites.
- Error monitoring watches OAuth callback failures, token refresh failures, GA4 sync failures, and Search Console sync failures.
- Team members connecting Google have access to the required GA4 properties and verified Search Console sites.

## Troubleshooting

`invalid redirect_uri`
: The callback URL sent by Argusly does not exactly match an authorized redirect URI in Google Cloud Console. Check `APP_URL`, `GOOGLE_REDIRECT_URI`, scheme, host, port, path, trailing slash, and config cache.

`access_denied`
: The user denied the Google consent request, the app is in testing mode and the user is not listed as a test user, or organization policy blocks the requested scopes. Add the user as a test user, confirm the OAuth consent screen status, and retry the connection.

Missing `refresh_token`
: Google does not always return a refresh token after the first consent for the same user and OAuth client. Confirm the authorization request asks for offline access, revoke the app from the user's Google Account permissions, then reconnect. In production, a missing refresh token means background sync may require the user to reconnect after access token expiry.

Insufficient permissions
: The connected Google user granted OAuth access but does not have permission to the requested GA4 property or Search Console site. Confirm the user has at least read access in GA4 and appropriate permission in Search Console, then reconnect or resync discovery.

Property not visible
: The GA4 property may belong to another Google account, the user lacks Analytics access, the Google Analytics Admin API is disabled, or the property is not a GA4 property. Confirm access in Google Analytics, enable the Admin API, and run property discovery again from **Settings > Integrations > Google Analytics**.

Search Console site not verified
: Search Console only returns sites available to the connected user. Confirm the site is verified in Search Console and that the Google user has permission. Then run site discovery again from **Settings > Integrations > Search Console**.
