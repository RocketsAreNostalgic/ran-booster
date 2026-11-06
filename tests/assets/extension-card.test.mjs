import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-extension-card.js', import.meta.url),
	'utf8'
);

function fixture({ css = true, runtime = true, fail = false } = {}) {
	const listeners = new Map();
	const microtasks = [];
	let renders = 0;
	let unmounts = 0;
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
	card.lookup = {
		'.plugin-card-top .name.column-name': nodes.name,
		'.plugin-card-top .action-links': nodes.actions,
		'.plugin-card-top .desc.column-description': nodes.description,
		'.plugin-card-bottom .ran-booster-extension-card__metadata':
			nodes.metadata,
		'.plugin-card-bottom .column-compatibility': nodes.compatibility,
	};
	document.readyState = 'complete';
	document.getElementById = () =>
		css ? { sheet: { cssRules: [{}] } } : null;
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
						Card: 'Card',
						CardHeader: 'CardHeader',
						CardBody: 'CardBody',
						CardFooter: 'CardFooter',
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
		run,
		emit,
		microtasks,
		counts: () => ({ renders, unmounts }),
	};
}

test('moves original nodes once, and restores them before cleanup', () => {
	const f = fixture();
	f.run();
	f.run();
	f.emit('htmx:load');
	assert.deepEqual(f.counts(), { renders: 1, unmounts: 0 });
	assert.equal(f.nodes.name.parent.slot, 'name');
	assert.equal(f.nodes.actions.parent.slot, 'actions');
	assert.equal(
		f.card.classes.has('ran-booster-extension-card--wordpress'),
		true
	);
	f.emit('htmx:beforeCleanupElement', f.document);
	assert.deepEqual(f.top.children, [
		f.nodes.name,
		f.nodes.actions,
		f.nodes.description,
	]);
	assert.deepEqual(f.bottom.children, [
		f.nodes.metadata,
		f.nodes.compatibility,
	]);
	assert.deepEqual(f.counts(), { renders: 1, unmounts: 1 });
});

test('history saves native nodes before reenhancing and can restore again', () => {
	const f = fixture();
	f.run();
	f.emit('htmx:beforeHistorySave');
	assert.equal(f.nodes.actions.parent, f.top);
	assert.equal(
		f.card.classes.has('ran-booster-extension-card--wordpress'),
		false
	);
	f.microtasks.shift()();
	f.emit('htmx:historyRestore');
	assert.equal(f.nodes.actions.parent.slot, 'actions');
	assert.deepEqual(f.counts(), { renders: 2, unmounts: 1 });
});

test('missing prerequisites and render failure leave the original card intact', () => {
	for (const options of [
		{ css: false },
		{ runtime: false },
		{ fail: true },
	]) {
		const f = fixture(options);
		f.run();
		assert.equal(f.nodes.name.parent, f.top);
		assert.equal(f.nodes.compatibility.parent, f.bottom);
		assert.equal(
			f.card.classes.has('ran-booster-extension-card--wordpress'),
			false
		);
		assert.equal(f.card.children.length, 2);
	}
});
