/**
 * Argusly Analytics - privacy-first pageview tracker
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
      if (src.indexOf('/argusly.js') !== -1 || src.indexOf('/pl.js') !== -1) {
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
    return typeof value === 'string' && value.length > max ? value.slice(0, max) : value;
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

      var pathname = '/' + String(parsed.pathname || '/').replace(/^\/+/, '');
      pathname = pathname.replace(/\/+/g, '/').toLowerCase();
      if (pathname.length > 1) {
        pathname = pathname.replace(/\/+$/, '');
      }

      return protocol + '//' + hostname + (port ? ':' + port : '') + pathname;
    } catch (error) {
      return null;
    }
  }

  function getCanonicalTagUrl() {
    var canonical = document.querySelector('link[rel="canonical"]');
    var href = canonical ? (canonical.getAttribute('href') || canonical.href || '') : '';

    return href ? normalizeUrl(href) : null;
  }

  function getCanonicalUrl() {
    var context = window.Argusly || {};

    return normalizeUrl(context.canonicalUrl || context.canonical_url)
      || getCanonicalTagUrl()
      || normalizeUrl(window.location.href);
  }

  function createSessionId() {
    var randomPart = String(Math.random()).slice(2);

    if (window.crypto && window.crypto.getRandomValues) {
      try {
        var bytes = new Uint8Array(8);
        window.crypto.getRandomValues(bytes);
        randomPart = Array.prototype.map.call(bytes, function (b) {
          return b.toString(16).padStart(2, '0');
        }).join('');
      } catch (error) {}
    }

    return 'args_' + Date.now().toString(36) + '_' + randomPart;
  }

  var inMemorySessionId = null;
  function getSessionId() {
    if (inMemorySessionId) {
      return inMemorySessionId;
    }

    var key = '__argusly_session_id';
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

  function sendPayload(endpoint, payload) {
    var body = JSON.stringify(payload);

    if (navigator.sendBeacon) {
      try {
        if (navigator.sendBeacon(endpoint, body)) {
          return;
        }
      } catch (error) {}
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
      } catch (error) {}
    }

    try {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', endpoint, true);
      xhr.setRequestHeader('Content-Type', 'text/plain;charset=UTF-8');
      xhr.send(body);
    } catch (error) {}
  }

  function buildPayload(siteKey, eventType, extra) {
    var context = window.Argusly || {};
    var payload = {
      site_key: siteKey,
      event_type: eventType,
      session_id: getSessionId(),
      url: getCanonicalUrl(),
      canonical_url: getCanonicalUrl(),
      referrer: truncate(document.referrer || '', 512) || null,
      page_title: document.title || null,
      occurred_at: new Date().toISOString()
    };

    if (context.articleId || context.article_id || context.contentId || context.content_id) {
      payload.article_id = String(context.articleId || context.article_id || context.contentId || context.content_id);
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

  window.Argusly = window.Argusly || {};

  var siteKey = window.Argusly.siteKey || script.getAttribute('data-site-key') || script.getAttribute('data-site') || '';
  if (!siteKey) {
    return;
  }

  var respectDnt = window.Argusly.respectDnt !== false;
  var endpoint = (script.src || '').replace(/\/(?:argusly|pl)\.js.*$/, '/api/tracking/events');
  var samplingRate = clamp(toNumber(window.Argusly.samplingRate || window.Argusly.sampling, 100), 0, 100);
  var sampledIn = Math.random() * 100 < samplingRate;

  function track(eventType, extra) {
    if (!eventType || typeof eventType !== 'string' || (respectDnt && isDntEnabled()) || !sampledIn) {
      return;
    }

    sendPayload(endpoint, buildPayload(siteKey, eventType, extra));
  }

  var sentPageview = false;
  var sentEngaged = false;
  var sentReadThrough = false;
  var sentReadTime = false;
  var sentScrollMilestones = { 25: false, 50: false, 75: false, 100: false };

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

    return visibleStartedAt === null ? visibleAccumulatedMs : visibleAccumulatedMs + (now - visibleStartedAt);
  }

  function trackReadTime() {
    if (sentReadTime) {
      return;
    }

    var seconds = Math.round(visibleTimeMs() / 1000);
    if (seconds <= 0) {
      return;
    }

    sentReadTime = true;
    track('read_time', { seconds: seconds });
  }

  var engagedAfterSeconds = Math.max(toNumber(window.Argusly.engagedAfterSeconds || window.Argusly.engaged_after_seconds, DEFAULT_ENGAGED_AFTER_SECONDS), 1);
  var readThroughScrollPercent = clamp(toNumber(window.Argusly.readThroughScrollPercent || window.Argusly.read_through_scroll_percent, DEFAULT_READ_THROUGH_SCROLL_PERCENT), 1, 100);
  var readThroughFallbackSeconds = Math.max(toNumber(window.Argusly.readThroughFallbackSeconds || window.Argusly.read_through_fallback_seconds, DEFAULT_READ_THROUGH_FALLBACK_SECONDS), 1);

  function getScrollPercent() {
    var doc = document.documentElement;
    var body = document.body;
    var viewportHeight = window.innerHeight || (doc ? doc.clientHeight : 0) || 0;
    var documentHeight = Math.max(doc ? doc.scrollHeight : 0, doc ? doc.offsetHeight : 0, doc ? doc.clientHeight : 0, body ? body.scrollHeight : 0, body ? body.offsetHeight : 0);
    if (documentHeight <= 0) {
      return 0;
    }

    var scrollTop = window.pageYOffset || (doc ? doc.scrollTop : 0) || (body ? body.scrollTop : 0) || 0;

    return clamp(((scrollTop + viewportHeight) / documentHeight) * 100, 0, 100);
  }

  function isShortPage() {
    var doc = document.documentElement;
    var body = document.body;
    var viewportHeight = window.innerHeight || (doc ? doc.clientHeight : 0) || 0;
    var documentHeight = Math.max(doc ? doc.scrollHeight : 0, doc ? doc.offsetHeight : 0, doc ? doc.clientHeight : 0, body ? body.scrollHeight : 0, body ? body.offsetHeight : 0);

    return viewportHeight > 0 && documentHeight > 0 && documentHeight <= viewportHeight * SHORT_PAGE_VIEWPORT_RATIO;
  }

  var maxScrollPercent = 0;
  function checkScrollDepthMilestones() {
    maxScrollPercent = Math.max(maxScrollPercent, getScrollPercent());

    [25, 50, 75, 100].forEach(function (milestone) {
      if (maxScrollPercent < milestone || sentScrollMilestones[milestone]) {
        return;
      }

      sentScrollMilestones[milestone] = true;
      track('scroll_depth', { depth: milestone });

      if (milestone === 50) {
        track('scroll_50');
      } else if (milestone === 100) {
        track('scroll_100');
      }
    });
  }

  function checkReadThrough() {
    if (sentReadThrough) {
      return;
    }

    checkScrollDepthMilestones();

    if ((!isShortPage() && maxScrollPercent >= readThroughScrollPercent) || (isShortPage() && sentPageview && visibleTimeMs() >= readThroughFallbackSeconds * 1000)) {
      trackReadThrough();
    }
  }

  function checkEngagedThreshold() {
    if (!sentEngaged && visibleTimeMs() >= engagedAfterSeconds * 1000) {
      trackEngaged();
    }
  }

  function bindEngagementSignals() {
    document.addEventListener('visibilitychange', function () {
      syncVisibilityWindow(Date.now());
      checkEngagedThreshold();
    });
    window.addEventListener('scroll', trackEngaged, { once: true, passive: true });
    document.addEventListener('click', trackEngaged, { once: true, passive: true });
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
  window.Argusly.track = track;
})();
