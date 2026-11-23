/* Tiny element factory shared by the inspector and the dialog. Text is always set with textContent. */

export const UI_ATTR = 'data-fixthisplz-ui';

export function make(tag, props = {}, children = []) {
	const node = document.createElement(tag);
	for (const [key, value] of Object.entries(props)) {
		if (value === false || value === null || value === undefined) {
			continue;
		}
		if (key === 'text') {
			node.textContent = value;
		} else if (key === 'class') {
			node.className = value;
		} else {
			node.setAttribute(key, value === true ? '' : String(value));
		}
	}
	node.append(...children.filter(Boolean));
	return node;
}

/** Reads a label from config.i18n; '' when missing (no hardcoded English fallback in the UI). */
export const translator = (i18n) => (key) => (i18n && typeof i18n[key] === 'string' ? i18n[key] : '');
