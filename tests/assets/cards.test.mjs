import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-cards.js', import.meta.url),
	'utf8'
);
function fixture({
	runtime = true,
	cardComponent = true,
	fail = false,
	moveFail = false,
} = {}) {
	const listeners = new Map(),
		microtasks = [];
	let renders = 0,
		unmounts = 0,
		moves = 0,
		tree;
	class Node {
		constructor(name, label = null) {
			this.name = name;
			this.tagName = name.toUpperCase();
			this.label = label;
			this.childNodes = [];
			this.parentElement = null;
			this.ownerDocument = doc;
			this.attributes = new Map();
			this.hidden = false;
			if (label !== null) {
				this.setAttribute('data-ran-booster-card', label);
			}
		}
		get firstChild() {
			return this.childNodes[0];
		}
		get isConnected() {
			return this === doc || !!this.parentElement?.isConnected;
		}
		contains(node) {
			return (
				this === node ||
				this.childNodes.some((child) => child.contains(node))
			);
		}
		remove() {
			if (this.parentElement) {
				const a = this.parentElement.childNodes;
				a.splice(a.indexOf(this), 1);
				this.parentElement = null;
			}
		}
		insertBefore(node, before) {
			node.remove();
			const index = this.childNodes.indexOf(before);
			this.childNodes.splice(
				index < 0 ? this.childNodes.length : index,
				0,
				node
			);
			node.parentElement = this;
		}
		appendChild(node) {
			if (this.content && moveFail && ++moves === 2) {
				throw Error('partial move');
			}
			if (node.contains(doc.activeElement)) {
				doc.activeElement = doc.body;
			}
			this.insertBefore(node, null);
		}
		replaceChildren(...nodes) {
			for (const child of [...this.childNodes]) {
				child.remove();
			}
			nodes.forEach((node) => this.appendChild(node));
		}
		setAttribute(name, value) {
			this.attributes.set(name, String(value));
		}
		getAttribute(name) {
			return this.attributes.get(name) ?? null;
		}
		removeAttribute(name) {
			this.attributes.delete(name);
		}
		matches(selector) {
			if (selector === '.ran-booster-admin') {
				return !!this.admin;
			}
			if (selector.startsWith('.ran-booster-card')) {
				return this.label !== null;
			}
			const attr = selector.match(/^\[([^\]]+)\]$/);
			return !!attr && this.attributes.has(attr[1]);
		}
		closest(selector) {
			return this.matches(selector)
				? this
				: this.parentElement?.closest?.(selector) || null;
		}
		querySelector(selector) {
			return this.querySelectorAll(selector)[0] || null;
		}
		querySelectorAll(selector) {
			const descendants = this.childNodes.flatMap((n) => [
				n,
				...n.querySelectorAll('*'),
			]);
			return selector === '*'
				? descendants
				: descendants.filter((n) => n.matches(selector));
		}
		focus(options) {
			this.focusOptions = options;
			doc.activeElement = this;
		}
	}
	const doc = {
		isConnected: true,
		activeElement: null,
		readyState: 'complete',
		listeners,
		addEventListener(type, fn) {
			const list = listeners.get(type) || [];
			list.push(fn);
			listeners.set(type, list);
		},
		createElement(name) {
			return new Node(name);
		},
		querySelectorAll(selector) {
			return this.body.querySelectorAll(selector);
		},
	};
	doc.body = new Node('body');
	doc.body.admin = true;
	doc.body.parentElement = doc;
	doc.activeElement = doc.body;
	const element = {
		createElement: (type, props, ...children) => ({
			type,
			props,
			children,
		}),
		flushSync: (fn) => fn(),
		createRoot: (host) => ({
			render(value) {
				renders++;
				tree = value;
				if (fail || host.fail) {
					throw Error('render failure');
				}
				host.replaceChildren();
				if (value.type === 'CardRoot') {
					const surface = new Node('surface'),
						content = new Node('content');
					content.content = true;
					content.setAttribute('data-ran-booster-card-content', '');
					host.appendChild(surface);
					surface.appendChild(content);
				} else {
					host.appendChild(new Node('badge-output'));
				}
			},
			unmount() {
				unmounts++;
				host.replaceChildren();
			},
		}),
	};
	const window = {
		wp: { element },
		ranBoosterUI: runtime
			? { CardRoot: cardComponent ? 'CardRoot' : null, Badge: 'Badge' }
			: null,
	};
	const context = vm.createContext({
		window,
		document: doc,
		queueMicrotask: (fn) => microtasks.push(fn),
	});
	function make(label = 'extensions', parent = doc.body, tag = 'div') {
		const card = new Node(tag, label),
			whitespace = new Node('text'),
			form = new Node('form'),
			link = new Node('a');
		form.appendChild(link);
		card.appendChild(whitespace);
		card.appendChild(form);
		parent.appendChild(card);
		return { card, whitespace, form, link };
	}
	function badge(parent = doc.body) {
		const host = new Node('span'),
			fallback = new Node('span'),
			mount = new Node('span');
		host.setAttribute('data-ran-booster-ui-badge', '');
		host.setAttribute('role', 'status');
		fallback.setAttribute('data-ran-booster-ui-badge-fallback', '');
		mount.setAttribute('data-ran-booster-ui-badge-mount', '');
		host.appendChild(fallback);
		host.appendChild(mount);
		parent.appendChild(host);
		return { host, fallback, mount };
	}
	const emit = (type, elt = doc.body) =>
		(listeners.get(type) || []).forEach((fn) =>
			fn({ detail: { elt }, target: elt })
		);
	const flush = () => {
		while (microtasks.length) {
			microtasks.shift()();
		}
	};
	const publish = (host, label, intent) => {
		host.setAttribute('data-ran-booster-ui-badge-label', label);
		host.setAttribute('data-ran-booster-ui-badge-intent', intent);
		emit('ran-booster:ui-badge-update', host);
	};
	return {
		doc,
		Node,
		make,
		badge,
		window,
		run: () => vm.runInContext(source, context),
		emit,
		flush,
		publish,
		counts: () => ({ renders, unmounts }),
		tree: () => tree,
	};
}

