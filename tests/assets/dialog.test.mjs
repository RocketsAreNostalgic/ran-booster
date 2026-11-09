import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-dialog.js', import.meta.url),
	'utf8'
);
const parts = [
	'Root',
	'Popup',
	'Header',
	'Title',
	'Description',
	'Content',
	'CloseIconButton',
	'Portal',
];

function install({ runtime = true, failRender = false, tabs = true } = {}) {
	class Node {
		constructor(name, nodeType = 1) {
			this.name = name;
			this.nodeType = nodeType;
			this.childNodes = [];
			this.parentNode = null;
			this.attributes = new Map();
			this.className = '';
		}
		get isConnected() {
			return this === doc.body || !!this.parentNode?.isConnected;
		}
		contains(node) {
			return (
				this === node ||
				this.childNodes.some((child) => child.contains(node))
			);
		}
		insertBefore(node, before) {
			node.remove();
			const index = this.childNodes.indexOf(before);
			this.childNodes.splice(
				index < 0 ? this.childNodes.length : index,
				0,
				node
			);
			node.parentNode = this;
		}
		appendChild(node) {
			if (node.contains(doc.activeElement)) {
				doc.activeElement = doc.body;
			}
			this.insertBefore(node, null);
		}
		append(...nodes) {
			nodes.forEach((node) => this.appendChild(node));
		}
		remove() {
			if (this.parentNode) {
				const siblings = this.parentNode.childNodes;
				siblings.splice(siblings.indexOf(this), 1);
				this.parentNode = null;
			}
		}
		replaceWith(node) {
			const parent = this.parentNode;
			parent.insertBefore(node, this);
			this.remove();
		}
		setAttribute(name, value) {
			this.attributes.set(name, String(value));
		}
		querySelector(selector) {
			const attribute = selector.match(/^\[([^\]]+)\]$/)?.[1];
			for (const child of this.childNodes) {
				if (attribute && child.attributes.has(attribute)) {
					return child;
				}
				const found = child.querySelector(selector);
				if (found) {
					return found;
				}
			}
			return null;
		}
		focus() {
			doc.activeElement = this;
		}
	}

	const listeners = { window: new Map(), document: new Map() };
	function listen(map) {
		return function (type, fn, options = {}) {
			const list = map.get(type) || [];
			list.push(fn);
			map.set(type, list);
			options.signal?.addEventListener('abort', () => {
				map.set(
					type,
					map.get(type).filter((entry) => entry !== fn)
				);
			});
		};
	}
	const doc = {
		activeElement: null,
		createElement: (name) => new Node(name),
		createComment: (text) => new Node(`#comment ${text}`, 8),
		addEventListener: listen(listeners.document),
	};
	doc.body = new Node('body');
	doc.activeElement = doc.body;
	const own = (node) => {
		node.ownerDocument = doc;
		return node;
	};

	const renders = [];
	let mounted = null;
	let unmounts = 0;
	function elementsOf(tree, type) {
		if (Array.isArray(tree)) {
			return tree.flatMap((child) => elementsOf(child, type));
		}
		if (!tree || typeof tree !== 'object') {
			return [];
		}
		return [
			...(tree.type === type ? [tree] : []),
			...tree.children.flatMap((child) => elementsOf(child, type)),
		];
	}
	const element = {
		createElement(type, props, ...children) {
			return { type, props: props || {}, children };
		},
		createRoot(host) {
			return {
				render(tree) {
					if (failRender) {
						throw new Error('render failed');
					}
					renders.push(tree);
					const portal = elementsOf(tree, 'Popup')[0].props.portal
						.props.container;
					if (!mounted) {
						const close = new Node('button');
						close.setAttribute('data-wp-ui-dialog-close-icon', '');
						portal.append(close);
						mounted = { close, slots: new Map(), host };
					}
					// Every React-owned slot receives its own element.
					elementsOf(tree, 'div')
						.filter((node) => node.props.ref)
						.forEach((node) => {
							const name = `${node.props.className}:${node.props.key ?? ''}`;
							if (!mounted.slots.has(name)) {
								const slot = new Node('div');
								slot.className = node.props.className;
								portal.append(slot);
								mounted.slots.set(name, slot);
							}
							node.props.ref(mounted.slots.get(name));
						});
				},
				unmount() {
					unmounts += 1;
					if (mounted) {
						mounted.close.remove();
						mounted.slots.forEach((slot) => slot.remove());
						mounted = null;
					}
				},
			};
		},
		flushSync: (fn) => fn(),
	};
	const window = {
		addEventListener: listen(listeners.window),
		wp: { element },
	};
	if (runtime) {
		window.ranBoosterUI = {
			version: '0.26.0',
			Dialog: Object.fromEntries(
				[...parts, 'Footer'].map((part) => [part, part])
			),
		};
		if (tabs) {
			window.ranBoosterUI.Tabs = {
				Root: 'Tabs.Root',
				List: 'Tabs.List',
				Tab: 'Tabs.Tab',
				Panel: 'Tabs.Panel',
			};
		}
	}
	vm.runInNewContext(source, { window, AbortController });

	const host = own(new Node('div'));
	host.setAttribute('hidden', '');
	const before = new Node('h2');
	const content = own(new Node('article'));
	const inner = new Node('p');
	content.appendChild(inner);
	const after = new Node('p');
	host.append(before, content, after);
	const trigger = own(new Node('a'));
	doc.body.append(trigger, host);
	trigger.focus();

	// A details host with a banner, two sections, an aside and an action.
	function region(name) {
		const node = own(new Node(name));
		node.appendChild(new Node('p'));
		return node;
	}
	const regions = {
		host: own(new Node('section')),
		banner: region('figure'),
		first: region('div'),
		second: region('div'),
		aside: region('aside'),
		footer: region('div'),
	};
	regions.host.append(
		regions.banner,
		regions.first,
		regions.second,
		regions.aside
	);
	const card = own(new Node('li'));
	card.append(regions.footer);
	doc.body.append(regions.host, card);
	regions.card = card;

	return {
		open: window.ranBoosterOpenDialog,
		regions,
		all: (type) => elementsOf(renders.at(-1), type),
		window,
		doc,
		host,
		content,
		inner,
		trigger,
		renders,
		listeners,
		latest: () => renders.at(-1),
		root: () => renders.at(-1),
		popup: () => elementsOf(renders.at(-1), 'Popup')[0].props,
		text: (type) =>
			elementsOf(renders.at(-1), type).flatMap((node) => node.children),
		unmounts: () => unmounts,
		portals: () =>
			doc.body.childNodes.filter(
				(node) => node.className === 'ran-booster-ui-dialog-portal'
			),
	};
}

