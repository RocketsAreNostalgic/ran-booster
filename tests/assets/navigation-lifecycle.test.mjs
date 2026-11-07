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
		'function initialize(scope) {',
		'window.navigationTest = { mountNavigation, setNavigationCurrent, cleanup, restoreAll, roots }; function initialize(scope) {'
	);

function fixture({
	fail = false,
	processFail = false,
	invalidTarget = false,
} = {}) {
	const queued = [];
	const listeners = new Map();
	let renders = 0;
	let processed = 0;
	class Node {
		constructor(tag, attrs = {}) {
			this.tagName = tag.toUpperCase();
			this.map = new Map(Object.entries(attrs));
			this.children = [];
			this.parentElement = null;
			this.ownerDocument = doc;
			this.scrollLeft = 0;
			this.textContent = '';
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
			'hx-target': invalidTarget
				? '#other'
				: '#ran-booster-provider-task-panel',
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
		createRoot(track) {
			return {
				render(tree) {
					if (fail) {
						throw Error('render');
					}
					renders++;
					const props = tree.children[0].props;
					track.replaceChildren(
						...props.items.map((item) => {
							const li = new Node('li');
							const link = li.appendChild(
								new Node('a', {
									href: item.href,
									'data-ran-booster-provider-task': item.task,
									...(item.current
										? { 'aria-current': 'page' }
										: {}),
								})
							);
							link.textContent = item.label;
							return li;
						})
					);
				},
				unmount() {
					track.replaceChildren();
				},
			};
		},
	};
	const window = {
		wp: { element },
		ranBoosterUI: { NavigationLinks: 'NavigationLinks' },
		htmx: {
			process() {
				processed++;
				if (processFail) {
					host.scrollLeft = 0;
					throw Error('process');
				}
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
		get renders() {
			return renders;
		},
		get processed() {
			return processed;
		},
		setFail(value) {
			fail = value;
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

test('provider uses same host, committed state and native history restoration', () => {
	const f = fixture();
	f.host.scrollLeft = 20;
	f.window.navigationTest.mountNavigation(f.host, true);
	assert.equal(f.window.navigationTest.roots.has(f.host), true);
	assert.equal(f.host.scrollLeft, 20);
	assert.equal(f.processed, 2);
	const selected = f.window.navigationTest.setNavigationCurrent(
		f.region,
		'repositories'
	);
	assert.equal(selected.getAttribute('aria-current'), 'page');
	assert.equal(
		f.window.navigationTest.setNavigationCurrent(f.region, 'unknown'),
		null
	);
	f.window.navigationTest.restoreAll([f.host]);
	assert.deepEqual(f.host.children, f.original);
	assert.equal(f.original[0].children[0].getAttribute('aria-current'), null);
	assert.equal(f.original[1].children[0].getAttribute('aria-current'), null);
});

test('unsafe targets and busy states remain native', () => {
	const unsafe = fixture({ invalidTarget: true });
	unsafe.window.navigationTest.mountNavigation(unsafe.host, true);
	assert.equal(unsafe.renders, 0);
	const busy = fixture();
	busy.region.setAttribute('aria-busy', 'true');
	busy.window.navigationTest.mountNavigation(busy.host, true);
	assert.equal(busy.renders, 0);
});

test('failed updates restore latest native state and focus; panel cleanup leaves navigation mounted', () => {
	const f = fixture();
	f.window.navigationTest.mountNavigation(f.host, true);
	f.window.navigationTest.cleanup(f.panel);
	assert.equal(f.window.navigationTest.roots.has(f.host), true);
	f.host.children[1].children[0].focus();
	f.setFail(true);
	assert.equal(
		f.window.navigationTest.setNavigationCurrent(f.region, 'repositories'),
		undefined
	);
	assert.deepEqual(f.host.children, f.original);
	assert.equal(f.doc.activeElement, f.original[1].children[0]);
	assert.equal(
		f.original[1].children[0].getAttribute('aria-current'),
		'page'
	);
});

test('initial processing failure restores original nodes', () => {
	const f = fixture({ processFail: true });
	f.host.scrollLeft = 73;
	f.window.navigationTest.mountNavigation(f.host, true);
	assert.deepEqual(f.host.children, f.original);
	assert.equal(f.host.scrollLeft, 73);
	assert.equal(f.window.navigationTest.roots.size, 0);
});

test('unrelated retries cannot end an active pointer or key gesture', () => {
	const f = fixture();
	f.fire('pointerdown');
	f.fire('focusout');
	f.fire('htmx:afterRequest');
	f.flush();
	f.window.navigationTest.mountNavigation(f.host, true);
	assert.equal(f.renders, 0);
	f.fire('pointerup');
	f.window.navigationTest.mountNavigation(f.host, true);
	assert.equal(f.renders, 0);
	f.flush();
	f.window.navigationTest.mountNavigation(f.host, true);
	assert.equal(f.renders, 1);
	const key = fixture();
	key.fire('keydown', { key: 'Enter' });
	key.fire('htmx:afterRequest');
	key.flush();
	key.window.navigationTest.mountNavigation(key.host, true);
	assert.equal(key.renders, 0);
	key.fire('keyup', { key: 'Enter' });
	key.flush();
	key.window.navigationTest.mountNavigation(key.host, true);
	assert.equal(key.renders, 1);
});

test('aliased overrides and non-page current semantics keep native links', () => {
	for (const target of ['nav', 'list', 'item', 'region']) {
		const f = fixture();
		const node = {
			nav: f.host.parentElement,
			list: f.host,
			item: f.original[0],
			region: f.region,
		}[target];
		node.setAttribute('data-hx-target', '#other');
		f.window.navigationTest.mountNavigation(f.host, true);
		assert.equal(f.renders, 0, target);
	}
	const f = fixture();
	f.original[0].children[0].setAttribute('aria-current', 'location');
	f.window.navigationTest.mountNavigation(f.host, true);
	assert.equal(f.renders, 0);
});

test('disabled or overridden HTMX inheritance retains native provider links', () => {
	for (const target of ['region', 'host']) {
		for (const attribute of ['hx-disinherit', 'data-hx-disinherit']) {
			const f = fixture();
			f[target].setAttribute(attribute, 'hx-target');
			f.window.navigationTest.mountNavigation(f.host, true);
			assert.equal(f.renders, 0);
			assert.deepEqual(f.host.children, f.original);
		}
	}
	const f = fixture();
	f.window.htmx.config = { disableInheritance: true };
	f.window.navigationTest.mountNavigation(f.host, true);
	assert.equal(f.renders, 0);
	assert.deepEqual(f.host.children, f.original);
});

test('history restoration does not reclaim focus that explicitly left navigation', () => {
	const f = fixture();
	f.window.navigationTest.mountNavigation(f.host, true);
	const link = f.host.children[0].children[0];
	link.focus();
	f.fire('focusin', { target: link });
	f.doc.activeElement = f.doc.body;
	f.fire('focusout', { target: link, relatedTarget: null });
	f.window.navigationTest.restoreAll([f.host]);
	assert.equal(f.doc.activeElement, f.doc.body);
	assert.deepEqual(f.host.children, f.original);
});

test('visible text beside provider links remains native', () => {
	for (const target of ['host', 'item']) {
		const f = fixture();
		const node = target === 'host' ? f.host : f.original[0];
		Object.defineProperty(node, 'childNodes', {
			get: () => [
				...node.children,
				{ nodeType: 3, textContent: ' (read only)' },
			],
		});
		f.window.navigationTest.mountNavigation(f.host, true);
		assert.equal(f.renders, 0);
		assert.deepEqual(f.host.children, f.original);
	}
});
