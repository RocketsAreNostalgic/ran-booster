import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-extensions.js', import.meta.url),
	'utf8'
);

function element(attributes = {}, extra = {}) {
	return {
		attributes: new Map(Object.entries(attributes)),
		getAttribute(name) {
			return this.attributes.get(name) ?? null;
		},
		setAttribute(name, value) {
			this.attributes.set(name, String(value));
		},
		hasAttribute(name) {
			return this.attributes.has(name);
		},
		...extra,
	};
}

function install({ helper = true, opened = true } = {}) {
	const listeners = new Map();
	const calls = [];
	const description = element({}, { textContent: 'Deploy from Bitbucket.' });
	const content = element();
	const section = element({}, { querySelector: () => description });
	const link = element({
		'data-ran-booster-extension-details': 'ran-booster-bitbucket',
		'data-title': 'Bitbucket Cloud',
	});
	link.closest = () => link;
	const document = {
		addEventListener: (type, fn) => listeners.set(type, fn),
		getElementById: (id) =>
			({
				'ran-booster-extension-details-ran-booster-bitbucket': section,
				'ran-booster-extension-details-ran-booster-bitbucket-content':
					content,
			})[id] ?? null,
	};
	const spoken = [];
	const window = {
		ranBoosterAnnounce: (message, politeness) =>
			spoken.push({ message, politeness }),
	};
	if (helper) {
		window.ranBoosterOpenDialog = (...args) => {
			calls.push(args);
			return opened ? {} : null;
		};
	}
	vm.runInNewContext(source, { window, document });

	function click(overrides = {}) {
		let prevented = false;
		listeners.get('click')({
			target: { closest: () => link },
			button: 0,
			defaultPrevented: false,
			preventDefault() {
				prevented = true;
			},
			...overrides,
		});
		return prevented;
	}

	return { listeners, calls, link, content, click, spoken };
}

test('a plain activation opens the section content in the shared Dialog', () => {
	const page = install();
	assert.equal(page.click(), true);
	assert.equal(page.calls.length, 1);
	const [trigger, content, options] = page.calls[0];
	assert.equal(trigger, page.link);
	assert.equal(content, page.content);
	assert.deepEqual(
		{ ...options },
		{
			title: 'Bitbucket Cloud',
			description: 'Deploy from Bitbucket.',
			size: 'large',
		}
	);
});

test('modified clicks, other buttons and handled events keep the fragment link', () => {
	const page = install();
	for (const overrides of [
		{ metaKey: true },
		{ ctrlKey: true },
		{ shiftKey: true },
		{ altKey: true },
		{ button: 1 },
		{ defaultPrevented: true },
	]) {
		assert.equal(page.click(overrides), false);
	}
	assert.equal(page.calls.length, 0);
});

test('without the helper or an opened dialog the fragment link follows natively', () => {
	assert.equal(install({ helper: false }).click(), false);
	const declined = install({ opened: false });
	assert.equal(declined.click(), false);
	assert.equal(declined.calls.length, 1);
});

test('an extension action submits once, shows its pending label and announces it', () => {
	const page = install();
	const button = element({ 'data-pending': 'Installing…' });
	button.textContent = 'Install';
	const form = element(
		{},
		{
			matches: (selector) => selector === '.ran-booster-extension-action',
			querySelector: () => button,
		}
	);
	let prevented = 0;
	const submit = () =>
		page.listeners.get('submit')({
			target: form,
			preventDefault() {
				prevented += 1;
			},
		});

	submit();
	assert.equal(prevented, 0);
	assert.equal(button.disabled, true);
	assert.equal(button.textContent, 'Installing…');
	assert.equal(button.getAttribute('aria-label'), 'Installing…');
	assert.equal(form.getAttribute('aria-busy'), 'true');
	assert.equal(form.hasAttribute('role'), false);
	assert.equal(form.hasAttribute('aria-live'), false);
	assert.deepEqual(page.spoken, [
		{ message: 'Installing…', politeness: 'polite' },
	]);
	submit();
	assert.equal(prevented, 1);
	assert.equal(page.spoken.length, 1, 'a repeated submit is not announced');

	const other = element({}, { matches: () => false });
	page.listeners.get('submit')({ target: other });
	assert.equal(other.hasAttribute('aria-busy'), false);
});
