import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-extension-card.js', import.meta.url),
	'utf8'
);

function fixture({
	css = 'loaded',
	runtime = true,
	cardComponent = true,
	fail = false,
	moveFail = false,
} = {}) {
	const listeners = new Map();
	const microtasks = [];
	let renders = 0;
	let unmounts = 0;
	let renderedTree;
	let moveFailed = false;
	class Node {
		constructor(name) {
			this.name = name;
			this.children = [];
			this.parent = null;
			this.classes = new Set();
			this.classList = {
				add: (value) => this.classes.add(value),
				remove: (value) => this.classes.delete(value),
			};
		}
		appendChild(node) {
			if (moveFail && this.slot === 'bottom' && !moveFailed) {
				moveFailed = true;
				throw new Error('move failed');
			}
			node.remove();
			this.children.push(node);
			node.parent = this;
		}
		remove() {
			if (this.parent) {
				this.parent.children.splice(
					this.parent.children.indexOf(this),
					1
				);
				this.parent = null;
			}
		}
		before(node) {
			node.remove();
			this.parent.children.splice(
				this.parent.children.indexOf(this),
				0,
				node
			);
			node.parent = this.parent;
		}
		replaceWith(node) {
			this.before(node);
			this.remove();
		}
		contains(node) {
			return (
				this === node ||
				this.children.some((child) => child.contains(node))
			);
		}
		querySelector(selector) {
			if (selector.startsWith('[data-ran-booster-card-slot=')) {
				const name = selector.split('"')[1];
				return (
					this.children
						.flatMap((child) => [child, ...child.descendants()])
						.find((child) => child.slot === name) || null
				);
			}
			return this.lookup?.[selector] || null;
		}
		descendants() {
			return this.children.flatMap((child) => [
				child,
				...child.descendants(),
			]);
		}
		matches() {
			return this === card;
		}
		querySelectorAll() {
			return this === document ? [card] : [];
		}
	}
	const document = new Node('document');
	const card = new Node('card');
	const top = new Node('top');
	const bottom = new Node('bottom');
	document.appendChild(card);
	card.appendChild(top);
	card.appendChild(bottom);
	const nodes = Object.fromEntries(
		['name', 'actions', 'description', 'metadata', 'compatibility'].map(
			(name) => [name, new Node(name)]
		)
	);
	['name', 'actions', 'description'].forEach((name) =>
		top.appendChild(nodes[name])
	);
	['metadata', 'compatibility'].forEach((name) =>
		bottom.appendChild(nodes[name])
	);
	card.lookup = { '.plugin-card-top': top, '.plugin-card-bottom': bottom };
	const form = new Node('form');
	const link = new Node('link');
	nodes.actions.appendChild(form);
	nodes.name.appendChild(link);
	document.readyState = 'complete';
	document.getElementById = () => {
		if (css === 'missing') {
			return null;
		}
		if (css === 'unreadable') {
			return {
				sheet: {
					get cssRules() {
						throw new Error('unreadable');
					},
				},
			};
		}
		return { sheet: { cssRules: css === 'empty' ? [] : [{}] } };
	};
	document.createElement = (name) => new Node(name);
	document.createComment = () => new Node('comment');
	document.addEventListener = (type, listener) => {
		const list = listeners.get(type) || [];
		list.push(listener);
		listeners.set(type, list);
	};
	const element = {
		createElement: (type, props, ...children) => ({
			type,
			props,
			children,
		}),
		flushSync: (callback) => callback(),
		createRoot: (host) => ({
			render(tree) {
				renders++;
				renderedTree = tree;
				if (fail) {
					throw new Error('render failed');
				}
				const build = (item, parent) => {
					const node = new Node(item.type);
					node.slot = item.props?.['data-ran-booster-card-slot'];
					parent.appendChild(node);
					item.children.forEach((child) => build(child, node));
				};
				build(tree, host);
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
					components: {
						Card: cardComponent ? 'Card' : null,
					},
				},
			}
		: {};
	const context = vm.createContext({
		window,
		document,
		queueMicrotask: (callback) => microtasks.push(callback),
	});
	const run = () => vm.runInContext(source, context);
	const emit = (type, elt = card) =>
		(listeners.get(type) || []).forEach((listener) =>
			listener({ detail: { elt }, target: elt })
		);
	return {
		card,
		document,
		top,
		bottom,
		nodes,
		form,
		link,
		tree: () => renderedTree,
		run,
		emit,
		microtasks,
		counts: () => ({ renders, unmounts }),
	};
}

test('moves intact subtrees once and restores them before cleanup and remount', () => {
	const f = fixture();
	f.run();
	f.run();
	f.emit('htmx:load');
	assert.deepEqual(f.counts(), { renders: 1, unmounts: 0 });
	assert.equal(f.tree().type, 'Card');
	assert.equal(f.tree().props.isBorderless, true);
	assert.equal(f.tree().props.isRounded, false);
	assert.equal(f.top.parent.slot, 'top');
	assert.equal(f.bottom.parent.slot, 'bottom');
	assert.equal(f.nodes.actions.children[0], f.form);
	assert.equal(f.nodes.name.children[0], f.link);
	f.emit('htmx:beforeCleanupElement', f.document);
	assert.deepEqual(f.card.children, [f.top, f.bottom]);
	assert.equal(f.nodes.actions.children[0], f.form);
	assert.equal(f.nodes.name.children[0], f.link);
	f.emit('htmx:load');
	assert.equal(f.top.parent.slot, 'top');
	assert.deepEqual(f.counts(), { renders: 2, unmounts: 1 });
});

test('history saves native subtrees before reenhancing and restores again', () => {
	const f = fixture();
	f.run();
	f.emit('htmx:beforeHistorySave');
	assert.deepEqual(f.card.children, [f.top, f.bottom]);
	f.microtasks.shift()();
	f.emit('htmx:historyRestore');
	assert.equal(f.top.parent.slot, 'top');
	assert.equal(f.nodes.actions.children[0], f.form);
	assert.deepEqual(f.counts(), { renders: 2, unmounts: 1 });
});

test('missing prerequisites and partial failures leave the original subtrees intact', () => {
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
		f.run();
		assert.deepEqual(f.card.children, [f.top, f.bottom]);
		assert.equal(f.nodes.actions.children[0], f.form);
		assert.equal(f.nodes.name.children[0], f.link);
	}
});
