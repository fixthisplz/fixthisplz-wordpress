/* FixThisPlz loader: diagnostics buffers + button. Classic script. */
(function () {
	'use strict';
	var w = window;
	var cfg = w.fixThisPlzConfig;
	if (!cfg || w.__fixThisPlzLoader) { return; }
	if (cfg.hideOnMobile && w.matchMedia && w.matchMedia(cfg.mobileQuery).matches) { return; }
	w.__fixThisPlzLoader = true;

	var LIMIT = 20;
	var collect = cfg.collect || {};
	var key = Symbol.for('fixthisplz.diagnostics');
	var store = (globalThis[key] = globalThis[key] || { console: [], network: [] });
	var ownUrl = String(cfg.restUrl || '');

	/* Mirror of lib/redact.js: same rules, same order, truncation last. */
	function text(s, max) {
		s = String(s)
			.replace(/\bBearer\s+\S+/gi, 'Bearer [token]')
			.replace(/\beyJ[A-Za-z0-9_-]*\.[A-Za-z0-9_-]*\.[A-Za-z0-9_-]*/g, '[token]')
			.replace(/[\p{L}\p{N}\p{Mn}\p{Mc}\p{Pc}.+%-]{1,64}@[\p{L}\p{N}\p{Mn}\p{Mc}\p{Pc}-]{1,63}(?:\.[\p{L}\p{N}\p{Mn}\p{Mc}\p{Pc}-]{1,63}){1,20}/gu, '[email]')
			.replace(/[A-Za-z0-9_-]{32,}/g, '[token]');
		return Array.from(s).slice(0, max).join('');
	}

	function url(u) {
		var out;
		try {
			var p = new URL(String(u), location.href);
			p.hash = p.username = p.password = '';
			p.searchParams.forEach(function (v, k) { p.searchParams.set(k, '…'); });
			out = decodeURI(p.href);
		} catch (e) {
			out = String(u).replace(/#.*$/, '').replace(/=[^&]*/g, '=…');
		}
		return text(out, 512);
	}

	function push(list, entry) {
		entry.at = new Date().toISOString();
		list.push(entry);
		if (list.length > LIMIT) { list.shift(); }
	}

	function describe(a) {
		if (a instanceof Error) { return a.name + ': ' + a.message; }
		if (typeof a === 'string') { return a; }
		try { return JSON.stringify(a); } catch (e) {}
		try { return String(a); } catch (e) { return '[unserializable]'; }
	}

	/* Never throws into the page; parts joined like console output. */
	function addConsole(level, parts) {
		try { push(store.console, { level: level, message: text(Array.prototype.map.call(parts, describe).join(' '), 500) }); } catch (e) {}
	}

	function addNetwork(method, target, status) {
		try {
			var raw = String(target);
			if (ownUrl && raw.indexOf(ownUrl) === 0) { return; }
			push(store.network, { method: String(method || 'GET').toUpperCase().slice(0, 16), url: url(raw), status: status });
		} catch (e) {}
	}

	if (collect.console) {
		var busy = false;
		['error', 'warn'].forEach(function (level) {
			var original = console[level];
			if (typeof original !== 'function') { return; }
			console[level] = function () {
				if (!busy) {
					busy = true;
					addConsole(level, arguments);
					busy = false;
				}
				return original.apply(this, arguments);
			};
		});
		w.addEventListener('error', function (e) {
			if (e.target && e.target !== w) { return; }
			var where = e.filename ? ' (' + url(e.filename) + ':' + e.lineno + ')' : '';
			addConsole('error', [(e.message || 'Script error') + where]);
		});
		w.addEventListener('unhandledrejection', function (e) {
			addConsole('error', ['Unhandled rejection:', e.reason]);
		});
	}

	if (collect.network) {
		if (typeof w.fetch === 'function') {
			var nativeFetch = w.fetch;
			w.fetch = function (input, init) {
				var promise = nativeFetch.apply(this, arguments);
				if (init && init.fixThisPlzInternal) { return promise; }
				try {
					var target = typeof input === 'string' ? input : (input && input.url) || String(input);
					var method = (init && init.method) || (input && input.method) || 'GET';
					promise.then(
						function (r) { if (r && r.status >= 400) { addNetwork(method, target, r.status); } },
						function (err) { if (!/^(Abort|Timeout)Error$/.test(err && err.name)) { addNetwork(method, target, 0); } }
					);
				} catch (e) {}
				return promise;
			};
		}
		if (w.XMLHttpRequest) {
			var proto = w.XMLHttpRequest.prototype;
			var nativeOpen = proto.open;
			var nativeSend = proto.send;
			proto.open = function (method, target) {
				this.__fixthisplz = { method: method, url: target };
				return nativeOpen.apply(this, arguments);
			};
			proto.send = function () {
				var xhr = this;
				var info = xhr.__fixthisplz;
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
		el.id = 'fixthisplz-button';
		el.type = 'button';
		el.setAttribute('aria-label', b.label || '');
		el.setAttribute('data-fixthisplz-ui', '');
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
			label.className = 'fixthisplz-label';
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
				if (!warned) { warned = true; console.warn('[fixthisplz] ' + (i18n.errorLoad || 'Reporter module failed to load.')); }
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
