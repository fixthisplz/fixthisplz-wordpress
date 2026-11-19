/* Anti-bot widget (spec 6.3). The provider script is injected once, only when the dialog opens.
 * mountCaptcha never throws: a provider that cannot load makes getToken() reject with CaptchaUnavailableError. */

const LOAD_TIMEOUT_MS = 15000;
const ACTION = 'bug_report';
const scripts = new Map();

export class CaptchaUnavailableError extends Error {
	constructor() {
		super('Anti-bot provider unavailable.');
		this.name = 'CaptchaUnavailableError';
	}
}

function loadScript(url) {
	if (!scripts.has(url)) {
		const promise = new Promise((resolve, reject) => {
			const script = document.createElement('script');
			script.src = url;
			script.async = true;
			script.setAttribute('data-bugspot-ui', '');
			script.onload = () => resolve();
			script.onerror = () => reject(new CaptchaUnavailableError());
			document.head.appendChild(script);
		}).catch((error) => {
			scripts.delete(url); // a later dialog may retry
			throw error;
		});
		scripts.set(url, promise);
	}
	return scripts.get(url);
}

/** Resolves with get() once it returns something truthy; rejects after the timeout. */
function waitFor(get, timeout = LOAD_TIMEOUT_MS) {
	return new Promise((resolve, reject) => {
		const started = Date.now();
		const tick = () => {
			let value;
			try {
				value = get();
			} catch {
				value = undefined;
			}
			if (value) {
				resolve(value);
			} else if (Date.now() - started > timeout) {
				reject(new CaptchaUnavailableError());
			} else {
				setTimeout(tick, 50);
			}
		};
		tick();
	});
}

function withTimeout(promise, timeout = LOAD_TIMEOUT_MS) {
	return Promise.race([promise, new Promise((_, reject) => setTimeout(() => reject(new CaptchaUnavailableError()), timeout))]);
}

const grecaptchaReady = () =>
	waitFor(() => window.grecaptcha && typeof window.grecaptcha.ready === 'function' && window.grecaptcha).then(
		(g) => withTimeout(new Promise((resolve) => g.ready(() => resolve(g))))
	);

const PROVIDERS = {
	turnstile: {
		async render(container, siteKey) {
			const api = await waitFor(() => window.turnstile && typeof window.turnstile.render === 'function' && window.turnstile);
			const id = api.render(container, { sitekey: siteKey, action: ACTION, theme: 'auto' });
			return {
				token: async () => api.getResponse(id) || '',
				reset: () => api.reset(id),
				remove: () => api.remove(id),
			};
		},
	},
	hcaptcha: {
		async render(container, siteKey) {
			const api = await waitFor(() => window.hcaptcha && typeof window.hcaptcha.render === 'function' && window.hcaptcha);
			const id = api.render(container, { sitekey: siteKey });
			return {
				token: async () => api.getResponse(id) || '',
				reset: () => api.reset(id),
				remove: () => api.remove(id),
			};
		},
	},
	recaptcha_v2: {
		async render(container, siteKey) {
			const api = await grecaptchaReady();
			const id = api.render(container, { sitekey: siteKey });
			return {
				token: async () => api.getResponse(id) || '',
				reset: () => api.reset(id),
				remove: () => {},
			};
		},
	},
	recaptcha_v3: {
		async render(container, siteKey) {
			const api = await grecaptchaReady();
			return {
				token: () => withTimeout(Promise.resolve(api.execute(siteKey, { action: ACTION }))),
				reset: () => {},
				remove: () => {},
			};
		},
	},
};

/**
 * @param {HTMLElement} container
 * @param {{provider: string, siteKey: string, scriptUrl: string}} captchaConfig
 * @returns {Promise<{getToken(): Promise<string>, reset(): void, destroy(): void}>}
 */
export async function mountCaptcha(container, captchaConfig) {
	const cfg = captchaConfig || {};
	const provider = PROVIDERS[cfg.provider];
	if (!provider) {
		return { getToken: async () => '', reset() {}, destroy() {} };
	}

	let widget = null;
	let failure = null;
	try {
		if (!cfg.scriptUrl || !cfg.siteKey) {
			throw new CaptchaUnavailableError();
		}
		await withTimeout(loadScript(cfg.scriptUrl));
		widget = await provider.render(container, cfg.siteKey);
	} catch {
		failure = new CaptchaUnavailableError();
	}

	return {
		async getToken() {
			if (failure || !widget) {
				throw failure || new CaptchaUnavailableError();
			}
			try {
				return String((await widget.token()) || '');
			} catch {
				throw new CaptchaUnavailableError();
			}
		},
		reset() {
			try {
				widget?.reset();
			} catch {
				/* widget already gone */
			}
		},
		destroy() {
			try {
				widget?.remove();
			} catch {
				/* widget already gone */
			}
		},
	};
}
