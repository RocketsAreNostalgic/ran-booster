import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-cards.js', import.meta.url),
	'utf8'
);
function fixture({ runtime = true, fail = false, moveFail = false } = {}) {
	const listeners = new Map(),
		microtasks = [];
	const unmountOrder = [];
	let renders = 0,
		creates = 0,
		unmounts = 0,
		moves = 0,
		tree,
		boundaryInstance;
	const boundaryTypes = [];
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
			this.disabled = false;
			this.textContent = '';
			if (label !== null) {
				this.setAttribute('data-ran-booster-card', label);
			}
		}
		get children() {
			return this.childNodes;
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
			if (selector === ':active') {
				return Boolean(this.active);
			}
			if (
				selector === '[data-ran-booster-ui-switch="general-preference"]'
			) {
				return (
					this.getAttribute('data-ran-booster-ui-switch') ===
					'general-preference'
				);
			}
			if (selector === 'input[role="switch"]') {
				return (
					this.tagName === 'INPUT' &&
					this.getAttribute('role') === 'switch'
				);
			}
			if (selector === 'button[role="switch"]') {
				return (
					this.tagName === 'BUTTON' &&
					this.getAttribute('role') === 'switch'
				);
			}
			if (
				selector ===
				'.ran-booster-settings__switch-control[aria-hidden="true"]'
			) {
				return (
					this.getAttribute('class') ===
						'ran-booster-settings__switch-control' &&
					this.getAttribute('aria-hidden') === 'true'
				);
			}
			if (selector === 'form') {
				return this.tagName === 'FORM';
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
			if (selector.startsWith(':scope > ')) {
				return (
					this.childNodes.find((node) =>
						node.matches(selector.slice(9))
					) || null
				);
			}
			return this.querySelectorAll(selector)[0] || null;
		}
		dispatchEvent(event) {
			this.events.push({
				type: event.type,
				bubbles: event.bubbles,
				checked: this.checked,
			});
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
		Component: class {
			constructor(props) {
				this.props = props;
			}
		},
		createElement: (type, props, ...children) => ({
			type,
			props,
			children,
		}),
		flushSync: (fn) => fn(),
		createRoot: (host) => (
			creates++,
			{
				render(value) {
					renders++;
					if (typeof value.type === 'function') {
						boundaryTypes.push(value.type);
						if (!this.boundary) {
							this.boundary = new value.type({
								children: value.children[0],
							});
						}
						boundaryInstance = this.boundary;
						if (this.boundary.state.failed) {
							this.boundary.getSnapshotBeforeUpdate();
							host.replaceChildren();
							return;
						}
						value = value.children[0];
					}
					tree = value;
					if (host.failAfterClear) {
						host.replaceChildren();
						throw Error('late failure');
					}
					if (fail || host.fail) {
						throw Error('render failure');
					}
					host.replaceChildren();
					if (host.redirectFocus) {
						host.redirectFocus.focus();
					}
					if (value.type === 'NoticeRoot') {
						const surface = new Node('surface'),
							content = new Node('content');
						content.content = true;
						content.setAttribute(
							'data-ran-booster-ui-notice-slot',
							''
						);
						host.appendChild(surface);
						surface.appendChild(content);
					} else if (value.type === 'Switch') {
						const button = new Node('button');
						button.id = value.props.id;
						button.type = 'button';
						button.setAttribute('role', 'switch');
						host.appendChild(button);
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
			}
		),
	};
	const window = {
		wp: { element },
		ranBoosterUI: runtime
			? {
					Button: 'Button',
					Switch: 'Switch',
					NoticeRoot: 'NoticeRoot',
					NoticeDescription: 'NoticeDescription',
				}
			: null,
	};
	const timers = [];
	const context = vm.createContext({
		window,
		document: doc,
		queueMicrotask: (fn) => microtasks.push(fn),
		setTimeout: (fn) => timers.push(fn),
		Event: class {
			constructor(type, options) {
				this.type = type;
				this.bubbles = options.bubbles;
			}
		},
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

	function generalSwitch({
		scope = 'hide_github',
		checked = false,
		disabled = false,
		parent = doc.body,
	} = {}) {
		const form = new Node('form'),
			label = new Node('label'),
			host = new Node('span'),
			fallback = new Node('label'),
			input = new Node('input'),
			track = new Node('span'),
			mount = new Node('span');
		form.dataset = { ranBoosterSettingsScope: scope };
		input.form = form;
		input.id = `control-${scope}`;
		input.type = 'checkbox';
		input.name = `ran_booster[preferences][${scope}]`;
		input.checked = checked;
		input.disabled = disabled;
		input.events = [];
		input.setAttribute('role', 'switch');
		input.setAttribute('aria-labelledby', `label-${scope}`);
		input.setAttribute('aria-describedby', 'help');
		input.setAttribute('aria-invalid', 'true');
		label.id = `label-${scope}`;
		label.htmlFor = input.id;
		fallback.htmlFor = input.id;
		host.setAttribute('data-ran-booster-ui-switch', 'general-preference');
		fallback.setAttribute('data-ran-booster-ui-switch-fallback', '');
		track.setAttribute('class', 'ran-booster-settings__switch-control');
		track.setAttribute('aria-hidden', 'true');
		mount.setAttribute('data-ran-booster-ui-switch-mount', '');
		fallback.appendChild(input);
		fallback.appendChild(track);
		host.appendChild(fallback);
		host.appendChild(mount);
		form.appendChild(label);
		form.appendChild(host);
		parent.appendChild(form);
		return { form, label, host, fallback, input, track, mount };
	}
	const emit = (type, elt = doc.body, extra = {}) =>
		(listeners.get(type) || []).forEach((fn) =>
			fn({ detail: { elt }, target: elt, ...extra })
		);
	const flush = () => {
		while (microtasks.length) {
			microtasks.shift()();
		}
	};
	return {
		doc,
		Node,
		generalSwitch,
		boundaryTypes,
		failAsync: (mount) => {
			boundaryInstance.getSnapshotBeforeUpdate();
			boundaryInstance.state.failed = true;
			mount.replaceChildren();
			boundaryInstance.componentDidCatch();
		},
		make,
		presentation,
		notice,
		unmountOrder,
		window,
		run: () => vm.runInContext(source, context),
		emit,
		flush,
		counts: () => ({ renders, unmounts, creates }),
		flushTimers: () => {
			while (timers.length) {
				timers.shift()();
			}
		},
		tree: () => tree,
	};
}

test('public switch derives state and emits exactly one native change', () => {
	const f = fixture();
	const s = f.generalSwitch({ checked: false });
	f.run();
	f.flush();
	assert.equal(f.tree().type, 'Switch');
	assert.equal(f.tree().props.checked, false);
	assert.equal(f.tree().props.disabled, false);
	assert.equal(f.tree().props.name, undefined);
	assert.equal(f.tree().props.nativeButton, true);
	assert.equal(f.tree().props.render.type, 'button');
	assert.equal(f.tree().props.render.props.type, 'button');
	assert.equal(f.tree().props['aria-describedby'], 'help');
	assert.equal(f.tree().props['aria-invalid'], 'true');
	assert.equal(s.fallback.hidden, true);
	assert.equal(s.label.htmlFor, `${s.input.id}-ui`);
	assert.equal(s.input.parentElement, s.fallback);
	f.tree().props.onCheckedChange(true);
	assert.equal(s.input.checked, true);
	assert.deepEqual(s.input.events, [
		{ type: 'change', bubbles: true, checked: true },
	]);
	const priorRenders = f.counts().renders;
	f.emit('ran-booster:ui-switch-update', s.input);
	assert.equal(f.counts().renders, priorRenders + 1);
	assert.equal(f.counts().unmounts, 0);
	assert.equal(f.counts().creates, 1);
	assert.equal(f.boundaryTypes[0], f.boundaryTypes[1]);
	assert.equal(f.tree().props.checked, true);
	s.input.disabled = true;
	f.emit('ran-booster:ui-switch-update', s.input);
	assert.equal(f.tree().props.disabled, true);
	f.tree().props.onCheckedChange(false);
	assert.equal(s.input.checked, true);
	assert.equal(s.input.events.length, 1);
});

test('history restores native focus and delays remount until focus leaves', () => {
	const f = fixture();
	const s = f.generalSwitch({ checked: true });
	f.run();
	f.flush();
	s.mount.querySelector('button[role="switch"]').focus();
	f.emit('htmx:beforeHistorySave');
	assert.equal(s.fallback.hidden, false);
	assert.equal(s.label.htmlFor, s.input.id);
	assert.equal(f.doc.activeElement, s.input);
	assert.equal(s.input.checked, true);
	f.flush();
	assert.equal(s.fallback.hidden, false);
	f.doc.body.focus();
	f.emit('focusout', s.input);
	f.flushTimers();
	f.flush();
	assert.equal(s.fallback.hidden, true);
	assert.equal(s.label.htmlFor, `${s.input.id}-ui`);
});

test('missing, malformed and failed rendering preserve the fallback', () => {
	for (const mode of ['missing', 'scope', 'extra', 'failure']) {
		const f = fixture({
			runtime: mode !== 'missing',
			fail: mode === 'failure',
		});
		const s = f.generalSwitch({
			scope: mode === 'scope' ? 'other' : 'hide_github',
		});
		if (mode === 'extra') {
			s.fallback.appendChild(new f.Node('span'));
		}
		f.run();
		f.flush();
		assert.equal(s.fallback.hidden, false, mode);
		assert.equal(s.label.htmlFor, s.input.id, mode);
		assert.equal(s.input.parentElement, s.fallback, mode);
	}
});

test('native pointer gesture is not interrupted and mounts after release', () => {
	const f = fixture();
	const s = f.generalSwitch();
	f.run();
	f.emit('pointerdown', s.input);
	f.flush();
	assert.equal(s.fallback.hidden, false);
	s.input.checked = true;
	f.emit('ran-booster:ui-switch-update', s.input);
	assert.equal(s.fallback.hidden, false);
	f.emit('pointerup', s.input);
	f.flushTimers();
	f.flush();
	assert.equal(s.fallback.hidden, true);
	assert.equal(f.tree().props.checked, true);
	assert.equal(f.counts().creates, 1);
});

test('later synchronous render failure restores focused native input without stealing outside focus', () => {
	for (const focused of [true, false]) {
		const f = fixture();
		const s = f.generalSwitch({ checked: true });
		f.run();
		f.flush();
		const outside = new f.Node('button');
		f.doc.body.appendChild(outside);
		(focused
			? s.mount.querySelector('button[role="switch"]')
			: outside
		).focus();
		s.mount.failAfterClear = true;
		f.emit('ran-booster:ui-switch-update', s.input);
		assert.equal(s.fallback.hidden, false);
		assert.equal(s.label.htmlFor, s.input.id);
		assert.equal(f.doc.activeElement, focused ? s.input : outside);
		assert.equal(s.input.checked, true);
	}
});

test('later boundary failure restores only its owner and retains outside focus', () => {
	for (const focused of [true, false]) {
		const f = fixture();
		const s = f.generalSwitch();
		f.run();
		f.flush();
		const outside = new f.Node('button');
		f.doc.body.appendChild(outside);
		(focused
			? s.mount.querySelector('button[role="switch"]')
			: outside
		).focus();
		f.failAsync(s.mount);
		f.flush();
		assert.equal(s.fallback.hidden, false);
		assert.equal(f.doc.activeElement, focused ? s.input : outside);
	}
});

test('queued failure from a removed root cannot restore over a new mount', () => {
	const f = fixture();
	const s = f.generalSwitch();
	f.run();
	f.flush();
	s.mount.querySelector('button[role="switch"]').focus();
	f.failAsync(s.mount);
	f.emit('htmx:beforeCleanupElement', s.host);
	f.doc.body.focus();
	f.emit('ran-booster:ui-switch-update', s.input);
	assert.equal(s.fallback.hidden, true);
	f.flush();
	assert.equal(s.fallback.hidden, true);
	assert.equal(s.label.htmlFor, `${s.input.id}-ui`);
	assert.equal(f.counts().creates, 2);
});

test('queued recovery does not reclaim focus moved outside after failure', () => {
	const f = fixture();
	const s = f.generalSwitch();
	f.run();
	f.flush();
	s.mount.querySelector('button[role="switch"]').focus();
	f.failAsync(s.mount);
	const outside = new f.Node('button');
	f.doc.body.appendChild(outside);
	outside.focus();
	f.flush();
	assert.equal(s.fallback.hidden, false);
	assert.equal(f.doc.activeElement, outside);
});

test('focused native switch stays native until focus leaves', () => {
	const f = fixture();
	const s = f.generalSwitch();
	s.input.focus();
	f.run();
	f.flush();
	assert.equal(s.fallback.hidden, false);
	assert.equal(f.doc.activeElement, s.input);
	f.doc.body.focus();
	f.emit('focusout', s.input);
	f.flushTimers();
	f.flush();
	assert.equal(s.fallback.hidden, true);
});

test('queued initialization cannot erase focus captured by a later failure', () => {
	const f = fixture();
	const s = f.generalSwitch();
	f.run();
	f.flush();
	s.mount.querySelector('button[role="switch"]').focus();
	f.emit('htmx:load', s.host);
	f.failAsync(s.mount);
	f.flush();
	assert.equal(s.fallback.hidden, false);
	assert.equal(f.doc.activeElement, s.input);
});

test('explicit cleanup uses pending failure focus without reclaiming outside focus', () => {
	for (const moveOutside of [false, true]) {
		const f = fixture();
		const s = f.generalSwitch();
		f.run();
		f.flush();
		s.mount.querySelector('button[role="switch"]').focus();
		f.failAsync(s.mount);
		const outside = new f.Node('button');
		f.doc.body.appendChild(outside);
		if (moveOutside) {
			outside.focus();
		}
		f.emit('htmx:beforeCleanupElement', s.host);
		assert.equal(f.doc.activeElement, moveOutside ? outside : s.input);
		f.flush();
	}
});

test('a static Settings Card never moves its switch during a native gesture', () => {
	for (const gesture of ['keyboard', 'pointer']) {
		const f = fixture();
		const { card } = f.make('settings');
		const s = f.generalSwitch({ parent: card });
		f.window.ranBoosterUI.Switch = null;
		if (gesture === 'keyboard') {
			s.input.focus();
		} else {
			s.fallback.active = true;
		}
		f.run();
		f.flush();
		assert.equal(s.fallback.hidden, false);
		s.input.checked = true;
		s.input.dispatchEvent({ type: 'change', bubbles: true });
		assert.equal(s.input.events.length, 1);
		if (gesture === 'pointer') {
			s.fallback.active = false;
			f.emit('pointerup', s.input);
		} else {
			f.emit('keyup', s.input, { key: ' ' });
			f.doc.body.focus();
			f.emit('focusout', s.input);
		}
		f.flushTimers();
		f.flush();
		assert.equal(
			card.hasAttribute('data-ran-booster-ui-mounted'),
			false,
			gesture
		);
		assert.equal(s.form.parentElement, card, gesture);
		assert.equal(s.input.checked, true);
	}
});