test('opening moves the same content node into the Dialog with its title and description', () => {
	const page = install();
	const handle = page.open(page.trigger, page.content, {
		title: 'Bitbucket Cloud',
		description: 'Deploy from Bitbucket.',
		size: 'large',
	});

	assert.ok(handle);
	assert.equal(page.root().type, 'Root');
	assert.equal(page.root().props.open, true);
	assert.equal(page.root().props.disablePointerDismissal, false);
	assert.deepEqual(page.text('Title'), ['Bitbucket Cloud']);
	assert.deepEqual(page.text('Description'), ['Deploy from Bitbucket.']);
	assert.equal(page.popup().size, 'large');
	assert.equal(page.portals().length, 1);
	assert.ok(page.portals()[0].contains(page.content));
	assert.equal(page.content.childNodes[0], page.inner);
	assert.equal(page.host.childNodes[1].nodeType, 8);
	assert.equal(page.host.childNodes.length, 3);
});

test('initial focus defaults to the close button; callers may override focus and pointer dismissal', () => {
	const page = install();
	page.open(page.trigger, page.content, { title: 'Details' });
	const close = page.popup().initialFocus();
	assert.ok(close.attributes.has('data-wp-ui-dialog-close-icon'));

	const custom = () => true;
	page.open(page.trigger, page.content, {
		title: 'Details',
		initialFocus: custom,
	});
	assert.equal(page.popup().initialFocus, custom);

	page.open(page.trigger, page.content, {
		title: 'Form',
		disablePointerDismissal: true,
	});
	assert.equal(page.root().props.disablePointerDismissal, true);
});

test('Escape, backdrop and close button requests close, then restore content intact', () => {
	const page = install();
	page.open(page.trigger, page.content, { title: 'Details' });
	const root = page.root().props;

	// Base UI reports Escape, outside press and the close button the same way.
	root.onOpenChange(false);
	assert.equal(page.root().props.open, false);
	assert.ok(page.portals()[0].contains(page.content), 'kept during exit');

	page.doc.activeElement = page.doc.body;
	assert.equal(page.popup().finalFocus(), page.trigger);

	root.onOpenChangeComplete(false);
	assert.equal(page.unmounts(), 1);
	assert.equal(page.portals().length, 0);
	assert.equal(page.content.parentNode, page.host);
	assert.equal(page.host.childNodes.indexOf(page.content), 1);
	assert.equal(page.host.childNodes.length, 3);
	assert.equal(page.content.childNodes[0], page.inner);
	assert.equal(
		page.doc.body.childNodes.filter((node) => node.name === 'div').length,
		1,
		'only the original details host remains'
	);
});

