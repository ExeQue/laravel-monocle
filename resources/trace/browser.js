/*
 * Monocle browser trace, injected into HTML pages when trace.browser is on.
 * Same-origin fetch and XMLHttpRequest calls send X-Monocle-Parent with the
 * capture id of the current page, so their captures join the page's trace.
 * When an SPA router (wire:navigate, Inertia, ...) moves to another URL, the
 * capture of the response fetched for that URL becomes the current page.
 */
(function (script) {
  var id = script && script.getAttribute('data-monocle-id');
  if (!id) return;

  function key(url) {
    var parsed = new URL(url, location.href);
    return parsed.pathname + parsed.search;
  }

  var trace = window.__monocleTrace;
  if (trace) {
    // The router ran the new page's copy of this script.
    trace.id = id;
    trace.seen[key(location.href)] = id;
    return;
  }
  trace = window.__monocleTrace = { id: id, seen: {}, waiting: null };
  trace.seen[key(location.href)] = id;

  function sameOrigin(url) {
    try {
      return new URL(url, location.href).origin === location.origin;
    } catch (e) {
      return false;
    }
  }

  // A response with a capture id: the page for its URL, now or once navigated to.
  function answered(url, capture) {
    if (!url || !capture) return;
    var at = key(url);
    trace.seen[at] = capture;
    if (trace.waiting === at) {
      trace.id = capture;
      trace.waiting = null;
    }
  }

  // A navigation to a URL: its response may have arrived already or still be on its way.
  function navigated(url) {
    var at = key(url);
    trace.waiting = null;
    if (trace.seen[at]) trace.id = trace.seen[at];
    else trace.waiting = at;
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
      headers.set('X-Monocle-Parent', trace.id);
      init.headers = headers;

      return originalFetch.call(this, input, init).then(function (response) {
        answered(response.url || url, response.headers.get('X-Monocle-Id'));
        return response;
      });
    };
  }

  var proto = XMLHttpRequest.prototype;
  var open = proto.open;
  var send = proto.send;

  proto.open = function (method, url) {
    this.__monocleUrl = sameOrigin(url) ? String(url) : null;
    return open.apply(this, arguments);
  };

  proto.send = function () {
    var url = this.__monocleUrl;
    if (url) {
      this.setRequestHeader('X-Monocle-Parent', trace.id);
      this.addEventListener('load', function () {
        answered(this.responseURL || url, this.getResponseHeader('X-Monocle-Id'));
      });
    }
    return send.apply(this, arguments);
  };
})(document.currentScript);
