import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-cards.js', import.meta.url),
	'utf8'
);
function fixture({
	css = 'loaded',
	runtime = true,
	cardComponent = true,
	fail = false,
	moveFail = false,
	stylesheets = [],
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
				throw new Error('partial move');
			}
			if (node.contains(doc.activeElement)) {
				doc.activeElement = doc.body;
			}
			this.insertBefore(node, null);
		}
		closest(selector) {
			if (selector === '.ran-booster-admin' && this.admin) {
				return this;
			}
			if (
				selector.startsWith('.ran-booster-card') &&
				this.label !== null
			) {
				return this;
			}
			return this.parentElement?.closest?.(selector) || null;
		}
		querySelector(selector) {
			return this.querySelectorAll(selector)[0] || null;
		}
		querySelectorAll(selector) {
			const descendants = this.childNodes.flatMap((n) => [
				n,
				...n.querySelectorAll('*'),
			]);
			if (selector === '*') {
				return descendants;
			}
			if (selector === '[data-ran-booster-card-content]') {
				return descendants.filter((n) => n.content);
			}
			return descendants.filter((n) => n.label !== null);
		}
		getAttribute() {
			return this.label;
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
		styleSheets: stylesheets,
		listeners,
		addEventListener(type, fn) {
			const list = listeners.get(type) || [];
			list.push(fn);
			listeners.set(type, list);
		},
		getElementById() {
			if (css === 'missing') {
				return null;
			}
			if (css === 'unreadable') {
				return {
					sheet: {
						get cssRules() {
							throw Error();
						},
					},
				};
			}
			return { sheet: { cssRules: css === 'empty' ? [] : [{}] } };
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
				if (fail) {
					throw Error();
				}
				const surface = new Node('surface');
				const content = new Node('content');
				content.content = true;
				host.appendChild(surface);
				surface.appendChild(content);
			},
			unmount() {
				unmounts++;
			},
		}),
	};
	const window = runtime
		? {
				wp: {
					element,
					components: { Card: cardComponent ? 'Card' : null },
				},
			}
		: {};
	window.location = {
		href: 'https://example.test/wp-admin/admin.php',
		origin: 'https://example.test',
	};
	const context = vm.createContext({
		window,
		URL,
		document: doc,
		queueMicrotask: (fn) => microtasks.push(fn),
	});
	function make(label = 'extensions', parent = doc.body, tag = 'div') {
		const card = new Node(tag, label);
		const whitespace = new Node('text'),
			form = new Node('form'),
			link = new Node('a');
		form.appendChild(link);
		card.appendChild(whitespace);
		card.appendChild(form);
		parent.appendChild(card);
		return { card, whitespace, form, link };
	}
	return {
		doc,
		Node,
		make,
		run: () => vm.runInContext(source, context),
		emit: (type, elt = doc.body) =>
			(listeners.get(type) || []).forEach((fn) =>
				fn({ detail: { elt }, target: elt })
			),
		microtasks,
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
	f.run();
	f.emit('htmx:load');
	assert.equal(f.counts().renders, 3);
	assert.equal(f.tree().type, 'Card');
	assert.equal(f.tree().props.isBorderless, true);
	assert.equal(f.tree().props.isRounded, false);
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
	for (const item of [unknown, outer, inner, external, invalid]) {
		assert.equal(item.card.firstChild, item.whitespace);
	}
	assert.equal(f.counts().renders, 0);
});

test('descendant and ancestor cleanup restore live children and partial load remounts', () => {
	const f = fixture();
	const { card, form, link } = f.make();
	f.run();
	const content = card.querySelector('[data-ran-booster-card-content]');
	const fresh = new f.Node('fresh');
	content.appendChild(fresh);
	link.focus();
	f.emit('htmx:beforeCleanupElement', form);
	assert.equal(card.childNodes.at(-1), fresh);
	assert.equal(f.doc.activeElement, link);
	f.emit('htmx:load', fresh);
	assert.equal(fresh.parentElement.content, true);
	f.emit('htmx:beforeCleanupElement', f.doc.body);
	assert.equal(card.childNodes.at(-1), fresh);
	assert.deepEqual(f.counts(), { renders: 2, unmounts: 2 });
});

test('host swap restores before cleanup only when the response will swap', () => {
	const f = fixture();
	const { card, form, link } = f.make();
	f.run();
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
	f.emit('htmx:beforeHistorySave');
	assert.equal(form.parentElement, card);
	f.microtasks.shift()();
	assert.equal(form.parentElement.content, true);
	card.remove();
	f.emit('htmx:historyRestore');
	assert.equal(form.parentElement, card);
	assert.deepEqual(f.counts(), { renders: 2, unmounts: 2 });
});

test('missing prerequisites and partial failures keep native children', () => {
	for (const options of [
		{ css: 'missing' },
		{ css: 'empty' },
		{ css: 'unreadable' },
		{ runtime: false },
		{ cardComponent: false },
		{ fail: true },
		{ moveFail: true },
	]) {
		const f = fixture(options);
		const { card, whitespace, form } = f.make();
		f.run();
		assert.deepEqual(card.childNodes, [whitespace, form]);
	}
});

for (const query of [
	'load=common,wp-components,wp-commands',
	'load%5Bchunk_0%5D=common&load%5Bchunk_1%5D=wp-components,wp-commands',
]) {
	test(`WordPress concatenated styles enable cards: ${query}`, () => {
		const sheet = {
			href: `https://example.test/wp-admin/load-styles.php?${query}`,
			cssRules: [{}],
			ownerNode: { disabled: false },
		};
		const f = fixture({ css: 'missing', stylesheets: [sheet] });
		f.make();
		f.run();
		assert.equal(f.counts().renders, 1, query);
	});
}

test('only enabled readable same-origin WordPress bundles with the exact handle qualify', () => {
	const base =
		'https://example.test/wp-admin/load-styles.php?load=wp-components';
	const unreadable = {
		href: base,
		get cssRules() {
			throw Error();
		},
	};
	const cases = [
		{
			href: base.replace('wp-components', 'wp-components-extra'),
			cssRules: [{}],
		},
		{ href: base.replace('/wp-admin/', '/other/'), cssRules: [{}] },
		{ href: base.replace('example.test', 'other.test'), cssRules: [{}] },
		{ href: base.replace('load=', 'load%5Bchunk_x%5D='), cssRules: [{}] },
		{ href: base, disabled: true, cssRules: [{}] },
		{ href: base, ownerNode: { disabled: true }, cssRules: [{}] },
		{ href: base, cssRules: [] },
		unreadable,
	];
	for (const sheet of cases) {
		const f = fixture({ css: 'missing', stylesheets: [sheet] });
		f.make();
		f.run();
		assert.equal(f.counts().renders, 0, sheet.href);
	}
	const f = fixture({
		css: 'missing',
		stylesheets: [
			{
				href: 'https://example.test/sub/wp-admin/load-styles.php?load=wp-components',
				cssRules: [{}],
			},
		],
	});
	f.make();
	f.run();
	assert.equal(f.counts().renders, 1);
});
