/* The field in which a text annotation is typed (spec 5.2, annotation): a real <input> laid over the editor's canvas
 * exactly where the text box will be, at the displayed size, so typing looks like the result. Enter or leaving the
 * field keeps the text, Escape drops it (without closing the editor). */
import { make } from './dom.js';
import { LINE_HEIGHT, TEXT_BACKGROUND, TEXT_MAX_LENGTH, fontFor, textPadding } from './annotate-text.js';

// iOS zooms the page into a field whose font is smaller than this: the field is rendered at least this large, then
// scaled down with a transform to the displayed size.
const MIN_FIELD_FONT = 16;

/**
 * Opens the field. host: positioned ancestor of canvas; at: native top-left corner of the text box; imageWidth: native
 * width of the image shown on canvas; measure(text, font): width of text in that font, as for the final box.
 * done(text, reason) is called once: text is the trimmed text ('' when left empty), or null when dropped with Escape;
 * reason is 'enter', 'escape', 'press' (keep() from outside) or 'blur' (the focus went elsewhere).
 * @returns {{keep: () => void, reposition: () => void}} keep() closes the field keeping the text (a press on the
 *   canvas); reposition() follows a new display size of the canvas.
 */
export function openTextField({ host, canvas, imageWidth, at, fontSize, color, text, label, measure }, done) {
	const field = make('input', { type: 'text', class: 'fixthisplz-annot-text', maxlength: TEXT_MAX_LENGTH, 'aria-label': label });
	field.value = text;
	const pad = textPadding(fontSize);

	function reposition() {
		const canvasBox = canvas.getBoundingClientRect();
		const hostBox = host.getBoundingClientRect();
		const css = canvasBox.width / imageWidth; // CSS pixels per native pixel, as displayed
		const render = Math.max(MIN_FIELD_FONT, fontSize * css) / fontSize; // CSS pixels per native pixel, as rendered
		Object.assign(field.style, {
			left: `${canvasBox.left - hostBox.left + at.x * css}px`,
			top: `${canvasBox.top - hostBox.top + at.y * css}px`,
			width: `${(measure(field.value, fontFor(fontSize)) + 2 * pad) * render}px`,
			height: `${(fontSize * LINE_HEIGHT + 2 * pad) * render}px`,
			padding: `0 ${pad * render}px`,
			font: fontFor(fontSize * render),
			transform: `scale(${css / render})`,
		});
	}

	let open = true;
	const close = (value, reason) => {
		if (!open) {
			return;
		}
		open = false;
		field.remove();
		done(value, reason);
	};
	const keep = (reason) => close(field.value.trim(), reason);
	field.addEventListener('input', reposition);
	field.addEventListener('blur', () => keep('blur'));
	field.addEventListener('keydown', (event) => {
		if (event.key === 'Enter' && !event.isComposing) {
			event.preventDefault();
			keep('enter');
		} else if (event.key === 'Escape') {
			event.preventDefault();
			event.stopPropagation(); // drops this text only: the editor's Escape (cancel everything) must not see it
			close(null, 'escape');
		}
	});
	Object.assign(field.style, { color, background: TEXT_BACKGROUND });
	reposition();
	host.appendChild(field);
	field.focus();
	return { keep: () => keep('press'), reposition };
}
