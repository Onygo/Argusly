# LinkedIn Integration

Argusly's LinkedIn MVP prepares personal profile posts from content, requires human approval, and can publish through LinkedIn's Share on LinkedIn UGC API only when publishing is explicitly enabled.

## LinkedIn Developer App Setup

1. Create or open a LinkedIn Developer App.
2. Add the **Share on LinkedIn** product.
3. Add the **Sign In with LinkedIn using OpenID Connect** product.
4. Configure the OAuth redirect URL to match `LINKEDIN_REDIRECT_URI`.
5. Request the `openid`, `profile`, and `w_member_social` scopes.
6. Store the client ID and client secret in the environment.

## Environment

```dotenv
LINKEDIN_CLIENT_ID=
LINKEDIN_CLIENT_SECRET=
LINKEDIN_REDIRECT_URI=https://app.example.com/settings/integrations/linkedin/callback
LINKEDIN_ENABLED=false
LINKEDIN_PUBLISHING_ENABLED=false
```

`LINKEDIN_ENABLED` allows OAuth connection. `LINKEDIN_PUBLISHING_ENABLED` controls outbound publishing and defaults to `false`.

## Current MVP

- Supports personal profile posting first.
- Supports text shares with `shareMediaCategory` `NONE`.
- Prepares article URL shares with `shareMediaCategory` `ARTICLE`.
- Requires `X-Restli-Protocol-Version: 2.0.0`.
- Stores the `X-RestLi-Id` response header as the provider post ID.
- Requires human approval before schedule or publish.

## Out of Scope For This Step

- Company page posting.
- Image and video upload execution.
- Fully autonomous publishing.

The data model includes room for image posts and media references, but upload registration and asset transfer are intentionally not enabled yet.

## Rate Limits

The MVP guards member publishing at 150 requests per day and documents LinkedIn's application-level 100,000 requests per day limit. Failed and successful publish attempts are logged in `social_publish_attempts`.
