/* Reporter entry point, loaded on demand by loader.js: inspect, then dialog. One flow at a time. */
import { inspect } from './lib/inspector.js';
import { openDialog } from './lib/dialog.js';

let session = null;

function end() {
	session = null;
	const button = document.getElementById('fixthisplz-button');
	if (button) {
		button.focus();
	}
}

async function run(config) {
	const options = { labels: config.i18n || {}, sensitiveSelectors: Array.isArray(config.sensitiveSelectors) ? config.sensitiveSelectors : [] };
	try {
		const target = await inspect(options);
		if (target === null) {
			end();
			return;
		}
		session.dialog = openDialog({
			config,
			element: target === 'page' ? null : target,
			onRetakeElement: () => inspect(options),
			onClose: end,
		});
	} catch (error) {
		end();
		throw error;
	}
}

/** Starts the flow; a second call while it is open only brings it back into focus. Returns immediately. */
export function open(config) {
	if (session) {
		if (session.dialog) {
			session.dialog.focus();
		} else {
			inspect({}); // already active: refocuses the instruction bar
		}
		return;
	}
	session = { dialog: null };
	run(config || {}).catch((error) => console.warn('[fixthisplz]', error));
}