test('focus returns to a connected trigger only', () => {
	const page = install();
	page.open(page.trigger, page.content, { title: 'Details' });
	assert.equal(page.popup().finalFocus(), false, 'not while open');
	page.root().props.onOpenChange(false);

	const elsewhere = new page.doc.body.constructor('input');
	page.doc.body.appendChild(elsewhere);
	page.doc.activeElement = elsewhere;
	assert.equal(
		page.popup().finalFocus(),
		false,
		'focus moved elsewhere on purpose'
	);

	page.doc.activeElement = page.doc.body;
	page.trigger.remove();
	assert.equal(page.popup().finalFocus(), false, 'trigger disconnected');
});

test('repeated opens reuse the same node and never duplicate portals', () => {
	const page = install();
	for (let index = 0; index < 3; index += 1) {
		const handle = page.open(page.trigger, page.content, {
			title: 'Details',
		});
		assert.equal(page.portals().length, 1);
		handle.close();
		page.root().props.onOpenChangeComplete(false);
		assert.equal(page.portals().length, 0);
		assert.equal(page.content.parentNode, page.host);
	}

	page.open(page.trigger, page.content, { title: 'Details' });
	page.open(page.trigger, page.content, { title: 'Again' });
	assert.equal(page.portals().length, 1, 'a second open replaces the first');
	assert.deepEqual(page.text('Title'), ['Again']);
	assert.equal(page.host.childNodes.length, 3);
});

test('teardown is idempotent and runs on page hide or a page body swap', () => {
	const page = install();
	const handle = page.open(page.trigger, page.content, { title: 'Details' });
	page.listeners.window.get('pagehide').forEach((fn) => fn());
	assert.equal(page.content.parentNode, page.host);
	handle.teardown();
	handle.close();
	assert.equal(page.unmounts(), 1);
	assert.equal(page.listeners.window.get('pagehide').length, 0);

	page.open(page.trigger, page.content, { title: 'Details' });
	page.listeners.document
		.get('htmx:beforeSwap')
		.forEach((fn) =>
			fn({ detail: { target: { id: 'other' }, shouldSwap: true } })
		);
	assert.equal(page.portals().length, 1, 'unrelated swaps are ignored');
	page.listeners.document.get('htmx:beforeSwap').forEach((fn) =>
		fn({
			detail: { target: { id: 'wpbody-content' }, shouldSwap: true },
		})
	);
	assert.equal(page.portals().length, 0);
	assert.equal(page.content.parentNode, page.host);
});

test('a removed origin leaves the content detached instead of misplaced', () => {
	const page = install();
	const handle = page.open(page.trigger, page.content, { title: 'Details' });
	page.host.remove();
	page.host.childNodes.find((node) => node.nodeType === 8).remove();
	handle.teardown();
	assert.equal(page.content.parentNode, null);
});

test('missing components, a missing title or a render failure keep native behaviour', () => {
	const absent = install({ runtime: false });
	assert.equal(
		absent.open(absent.trigger, absent.content, { title: 'Details' }),
		null
	);
	assert.equal(absent.content.parentNode, absent.host);

	const untitled = install();
	assert.equal(untitled.open(untitled.trigger, untitled.content, {}), null);

	const failing = install({ failRender: true });
	assert.equal(
		failing.open(failing.trigger, failing.content, { title: 'Details' }),
		null
	);
	assert.equal(failing.content.parentNode, failing.host);
	assert.equal(failing.portals().length, 0);
	assert.equal(failing.host.childNodes.length, 3);
});

test('only one namespaced function is published', () => {
	const page = install();
	assert.deepEqual(
		Object.keys(page.window).sort(),
		[
			'addEventListener',
			'ranBoosterOpenDialog',
			'ranBoosterUI',
			'wp',
		].sort()
	);
});

function sectionsOf(page, count = 1) {
	return [
		{ id: 'details', label: 'Details', content: page.regions.first },
		{ id: 'changelog', label: 'Changelog', content: page.regions.second },
	].slice(0, count);
}

test('one section renders a tab list with one tab that labels its panel', () => {
	const page = install();
	const handle = page.open(page.trigger, null, {
		title: 'Bitbucket Cloud',
		sections: sectionsOf(page),
	});

	assert.ok(handle);
	const [tabsRoot] = page.all('Tabs.Root');
	assert.equal(tabsRoot.props.defaultValue, 'details');
	const [list] = page.all('Tabs.List');
	assert.equal(list.props.activateOnFocus, true);
	const tabs = page.all('Tabs.Tab');
	assert.deepEqual(
		tabs.map((tab) => [tab.props.value, ...tab.children]),
		[['details', 'Details']]
	);
	const panels = page.all('Tabs.Panel');
	assert.deepEqual(
		panels.map((panel) => [panel.props.value, panel.props.keepMounted]),
		[['details', true]]
	);
	// The tab row sits outside the scrolling Content; the panels inside it.
	assert.equal(page.all('Content')[0].children.length, 2);
	assert.deepEqual(page.all('Tabs.Root')[0].children[1].type, 'Content');
	assert.ok(page.portals()[0].contains(page.regions.first));
	assert.equal(page.regions.second.parentNode, page.regions.host);
});

