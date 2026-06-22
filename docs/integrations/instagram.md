# Instagram Integration

Argusly supports Instagram publishing through the official Meta Graph API for Instagram Professional accounts only. Personal Instagram profiles are not supported for automated publishing.

## Meta App Setup

Create a Meta app in Meta for Developers and configure OAuth for the app domain. Add the redirect URL configured in `META_REDIRECT_URI`.

Required environment variables:

```env
META_CLIENT_ID=
META_CLIENT_SECRET=
META_REDIRECT_URI=
META_GRAPH_API_VERSION=v23.0
META_ENABLED=true
```

Required scopes for the MVP:

- `instagram_basic`
- `instagram_content_publish`
- `pages_show_list`
- `pages_read_engagement`

## Supported Accounts

Instagram publishing is only available for:

- Business accounts
- Creator accounts

If Meta returns no connected Instagram Professional account, or the account type is not `business` or `creator`, Argusly rejects the connection with:

“Instagram publishing is alleen beschikbaar voor Business en Creator accounts. Zet je Instagram account om naar een professioneel account om dit kanaal te gebruiken.”

## MVP Features

The first Instagram publishing version supports:

- Single image feed posts
- Caption text
- Hashtags
- Publish now through the existing social publication queue
- Publication status and Meta API error storage

Not active in the MVP:

- Stories
- Reels
- Carousels
- Video publishing
- Comment management
- Advanced insights

## Campaign Flow

Instagram is modeled as a social channel variant inside the existing campaign flow:

Campaign -> Content asset -> Channel variants -> Review -> Approve -> Publish

LinkedIn variants remain text/article friendly. Instagram variants are shorter, visual-first captions and require an image before scheduling or publishing.
