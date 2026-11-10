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
	fail = false,
	moveFail = false,
	disclosureRuntime = false,
	disclosureFail = false,
} = {}) {
	const listeners = new Map(),
		microtasks = [];
	const unmountOrder = [];
	const stateUpdates = [];
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
			this.dataset = {};
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
		cloneNode() {
			const copy = new Node(this.name);
			copy.textContent = this.textContent;
			this.attributes.forEach((value, name) =>
				copy.setAttribute(name, value)
			);
			this.childNodes.forEach((child) =>
				copy.appendChild(child.cloneNode())
			);
			return copy;
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
			if (selector.includes('[data-ran-booster-ui-disclosure]')) {
				return (
					this.getAttribute('data-ran-booster-ui-disclosure') !== null
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
			if (selector === '.ran-booster-documentation') {
				return !!this.documentation;
			}
			if (selector === '.ran-booster-admin') {
				return !!this.admin;
			}
			if (selector === '.ran-wp-ui-notice') {
				return !!this.staticNotice;
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
						/^(details|summary|div|span)?(?:\[([^\]]+)\])?$/
					);
					return (
						match &&
						(!match[1] || n.tagName === match[1].toUpperCase()) &&
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
		dispatchEvent(event) {
			emit(event.type, this);
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
					const content = component.children.at(-1);
					const trigger = new Node('button'),
						panel = new Node('div'),
						slot = new Node('div');
					trigger.setAttribute('role', 'button');
					trigger.setAttribute(
						'aria-expanded',
						String(component.props.open)
					);
					panel.setAttribute('id', 'test-panel-' + renders);
					if (component.props.open) {
						trigger.setAttribute('aria-controls', panel.id);
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
			useState: (value) => [value, (next) => stateUpdates.push(next)],
		};
		window.CSS = { escape: (value) => value };
		window.CustomEvent = class {
			constructor(type, options) {
				this.type = type;
				Object.assign(this, options);
			}
		};
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

	const emit = (type, elt = doc.body) =>
		(listeners.get(type) || []).forEach((fn) =>
			fn({ detail: { elt }, target: elt })
		);
	const flush = () => {
		while (microtasks.length) {
			microtasks.shift()();
		}
	};
	return {
		doc,
		Node,
		make,
		presentation,
		notice,
		unmountOrder,
		stateUpdates,
		window,
		run: () => vm.runInContext(source, context),
		emit,
		flush,
		counts: () => ({ renders, unmounts }),
		tree: () => tree,
	};
}

test('page Cards are static markup and are never mounted or moved', () => {
	const f = fixture();
	const labels = [
		'extensions',
		'overview',
		'plugins',
		'themes',
		'provider',
		'settings',
		'transporter',
		'package-edit',
		'troubleshooting',
	];
	const cards = labels.map((label) => f.make(label, f.doc.body, 'section'));
	const transporter = cards[labels.indexOf('transporter')];
	transporter.card.hidden = true;
	transporter.card.setAttribute('id', 'transport-flow');
	cards[0].link.focus();
	f.run();
	f.flush();
	f.emit('htmx:load');
	f.emit('htmx:afterProcessNode', cards[1].card);
	f.flush();
	f.emit('htmx:beforeHistorySave');
	f.emit('htmx:historyRestore');
	f.flush();
	f.doc.listeners.get('htmx:beforeSwap')[0]({
		detail: { shouldSwap: true, target: cards[2].card },
	});
	f.emit('htmx:beforeCleanupElement', cards[3].form);
	assert.deepEqual(f.counts(), { renders: 0, unmounts: 0 });
	for (const { card, whitespace, form, link } of cards) {
		assert.deepEqual(card.childNodes, [whitespace, form]);
		assert.equal(form.firstChild, link);
		assert.equal(card.hasAttribute('data-ran-booster-ui-mounted'), false);
	}
	assert.equal(transporter.card.hidden, true);
	assert.equal(transporter.card.getAttribute('id'), 'transport-flow');
	assert.equal(f.doc.activeElement, cards[0].link);
});

test('an inner region replaced in a static Card keeps its identity and focus', () => {
	const f = fixture();
	const { card, form } = f.make('troubleshooting', f.doc.body, 'section');
	const navigation = new f.Node('nav');
	f.doc.body.insertBefore(navigation, card);
	f.run();
	f.flush();
	f.emit('htmx:beforeCleanupElement', form);
	const replacement = new f.Node('form');
	const replacementLink = new f.Node('a');
	replacement.appendChild(replacementLink);
	form.replaceWith(replacement);
	replacementLink.focus();
	f.emit('htmx:afterProcessNode', replacement);
	f.emit('htmx:load', replacement);
	f.flush();
	assert.deepEqual(f.counts(), { renders: 0, unmounts: 0 });
	assert.equal(replacement.parentElement, card);
	assert.equal(replacement.firstChild, replacementLink);
	assert.equal(f.doc.activeElement, replacementLink);
	assert.equal(navigation.parentElement, f.doc.body);
});

test('ranBoosterBadge.update swaps the intent class, attribute and label in place', () => {
	const f = fixture();
	f.run();
	const classes = new Set(['is-none-intent', 'other']);
	const attributes = {};
	const badge = {
		textContent: 'Old',
		classList: {
			toggle: (name, on) =>
				on ? classes.add(name) : classes.delete(name),
		},
		setAttribute: (name, value) => (attributes[name] = value),
	};
	f.window.ranBoosterBadge.update(badge, 'Ready', 'stable');
	assert.deepEqual([...classes].sort(), ['is-stable-intent', 'other']);
	assert.equal(attributes['data-ran-booster-badge-intent'], 'stable');
	assert.equal(badge.textContent, 'Ready');
	f.window.ranBoosterBadge.update(badge, undefined, 'high');
	assert.deepEqual([...classes].sort(), ['is-high-intent', 'other']);
	assert.equal(badge.textContent, 'Ready');
	f.window.ranBoosterBadge.update(badge, 'Nope', 'unknown');
	assert.deepEqual([...classes].sort(), ['is-high-intent', 'other']);
	assert.equal(badge.textContent, 'Ready');
	assert.doesNotThrow(() =>
		f.window.ranBoosterBadge.update(null, 'x', 'none')
	);
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

test('a static Notice is never mounted and keeps its content, focus and intent', () => {
	const f = fixture();
	const { host, content, paragraph, link } = f.notice();
	host.staticNotice = true;
	host.setAttribute('tabindex', '-1');
	link.focus();
	f.run();
	f.flush();
	f.emit('htmx:load', host);
	host.setAttribute('data-ran-booster-ui-notice-intent', 'warning');
	host.dispatchEvent({ type: 'ran-booster:ui-notice-update' });
	f.flush();
	assert.deepEqual(f.counts(), { renders: 0, unmounts: 0 });
	assert.equal(host.getAttribute('data-ran-booster-ui-mounted'), null);
	assert.equal(host.firstChild, content);
	assert.equal(content.firstChild, paragraph);
	assert.equal(paragraph.firstChild, link);
	assert.equal(host.getAttribute('tabindex'), '-1');
	assert.equal(f.doc.activeElement, link);
});

test('only explicitly owned global Notice hosts mount outside Booster ancestry', () => {
	for (const scope of [null, '', 'other', 'global']) {
		const f = fixture();
		f.doc.body.admin = false;
		const n = f.notice();
		if (scope !== null) {
			n.host.setAttribute('data-ran-booster-ui-notice-scope', scope);
		}
		n.link.focus();
		f.run();
		f.flush();
		assert.equal(
			n.host.getAttribute('data-ran-booster-ui-mounted'),
			scope === 'global' ? '' : null
		);
		assert(n.content.contains(n.paragraph));
		assert.equal(f.doc.activeElement, n.link);
		if (scope === 'global') {
			assert.equal(f.tree().type, 'NoticeRoot');
			f.emit('htmx:beforeCleanupElement', n.host);
			assert.equal(n.host.firstChild, n.content);
			assert.equal(f.doc.activeElement, n.link);
		}
	}
});

test('global Notice scope does not adopt unmarked third-party notices or Cards', () => {
	const f = fixture();
	f.doc.body.admin = false;
	const n = f.notice();
	n.host.removeAttribute('data-ran-booster-ui-notice');
	n.host.setAttribute('data-ran-booster-ui-notice-scope', 'global');
	const { card } = f.make();
	card.setAttribute('data-ran-booster-ui-notice-scope', 'global');
	f.run();
	f.flush();
	assert.equal(n.host.getAttribute('data-ran-booster-ui-mounted'), null);
	assert.equal(card.getAttribute('data-ran-booster-ui-mounted'), null);
	assert.equal(n.host.firstChild, n.content);
});

test('owned global Notice keeps native content when runtime or rendering fails', () => {
	for (const mode of ['runtime', 'render']) {
		const f = fixture({
			runtime: mode !== 'runtime',
			fail: mode === 'render',
		});
		f.doc.body.admin = false;
		const n = f.notice();
		n.host.setAttribute('data-ran-booster-ui-notice-scope', 'global');
		f.run();
		f.flush();
		assert.equal(n.host.getAttribute('data-ran-booster-ui-mounted'), null);
		assert.equal(n.host.firstChild, n.content);
		assert.equal(n.paragraph.firstChild, n.link);
	}
});

test('a Notice inside a static Card restores alone for cleanup, history and host swap', () => {
	for (const mode of ['cleanup', 'history', 'swap']) {
		const f = fixture();
		const { card, form } = f.make('settings');
		card.setAttribute('id', 'card');
		const n = f.notice(form);
		f.run();
		f.flush();
		assert.equal(card.hasAttribute('data-ran-booster-ui-mounted'), false);
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
		assert.deepEqual(f.unmountOrder, ['notice']);
		assert.equal(form.parentElement, card);
		assert.equal(n.host.firstChild, n.content);
		assert.equal(n.host.getAttribute('role'), 'alert');
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

test('Disclosure heading slot fills the header, keeps one accessible name and returns to the summary', () => {
	const f = fixture({ disclosureRuntime: true });
	const host = new f.Node('div'),
		details = new f.Node('details'),
		summary = new f.Node('summary'),
		heading = new f.Node('span'),
		meta = new f.Node('span'),
		body = new f.Node('div'),
		link = new f.Node('a');
	host.setAttribute('data-ran-booster-ui-disclosure', 'installation-section');
	details.setAttribute('data-ran-booster-ui-disclosure-fallback', '');
	summary.setAttribute('aria-label', 'Bitbucket Cloud');
	heading.setAttribute('data-ran-booster-ui-disclosure-heading', '');
	heading.textContent = 'Bitbucket Cloud Provider extension: Bitbucket Cloud';
	meta.setAttribute('data-ran-booster-ui-disclosure-meta', '');
	meta.textContent = 'Active';
	body.setAttribute('data-ran-booster-ui-disclosure-content', '');
	body.appendChild(link);
	summary.appendChild(heading);
	summary.appendChild(meta);
	details.appendChild(summary);
	details.appendChild(body);
	host.appendChild(details);
	f.doc.body.appendChild(host);
	f.run();
	f.flush();
	assert.equal(host.getAttribute('data-ran-booster-ui-mounted'), '');
	const header = f.tree().children[0];
	const [title, label, badge] = header.children;
	// The header wrapper becomes a real h4 around the toggle, with no description without a warning.
	assert.equal(header.props.render.type, 'h4');
	assert.equal(header.props['aria-describedby'], undefined);
	// The heading slot replaces the plain title and is hidden from assistive technology.
	assert.equal(title.props['aria-hidden'], 'true');
	assert.deepEqual(title.children, [null]);
	const target = new f.Node('span');
	title.props.ref(target);
	assert.equal(target.childNodes[0], heading);
	// The accessible name is the single screen-reader label; the badge text stays readable.
	assert.equal(label.props.className, 'screen-reader-text');
	assert.deepEqual(label.children, ['Bitbucket Cloud']);
	assert.equal(badge.props.className, 'ran-booster-ui-disclosure__meta');
	f.emit('htmx:beforeHistorySave');
	assert.equal(host.firstChild, details);
	assert.deepEqual(summary.children, [heading, meta]);
	assert.equal(details.children[1], body);
});

test('Disclosure warning row mounts between header and content, outside the heading, and restores in place', () => {
	const build = (withHeading, withWarning) => {
		const f = fixture({ disclosureRuntime: true });
		const host = new f.Node('div'),
			details = new f.Node('details'),
			summary = new f.Node('summary'),
			heading = new f.Node('span'),
			warning = new f.Node('div'),
			body = new f.Node('div');
		host.setAttribute(
			'data-ran-booster-ui-disclosure',
			'installation-section'
		);
		details.setAttribute('data-ran-booster-ui-disclosure-fallback', '');
		summary.setAttribute('aria-label', 'GitHub');
		heading.setAttribute('data-ran-booster-ui-disclosure-heading', '');
		warning.setAttribute('data-ran-booster-ui-disclosure-warning', '');
		warning.textContent = 'Review provider settings.';
		body.setAttribute('data-ran-booster-ui-disclosure-content', '');
		body.appendChild(new f.Node('a'));
		if (withHeading) {
			summary.appendChild(heading);
			if (withWarning) {
				summary.appendChild(warning);
			}
		} else {
			summary.textContent = 'GitHub';
		}
		details.appendChild(summary);
		details.appendChild(body);
		host.appendChild(details);
		f.doc.body.appendChild(host);
		f.run();
		f.flush();
		return { f, host, details, summary, heading, warning, body };
	};
	const warned = build(true, true);
	assert.equal(warned.host.getAttribute('data-ran-booster-ui-mounted'), '');
	const [header, row, content] = warned.f.tree().children;
	assert.equal(header.props.render.type, 'h4');
	// The warning is plain flow text: no description wiring and not inside the header or aria-hidden title.
	assert.equal(header.props['aria-describedby'], undefined);
	assert.equal(row.type, 'div');
	assert.equal(row.props['aria-hidden'], undefined);
	assert.equal(content.props.keepMounted, true);
	assert.deepEqual(header.children[1].children, ['GitHub']);
	const target = new warned.f.Node('div');
	row.props.ref(target);
	assert.equal(target.childNodes[0], warned.warning);
	// Restoring returns the warning to the native summary after the heading.
	warned.f.emit('htmx:beforeHistorySave');
	assert.equal(warned.host.firstChild, warned.details);
	assert.deepEqual(warned.summary.children, [warned.heading, warned.warning]);
	const healthy = build(true, false);
	assert.equal(healthy.f.tree().children[1], null);
	const plain = build(false, false);
	assert.deepEqual(Object.keys(plain.f.tree().children[0].props), [
		'className',
	]);
	assert.equal(
		plain.f.tree().children[0].props.className,
		'ran-booster-ui-disclosure__header'
	);
});

test('Mounted disclosures report open state by id and honour remembered state when remounted', () => {
	const f = fixture({ disclosureRuntime: true });
	const build = (id) => {
		const host = new f.Node('div'),
			details = new f.Node('details'),
			summary = new f.Node('summary'),
			body = new f.Node('div'),
			link = new f.Node('a');
		host.setAttribute('data-ran-booster-ui-disclosure', 'package-details');
		details.setAttribute('id', id);
		details.setAttribute('data-ran-booster-ui-disclosure-fallback', '');
		summary.textContent = 'Package details';
		body.setAttribute('data-ran-booster-ui-disclosure-content', '');
		body.appendChild(link);
		details.appendChild(summary);
		details.appendChild(body);
		host.appendChild(details);
		f.doc.body.appendChild(host);
		details.open = false;
		return { host, details };
	};
	const first = build('package-panel');
	f.run();
	f.flush();
	assert.deepEqual(
		{ ...f.window.ranBoosterUI.disclosureOpenStates() },
		{
			'package-panel': false,
		}
	);
	f.tree().props.onOpenChange(true);
	assert.deepEqual(
		{ ...f.window.ranBoosterUI.disclosureOpenStates() },
		{
			'package-panel': true,
		}
	);
	first.host.remove?.();
	f.window.ranBoosterUI.rememberedDisclosureStates = {
		'package-panel-next': true,
	};
	const next = build('package-panel-next');
	f.emit('htmx:load', next.host);
	f.flush();
	assert.equal(next.details.open, true);
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

test('Notice intent changes inside a static Card preserve the current form', () => {
	const f = fixture();
	const { card, form } = f.make('settings');
	const n = f.notice(form);
	const nativeForm = new f.Node('form');
	const input = new f.Node('input');
	nativeForm.appendChild(input);
	n.content.appendChild(nativeForm);
	f.run();
	f.flush();
	assert.equal(f.counts().renders, 1);
	n.host.setAttribute('data-ran-booster-ui-notice-intent', 'info');
	f.emit('ran-booster:ui-notice-update', n.paragraph);
	f.flush();
	assert.equal(card.hasAttribute('data-ran-booster-ui-mounted'), false);
	assert.equal(form.parentElement, card);
	assert.equal(nativeForm.firstChild, input);
	assert.equal(f.counts().renders, 2);
	assert.equal(f.counts().unmounts, 1);
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

test('Disclosures use the visible summary as label, any kind mounts, and Installation requires context', () => {
	for (const kind of [
		'settings-configuration',
		'settings-storage',
		'installation-component',
		'troubleshooting-support',
	]) {
		const f = fixture({ disclosureRuntime: true });
		const host = new f.Node('div'),
			details = new f.Node('details'),
			summary = new f.Node('summary'),
			body = new f.Node('div'),
			link = new f.Node('a');
		host.setAttribute('data-ran-booster-ui-disclosure', kind);
		details.setAttribute('data-ran-booster-ui-disclosure-fallback', '');
		body.setAttribute('data-ran-booster-ui-disclosure-content', '');
		summary.textContent = 'Configuration details';
		body.appendChild(link);
		details.appendChild(summary);
		details.appendChild(body);
		host.appendChild(details);
		f.doc.body.appendChild(host);
		f.run();
		f.flush();
		if (kind !== 'installation-component') {
			assert.equal(host.getAttribute('data-ran-booster-ui-mounted'), '');
			assert.equal(f.tree().props.className, 'ran-booster-ui-disclosure');
			assert.equal(
				f.tree().children[0].props.className,
				'ran-booster-ui-disclosure__header'
			);
			assert.equal(
				f.tree().children[0].children[1].children[0],
				'Configuration details'
			);
			assert.equal(
				f.tree().children.at(-1).props.render.props.style.padding,
				0
			);
			assert.equal(
				host.querySelector('[data-disclosure-slot]').firstChild,
				body
			);
			f.emit('htmx:beforeHistorySave');
			assert.equal(host.firstChild, details);
			assert.equal(body.firstChild, link);
		} else {
			assert.equal(host.firstChild, details);
			assert.equal(
				host.hasAttribute('data-ran-booster-ui-mounted'),
				false
			);
		}
	}
});

function documentationFixture(options = {}) {
	const f = fixture({ disclosureRuntime: true, ...options });
	const root = new f.Node('section');
	root.documentation = true;
	f.doc.body.appendChild(root);
	function section(id) {
		const host = new f.Node('div');
		host.setAttribute('id', id);
		host.setAttribute('data-ran-booster-ui-disclosure', 'documentation');
		const details = new f.Node('details');
		details.setAttribute('name', 'ran-booster-documentation');
		details.setAttribute('data-ran-booster-ui-disclosure-fallback', '');
		const summary = new f.Node('summary');
		summary.textContent = id;
		const body = new f.Node('div');
		body.setAttribute('data-ran-booster-ui-disclosure-content', '');
		const heading = new f.Node('h3');
		heading.setAttribute('id', id + '-heading');
		body.appendChild(heading);
		details.appendChild(summary);
		details.appendChild(body);
		host.appendChild(details);
		root.appendChild(host);
		details.open = false;
		return { host, details, summary, body, heading };
	}
	return { ...f, root, section };
}

test('Docs keep connected native state and synchronize the prepared group before returning', () => {
	const f = documentationFixture();
	const a = f.section('first');
	const b = f.section('second');
	f.run();
	f.flush();
	for (const item of [a, b]) {
		assert(item.details.isConnected && item.details.hidden);
		assert.equal(
			item.details.getAttribute('name'),
			'ran-booster-documentation'
		);
		assert.equal(item.body.firstChild, item.heading);
		assert.equal(
			item.host.querySelector('[data-disclosure-slot]').firstChild,
			item.body
		);
	}
	a.details.open = true;
	b.details.open = false;
	f.emit('ran-booster:documentation-state', f.root);
	assert.deepEqual(f.stateUpdates, [true, false]);
	f.flush();
	assert.equal(f.counts().renders, 2);
	f.tree().props.onOpenChange(true);
	assert.equal(b.details.open, true);
	assert.equal(f.stateUpdates.at(-1), true);
	f.emit('htmx:beforeHistorySave');
	for (const item of [a, b]) {
		assert.equal(item.host.firstChild, item.details);
		assert.equal(item.details.hidden, false);
		assert.equal(item.details.children[1], item.body);
		assert.equal(item.heading.isConnected, true);
	}
	f.flush();
	assert.equal(a.details.hidden, true);
	assert.equal(b.details.hidden, true);
});

test('Docs leave the connected native body usable when public rendering fails', () => {
	const f = documentationFixture({ disclosureFail: true });
	const item = f.section('guide');
	f.run();
	f.flush();
	assert.equal(item.details.hidden, false);
	assert.equal(item.host.firstChild, item.details);
	assert.equal(item.details.children[1], item.body);
	assert.equal(item.body.firstChild, item.heading);
	assert.equal(item.host.hasAttribute('data-ran-booster-ui-mounted'), false);
});
