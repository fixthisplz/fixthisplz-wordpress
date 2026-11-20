/* Hand-made fake cloned tree shared by the capture tests: just what the masking and pseudo repair touch (no jsdom). */
export const doc = {
	createTextNode: (data) => text(data),
	createElement: (tag) => el(tag),
};

function text(data) {
	return { nodeType: 3, data, get textContent() { return this.data; } };
}

export function el(tag, attrs = {}, children = []) {
	const styles = new Map();
	const node = {
		nodeType: 1,
		tagName: tag.toUpperCase(),
		ownerDocument: doc,
		childNodes: [],
		attrs: { ...attrs },
		style: {
			setProperty: (name, value, priority) => styles.set(name, [value, priority]),
			get: (name) => styles.get(name)?.[0],
		},
		getAttribute(name) {
			return Object.hasOwn(this.attrs, name) ? String(this.attrs[name]) : null;
		},
		setAttribute(name, value) {
			this.attrs[name] = String(value);
		},
		removeAttribute(name) {
			delete this.attrs[name];
		},
		hasAttribute(name) {
			return Object.hasOwn(this.attrs, name);
		},
		matches(selector) {
			const attr = /^\[([\w-]+)\]$/.exec(selector);
			if (attr) return this.hasAttribute(attr[1]);
			if (selector.startsWith('.')) return (this.getAttribute('class') || '').split(/\s+/).includes(selector.slice(1));
			throw new SyntaxError(`unsupported selector ${selector}`);
		},
		get firstChild() {
			return this.childNodes[0] || null;
		},
		removeChild(child) {
			this.childNodes.splice(this.childNodes.indexOf(child), 1);
			return child;
		},
		appendChild(child) {
			this.childNodes.push(child);
			return child;
		},
		get textContent() {
			return this.childNodes.map((c) => c.textContent).join('');
		},
		set textContent(value) {
			this.childNodes = [text(value)];
		},
	};
	children.forEach((c) => node.appendChild(typeof c === 'string' ? text(c) : c));
	return node;
}

export const serialize = (node) =>
	node.nodeType === 3 ? node.data : `<${node.tagName} ${JSON.stringify(node.attrs)}>${node.childNodes.map(serialize).join('')}</${node.tagName}>`;
