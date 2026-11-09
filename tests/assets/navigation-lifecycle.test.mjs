import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs
	.readFileSync(
		new URL('../../assets/ran-booster-cards.js', import.meta.url),
		'utf8'
	)
	.replace(
		'function initialize(scope, restoredDisclosure = null) {',
		'window.navigationTest = { mountNavigation, setNavigationCurrent, cleanup, restoreAll, roots }; function initialize(scope, restoredDisclosure = null) {'
	);

function fixture({ fail = false } = {}) {
	const queued = [];
	const listeners = new Map();
	const created = [];
	const updates = [];
	let destroyed = 0;
	class Node {
		constructor(tag, attrs = {}) {
			this.tagName = tag.toUpperCase();
			this.map = new Map(Object.entries(attrs));
			this.children = [];
			this.parentElement = null;
			this.ownerDocument = doc;
			this.scrollLeft = 0;
			this.textContent = '';
			this.listeners = new Map();
			this.style = { removeProperty() {} };
		}
		addEventListener(type, listener) {
			this.listeners.set(type, listener);
		}
		removeEventListener(type, listener) {
			if (this.listeners.get(type) === listener) {
				this.listeners.delete(type);
			}
		}
		matches(selector) {
			return (this.getAttribute('class') || '')
				.split(' ')
				.includes(selector.slice(1));
		}
		get childNodes() {
			return this.children;
		}
		get childElementCount() {
			return this.children.length;
		}
		get attributes() {
			return [...this.map].map(([name, value]) => ({ name, value }));
		}
		get isConnected() {
			return this === doc.body || !!this.parentElement?.isConnected;
		}
		get classList() {
			return {
				add: (...names) =>
					names.forEach((name) => this.classList.toggle(name, true)),
				remove: (...names) =>
					names.forEach((name) => this.classList.toggle(name, false)),
				toggle: (name, selected) => {
					const c = new Set(
						(this.getAttribute('class') || '')
							.split(' ')
							.filter(Boolean)
					);
					if (selected) {
						c.add(name);
					} else {
						c.delete(name);
					}
					this.setAttribute('class', [...c].join(' '));
				},
			};
		}
		getAttribute(name) {
			return this.map.has(name) ? this.map.get(name) : null;
		}
		hasAttribute(name) {
			return this.map.has(name);
		}
		setAttribute(name, value) {
			this.map.set(name, String(value));
		}
		removeAttribute(name) {
			this.map.delete(name);
		}
		appendChild(node) {
			node.parentElement = this;
			this.children.push(node);
			return node;
		}
		replaceChildren(...nodes) {
			this.children.forEach((node) => {
				node.parentElement = null;
			});
			this.children = [];
			nodes.forEach((node) => this.appendChild(node));
		}
		contains(node) {
			return (
				node === this ||
				this.children.some((child) => child.contains(node))
			);
		}
		closest(selector) {
			if (
				selector === '#ran-booster-provider-tasks' &&
				this.getAttribute('id') === 'ran-booster-provider-tasks'
			) {
				return this;
			}
			if (
				selector === '[aria-busy="true"]' &&
				this.getAttribute('aria-busy') === 'true'
			) {
				return this;
			}
			return this.parentElement?.closest(selector) || null;
		}
		querySelectorAll(selector) {
			if (selector === ':scope > a') {
				return this.children.filter((child) => child.tagName === 'A');
			}
			if (selector === ':scope > li > a') {
				return this.children.flatMap((li) =>
					li.children.filter((child) => child.tagName === 'A')
				);
			}
			return [];
		}
		querySelector(selector) {
			return selector === '#ran-booster-provider-task-panel'
				? panel
				: null;
		}
		focus() {
			doc.activeElement = this;
		}
	}
	const doc = {
		readyState: 'loading',
		activeElement: null,
		addEventListener(type, fn) {
			const list = listeners.get(type) || [];
			list.push(fn);
			listeners.set(type, list);
		},
		getElementById: () => panel,
	};
	doc.body = new Node('body');
	doc.activeElement = doc.body;
	const region = doc.body.appendChild(
		new Node('div', {
			id: 'ran-booster-provider-tasks',
			'hx-target': '#ran-booster-provider-task-panel',
			'hx-select': '#ran-booster-provider-task-panel',
			'hx-swap': 'outerHTML transition:false show:none',
			'hx-push-url': 'true',
			'hx-history': 'false',
			'hx-sync': 'this:replace',
		})
	);
	const nav = region.appendChild(new Node('nav', { 'hx-boost': 'true' }));
	const host = nav.appendChild(new Node('ul'));
	const panel = region.appendChild(
		new Node('section', { id: 'ran-booster-provider-task-panel' })
	);
	for (const task of ['status', 'repositories']) {
		const li = host.appendChild(new Node('li'));
		const link = li.appendChild(
			new Node('a', {
				href: `/${task}`,
				'hx-get': `/fragment/${task}`,
				'data-ran-booster-provider-task': task,
				class: task === 'status' ? 'current' : '',
				...(task === 'status' ? { 'aria-current': 'page' } : {}),
			})
		);
		link.textContent = task;
	}
	const original = [...host.children];
	const window = {
		ranBoosterUI: {
			createSectionNavigation(track) {
				created.push(track);
				return fail
					? null
					: {
							update: (options) => updates.push(options),
							destroy: () => destroyed++,
						};
			},
		},
	};
	vm.runInNewContext(source, {
		window,
		document: doc,
		queueMicrotask: (fn) => queued.push(fn),
		setTimeout: (fn) => queued.push(fn),
	});
	return {
		window,
		doc,
		region,
		host,
		original,
		panel,
		created,
		updates,
		get destroyed() {
			return destroyed;
		},
		fire(type, event = {}) {
			(listeners.get(type) || []).forEach((fn) =>
				fn({ target: doc.body, detail: {}, ...event })
			);
		},
		flush() {
			while (queued.length) {
				queued.shift()();
			}
		},
	};
}

