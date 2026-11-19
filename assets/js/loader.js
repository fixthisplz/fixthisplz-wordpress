/* Bug Spotter loader: diagnostics buffers + floating button. Classic script, no dependencies. */
(function () {
	'use strict';
	var w = window;
	var cfg = w.bugSpotterConfig;
	if (!cfg || w.__bugSpotterLoader) { return; }
	if (cfg.hideOnMobile && w.matchMedia && w.matchMedia(cfg.mobileQuery).matches) { return; }
	w.__bugSpotterLoader = true;

	var LIMIT = 20;
	var collect = cfg.collect || {};
	var key = Symbol.for('bugspot.diagnostics');
	var store = (globalThis[key] = globalThis[key] || { console: [], network: [] });
	var ownUrl = String(cfg.restUrl || '');

	/* Minimal mirror of lib/redact.js (same rules, same order; truncation last). */
	function text(s, max) {
		s = String(s)
			.replace(/\bBearer\s+\S+/gi, 'Bearer [jeton]')
			.replace(/\beyJ[A-Za-z0-9_-]*\.[A-Za-z0-9_-]*\.[A-Za-z0-9_-]*/g, '[jeton]')
			.replace(/[\w.+%-]{1,64}@[\w-]{1,63}(?:\.[\w-]{1,63}){1,20}/g, '[email]')
			.replace(/[A-Za-z0-9_-]{32,}/g, '[jeton]');
		return Array.from(s).slice(0, max).join('');
	}

	function url(u) {
		var out;
		try {
			var p = new URL(String(u), location.href);
			p.hash = '';
			p.username = '';
			p.password = '';
			p.searchParams.forEach(function (v, k) { p.searchParams.set(k, '…'); });
			out = decodeURI(p.href);
		} catch (e) {
			out = String(u).replace(/#.*$/, '').replace(/=[^&]*/g, '=…');
		}
		return text(out, 512);
	}

	function push(list, entry) {
		list.push(entry);
		if (list.length > LIMIT) { list.shift(); }
	}

	function describe(a) {
		if (a instanceof Error) { return a.name + ': ' + a.message; }
		if (typeof a === 'string') { return a; }
		try { return JSON.stringify(a); } catch (e) { /* circular */ }
		try { return String(a); } catch (e) { return '[unserializable]'; }
	}

	function addConsole(level, message) {
		try {
			push(store.console, { level: level, message: text(message, 500), at: new Date().toISOString() });
		} catch (e) { /* never break the page */ }
	}

	function addNetwork(method, target, status) {
		try {
			var raw = String(target);
			if (ownUrl && raw.indexOf(ownUrl) === 0) { return; }
			push(store.network, { method: String(method || 'GET').toUpperCase().slice(0, 16), url: url(raw), status: status, at: new Date().toISOString() });
		} catch (e) { /* never break the page */ }
	}

	if (collect.console) {
		var busy = false;
		['error', 'warn'].forEach(function (level) {
			var original = console[level];
			if (typeof original !== 'function') { return; }
			console[level] = function () {
				if (!busy) {
					busy = true;
					try { addConsole(level, Array.prototype.map.call(arguments, describe).join(' ')); } catch (e) { /* never throw into the page */ } finally { busy = false; }
				}
				return original.apply(this, arguments);
			};
		});
		w.addEventListener('error', function (e) {
			if (e.target && e.target !== w) { return; }
			var where = e.filename ? ' (' + url(e.filename) + ':' + e.lineno + ')' : '';
			addConsole('error', (e.message || 'Script error') + where);
		});
		w.addEventListener('unhandledrejection', function (e) {
			try { addConsole('error', 'Unhandled rejection: ' + describe(e.reason)); } catch (err) { /* ignore */ }
		});
	}

	if (collect.network) {
		if (typeof w.fetch === 'function') {
			var nativeFetch = w.fetch;
			w.fetch = function (input, init) {
				var promise = nativeFetch.apply(this, arguments);
				try {
					var target = typeof input === 'string' ? input : (input && input.url) || String(input);
					var method = (init && init.method) || (input && input.method) || 'GET';
					promise.then(
						function (r) { if (r && r.status >= 400) { addNetwork(method, target, r.status); } },
						function (err) { if (!err || err.name !== 'AbortError') { addNetwork(method, target, 0); } }
					);
				} catch (e) { /* never break the page */ }
				return promise;
			};
		}
		if (w.XMLHttpRequest) {
			var proto = w.XMLHttpRequest.prototype;
			var nativeOpen = proto.open;
			var nativeSend = proto.send;
			proto.open = function (method, target) {
				this.__bugspot = { method: method, url: target };
				return nativeOpen.apply(this, arguments);
			};
			proto.send = function () {
				var xhr = this;
				var info = xhr.__bugspot;
				if (info) {
					xhr.addEventListener('abort', function () { info.aborted = true; });
					xhr.addEventListener('loadend', function () {
						if (!info.aborted && (xhr.status >= 400 || xhr.status === 0)) { addNetwork(info.method, info.url, xhr.status); }
					});
				}
				return nativeSend.apply(this, arguments);
			};
		}
	}

	function createButton() {
		var b = cfg.button || {};
		var i18n = cfg.i18n || {};
		var el = document.createElement('button');
		el.id = 'bugspot-button';
		el.type = 'button';
		el.setAttribute('aria-label', b.label || '');
		el.setAttribute('data-bugspot-ui', '');
		if (b.tooltip) { el.title = b.tooltip; }

		if (b.iconType === 'svg' && b.svgUrl) {
			var img = document.createElement('img');
			img.alt = '';
			img.src = b.svgUrl;
			el.appendChild(img);
		} else if (b.dashicon) {
			var icon = document.createElement('span');
			icon.className = 'dashicons ' + b.dashicon;
			icon.setAttribute('aria-hidden', 'true');
			el.appendChild(icon);
		}
		if (b.shape === 'pill') {
			var label = document.createElement('span');
			label.className = 'bugspot-label';
			label.textContent = b.label || '';
			el.appendChild(label);
		}

		var warned = false;
		el.addEventListener('click', function () {
			el.disabled = true;
			el.setAttribute('aria-busy', 'true');
			if (i18n.loading) { el.title = i18n.loading; }
			import(cfg.moduleUrl).then(function (m) {
				return m.open(cfg);
			}).catch(function () {
				if (!warned) { warned = true; console.warn('[bug-spotter] ' + (i18n.errorLoad || 'Reporter module failed to load.')); }
			}).then(function () {
				el.disabled = false;
				el.removeAttribute('aria-busy');
				el.title = b.tooltip || '';
			});
		});
		document.body.appendChild(el);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', createButton);
	} else {
		createButton();
	}
})();
