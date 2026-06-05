# Security Hardening

## Toegevoegd

- Nieuw centraal security-configcontract in [config/security.php](/Users/ricardohagens/Sites/_project_publishlayer/publishlayer/config/security.php) met toggles, suspicious traffic detection, rate limits en response-messages.
- Globale middleware [app/Http/Middleware/BlockSuspiciousTraffic.php](/Users/ricardohagens/Sites/_project_publishlayer/publishlayer/app/Http/Middleware/BlockSuspiciousTraffic.php) die verdachte user agents, gevoelige paden, query/path patronen en extreem lange querystrings detecteert.
- Middleware [app/Http/Middleware/ProtectHeavyEndpoints.php](/Users/ricardohagens/Sites/_project_publishlayer/publishlayer/app/Http/Middleware/ProtectHeavyEndpoints.php) voor search, audits, reports, AI-generatie, exports en andere kostbare acties.
- Centrale `RateLimiter::for(...)` definities voor `web`, `api`, `login`, `password-reset`, `contact`, `heavy` en compatibele aliases voor bestaande route-definities.
- Compacte 403/429 responses via [app/Support/SecurityResponse.php](/Users/ricardohagens/Sites/_project_publishlayer/publishlayer/app/Support/SecurityResponse.php), inclusief nette JSON responses voor API requests.
- Aparte `security` log channel in [config/logging.php](/Users/ricardohagens/Sites/_project_publishlayer/publishlayer/config/logging.php) zodat suspicious traffic compact en release-safe gelogd wordt.

## Relevante env vars

- `SECURITY_BLOCK_SUSPICIOUS_TRAFFIC`
- `SECURITY_LOG_SUSPICIOUS_TRAFFIC`
- `SECURITY_LOG_ONLY_MODE`
- `SECURITY_PROTECT_HEAVY_ENDPOINTS`
- `SECURITY_MAX_QUERY_LENGTH`
- `SECURITY_HEAVY_SEARCH_MAX_QUERY_LENGTH`
- `SECURITY_LOG_CHANNEL`
- `THROTTLE_WEB_PER_MINUTE`
- `THROTTLE_API_PER_MINUTE`
- `THROTTLE_LOGIN_PER_MINUTE`
- `THROTTLE_PASSWORD_RESET_PER_MINUTE`
- `THROTTLE_CONTACT_PER_MINUTE`
- `THROTTLE_HEAVY_PER_MINUTE`
- `THROTTLE_WEBHOOK_PER_MINUTE`
- `THROTTLE_ANALYTICS_EVENTS_PER_MINUTE`
- `THROTTLE_INTEGRATION_API_PER_MINUTE`
- `RATE_LIMIT_STORE`
- `LOG_LEVEL`
- `LOG_SECURITY_LEVEL`

## Aanbevolen local development waarden

Gebruik lokaal ruimere limieten en liever log-only gedrag:

- `SECURITY_BLOCK_SUSPICIOUS_TRAFFIC=false`
- `SECURITY_LOG_SUSPICIOUS_TRAFFIC=true`
- `SECURITY_LOG_ONLY_MODE=true`
- `THROTTLE_WEB_PER_MINUTE=300`
- `THROTTLE_API_PER_MINUTE=120`
- `THROTTLE_LOGIN_PER_MINUTE=20`
- `THROTTLE_PASSWORD_RESET_PER_MINUTE=20`
- `THROTTLE_CONTACT_PER_MINUTE=20`
- `THROTTLE_HEAVY_PER_MINUTE=60`
- `LOG_LEVEL=debug`

## Aanbevolen productie waarden

- `SECURITY_BLOCK_SUSPICIOUS_TRAFFIC=true`
- `SECURITY_LOG_SUSPICIOUS_TRAFFIC=true`
- `SECURITY_LOG_ONLY_MODE=false`
- `THROTTLE_WEB_PER_MINUTE=120`
- `THROTTLE_API_PER_MINUTE=60`
- `THROTTLE_LOGIN_PER_MINUTE=5`
- `THROTTLE_PASSWORD_RESET_PER_MINUTE=5`
- `THROTTLE_CONTACT_PER_MINUTE=5`
- `THROTTLE_HEAVY_PER_MINUTE=10`
- `SECURITY_MAX_QUERY_LENGTH=2000`
- `LOG_LEVEL=error`
- `RATE_LIMIT_STORE=redis` waar beschikbaar

## Extra beschermde routes

- `web`: op marketing, app, admin en legacy web routegroepen.
- `api`: op de primaire en compat API-routes.
- `login`: op publieke, app- en admin-login POST routes.
- `contact`: op publieke contact-, early-access- en invite-formulieren.
- `heavy`: op search, onboarding scans, connector/test-connection acties, audit runs, queue retries, AI-generatie, exports en vergelijkbare dure acties.
- `webhook-public`: alleen op publieke webhooks waar een generieke `api` limiter te riskant of te generiek zou zijn.

Concreet extra beschermd in routes:

- [routes/marketing.php](/Users/ricardohagens/Sites/_project_publishlayer/publishlayer/routes/marketing.php): contact, early-access en public invite submissions.
- [routes/app.php](/Users/ricardohagens/Sites/_project_publishlayer/publishlayer/routes/app.php): search, onboarding scan, connector tests, SEO audit runs, AI generation en report-achtige acties.
- [routes/admin.php](/Users/ricardohagens/Sites/_project_publishlayer/publishlayer/routes/admin.php): admin login, search, queue retries en LLM/test-connection acties.
- [routes/api.php](/Users/ricardohagens/Sites/_project_publishlayer/publishlayer/routes/api.php): globale API limiter, publieke Mollie webhook limiter, integratie-API limiter en extra heavy protection op generation/audit/export endpoints.

## Opmerkingen

- Er lijken op dit moment geen password reset routes actief te zijn in deze codebase. De limiter staat alvast klaar voor toekomstig gebruik.
- De huidige defaults voor `SESSION_DRIVER` en `CACHE_STORE` blijven backward compatible op `database`. Voor piekbelasting is `redis` voor `CACHE_STORE`, `RATE_LIMIT_STORE` en vaak ook `SESSION_DRIVER` de betere keuze.
- Suspicious traffic logging schrijft alleen compacte metadata weg: timestamp via het logrecord, plus IP, method, path, user agent en reason. Geen request bodies of payload dumps.

## Nog buiten Laravel

- Reverse proxy of CDN rate limiting.
- WAF of edge filtering.
- Fail2ban of vergelijkbare IP-based blocking op serverniveau.
- Webserver of reverse proxy request-size, connection en timeout limits.
- Bot management en origin shielding bij publieke traffic.
- Upstream webserver timeouts, connection limits en buffering.
- Queue worker autoscaling en infra monitoring.
