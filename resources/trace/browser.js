/*
 * Monocle browser trace, injected into HTML pages when trace.browser is on.
 * Same-origin fetch and XMLHttpRequest calls send X-Monocle-Parent with the
 * capture id of the current page, so their captures join the page's trace.
 * When an SPA router (wire:navigate, Inertia, ...) moves to another URL, the
 * capture of the response fetched for that URL becomes the current page.
 * Each step is written to the console, with links to the captures.
 */
(function (script) {
  var id = script && script.getAttribute('data-monocle-id');
  if (!id) return;
  var dashboard = script.getAttribute('data-monocle-dashboard');

  function key(url) {
    var parsed = new URL(url, location.href);
    return parsed.pathname + parsed.search;
  }

  // One styled line in the console, with a link to the capture in the dashboard when there is one.
  function log(text, capture) {
    var args = ['%cmonocle%c ' + text, 'background:#a36a1e;color:#fff;border-radius:3px;padding:1px 5px;font-weight:600', 'color:inherit'];
    if (capture && dashboard) args.push(dashboard + '#' + capture);
    console.log.apply(console, args);
  }

  // The tail of a capture id, enough to tell captures apart in the console.
  function short(capture) {
    return '...' + capture.slice(-8);
  }

  var trace = window.__monocleTrace;
  if (trace) {
    // The router ran the new page's copy of this script.
    trace.id = id;
    trace.seen[key(location.href)] = id;
    log('page is now ' + short(id), id);
    return;
  }
  trace = window.__monocleTrace = { id: id, seen: {}, waiting: null };
  trace.seen[key(location.href)] = id;
  log('linking this page\'s fetch and XHR calls to its capture ' + short(id), id);

  function sameOrigin(url) {
    try {
      return new URL(url, location.href).origin === location.origin;
    } catch (e) {
      return false;
    }
  }

  // A response with a capture id: the page for its URL, now or once navigated to.
  // Only calls Monocle recorded are logged (they answer with X-Monocle-Id), so a logger that
  // posts console output back to the server cannot set off a loop.
  function answered(url, capture, method, parent) {
    if (!url || !capture) return;
    var at = key(url);
    trace.seen[at] = capture;
    log(method + ' ' + at + ' recorded as ' + short(capture) + ', linked to page ' + short(parent), capture);
    if (trace.waiting === at) {
      trace.id = capture;
      trace.waiting = null;
      log('page is now ' + short(capture), capture);
    }
  }

  // A navigation to a URL: its response may have arrived already or still be on its way.
  function navigated(url) {
    var at = key(url);
    trace.waiting = null;
    if (!trace.seen[at]) {
      trace.waiting = at;
      return;
    }
    if (trace.seen[at] === trace.id) return;
    trace.id = trace.seen[at];
    log('page is now ' + short(trace.id), trace.id);
  }

  if (window.navigation) {
    window.navigation.addEventListener('navigate', function (event) { navigated(event.destination.url); });
  } else {
    ['pushState', 'replaceState'].forEach(function (name) {
      var original = history[name];
      history[name] = function (state, title, url) {
        var result = original.apply(this, arguments);
        if (url != null) navigated(url);
        return result;
      };
    });
    window.addEventListener('popstate', function () { navigated(location.href); });
  }

  var originalFetch = window.fetch;
  if (originalFetch) {
    window.fetch = function (input, init) {
      var url = input instanceof Request ? input.url : String(input);
      if (!sameOrigin(url)) return originalFetch.apply(this, arguments);

      init = Object.assign({}, init);
      var headers = new Headers(init.headers || (input instanceof Request ? input.headers : undefined));
      var parent = trace.id;
      headers.set('X-Monocle-Parent', parent);
      init.headers = headers;
      var method = (init.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();

      return originalFetch.call(this, input, init).then(function (response) {
        answered(response.url || url, response.headers.get('X-Monocle-Id'), method, parent);
        return response;
      });
    };
  }

  var proto = XMLHttpRequest.prototype;
  var open = proto.open;
  var send = proto.send;

  proto.open = function (method, url) {
    this.__monocleUrl = sameOrigin(url) ? String(url) : null;
    this.__monocleMethod = String(method || 'GET').toUpperCase();
    return open.apply(this, arguments);
  };

  proto.send = function () {
    var url = this.__monocleUrl;
    if (url) {
      var parent = trace.id;
      this.setRequestHeader('X-Monocle-Parent', parent);
      this.addEventListener('load', function () {
        answered(this.responseURL || url, this.getResponseHeader('X-Monocle-Id'), this.__monocleMethod, parent);
      });
    }
    return send.apply(this, arguments);
  };
})(document.currentScript);