test('a second section adds a second tab and panel in declaration order', () => {
	const page = install();
	page.open(page.trigger, null, {
		title: 'Bitbucket Cloud',
		sections: sectionsOf(page, 2),
	});

	assert.deepEqual(
		page.all('Tabs.Tab').map((tab) => tab.props.value),
		['details', 'changelog']
	);
	assert.deepEqual(
		page.all('Tabs.Panel').map((panel) => panel.props.value),
		['details', 'changelog']
	);
	assert.ok(page.portals()[0].contains(page.regions.first));
	assert.ok(page.portals()[0].contains(page.regions.second));

	page.root().props.onOpenChange(false);
	page.root().props.onOpenChangeComplete(false);
	assert.deepEqual(page.regions.host.childNodes, [
		page.regions.banner,
		page.regions.first,
		page.regions.second,
		page.regions.aside,
	]);
});

test('banner, aside and footer move into the header, layout and Footer and return on close', () => {
	const page = install();
	page.open(page.trigger, null, {
		title: 'Bitbucket Cloud',
		description: 'Deploy from Bitbucket.',
		sections: sectionsOf(page),
		banner: page.regions.banner,
		aside: page.regions.aside,
		footer: page.regions.footer,
	});

	const [header] = page.all('Header');
	assert.equal(header.props.className, 'ran-booster-ui-dialog__banner');
	assert.equal(
		header.children[0].props.className,
		'ran-booster-ui-dialog__banner-media'
	);
	assert.equal(header.children[1].type, 'Title');
	assert.equal(
		header.children[1].props.className,
		'ran-booster-ui-dialog__banner-title'
	);
	assert.equal(header.children[2].type, 'CloseIconButton');
	const [layout] = page
		.all('div')
		.filter(
			(node) => node.props.className === 'ran-booster-ui-dialog__layout'
		);
	assert.ok(layout);
	assert.equal(page.all('Footer').length, 1);
	assert.deepEqual(page.text('Description'), ['Deploy from Bitbucket.']);
	for (const name of ['banner', 'first', 'aside', 'footer']) {
		assert.ok(page.portals()[0].contains(page.regions[name]), name);
	}
	assert.equal(page.regions.card.childNodes[0].nodeType, 8);

	page.root().props.onOpenChange(false);
	page.root().props.onOpenChangeComplete(false);
	assert.deepEqual(page.regions.card.childNodes, [page.regions.footer]);
	assert.deepEqual(page.regions.host.childNodes, [
		page.regions.banner,
		page.regions.first,
		page.regions.second,
		page.regions.aside,
	]);
	assert.equal(page.portals().length, 0);
});

test('a plain content dialog keeps the plain header and no tabs or footer', () => {
	const page = install();
	page.open(page.trigger, page.content, { title: 'Details' });
	assert.equal(page.all('Header')[0].props.className, undefined);
	assert.equal(page.all('Tabs.Root').length, 0);
	assert.equal(page.all('Footer').length, 0);
});

test('invalid sections or missing Tabs keep native behaviour and move nothing', () => {
	const page = install();
	const invalid = [
		[],
		[{ id: '', label: 'Details', content: page.regions.first }],
		[{ id: 'details', label: '', content: page.regions.first }],
		[{ id: 'details', label: 'Details' }],
		[
			{ id: 'details', label: 'Details', content: page.regions.first },
			{ id: 'details', label: 'Again', content: page.regions.second },
		],
	];
	for (const sections of invalid) {
		assert.equal(
			page.open(page.trigger, null, { title: 'Details', sections }),
			null
		);
	}
	assert.equal(
		page.open(page.trigger, page.content, {
			title: 'Details',
			sections: sectionsOf(page),
		}),
		null,
		'content and sections are exclusive'
	);
	const detached = new page.doc.body.constructor('div');
	detached.ownerDocument = page.doc;
	assert.equal(
		page.open(page.trigger, page.content, {
			title: 'Details',
			footer: detached,
		}),
		null,
		'every moved node must be in the page'
	);
	assert.equal(page.regions.first.parentNode, page.regions.host);
	assert.equal(page.portals().length, 0);

	const tabless = install({ tabs: false });
	assert.equal(
		tabless.open(tabless.trigger, null, {
			title: 'Details',
			sections: sectionsOf(tabless),
		}),
		null
	);
	assert.equal(tabless.regions.first.parentNode, tabless.regions.host);
});
