import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-notice.js', import.meta.url),
	'utf8'
);
const php = fs.readFileSync(
	new URL('../../RAN/Admin/Component/NoticeIcon.php', import.meta.url),
	'utf8'
);

// A small element model: enough of the DOM for the helper and no more.
class Element {
	constructor(doc, tag, attributes = {}) {
		this.ownerDocument = doc;
		this.tagName = tag.toUpperCase();
		this.parentElement = null;
		this.children = [];
		this.attributes = new Map(Object.entries(attributes));
		const classes = () =>
			new Set((this.attributes.get('class') || '').split(/\s+/));
		const write = (set) =>
			this.attributes.set('class', [...set].filter(Boolean).join(' '));
		this.classList = {
			add: (...names) => write(new Set([...classes(), ...names])),
			remove: (...names) =>
				write(
					new Set([...classes()].filter((n) => !names.includes(n)))
				),
			contains: (name) => classes().has(name),
		};
	}
	get className() {
		return this.attributes.get('class') || '';
	}
	getAttribute(name) {
		return this.attributes.has(name) ? this.attributes.get(name) : null;
	}
	setAttribute(name, value) {
		this.attributes.set(name, String(value));
	}
	removeAttribute(name) {
		this.attributes.delete(name);
	}
	hasAttribute(name) {
		return this.attributes.has(name);
	}
	append(node) {
		node.remove();
		node.parentElement = this;
		this.children.push(node);
	}
	after(node) {
		node.remove();
		const siblings = this.parentElement.children;
		siblings.splice(siblings.indexOf(this) + 1, 0, node);
		node.parentElement = this.parentElement;
	}
	remove() {
		if (this.parentElement) {
			const siblings = this.parentElement.children;
			siblings.splice(siblings.indexOf(this), 1);
			this.parentElement = null;
		}
	}
	cloneNode(deep) {
		const copy = new Element(
			this.ownerDocument,
			this.tagName,
			Object.fromEntries(this.attributes)
		);
		if (deep) {
			this.children.forEach((child) =>
				copy.append(child.cloneNode(true))
			);
		}
		return copy;
	}
	querySelector(selector) {
		const direct = selector.match(/^:scope > \.([\w-]+)$/);
		if (direct) {
			return (
				this.children.find((child) =>
					child.classList.contains(direct[1])
				) || null
			);
		}
		const keyed = selector.match(/^\[([\w-]+)="([\w-]+)"\]$/);
		assert.ok(keyed, `unsupported selector ${selector}`);
		for (const child of this.children) {
			if (child.getAttribute(keyed[1]) === keyed[2]) {
				return child;
			}
			const found = child.querySelector(selector);
			if (found) {
				return found;
			}
		}
		return null;
	}
}

// Builds the page the way NoticeIcon prints it: one template keyed by intent.
function page({ template = true } = {}) {
	const ids = new Map();
	const doc = {
		getElementById: (id) => ids.get(id) || null,
		createElement: (tag) => new Element(doc, tag),
	};
	if (template) {
		const element = new Element(doc, 'template', {
			id: 'ran-booster-notice-icons',
		});
		element.content = new Element(doc, '#fragment');
		for (const intent of ['info', 'warning', 'success', 'error']) {
			const svg = new Element(doc, 'svg', {
				'data-ran-booster-notice-icon': intent,
				class: 'ran-wp-ui-notice__icon',
				'aria-hidden': 'true',
				focusable: 'false',
			});
			svg.append(new Element(doc, 'path', { d: intent }));
			element.content.append(svg);
		}
		ids.set('ran-booster-notice-icons', element);
	}
	const host = new Element(doc, 'div', {
		class: 'notice notice-warning inline is-dismissible ran-wp-ui-notice ran-wp-ui-box-sizing is-warning',
		'data-ran-booster-ui-notice': '',
		'data-ran-booster-ui-notice-intent': 'warning',
		tabindex: '-1',
	});
	const description = new Element(doc, 'div', {
		class: 'ran-wp-ui-text is-body-md ran-wp-ui-notice__description',
	});
	host.append(description);
	const icon = ids
		.get('ran-booster-notice-icons')
		?.content.querySelector('[data-ran-booster-notice-icon="warning"]')
		.cloneNode(true);
	if (icon) {
		icon.removeAttribute('data-ran-booster-notice-icon');
		host.append(icon);
	}
	const dismiss = new Element(doc, 'button', { class: 'notice-dismiss' });
	host.append(dismiss);
	const window = { document: doc };
	vm.runInNewContext(source, { window });
	return { doc, host, description, dismiss, window, ids };
}

const icon = (host) =>
	host.children.find((child) =>
		child.classList.contains('ran-wp-ui-notice__icon')
	);

test('setIntent changes the state class, core class, attribute and icon together', () => {
	const { host, description, dismiss, window, ids } = page();
	for (const intent of ['error', 'info', 'warning', 'success']) {
		assert.equal(window.ranBoosterNotice.setIntent(host, intent), true);
		const classes = host.className.split(' ');
		assert.deepEqual(
			classes.filter((name) => /^(is|notice)-/.test(name)).sort(),
			['is-dismissible', `is-${intent}`, `notice-${intent}`].sort(),
			intent
		);
		for (const kept of [
			'notice',
			'inline',
			'ran-wp-ui-notice',
			'ran-wp-ui-box-sizing',
		]) {
			assert.ok(classes.includes(kept), `${intent} keeps ${kept}`);
		}
		assert.equal(
			host.getAttribute('data-ran-booster-ui-notice-intent'),
			intent
		);
		const icons = host.children.filter((child) =>
			child.classList.contains('ran-wp-ui-notice__icon')
		);
		assert.equal(icons.length, 1, intent);
		// The icon follows the description, as NoticeIcon prints it.
		assert.deepEqual(host.children, [description, icons[0], dismiss]);
		assert.equal(icons[0].children[0].getAttribute('d'), intent);
		assert.equal(icons[0].getAttribute('aria-hidden'), 'true');
		assert.equal(
			icons[0].hasAttribute('data-ran-booster-notice-icon'),
			false
		);
		// A clone: the template keeps its own icon.
		const original = ids
			.get('ran-booster-notice-icons')
			.content.querySelector(
				`[data-ran-booster-notice-icon="${intent}"]`
			);
		assert.notEqual(icons[0], original);
		assert.equal(
			original.parentElement,
			ids.get('ran-booster-notice-icons').content
		);
	}
});