test('three supported hosts move native child nodes once, keeping form, link and focus', () => {
	const f = fixture();
	const cards = ['extensions', 'overview', 'plugins'].map((label) =>
		f.make(label)
	);
	cards[0].link.focus();
	f.run();
	f.flush();
	f.run();
	f.flush();
	f.emit('htmx:load');
	assert.equal(f.counts().renders, 3);
	assert.equal(f.tree().type, 'CardRoot');
	assert.equal(f.tree().props.className, 'ran-booster-card__surface');
	for (const { card, whitespace, form, link } of cards) {
		const content = card.querySelector('[data-ran-booster-card-content]');
		assert.deepEqual(content.childNodes, [whitespace, form]);
		assert.equal(form.firstChild, link);
	}
	assert.equal(f.doc.activeElement, cards[0].link);
	assert.equal(cards[0].link.focusOptions.preventScroll, true);
});

test('unknown, nested, outside admin and invalid hosts retain native content', () => {
	const f = fixture();
	const unknown = f.make('other');
	const outer = f.make('overview');
	const inner = f.make('plugins', outer.card);
	const outside = new f.Node('div');
	outside.parentElement = f.doc;
	const external = f.make('extensions', outside);
	const invalid = f.make('plugins', f.doc.body, 'form');
	f.run();
	f.flush();
	for (const item of [unknown, outer, inner, external, invalid]) {
		assert.equal(item.card.firstChild, item.whitespace);
	}
	assert.equal(f.counts().renders, 0);
});

test('descendant and ancestor cleanup restore live children and partial load remounts', () => {
	const f = fixture();
	const { card, form, link } = f.make();
	f.run();
	f.flush();
	const content = card.querySelector('[data-ran-booster-card-content]');
	const fresh = new f.Node('fresh');
	content.appendChild(fresh);
	link.focus();
	f.emit('htmx:beforeCleanupElement', form);
	assert.equal(card.childNodes.at(-1), fresh);
	assert.equal(f.doc.activeElement, link);
	f.emit('htmx:load', fresh);
	f.flush();
	assert.equal(fresh.parentElement.content, true);
	f.emit('htmx:beforeCleanupElement', f.doc.body);
	assert.equal(card.childNodes.at(-1), fresh);
	assert.deepEqual(f.counts(), { renders: 2, unmounts: 2 });
});

test('host swap restores before cleanup only when the response will swap', () => {
	const f = fixture();
	const { card, form, link } = f.make();
	f.run();
	f.flush();
	link.focus();
	const beforeSwap = f.doc.listeners.get('htmx:beforeSwap')[0];
	beforeSwap({ detail: { shouldSwap: false, target: card } });
	assert.equal(form.parentElement.content, true);
	assert.equal(f.counts().unmounts, 0);
	beforeSwap({ detail: { shouldSwap: true, target: card } });
	assert.equal(form.parentElement, card);
	assert.equal(f.doc.activeElement, link);
	assert.equal(f.counts().unmounts, 1);
});

test('history caches native children, remounts and prunes detached roots', () => {
	const f = fixture();
	const { card, form } = f.make();
	f.run();
	f.flush();
	f.emit('htmx:beforeHistorySave');
	assert.equal(form.parentElement, card);
	f.flush();
	assert.equal(form.parentElement.content, true);
	card.remove();
	f.emit('htmx:historyRestore');
	f.flush();
	assert.equal(form.parentElement, card);
	assert.deepEqual(f.counts(), { renders: 2, unmounts: 2 });
});

test('missing prerequisites and partial failures keep native children', () => {
	for (const options of [
		{ runtime: false },
		{ cardComponent: false },
		{ fail: true },
		{ moveFail: true },
	]) {
		const f = fixture(options);
		const { card, whitespace, form } = f.make();
		f.run();
		f.flush();
		assert.deepEqual(card.childNodes, [whitespace, form]);
	}
});

