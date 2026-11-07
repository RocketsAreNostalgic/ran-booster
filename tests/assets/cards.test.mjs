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
	disclosureRuntime = false,
	disclosureFail = false,
} = {}) {
	const listeners = new Map(),
		microtasks = [];
	const unmountOrder = [];
	let renders = 0,
		unmounts = 0,
		moves = 0,
		tree;
	class Node {
		constructor(name, label = null) {
			this.name = name;
			this.tagName = name.toUpperCase();
			this.nodeType = name === 'text' ? 3 : 1;
			this.tabIndex = 0;
			this.label = label;
			this.childNodes = [];
			this.parentElement = null;
			this.ownerDocument = doc;
			this.attributes = new Map();
			this.hidden = false;
			this.disabled = false;
			this.textContent = '';
			if (label !== null) {
				this.setAttribute('data-ran-booster-card', label);
			}
		}
		get children() {
			return this.childNodes.filter((node) => node.nodeType !== 3);
		}
		get id() {
			return this.getAttribute('id') || '';
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
				if (this.contains(doc.activeElement)) {
					doc.activeElement = doc.body;
				}
				const a = this.parentElement.childNodes;
				a.splice(a.indexOf(this), 1);
				this.parentElement = null;
			}
		}
		replaceWith(node) {
			const parent = this.parentElement;
			parent.insertBefore(node, this);
			this.remove();
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
		hasAttribute(name) {
			return this.attributes.has(name);
		}
		getAttribute(name) {
			return this.attributes.get(name) ?? null;
		}
		removeAttribute(name) {
			this.attributes.delete(name);
		}
		matches(selector) {
			if (
				selector ===
				'[data-ran-booster-ui-disclosure="installation-component"]'
			) {
				return (
					this.getAttribute('data-ran-booster-ui-disclosure') ===
					'installation-component'
				);
			}
			if (
				selector === '[role="button"]' ||
				selector === '[role=button]'
			) {
				return this.getAttribute('role') === 'button';
			}
			if (selector.startsWith('#')) {
				return this.id === selector.slice(1);
			}
			if (selector === 'button') {
				return this.tagName === 'BUTTON';
			}
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
			if (selector.startsWith(':scope > ')) {
				const part = selector.slice(9);
				return this.children.filter((n) => {
					const match = part.match(
						/^(details|summary|div)(?:\[([^\]]+)\])?$/
					);
					return (
						match &&
						n.tagName === match[1].toUpperCase() &&
						(!match[2] || n.hasAttribute(match[2]))
					);
				});
			}
			const descendants = this.childNodes.flatMap((n) => [
				n,
				...n.querySelectorAll('*'),
			]);
			return selector === '*'
				? descendants
				: descendants.filter((n) => n.matches(selector));
		}
		addEventListener() {}
		removeEventListener() {}
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
		getElementById(id) {
			return (
				this.body
					.querySelectorAll('*')
					.find((node) => node.id === id) || null
			);
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
				if (disclosureRuntime && typeof value.type === 'function') {
					if (disclosureFail) {
						throw Error('bad disclosure');
					}
					const component = value.children[0].type();
					const content = component.children[1];
					const trigger = new Node('button'),
						panel = new Node('div'),
						slot = new Node('div');
					trigger.setAttribute('role', 'button');
					trigger.setAttribute(
						'aria-expanded',
						String(component.props.open)
					);
					panel.setAttribute('id', 'test-panel');
					if (component.props.open) {
						trigger.setAttribute('aria-controls', 'test-panel');
					}
					slot.setAttribute('data-disclosure-slot', '');
					content.props.ref.current = panel;
					host.appendChild(trigger);
					host.appendChild(panel);
					panel.appendChild(slot);
					tree = component;
				} else if (
					value.type === 'CardRoot' ||
					value.type === 'NoticeRoot'
				) {
					const surface = new Node('surface'),
						content = new Node('content');
					content.content = true;
					content.setAttribute(
						value.type === 'CardRoot'
							? 'data-ran-booster-card-content'
							: 'data-ran-booster-ui-notice-slot',
						''
					);
					host.appendChild(surface);
					surface.appendChild(content);
				} else if (value.type === 'Button') {
					const button = new Node('button');
					button.disabled = value.props.disabled;
					button.textContent = value.children[0];
					host.appendChild(button);
				} else {
					host.appendChild(new Node('badge-output'));
				}
			},
			unmount() {
				unmounts++;
				unmountOrder.push(host.parentElement?.getAttribute('id'));
				host.replaceChildren();
			},
		}),
	};
	const window = {
		wp: { element },
		ranBoosterUI: runtime
			? {
					CardRoot: cardComponent ? 'CardRoot' : null,
					Badge: 'Badge',
					Button: 'Button',
					NoticeRoot: 'NoticeRoot',
					NoticeDescription: 'NoticeDescription',
				}
			: null,
	};
	if (disclosureRuntime) {
		window.React = {
			Component: class {},
			createRef: () => ({ current: null }),
			useState: (value) => [value, () => {}],
		};
		window.CSS = { escape: (value) => value };
		window.ranBoosterUI.CollapsibleCard = {
			Root: 'Root',
			Header: 'Header',
			Content: 'Content',
		};
	}
	const context = vm.createContext({
		window,
		document: doc,
		queueMicrotask: (fn) => microtasks.push(fn),
		setTimeout: () => {},
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
	function notice(parent = doc.body, tag = 'div') {
		const host = new Node(tag),
			content = new Node('div'),
			paragraph = new Node('p'),
			link = new Node('a');
		host.setAttribute('data-ran-booster-ui-notice', '');
		host.setAttribute('id', 'notice');
		host.setAttribute('role', 'alert');
		content.setAttribute('data-ran-booster-ui-notice-content', '');
		paragraph.setAttribute('id', 'notice-message');
		paragraph.appendChild(link);
		content.appendChild(paragraph);
		host.appendChild(content);
		parent.appendChild(host);
		return { host, content, paragraph, link };
	}
	function presentation() {
		const form = new Node('form'),
			nonce = new Node('input'),
			host = new Node('span'),
			button = new Node('button'),
			mount = new Node('span');
		host.setAttribute(
			'data-ran-booster-ui-button',
			'overview-presentation'
		);
		button.setAttribute('data-ran-booster-ui-button-fallback', '');
		button.setAttribute('type', 'submit');
		button.setAttribute('name', 'presentation');
		button.setAttribute('value', 'compact');
		button.setAttribute('id', 'presentation-button');
		button.setAttribute('aria-label', 'Choose compact');
		button.setAttribute('aria-describedby', 'hint');
		button.setAttribute('form', 'target-form');
		button.setAttribute('title', 'Compact view');
		button.textContent = 'Compact';
		mount.setAttribute('data-ran-booster-ui-button-mount', '');
		form.appendChild(nonce);
		form.appendChild(host);
		host.appendChild(button);
		host.appendChild(mount);
		doc.body.appendChild(form);
		return { form, nonce, host, button, mount };
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
		presentation,
		badge,
		notice,
		unmountOrder,
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

test('confirmed Badge intents preserve the producer text and sole results live region', () => {
	const f = fixture();
	const results = new f.Node('div');
	results.setAttribute('role', 'status');
	f.doc.body.appendChild(results);
	const { host, fallback } = f.badge(results);
	host.removeAttribute('role');
	f.run();
	f.flush();
	for (const intent of [
		'none',
		'stable',
		'medium',
		'high',
		'informational',
	]) {
		f.publish(host, `Producer ${intent}`, intent);
		assert.equal(f.tree().props.intent, intent);
		assert.equal(f.tree().children[0], `Producer ${intent}`);
		assert.equal(fallback.hidden, true);
		assert.equal(host.getAttribute('role'), null);
		assert.equal(results.getAttribute('role'), 'status');
	}
	assert.equal(f.counts().renders, 5);
});

test('informational linked badges retain their native link, focus and fallback through updates and history', () => {
	const f = fixture();
	const link = new f.Node('a');
	link.setAttribute('href', 'https://example.test/dependency');
	link.setAttribute('target', '_blank');
	f.doc.body.appendChild(link);
	const { host, fallback, mount } = f.badge(link);
	host.removeAttribute('role');
	link.focus();
	f.run();
	f.publish(host, 'Installed dependency', 'informational');
	f.flush();
	assert.equal(f.tree().props.intent, 'informational');
	assert.equal(f.tree().children[0], 'Installed dependency');
	assert.equal(fallback.hidden, true);
	assert.equal(f.doc.activeElement, link);
	assert.equal(host.parentElement, link);
	assert.equal(link.getAttribute('href'), 'https://example.test/dependency');
	assert.equal(link.getAttribute('target'), '_blank');
	assert.equal(link.childNodes.length, 1);
	f.publish(host, 'Updated dependency', 'informational');
	assert.equal(f.tree().children[0], 'Updated dependency');
	f.emit('htmx:beforeHistorySave');
	assert.equal(fallback.hidden, false);
	assert.equal(mount.childNodes.length, 0);
	assert.equal(f.doc.activeElement, link);
	f.flush();
	assert.equal(f.tree().children[0], 'Updated dependency');
	assert.equal(fallback.hidden, true);
	f.publish(host, 'Invalid producer intent', 'info');
	assert.equal(fallback.hidden, false);
	assert.equal(host.parentElement, link);
	assert.equal(f.doc.activeElement, link);
	assert.equal(host.getAttribute('role'), null);
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

test('Settings is a supported Card host', () => {
	const f = fixture();
	const { card, form } = f.make('settings');
	f.run();
	f.flush();
	assert.equal(f.tree().type, 'CardRoot');
	assert.equal(
		form.parentElement.getAttribute('data-ran-booster-card-content'),
		''
	);
	assert.equal(card.getAttribute('data-ran-booster-ui-mounted'), '');
});

test('hidden alert retains its native content, paragraph ID and focus through mounting and current-content cleanup', () => {
	const f = fixture();
	const { host, content, paragraph, link } = f.notice();
	host.hidden = true;
	link.focus();
	f.run();
	f.flush();
	assert.equal(f.tree().type, 'NoticeRoot');
	assert.equal(f.tree().props.intent, 'error');
	assert.equal(f.tree().children[0].type, 'NoticeDescription');
	assert.equal(f.tree().children[0].props.render.type, 'div');
	assert.equal(host.hidden, true);
	assert.equal(host.getAttribute('id'), 'notice');
	assert.equal(host.getAttribute('role'), 'alert');
	assert.equal(
		content.parentElement.getAttribute('data-ran-booster-ui-notice-slot'),
		''
	);
	assert.equal(paragraph.getAttribute('id'), 'notice-message');
	assert.equal(paragraph.firstChild, link);
	assert.equal(f.doc.activeElement, link);
	assert.equal(host.querySelectorAll('[role]').length, 0);
	const fresh = new f.Node('p');
	fresh.setAttribute('id', 'new-message');
	content.appendChild(fresh);
	paragraph.textContent = 'current producer text';
	f.emit('htmx:beforeCleanupElement', host);
	assert.equal(host.firstChild, content);
	assert.equal(content.childNodes.at(-1), fresh);
	assert.equal(paragraph.textContent, 'current producer text');
	assert.equal(f.doc.activeElement, link);
	assert.equal(host.hidden, true);
	f.emit('htmx:load', content);
	f.flush();
	assert.equal(
		content.parentElement.getAttribute('data-ran-booster-ui-notice-slot'),
		''
	);
	assert.equal(content.childNodes.at(-1), fresh);
	assert.equal(paragraph.textContent, 'current producer text');
});

test('nested Card, Notice and Badge restore child-first for cleanup, history and host swap', () => {
	for (const mode of ['cleanup', 'history', 'swap']) {
		const f = fixture();
		const { card, form } = f.make('settings');
		card.setAttribute('id', 'card');
		const n = f.notice(form);
		const b = f.badge(n.content);
		b.host.setAttribute('id', 'badge');
		f.run();
		f.publish(b.host, 'Ready', 'stable');
		f.flush();
		assert.equal(card.getAttribute('data-ran-booster-ui-mounted'), '');
		assert.equal(n.host.getAttribute('data-ran-booster-ui-mounted'), '');
		if (mode === 'cleanup') {
			f.emit('htmx:beforeCleanupElement', card);
		}
		if (mode === 'history') {
			f.emit('htmx:beforeHistorySave');
		}
		if (mode === 'swap') {
			f.doc.listeners.get('htmx:beforeSwap')[0]({
				detail: { shouldSwap: true, target: card },
			});
		}
		assert.deepEqual(f.unmountOrder, ['badge', 'notice', 'card']);
		assert.equal(form.parentElement, card);
		assert.equal(n.host.firstChild, n.content);
		assert.equal(b.fallback.hidden, false);
		assert.equal(b.mount.childNodes.length, 0);
		assert.equal(n.host.getAttribute('role'), 'alert');
		assert.equal(card.getAttribute('data-ran-booster-ui-mounted'), null);
		assert.equal(n.host.getAttribute('data-ran-booster-ui-mounted'), null);
		if (mode === 'history') {
			f.flush();
			assert.equal(
				n.content.parentElement.getAttribute(
					'data-ran-booster-ui-notice-slot'
				),
				''
			);
		}
	}
});

test('Notice keeps native fallback when public exports, rendering or host shape are invalid', () => {
	for (const mode of ['root', 'description', 'render', 'tag', 'indirect']) {
		const f = fixture();
		const n = f.notice(f.doc.body, mode === 'tag' ? 'section' : 'div');
		if (mode === 'root') {
			f.window.ranBoosterUI.NoticeRoot = null;
		}
		if (mode === 'description') {
			f.window.ranBoosterUI.NoticeDescription = null;
		}
		if (mode === 'render') {
			f.window.wp.element.createRoot = () => ({
				render() {
					throw Error('render failed');
				},
				unmount() {},
			});
		}
		if (mode === 'indirect') {
			const wrapper = new f.Node('div');
			n.host.replaceChildren(wrapper);
			wrapper.appendChild(n.content);
		}
		f.run();
		f.flush();
		assert.equal(n.host.getAttribute('data-ran-booster-ui-mounted'), null);
		assert.equal(n.content.contains(n.paragraph), true);
		assert.equal(n.host.getAttribute('role'), 'alert');
		assert.equal(
			n.host.querySelector('[data-ran-booster-ui-notice-slot]'),
			null
		);
	}
});

test('presentation Button replaces only the native submitter, copies its authority and restores its identity', () => {
	const f = fixture();
	const { form, nonce, host, button, mount } = f.presentation();
	button.focus();
	f.run();
	f.flush();
	const replacement = mount.querySelector('button');
	assert.equal(f.tree().type, 'Button');
	assert.equal(f.tree().props.type, 'submit');
	assert.equal(f.tree().props.focusableWhenDisabled, false);
	assert.equal(f.tree().props.tone, 'neutral');
	assert.equal(f.tree().props.variant, 'outline');
	assert.equal(f.tree().props.name, 'presentation');
	assert.equal(f.tree().props.value, 'compact');
	assert.equal(f.tree().props.id, 'presentation-button');
	assert.equal(f.tree().props['aria-label'], 'Choose compact');
	assert.equal(f.tree().props['aria-describedby'], 'hint');
	assert.equal(f.tree().props.form, 'target-form');
	assert.equal(f.tree().props.title, 'Compact view');
	assert.equal(f.tree().children[0], 'Compact');
	assert.equal(form.firstChild, nonce);
	assert.equal(host.firstChild, mount);
	assert.equal(button.isConnected, false);
	assert.equal(f.doc.activeElement, replacement);
	f.emit('htmx:load', host);
	f.flush();
	assert.equal(f.counts().renders, 1);
	replacement.disabled = true;
	f.emit('htmx:beforeCleanupElement', host);
	assert.equal(host.firstChild, button);
	assert.equal(host.childNodes[1], mount);
	assert.equal(mount.firstChild, undefined);
	assert.equal(button.disabled, true);
	assert.equal(f.doc.activeElement, button);
	assert.equal(form.firstChild, nonce);
});

test('presentation Button history restores native submitter and remounts without duplicate actions', () => {
	const f = fixture();
	const { host, button, mount } = f.presentation();
	f.run();
	f.flush();
	f.emit('htmx:beforeHistorySave');
	assert.equal(host.firstChild, button);
	assert.equal(mount.firstChild, undefined);
	f.flush();
	assert.equal(host.firstChild, mount);
	assert.equal(host.querySelectorAll('button').length, 1);
	assert.equal(f.counts().renders, 2);
	assert.equal(button.isConnected, false);
});

test('presentation Button failure or missing component keeps the original usable', () => {
	for (const mode of ['missing', 'render', 'kind', 'type']) {
		const f = fixture();
		const { host, button, mount } = f.presentation();
		if (mode === 'missing') {
			f.window.ranBoosterUI.Button = null;
		}
		if (mode === 'render') {
			mount.fail = true;
		}
		if (mode === 'kind') {
			host.setAttribute('data-ran-booster-ui-button', 'other');
		}
		if (mode === 'type') {
			button.setAttribute('type', 'button');
		}
		f.run();
		f.flush();
		assert.equal(host.firstChild, button);
		assert.equal(button.isConnected, true);
		assert.equal(host.childNodes[1], mount);
		assert.equal(mount.firstChild, undefined);
		assert.equal(host.getAttribute('data-ran-booster-ui-mounted'), null);
	}
});

test('presentation Button preserves disabled state and never steals external focus', () => {
	const f = fixture();
	const { host, button, mount } = f.presentation();
	button.disabled = true;
	const other = new f.Node('button');
	f.doc.body.appendChild(other);
	other.focus();
	f.run();
	f.flush();
	assert.equal(f.tree().props.disabled, true);
	assert.equal(f.doc.activeElement, other);
	f.emit('htmx:beforeCleanupElement', host);
	assert.equal(button.disabled, true);
	assert.equal(f.doc.activeElement, other);
	assert.equal(mount.firstChild, undefined);
});

test('HTMX processing a newly appended row discovers its Badge exactly once without a load event', () => {
	const f = fixture();
	f.run();
	f.flush();
	const row = new f.Node('div');
	f.doc.body.appendChild(row);
	const { host, fallback, mount } = f.badge(row);
	host.setAttribute('data-ran-booster-ui-badge-label', 'Complete');
	host.setAttribute('data-ran-booster-ui-badge-intent', 'stable');
	f.emit('htmx:afterProcessNode', row);
	f.flush();
	assert.equal(f.counts().renders, 1);
	assert.equal(f.tree().type, 'Badge');
	assert.equal(f.tree().children[0], 'Complete');
	assert.equal(fallback.hidden, true);
	assert.ok(mount.firstChild);
	f.emit('htmx:afterProcessNode', row);
	f.flush();
	assert.equal(f.counts().renders, 1);
});

test('Disclosure keeps native details, body identity, open state and focus through history', () => {
	const f = fixture({ disclosureRuntime: true });
	const host = new f.Node('div'),
		details = new f.Node('details'),
		summary = new f.Node('summary'),
		body = new f.Node('div'),
		link = new f.Node('a');
	host.setAttribute(
		'data-ran-booster-ui-disclosure',
		'installation-component'
	);
	host.setAttribute('id', 'technical-anchor');
	details.setAttribute('data-ran-booster-ui-disclosure-fallback', '');
	summary.setAttribute('aria-label', 'Technical details: Example');
	summary.textContent = 'Technical details';
	body.setAttribute('data-ran-booster-ui-disclosure-content', '');
	body.appendChild(link);
	details.appendChild(summary);
	details.appendChild(body);
	host.appendChild(details);
	f.doc.body.appendChild(host);
	details.open = false;
	f.run();
	f.flush();
	assert.equal(host.getAttribute('data-ran-booster-ui-mounted'), '');
	assert.equal(host.getAttribute('id'), 'technical-anchor');
	assert.equal(host.querySelector('[data-disclosure-slot]').firstChild, body);
	f.tree().props.onOpenChange(true);
	assert.equal(details.open, true);
	host.querySelector('[role="button"]').focus();
	f.emit('htmx:beforeHistorySave');
	assert.equal(host.firstChild, details);
	assert.equal(details.children[1], body);
	assert.equal(body.firstChild, link);
	assert.equal(f.doc.activeElement, summary);
	f.flush();
	assert.equal(host.querySelector('[data-disclosure-slot]').firstChild, body);
});

test('Disclosure render failure leaves the original native body untouched', () => {
	const f = fixture({ disclosureRuntime: true, disclosureFail: true });
	const host = new f.Node('div'),
		details = new f.Node('details'),
		summary = new f.Node('summary'),
		body = new f.Node('div'),
		link = new f.Node('a');
	host.setAttribute(
		'data-ran-booster-ui-disclosure',
		'installation-component'
	);
	details.setAttribute('data-ran-booster-ui-disclosure-fallback', '');
	summary.setAttribute('aria-label', 'Technical details: Example');
	body.setAttribute('data-ran-booster-ui-disclosure-content', '');
	body.appendChild(link);
	details.appendChild(summary);
	details.appendChild(body);
	host.appendChild(details);
	f.doc.body.appendChild(host);
	f.run();
	f.flush();
	assert.equal(host.firstChild, details);
	assert.equal(details.children[1], body);
	assert.equal(body.firstChild, link);
	assert.equal(host.hasAttribute('data-ran-booster-ui-mounted'), false);
});

test('Notice uses only supported producer intents and retains the absent-error default', () => {
	for (const [attribute, expected] of [
		[null, 'error'],
		['error', 'error'],
		['warning', 'warning'],
		['info', 'info'],
		['success', 'success'],
	]) {
		const f = fixture();
		const n = f.notice();
		if (attribute !== null) {
			n.host.setAttribute('data-ran-booster-ui-notice-intent', attribute);
		}
		f.run();
		f.flush();
		assert.equal(f.tree().type, 'NoticeRoot');
		assert.equal(f.tree().props.intent, expected);
		assert.equal(n.host.getAttribute('role'), 'alert');
		assert.equal(n.paragraph.firstChild, n.link);
	}
	for (const invalid of ['', 'neutral', 'danger']) {
		const f = fixture();
		const n = f.notice();
		n.host.setAttribute('data-ran-booster-ui-notice-intent', invalid);
		f.run();
		f.flush();
		assert.equal(f.counts().renders, 0);
		assert.equal(n.host.firstChild, n.content);
	}
});

test('Notice update events refresh intent and retain content on invalid metadata', () => {
	const f = fixture();
	const n = f.notice();
	n.host.hidden = true;
	n.link.focus();
	f.run();
	f.flush();
	assert.equal(f.tree().props.intent, 'error');
	n.host.setAttribute('data-ran-booster-ui-notice-intent', 'error');
	f.emit('ran-booster:ui-notice-update', n.paragraph);
	f.flush();
	assert.equal(f.counts().renders, 1);
	const fresh = new f.Node('p');
	fresh.setAttribute('id', 'fresh');
	n.content.appendChild(fresh);
	n.host.setAttribute('data-ran-booster-ui-notice-intent', 'warning');
	assert.equal(f.counts().renders, 1);
	f.emit('ran-booster:ui-notice-update', n.paragraph);
	f.flush();
	assert.equal(f.tree().props.intent, 'warning');
	assert.equal(f.counts().renders, 2);
	assert.equal(f.counts().unmounts, 1);
	assert.equal(n.content.childNodes.at(-1), fresh);
	assert.equal(n.paragraph.firstChild, n.link);
	assert.equal(f.doc.activeElement, n.link);
	f.emit('ran-booster:ui-notice-update', n.paragraph);
	f.flush();
	assert.equal(f.counts().renders, 2);
	n.host.setAttribute('data-ran-booster-ui-notice-intent', '');
	f.emit('ran-booster:ui-notice-update', n.paragraph);
	f.flush();
	assert.equal(n.host.firstChild, n.content);
	assert.equal(n.content.childNodes.at(-1), fresh);
	assert.equal(n.host.hidden, true);
	assert.equal(n.host.getAttribute('role'), 'alert');
	assert.equal(n.host.getAttribute('data-ran-booster-ui-mounted'), null);
	assert.equal(f.doc.activeElement, n.link);
	assert.equal(f.counts().unmounts, 2);
});

test('Notice intent changes preserve the current form and nested Badge without unmounting an ancestor Card', () => {
	const f = fixture();
	const { card, form } = f.make('settings');
	const n = f.notice(form);
	const nativeForm = new f.Node('form');
	const input = new f.Node('input');
	nativeForm.appendChild(input);
	n.content.appendChild(nativeForm);
	const b = f.badge(n.content);
	b.host.setAttribute('data-ran-booster-ui-badge-label', 'Saved');
	b.host.setAttribute('data-ran-booster-ui-badge-intent', 'stable');
	f.run();
	f.flush();
	assert.equal(f.counts().renders, 3);
	n.host.setAttribute('data-ran-booster-ui-notice-intent', 'info');
	f.emit('ran-booster:ui-notice-update', n.paragraph);
	f.flush();
	assert.equal(card.getAttribute('data-ran-booster-ui-mounted'), '');
	assert.equal(nativeForm.firstChild, input);
	assert.equal(b.fallback.hidden, true);
	assert.ok(b.mount.firstChild);
	assert.equal(f.counts().renders, 5);
	assert.equal(f.counts().unmounts, 2);
});

test('changed Notice rendering failure stays native without a second unsolicited attempt', () => {
	for (const failures of [1, Infinity]) {
		const f = fixture();
		const n = f.notice();
		f.run();
		f.flush();
		const createRoot = f.window.wp.element.createRoot;
		let attempts = 0;
		f.window.wp.element.createRoot = (host) => {
			const root = createRoot(host);
			return {
				...root,
				render(value) {
					attempts++;
					if (attempts <= failures) {
						throw Error('injected changed Notice failure');
					}
					root.render(value);
				},
			};
		};
		n.link.focus();
		n.host.setAttribute('data-ran-booster-ui-notice-intent', 'warning');
		f.emit('ran-booster:ui-notice-update', n.paragraph);
		f.flush();
		assert.equal(attempts, 1);
		assert.equal(n.host.firstChild, n.content);
		assert.equal(n.host.getAttribute('data-ran-booster-ui-mounted'), null);
		assert.equal(f.doc.activeElement, n.link);
	}
});

test('Notice update preserves an ancestor Card and sibling Badge', () => {
	const f = fixture();
	const { card, form } = f.make('settings');
	const n = f.notice(form);
	const b = f.badge(form);
	b.host.setAttribute('data-ran-booster-ui-badge-label', 'Saved');
	b.host.setAttribute('data-ran-booster-ui-badge-intent', 'stable');
	f.run();
	f.flush();
	n.host.setAttribute('data-ran-booster-ui-notice-intent', 'warning');
	f.emit('ran-booster:ui-notice-update', n.link);
	f.flush();
	assert.equal(card.getAttribute('data-ran-booster-ui-mounted'), '');
	assert.equal(b.fallback.hidden, true);
	assert.ok(b.mount.firstChild);
	assert.equal(f.counts().renders, 4);
	assert.equal(f.counts().unmounts, 1);
});

test('Notice producer changes warning to error through a descendant update event', () => {
	const f = fixture();
	const n = f.notice();
	n.host.setAttribute('data-ran-booster-ui-notice-intent', 'warning');
	f.run();
	f.flush();
	const fresh = new f.Node('p');
	n.content.appendChild(fresh);
	n.link.focus();
	n.host.setAttribute('data-ran-booster-ui-notice-intent', 'error');
	f.emit('ran-booster:ui-notice-update', n.paragraph);
	f.flush();
	assert.equal(f.tree().props.intent, 'error');
	assert.equal(f.counts().renders, 2);
	assert.equal(n.content.childNodes.at(-1), fresh);
	assert.equal(n.paragraph.firstChild, n.link);
	assert.equal(f.doc.activeElement, n.link);
	f.emit('ran-booster:ui-notice-update', n.paragraph);
	f.flush();
	assert.equal(f.counts().renders, 2);
});

test('Notice update without a runtime leaves native content and actions intact', () => {
	const f = fixture({ runtime: false });
	const n = f.notice();
	n.link.focus();
	f.run();
	f.flush();
	n.host.setAttribute('data-ran-booster-ui-notice-intent', 'warning');
	f.emit('ran-booster:ui-notice-update', n.paragraph);
	f.flush();
	assert.equal(f.counts().renders, 0);
	assert.equal(n.host.firstChild, n.content);
	assert.equal(n.paragraph.firstChild, n.link);
	assert.equal(n.host.getAttribute('role'), 'alert');
	assert.equal(f.doc.activeElement, n.link);
});

test('provider Card preserves native form and link through cleanup and history', () => {
	const f = fixture();
	const { card, form, link } = f.make('provider', f.doc.body, 'section');
	link.focus();
	f.run();
	f.flush();
	assert.equal(f.tree().type, 'CardRoot');
	assert.equal(form.firstChild, link);
	assert.equal(f.doc.activeElement, link);
	f.emit('htmx:beforeCleanupElement', link);
	assert.equal(form.parentElement, card);
	assert.equal(form.firstChild, link);
	f.emit('htmx:load', card);
	f.flush();
	f.emit('htmx:beforeHistorySave');
	assert.equal(form.parentElement, card);
	assert.equal(form.firstChild, link);
	f.flush();
	assert.equal(form.firstChild, link);
});
