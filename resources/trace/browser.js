/*
 * Monocle browser trace, injected into HTML pages when trace.browser is on.
 * Same-origin fetch and XMLHttpRequest calls send X-Monocle-Parent with the
 * capture id of the current page, so their captures join the page's trace.
 * When an SPA router (wire:navigate, Inertia, ...) moves to another URL, the
 * capture of the response fetched for that URL becomes the current page.
 * Each step is written to the console, with links to the captures.
 * With the dashboard on, a round button in a corner opens the captures in it:
 * straight away for one, from a list (newest first) once calls have followed.
 * The button can be dragged to a corner or the middle of an edge, which is remembered.
 */
(function (script) {
  var id = script && script.getAttribute('data-monocle-id');
  if (!id) return;
  var dashboard = script.getAttribute('data-monocle-dashboard');
  var page = { id: id, method: script.getAttribute('data-monocle-method') || 'GET', path: location.pathname + location.search, status: Number(script.getAttribute('data-monocle-status')) || null, page: true };

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
    trace.add(page);
    log('page is now ' + short(id), id);
    return;
  }
  trace = window.__monocleTrace = { id: id, seen: {}, waiting: null, captures: [], add: function () {} };
  trace.seen[key(location.href)] = id;
  if (dashboard) button();
  trace.add(page);
  log('linking this page\'s fetch and XHR calls to its capture ' + short(id), id);

  // The round button and its list, in a shadow root so the page's CSS cannot reach them.
  // It hangs off <html>, so routers that swap <body> leave it in place.
  function button() {
    var host = document.createElement('monocle-button');
    host.style.all = 'initial';
    var root = host.attachShadow({ mode: 'open' });
    root.innerHTML = '<style>' +
      ':host{--brass:#a36a1e;--glass:#17181b;--bg:#fff;--fg:#1d1b18;--muted:#6b645a;--line:#ddd6cb;--hover:rgba(163,106,30,.1);--ok:#2f7d4f;--redirect:#3b6fb6;--client:#b26a00;--server:#c0392b}' +
      '@media (prefers-color-scheme:dark){:host{--brass:#e9b25a;--bg:#17181b;--fg:#ece8e1;--muted:#9b958c;--line:#2c2e33;--hover:rgba(233,178,90,.12);--ok:#5cc28a;--redirect:#7aa7e8;--client:#e9b25a;--server:#f0776a}}' +
      '.wrap{position:fixed;z-index:2147483647;font:13px/1.4 ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--fg)}' +
      '.wrap.t{top:16px}.wrap.b{bottom:16px}.wrap.l{left:16px}.wrap.r{right:16px}.wrap.c{left:50%;transform:translateX(-50%)}.wrap.m{top:50%;transform:translateY(-50%)}' +
      '.dot{position:relative;display:grid;place-items:center;width:44px;height:44px;padding:0;border:3px solid var(--brass);border-radius:50%;background:var(--glass);cursor:pointer;touch-action:none;user-select:none;-webkit-user-select:none;box-shadow:0 4px 14px rgba(0,0,0,.25);transition:transform .15s}' +
      '.dot:hover{transform:scale(1.06)}.dot:focus-visible{outline:2px solid var(--brass);outline-offset:3px}.dragging .dot{cursor:grabbing;transform:scale(1.1);transition:none}' +
      '.dot svg{width:24px;height:24px}' +
      '.count{position:absolute;top:-6px;right:-6px;min-width:18px;height:18px;padding:0 5px;box-sizing:border-box;border-radius:9px;background:var(--brass);color:var(--glass);font-size:11px;font-weight:700;line-height:18px;text-align:center;font-variant-numeric:tabular-nums}' +
      '.pop{position:absolute;width:min(420px,calc(100vw - 32px));max-height:60vh;overflow:auto;margin:0;padding:6px;list-style:none;box-sizing:border-box;background:var(--bg);border:1px solid var(--line);border-radius:10px;box-shadow:0 12px 32px rgba(0,0,0,.22)}' +
      '.t .pop{top:54px}.b .pop{bottom:54px}.t.l .pop,.b.l .pop{left:0}.t.r .pop,.b.r .pop{right:0}.c .pop{left:50%;transform:translateX(-50%)}' +
      '.m .pop{top:50%;transform:translateY(-50%)}.m.l .pop{left:54px}.m.r .pop{right:54px}' +
      '.pop a{display:flex;gap:8px;align-items:baseline;padding:6px 8px;border-radius:6px;color:inherit;text-decoration:none}' +
      '.pop a:hover,.pop a:focus-visible{background:var(--hover);outline:none}' +
      '.method{flex:none;width:52px;font:600 11px ui-monospace,Menlo,Consolas,monospace;color:var(--muted)}' +
      '.path{flex:1;min-width:0;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px}' +
      '.tag{flex:none;font-size:11px;color:var(--brass)}' +
      '.status{flex:none;font:600 12px ui-monospace,Menlo,Consolas,monospace;font-variant-numeric:tabular-nums}' +
      '.s2{color:var(--ok)}.s3{color:var(--redirect)}.s4{color:var(--client)}.s5{color:var(--server)}' +
      '[hidden]{display:none!important}' +
      '</style>' +
      '<div class="wrap"><button class="dot" type="button" aria-label="Open in Monocle" aria-haspopup="menu" aria-expanded="false">' +
      '<svg viewBox="5 5 17 17" aria-hidden="true"><rect x="6" y="9" width="13" height="2.4" rx="1.2" fill="#6fd6cc"/><rect x="8.5" y="12.3" width="10" height="2.4" rx="1.2" fill="#a593ea"/><rect x="11" y="15.6" width="5.5" height="2.4" rx="1.2" fill="#6fd6cc"/></svg>' +
      '<span class="count" hidden></span></button><ol class="pop" role="menu" hidden></ol></div>';
    document.documentElement.appendChild(host);

    var wrap = root.querySelector('.wrap');
    var dot = root.querySelector('.dot');
    var count = root.querySelector('.count');
    var list = root.querySelector('.pop');
    var spot = 'br';
    try { spot = localStorage.getItem('monocle.spot') || spot; } catch (e) {}

    function place(at) {
      spot = at;
      wrap.className = 'wrap ' + at.charAt(0) + ' ' + at.charAt(1);
      wrap.style.left = wrap.style.top = wrap.style.right = wrap.style.bottom = '';
    }
    place(/^([tb][lcr]|m[lr])$/.test(spot) ? spot : 'br');

    // The spot nearest to x, y: a corner or the middle of an edge.
    function nearest(x, y) {
      var rows = { t: 0, m: innerHeight / 2, b: innerHeight };
      var columns = { l: 0, c: innerWidth / 2, r: innerWidth };
      var best = null;
      ['tl', 'tc', 'tr', 'ml', 'mr', 'bl', 'bc', 'br'].forEach(function (at) {
        var distance = Math.hypot(x - columns[at.charAt(1)], y - rows[at.charAt(0)]);
        if (!best || distance < best.distance) best = { at: at, distance: distance };
      });
      return best.at;
    }

    function link(capture) {
      return dashboard + '#' + capture;
    }

    function open(state) {
      list.hidden = !state;
      dot.setAttribute('aria-expanded', String(state));
    }

    function row(capture) {
      var item = document.createElement('li');
      var a = document.createElement('a');
      a.href = link(capture.id);
      a.target = '_blank';
      a.rel = 'noopener';
      a.setAttribute('role', 'menuitem');
      a.title = capture.method + ' ' + capture.path;
      [['method', capture.method], ['path', capture.path], ['tag', capture.page ? 'page' : ''], ['status s' + String(capture.status || '').charAt(0), capture.status || '']].forEach(function (part) {
        if (part[1] === '') return;
        var span = document.createElement('span');
        span.className = part[0];
        span.textContent = part[1];
        a.appendChild(span);
      });
      a.addEventListener('click', function () { open(false); });
      item.appendChild(a);
      return item;
    }

    trace.add = function (capture) {
      if (trace.captures.some(function (known) { return known.id === capture.id; })) return;
      trace.captures.push(capture);
      list.insertBefore(row(capture), list.firstChild);
      count.hidden = trace.captures.length < 2;
      count.textContent = trace.captures.length;
    };

    // A press that moves more than a few pixels drags; letting go snaps to the nearest spot.
    var press = null;
    dot.addEventListener('pointerdown', function (event) {
      if (event.button !== 0) return;
      // No text selection or native drag while the button is held.
      event.preventDefault();
      press = { x: event.clientX, y: event.clientY, dragging: false };
      dot.setPointerCapture(event.pointerId);
    });
    dot.addEventListener('pointermove', function (event) {
      if (!press) return;
      if (!press.dragging && Math.hypot(event.clientX - press.x, event.clientY - press.y) < 5) return;
      press.dragging = true;
      open(false);
      wrap.className = 'wrap dragging';
      wrap.style.left = event.clientX - 22 + 'px';
      wrap.style.top = event.clientY - 22 + 'px';
    });
    dot.addEventListener('pointerup', function (event) {
      if (!press || !press.dragging) return;
      var at = nearest(event.clientX, event.clientY);
      place(at);
      try { localStorage.setItem('monocle.spot', at); } catch (e) {}
    });
    dot.addEventListener('pointercancel', function () {
      press = null;
      place(spot);
    });
    dot.addEventListener('click', function () {
      var dragged = press && press.dragging;
      press = null;
      if (dragged) return;
      if (trace.captures.length === 1) {
        window.open(link(trace.captures[0].id), '_blank', 'noopener');
        return;
      }
      open(list.hidden);
      if (!list.hidden) list.querySelector('a').focus();
    });

    document.addEventListener('click', function (event) {
      if (event.composedPath().indexOf(host) === -1) open(false);
    });
    document.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape' || list.hidden) return;
      open(false);
      dot.focus();
    });
  }

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
  function answered(url, capture, method, parent, status) {
    if (!url || !capture) return;
    var at = key(url);
    trace.seen[at] = capture;
    trace.add({ id: capture, method: method, path: at, status: status, page: false });
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
        answered(response.url || url, response.headers.get('X-Monocle-Id'), method, parent, response.status);
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
        answered(this.responseURL || url, this.getResponseHeader('X-Monocle-Id'), this.__monocleMethod, parent, this.status);
      });
    }
    return send.apply(this, arguments);
  };
})(document.currentScript);