test('badge waits for producer projection and preserves fallback, mount and status identity', () => {
	const f = fixture();
	const { host, fallback, mount } = f.badge();
	f.run();
	f.flush();
	assert.equal(f.counts().renders, 0);
	assert.equal(fallback.hidden, false);
	f.publish(host, 'Confirmed', 'stable');
	assert.equal(f.tree().type, 'Badge');
	assert.equal(f.tree().props.intent, 'stable');
	assert.equal(f.tree().children[0], 'Confirmed');
	assert.equal(host.getAttribute('role'), 'status');
	assert.equal(host.childNodes[0], fallback);
	assert.equal(host.childNodes[1], mount);
	assert.equal(fallback.hidden, true);
	assert.equal(host.getAttribute('data-ran-booster-ui-mounted'), '');
	f.publish(host, 'Confirmed', 'stable');
	assert.equal(f.counts().renders, 1);
	f.publish(host, 'Pending', 'none');
	assert.equal(f.counts().renders, 2);
	assert.equal(f.tree().children[0], 'Pending');
});

test('badge discovers producer state in both startup orders', () => {
	const first = fixture();
	const a = first.badge();
	a.host.setAttribute('data-ran-booster-ui-badge-label', 'Ready');
	a.host.setAttribute('data-ran-booster-ui-badge-intent', 'stable');
	first.run();
	first.flush();
	assert.equal(first.tree().type, 'Badge');
	assert.equal(a.fallback.hidden, true);
	const second = fixture();
	const b = second.badge();
	second.run();
	second.publish(b.host, 'Ready', 'stable');
	second.flush();
	assert.equal(second.counts().renders, 1);
	assert.equal(second.tree().children[0], 'Ready');
});

test('invalid projection, missing runtime and failed badge render reveal native fallback', () => {
	const f = fixture();
	const { host, fallback, mount } = f.badge();
	f.run();
	f.flush();
	f.publish(host, 'Ready', 'stable');
	f.publish(host, 'Ready', 'unsupported');
	assert.equal(fallback.hidden, false);
	assert.equal(mount.childNodes.length, 0);
	assert.equal(host.getAttribute('data-ran-booster-ui-mounted'), null);
	f.publish(host, '', 'stable');
	assert.equal(fallback.hidden, false);
	assert.equal(mount.childNodes.length, 0);
	f.window.ranBoosterUI = null;
	f.publish(host, 'Ready', 'stable');
	assert.equal(fallback.hidden, false);
	assert.equal(mount.childNodes.length, 0);
	f.window.ranBoosterUI = { Badge: 'Badge' };
	mount.fail = true;
	f.publish(host, 'Ready', 'stable');
	assert.equal(fallback.hidden, false);
	assert.equal(mount.childNodes.length, 0);
	assert.equal(host.getAttribute('data-ran-booster-ui-mounted'), null);
});

test('history saves native fallback and later producer supersedes cached badge projection', () => {
	const f = fixture();
	const { host, fallback, mount } = f.badge();
	f.run();
	f.publish(host, 'Old', 'stable');
	f.flush();
	f.emit('htmx:beforeHistorySave');
	assert.equal(fallback.hidden, false);
	assert.equal(mount.childNodes.length, 0);
	assert.equal(host.getAttribute('data-ran-booster-ui-mounted'), null);
	f.publish(host, 'Current', 'none');
	f.flush();
	assert.equal(f.tree().children[0], 'Current');
	assert.equal(f.tree().props.intent, 'none');
	assert.equal(f.counts().renders, 2);
	assert.equal(fallback.hidden, true);
});

test('nested card and badge cleanup restores both roots even when card moves its children', () => {
	const f = fixture();
	const { card, form } = f.make();
	const { host, fallback, mount } = f.badge(form);
	f.run();
	f.publish(host, 'Ready', 'stable');
	f.flush();
	assert.equal(f.counts().renders, 2);
	f.emit('htmx:beforeCleanupElement', card);
	assert.equal(form.parentElement, card);
	assert.equal(fallback.hidden, false);
	assert.equal(mount.childNodes.length, 0);
	assert.equal(host.getAttribute('data-ran-booster-ui-mounted'), null);
	assert.equal(card.getAttribute('data-ran-booster-ui-mounted'), null);
	assert.equal(f.counts().unmounts, 2);
	f.emit('htmx:beforeCleanupElement', card);
	assert.equal(f.counts().unmounts, 2);
});

test('disconnected badges do not mount from events or deferred discovery', () => {
	const f = fixture();
	const { host, fallback } = f.badge();
	f.run();
	host.remove();
	f.publish(host, 'Ready', 'stable');
	f.flush();
	assert.equal(f.counts().renders, 0);
	assert.equal(fallback.hidden, false);
});