test('neutral removes the icon and the core intent class', () => {
	const { host, description, dismiss, window } = page();
	assert.equal(window.ranBoosterNotice.setIntent(host, 'neutral'), true);
	assert.equal(icon(host), undefined);
	assert.deepEqual(host.children, [description, dismiss]);
	assert.doesNotMatch(host.className, /notice-(info|warning|success|error)/);
	assert.match(host.className, /(^| )is-neutral( |$)/);
	assert.equal(
		host.getAttribute('data-ran-booster-ui-notice-intent'),
		'neutral'
	);
	window.ranBoosterNotice.setIntent(host, 'error');
	assert.deepEqual(host.children.length, 3);
	assert.doesNotMatch(host.className, /is-neutral/);
});

test('unknown intents and missing hosts change nothing', () => {
	const { host, window } = page();
	const before = host.className;
	for (const intent of ['high', 'Error', '', undefined]) {
		assert.equal(window.ranBoosterNotice.setIntent(host, intent), false);
	}
	assert.equal(window.ranBoosterNotice.setIntent(null, 'error'), false);
	assert.equal(host.className, before);
	assert.equal(
		host.getAttribute('data-ran-booster-ui-notice-intent'),
		'warning'
	);
	assert.equal(icon(host).children[0].getAttribute('d'), 'warning');
});

test('without the template the classes still change and no stale icon stays', () => {
	const { host, window } = page({ template: false });
	assert.equal(window.ranBoosterNotice.setIntent(host, 'error'), true);
	assert.match(host.className, /notice-error/);
	assert.equal(icon(host), undefined);
});

test('setIntent writes no live region, role or focus', () => {
	const { host, window } = page();
	window.ranBoosterNotice.setIntent(host, 'error');
	assert.deepEqual([...host.attributes.keys()].sort(), [
		'class',
		'data-ran-booster-ui-notice',
		'data-ran-booster-ui-notice-intent',
		'tabindex',
	]);
	assert.doesNotMatch(
		source,
		/aria-live|role|\.focus\(|speak|ranBoosterAnnounce\(/
	);
});

test('the template id and icon key match what NoticeIcon prints', () => {
	assert.match(php, /TEMPLATE_ID = 'ran-booster-notice-icons';/);
	assert.match(source, /getElementById\('ran-booster-notice-icons'\)/);
	assert.match(php, /' data-ran-booster-notice-icon="' \. \$intent \. '"'/);
	assert.match(
		source,
		/icon\.removeAttribute\('data-ran-booster-notice-icon'\)/
	);
});

test('create builds the static Notice markup a PHP host prints', () => {
	const { window } = page();
	const { host, content } = window.ranBoosterNotice.create('warning', {
		className: 'ran-booster-release-candidate__warning',
		scope: 'global',
	});
	// Same classes, in the order the PHP hosts print them.
	assert.equal(
		host.className,
		'notice inline ran-wp-ui-notice ran-wp-ui-box-sizing ran-booster-release-candidate__warning is-warning notice-warning'
	);
	assert.equal(host.hasAttribute('data-ran-booster-ui-notice'), true);
	assert.equal(
		host.getAttribute('data-ran-booster-ui-notice-intent'),
		'warning'
	);
	assert.equal(
		host.getAttribute('data-ran-booster-ui-notice-scope'),
		'global'
	);
	assert.equal(
		content.className,
		'ran-wp-ui-text is-body-md ran-wp-ui-notice__description'
	);
	assert.equal(
		content.hasAttribute('data-ran-booster-ui-notice-content'),
		true
	);
	// Content first, then the icon, as NoticeIcon prints it, and no role: the
	// caller announces through ranBoosterAnnounce.
	assert.deepEqual(host.children[0], content);
	assert.equal(host.children.length, 2);
	assert.equal(host.children[1].getAttribute('aria-hidden'), 'true');
	assert.equal(host.children[1].children[0].getAttribute('d'), 'warning');
	assert.equal(host.hasAttribute('role'), false);
	assert.equal(host.hasAttribute('aria-live'), false);
});

test('create omits scope unless asked and draws no icon for neutral or without the template', () => {
	const { window } = page();
	const { host } = window.ranBoosterNotice.create('info');
	assert.equal(host.hasAttribute('data-ran-booster-ui-notice-scope'), false);
	assert.equal(
		host.className,
		'notice inline ran-wp-ui-notice ran-wp-ui-box-sizing is-info notice-info'
	);
	const neutral = window.ranBoosterNotice.create('neutral').host;
	assert.equal(neutral.children.length, 1);
	assert.equal(neutral.className.includes('notice-neutral'), false);
	const bare = page({ template: false }).window.ranBoosterNotice.create(
		'error'
	).host;
	assert.equal(bare.children.length, 1);
	assert.equal(bare.className.endsWith('is-error notice-error'), true);
});