test('mounting adds the indicator behavior and leaves the server links untouched', () => {
	const f = fixture();
	f.host.scrollLeft = 20;
	f.window.navigationTest.mountNavigation(f.host, true);
	assert.equal(f.window.navigationTest.roots.has(f.host), true);
	assert.deepEqual(f.created, [f.host]);
	assert.deepEqual(f.host.children, f.original);
	assert.equal(f.host.scrollLeft, 20);
	f.window.navigationTest.mountNavigation(f.host, true);
	assert.equal(f.created.length, 1, 'a host is mounted only once');
});

test('a track the section navigation declines stays an ordinary native list', () => {
	const f = fixture({ fail: true });
	f.window.navigationTest.mountNavigation(f.host, true);
	assert.equal(f.window.navigationTest.roots.size, 0);
	assert.deepEqual(f.host.children, f.original);
	const absent = fixture();
	delete absent.window.ranBoosterUI.createSectionNavigation;
	absent.window.navigationTest.mountNavigation(absent.host, true);
	assert.equal(absent.window.navigationTest.roots.has(absent.host), false);
});

test('committing a provider task moves aria-current and the indicator without replacing links', () => {
	const f = fixture();
	assert.equal(
		f.window.navigationTest.setNavigationCurrent(f.region, 'repositories'),
		undefined,
		'unmounted navigation leaves the caller to write native state'
	);
	f.window.navigationTest.mountNavigation(f.host, true);
	const [status, repositories] = f.original.map((li) => li.children[0]);
	repositories.focus();
	const selected = f.window.navigationTest.setNavigationCurrent(
		f.region,
		'repositories'
	);
	assert.equal(selected, repositories);
	assert.equal(repositories.getAttribute('aria-current'), 'page');
	assert.equal(repositories.matches('.current'), true);
	assert.equal(status.getAttribute('aria-current'), null);
	assert.equal(status.matches('.current'), false);
	assert.equal(
		JSON.stringify(f.updates),
		JSON.stringify([{ animate: true, afterPaint: true }])
	);
	assert.equal(f.doc.activeElement, repositories, 'focus is not moved');
	assert.deepEqual(f.host.children, f.original, 'nodes are never replaced');
	assert.equal(
		f.window.navigationTest.setNavigationCurrent(f.region, 'unknown'),
		null
	);
	assert.equal(repositories.getAttribute('aria-current'), null);
	assert.equal(status.getAttribute('aria-current'), null);
});

test('panel cleanup leaves navigation mounted; restoring destroys only the behavior', () => {
	const f = fixture();
	f.window.navigationTest.mountNavigation(f.host, true);
	f.window.navigationTest.cleanup(f.panel);
	assert.equal(f.window.navigationTest.roots.has(f.host), true);
	assert.equal(f.destroyed, 0);
	f.window.navigationTest.setNavigationCurrent(f.region, 'repositories');
	f.window.navigationTest.restoreAll([f.host]);
	assert.equal(f.window.navigationTest.roots.has(f.host), false);
	assert.equal(f.destroyed, 1);
	assert.deepEqual(f.host.children, f.original);
	assert.equal(
		f.original[1].children[0].getAttribute('aria-current'),
		'page',
		'the committed native state survives cleanup'
	);
});

test('mounting is not deferred by pointer, key or busy state because no node is replaced', () => {
	const f = fixture();
	f.fire('pointerdown');
	f.fire('keydown', { key: 'Enter' });
	f.region.setAttribute('aria-busy', 'true');
	f.host.children[0].children[0].focus();
	f.window.navigationTest.mountNavigation(f.host, true);
	assert.equal(f.window.navigationTest.roots.has(f.host), true);
	assert.equal(f.doc.activeElement, f.host.children[0].children[0]);
});
