# Argusly Headless API Notes

## API key creation
- API keys are workspace-scoped and can be destination-scoped.
- The plain key is returned only once at creation.
- Store it in a secret manager and never in client-side code.

## Webhook signature verification
- Header: `X-Argusly-Signature`
- Format: `sha256=<hex_digest>`
- Compute HMAC SHA-256 over the raw request body using the webhook secret.

Example (PHP):

```php
$expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
$provided = $_SERVER['HTTP_X_ARGUSLY_SIGNATURE'] ?? '';
if (! hash_equals($expected, $provided)) {
    http_response_code(401);
    exit('invalid signature');
}
```

## Async polling pattern
1. Start an async action (draft generation, translation, SEO audit).
2. Receive an operation id from the `meta.operation.id` payload.
3. Poll `GET /api/v1/operations/{operation}` until status is `completed` or `failed`.
4. Read `result_payload` and follow referenced resource ids.

## Example event payloads

```json
{
  "events": [
    {
      "event_type": "article_view",
      "article_identifier": "draft_123",
      "page_url": "https://example.com/blog/post-1",
      "timestamp": "2026-03-09T10:00:00Z",
      "session_id": "sess_1",
      "visitor_id": "vis_1",
      "meta": {"read_time": 0}
    }
  ]
}
```
