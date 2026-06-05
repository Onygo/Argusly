/**
 * Argusly Analytics - privacy-first pageview tracker
 * Version: 1.2.1
 */
(function () {
  'use strict';

  var DEFAULT_ENGAGED_AFTER_SECONDS = 10;
  var DEFAULT_READ_THROUGH_SCROLL_PERCENT = 75;
  var DEFAULT_READ_THROUGH_FALLBACK_SECONDS = 20;
  var SHORT_PAGE_VIEWPORT_RATIO = 1.25;

  function findScriptTag() {
    var script = document.currentScript;
    if (script) {
      return script;
    }

    var scripts = document.getElementsByTagName('script');
    for (var i = scripts.length - 1; i >= 0; i--) {
      var src = scripts[i].src || '';
      if (src.indexOf('/pl.js') !== -1) {
        return scripts[i];
      }
    }

    return null;
  }

  function isDntEnabled() {
    var value = navigator.doNotTrack || window.doNotTrack || navigator.msDoNotTrack;

    return value === '1' || value === 'yes';
  }

  function toNumber(value, fallback) {
    var parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : fallback;
  }

  function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
  }

  function truncate(value, max) {
    if (typeof value !== 'string') {
      return value;
    }

    return value.length > max ? value.slice(0, max) : value;
  }

  function normalizeUrl(input) {
    var raw = String(input || '').trim();
    if (!raw) {
      return null;
    }

    try {
      var parsed = new URL(raw, window.location.href);
      var protocol = String(parsed.protocol || 'https:').toLowerCase();
      if (protocol !== 'http:' && protocol !== 'https:') {
        protocol = 'https:';
      }

      var hostname = String(parsed.hostname || '').toLowerCase();
      if (!hostname) {
        return null;
      }

      var port = String(parsed.port || '');
      if ((protocol === 'http:' && port === '80') || (protocol === 'https:' && port === '443')) {
        port = '';
      }

      var pathname = String(parsed.pathname || '/').toLowerCase();
      pathname = '/' + pathname.replace(/^\/+/, '');
      pathname = pathname.replace(/\/+/g, '/');
      if (pathname.length > 1) {
        pathname = pathname.replace(/\/+$/, '');
      }

      return protocol + '//' + hostname + (port ? ':' + port : '') + pathname;
    } catch (error) {
      var value = raw.split('#')[0].split('?')[0].trim().toLowerCase();
      if (!value) {
        return null;
      }

      value = value.replace(/\/+/g, '/');
      if (value.charAt(0) !== '/' && value.indexOf('://') === -1) {
        value = '/' + value;
      }

      if (value.length > 1) {
        value = value.replace(/\/+$/, '');
      }

      return value;
    }
  }

  function getCanonicalTagUrl() {
    var canonical = document.querySelector('link[rel="canonical"]');
    var href = canonical ? (canonical.getAttribute('href') || canonical.href || '') : '';
    var fromTag = href ? normalizeUrl(href) : null;
    if (fromTag) {
      return fromTag;
    }

    return null;
  }

  function getCanonicalUrl() {
    var context = window.PublishLayer || {};

    return normalizeUrl(context.canonicalUrl || context.canonical_url)
      || getCanonicalTagUrl()
      || normalizeUrl(window.location.href);
  }

  function getUrl() {
    // Prefer canonical identity so different URL variants collapse to one page.
    return getCanonicalUrl();
  }

  function detectPublishLayerArticleId() {
    var metaSelectors = [
      'meta[name="publishlayer_article_id"]',
      'meta[name="publishlayer:article_id"]',
      'meta[name="publishlayer-article-id"]',
      'meta[property="publishlayer_article_id"]',
      'meta[property="publishlayer:article_id"]'
    ];

    for (var i = 0; i < metaSelectors.length; i++) {
      var node = document.querySelector(metaSelectors[i]);
      if (!node) {
        continue;
      }

      var content = String(node.getAttribute('content') || '').trim();
      if (content) {
        return content;
      }
    }

    return null;
  }

  function sendPayload(endpoint, payload) {
    var body = JSON.stringify(payload);

    if (navigator.sendBeacon) {
      try {
        if (navigator.sendBeacon(endpoint, body)) {
          return;
        }
      } catch (error) {
        // Ignore and try fallback transport.
      }
    }

    if (window.fetch) {
      try {
        fetch(endpoint, {
          method: 'POST',
          mode: 'no-cors',
          credentials: 'omit',
          keepalive: true,
          headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
          body: body
        });

        return;
      } catch (error) {
        // Ignore and try XHR fallback.
      }
    }

    try {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', endpoint, true);
      xhr.setRequestHeader('Content-Type', 'text/plain;charset=UTF-8');
      xhr.send(body);
    } catch (error) {
      // Ignore final failure.
    }
  }

  function buildPayload(siteKey, eventType, extra) {
    var context = window.PublishLayer || {};
    var payload = {
      site_key: siteKey,
      event_type: eventType,
      session_id: getSessionId(),
      url: getUrl(),
      canonical_url: getCanonicalUrl(),
      referrer: truncate(document.referrer || '', 512) || null,
      page_title: document.title || null,
      occurred_at: new Date().toISOString()
    };

    var articleId = context.articleId
      || context.article_id
      || context.contentId
      || context.content_id
      || detectPublishLayerArticleId();
    if (articleId) {
      payload.article_id = String(articleId);
    }

    if (context.contentType) {
      payload.content_type = String(context.contentType);
    }

    if (extra && typeof extra === 'object') {
      for (var key in extra) {
        if (Object.prototype.hasOwnProperty.call(extra, key)) {
          payload[key] = extra[key];
        }
      }
    }

    return payload;
  }

  var script = findScriptTag();
  if (!script) {
    return;
  }

  window.PublishLayer = window.PublishLayer || {};
  var inMemorySessionId = null;

  function createSessionId() {
    var randomPart = String(Math.random()).slice(2);

    if (window.crypto && window.crypto.getRandomValues) {
      try {
        var bytes = new Uint8Array(8);
        window.crypto.getRandomValues(bytes);
        randomPart = Array.prototype.map.call(bytes, function (b) {
          return b.toString(16).padStart(2, '0');
        }).join('');
      } catch (error) {
        // Ignore and use Math.random fallback.
      }
    }

    return 'pls_' + Date.now().toString(36) + '_' + randomPart;
  }

  function getSessionId() {
    if (inMemorySessionId) {
      return inMemorySessionId;
    }

    var key = '__publishlayer_session_id';
    try {
      var existing = window.sessionStorage ? window.sessionStorage.getItem(key) : null;
      if (existing) {
        inMemorySessionId = existing;
        return inMemorySessionId;
      }

      inMemorySessionId = createSessionId();
      if (window.sessionStorage) {
        window.sessionStorage.setItem(key, inMemorySessionId);
      }

      return inMemorySessionId;
    } catch (error) {
      inMemorySessionId = createSessionId();
      return inMemorySessionId;
    }
  }

  var siteKey =
    window.PublishLayer.siteKey ||
    script.getAttribute('data-site-key') ||
    script.getAttribute('data-site') ||
    '';

  if (!siteKey) {
    return;
  }

  var respectDnt = window.PublishLayer.respectDnt !== false;
  var endpoint = (script.src || '').replace(/\/pl\.js.*$/, '/api/tracking/events');
  var samplingRate = clamp(
    toNumber(window.PublishLayer.samplingRate || window.PublishLayer.sampling, 100),
    0,
    100
  );
  var sampledIn = Math.random() * 100 < samplingRate;

  function track(eventType, extra) {
    if (!eventType || typeof eventType !== 'string') {
      return;
    }

    if (respectDnt && isDntEnabled()) {
      return;
    }

    if (!sampledIn) {
      return;
    }

    sendPayload(endpoint, buildPayload(siteKey, eventType, extra));
  }

  var sentPageview = false;
  var sentEngaged = false;
  var sentReadThrough = false;
  var sentReadTime = false;
  var sentScrollMilestones = {
    25: false,
    50: false,
    75: false,
    100: false
  };

  function trackPageview() {
    if (sentPageview) {
      return;
    }

    sentPageview = true;
    track('pageview');
  }

  function trackEngaged() {
    if (sentEngaged) {
      return;
    }

    trackPageview();
    sentEngaged = true;
    track('engaged');
  }

  function trackReadThrough() {
    if (sentReadThrough) {
      return;
    }

    trackPageview();
    sentReadThrough = true;
    track('read_through');
  }

  function trackReadTime() {
    if (sentReadTime) {
      return;
    }

    syncVisibilityWindow(Date.now());
    var seconds = Math.round(visibleTimeMs() / 1000);
    if (seconds <= 0) {
      return;
    }

    sentReadTime = true;
    track('read_time', { seconds: seconds });
  }

  var engagedAfterSeconds = Math.max(
    toNumber(window.PublishLayer.engagedAfterSeconds || window.PublishLayer.engaged_after_seconds, DEFAULT_ENGAGED_AFTER_SECONDS),
    1
  );
  var readThroughScrollPercent = clamp(
    toNumber(window.PublishLayer.readThroughScrollPercent || window.PublishLayer.read_through_scroll_percent, DEFAULT_READ_THROUGH_SCROLL_PERCENT),
    1,
    100
  );
  var readThroughFallbackSeconds = Math.max(
    toNumber(window.PublishLayer.readThroughFallbackSeconds || window.PublishLayer.read_through_fallback_seconds, DEFAULT_READ_THROUGH_FALLBACK_SECONDS),
    1
  );

  var visibleAccumulatedMs = 0;
  var visibleStartedAt = document.visibilityState === 'visible' ? Date.now() : null;

  function syncVisibilityWindow(now) {
    if (document.visibilityState === 'visible') {
      if (visibleStartedAt === null) {
        visibleStartedAt = now;
      }

      return;
    }

    if (visibleStartedAt !== null) {
      visibleAccumulatedMs += now - visibleStartedAt;
      visibleStartedAt = null;
    }
  }

  function visibleTimeMs() {
    var now = Date.now();
    syncVisibilityWindow(now);

    if (visibleStartedAt === null) {
      return visibleAccumulatedMs;
    }

    return visibleAccumulatedMs + (now - visibleStartedAt);
  }

  function isShortPage() {
    var doc = document.documentElement;
    var body = document.body;
    var viewportHeight = window.innerHeight || (doc ? doc.clientHeight : 0) || 0;
    var documentHeight = Math.max(
      doc ? doc.scrollHeight : 0,
      doc ? doc.offsetHeight : 0,
      doc ? doc.clientHeight : 0,
      body ? body.scrollHeight : 0,
      body ? body.offsetHeight : 0
    );

    if (viewportHeight <= 0 || documentHeight <= 0) {
      return false;
    }

    return documentHeight <= viewportHeight * SHORT_PAGE_VIEWPORT_RATIO;
  }

  function getScrollPercent() {
    var doc = document.documentElement;
    var body = document.body;
    var viewportHeight = window.innerHeight || (doc ? doc.clientHeight : 0) || 0;
    var documentHeight = Math.max(
      doc ? doc.scrollHeight : 0,
      doc ? doc.offsetHeight : 0,
      doc ? doc.clientHeight : 0,
      body ? body.scrollHeight : 0,
      body ? body.offsetHeight : 0
    );
    if (documentHeight <= 0) {
      return 0;
    }

    var scrollTop = window.pageYOffset
      || (doc ? doc.scrollTop : 0)
      || (body ? body.scrollTop : 0)
      || 0;
    var viewportBottom = scrollTop + viewportHeight;

    return clamp((viewportBottom / documentHeight) * 100, 0, 100);
  }

  var maxScrollPercent = 0;

  function checkScrollDepthMilestones() {
    maxScrollPercent = Math.max(maxScrollPercent, getScrollPercent());

    var milestones = [25, 50, 75, 100];
    for (var i = 0; i < milestones.length; i++) {
      var milestone = milestones[i];
      if (maxScrollPercent < milestone || sentScrollMilestones[milestone]) {
        continue;
      }

      sentScrollMilestones[milestone] = true;
      track('scroll_depth', { depth: milestone });

      // Keep existing rollup-compatible events for historical continuity.
      if (milestone === 50) {
        track('scroll_50');
      } else if (milestone === 100) {
        track('scroll_100');
      }
    }
  }

  function checkReadThrough() {
    if (sentReadThrough) {
      return;
    }

    checkScrollDepthMilestones();
    var shortPage = isShortPage();

    if (!shortPage && maxScrollPercent >= readThroughScrollPercent) {
      trackReadThrough();
      return;
    }

    // For short pages, use visible time fallback after pageview.
    if (shortPage && sentPageview && visibleTimeMs() >= readThroughFallbackSeconds * 1000) {
      trackReadThrough();
    }
  }

  function checkEngagedThreshold() {
    if (sentEngaged) {
      return;
    }

    if (visibleTimeMs() >= engagedAfterSeconds * 1000) {
      trackEngaged();
    }
  }

  function bindEngagementSignals() {
    document.addEventListener('visibilitychange', function () {
      syncVisibilityWindow(Date.now());
      checkEngagedThreshold();
    });

    // First meaningful interaction counts as engagement.
    window.addEventListener('scroll', trackEngaged, { once: true, passive: true });
    document.addEventListener('click', trackEngaged, { once: true, passive: true });

    // Track read-through from scroll depth.
    window.addEventListener('scroll', checkReadThrough, { passive: true });

    window.addEventListener('pagehide', trackReadTime, { passive: true });
    window.addEventListener('beforeunload', trackReadTime, { passive: true });

    var intervalId = window.setInterval(function () {
      checkEngagedThreshold();
      checkReadThrough();

      if (sentEngaged && sentReadThrough) {
        window.clearInterval(intervalId);
      }
    }, 500);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', trackPageview, { once: true });
  } else {
    trackPageview();
  }

  bindEngagementSignals();

  window.PublishLayer.track = track;
})();
