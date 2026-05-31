# LinkedIn OAuth Setup

This guide configures LinkedIn OAuth for personal profile publishing in Argusly. The current implementation supports personal LinkedIn profile connections and text or article URL publishing. Organization/page publishing and media uploads are prepared in the data model, but require additional LinkedIn approval before they can be enabled.

## LinkedIn Developer Portal

1. Open the LinkedIn Developer Portal and create or select an app.
2. In **Auth**, add the OAuth 2.0 redirect URL:

   ```text
   {APP_URL}/settings/integrations/linkedin/callback
   ```

3. Replace `{APP_URL}` with the exact app URL for the environment, including scheme and port when applicable.
4. Confirm the app has access to the required products/scopes for Sign In with LinkedIn using OpenID Connect and member social publishing.

## Required App Settings

Configure the LinkedIn app with:

- App name, logo, privacy policy URL, and company association.
- OAuth 2.0 redirect URL matching the environment exactly.
- OpenID Connect profile access.
- Member social publishing access.

Required initial scopes:

```text
openid
profile
email
w_member_social
```

Future organization/page scopes:

```text
r_organization_social
w_organization_social
```

Organization publishing requires LinkedIn approval. Do not enable real page publishing until those scopes and page-role checks are approved.

## Environment Variables

Set these in the app environment:

```env
LINKEDIN_CLIENT_ID=
LINKEDIN_CLIENT_SECRET=
LINKEDIN_REDIRECT_URI="${APP_URL}/settings/integrations/linkedin/callback"
```

`LINKEDIN_REDIRECT_URI` must exactly match the redirect URL configured in LinkedIn.

## Local Development

1. Use a stable local URL, such as a tunnel URL, if LinkedIn cannot redirect to your local host.
2. Set `APP_URL` to the same public URL used in the LinkedIn app.
3. Set `LINKEDIN_REDIRECT_URI` to:

   ```text
   {APP_URL}/settings/integrations/linkedin/callback
   ```

4. Clear config after changing env values:

   ```bash
   php artisan config:clear
   ```

5. Visit **Settings > Integrations > LinkedIn** and use **Connect LinkedIn**.

## Refresh Tokens

LinkedIn may return refresh tokens only when the app is authorized for programmatic refresh tokens. The integration stores:

- encrypted `access_token`
- encrypted `refresh_token` only if returned
- access token expiry
- refresh token expiry when returned
- granted scopes

If no refresh token is available, or refresh fails, the connection and related social profile are marked expired. Users must reconnect the LinkedIn profile before publishing can continue.

Token health can be checked with:

```bash
php artisan linkedin:check-token-health
```

## Organization Publishing Notes

Organization/page publishing is prepared but not enabled by default.

Prepared architecture includes:

- `social_profiles.type = organization` or `page`
- organization id in `provider_profile_id`
- organization URN in metadata
- role and capability metadata
- future scopes `r_organization_social` and `w_organization_social`

Publishing to pages should remain disabled until LinkedIn approves organization social API access and page-role validation is confirmed.

## Troubleshooting

`invalid redirect_uri`
: The callback URL in LinkedIn does not exactly match `LINKEDIN_REDIRECT_URI`. Check scheme, host, port, trailing slash, and environment.

`invalid scope`
: The LinkedIn app has not been approved for one or more requested scopes. Confirm `openid`, `profile`, `email`, and `w_member_social` are available.

Denied consent
: The user rejected the LinkedIn consent screen. No connection is created; ask the user to connect again.

Expired token
: The access token expired and could not be refreshed. Reconnect the LinkedIn profile.

Missing `w_member_social`
: The connection cannot publish personal profile posts. Reconnect after the LinkedIn app has member publishing approval.

API access not approved
: LinkedIn may allow sign-in but reject publishing or organization APIs. Verify product access and approved scopes in the Developer Portal.

## Production Checklist

- LinkedIn app is verified and associated with the correct company.
- Production redirect URL is configured exactly:

  ```text
  {APP_URL}/settings/integrations/linkedin/callback
  ```

- `APP_URL`, `LINKEDIN_CLIENT_ID`, `LINKEDIN_CLIENT_SECRET`, and `LINKEDIN_REDIRECT_URI` are set.
- Config cache is rebuilt after env changes.
- Required scopes are approved.
- Token health command is scheduled.
- Error monitoring watches LinkedIn token refresh and publishing failures.
- Organization/page publishing remains disabled until LinkedIn approval is complete.
