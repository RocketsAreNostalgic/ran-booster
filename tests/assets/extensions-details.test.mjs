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

function install({
	helper = true,
	opened = true,
	sections = 'details',
	missing = [],
} = {}) {
	const listeners = new Map();
	const calls = [];
	const base = 'ran-booster-extension-details-ran-booster-bitbucket';
	const description = element({}, { textContent: 'Deploy from Bitbucket.' });
	const section = element(
		{ 'data-ran-booster-dialog-sections': sections },
		{ querySelector: () => description }
	);
	const regions = {
		[base]: section,
		[`${base}-banner`]: element(),
		[`${base}-aside`]: element(),
		[`${base}-section-details`]: element({
			'data-ran-booster-dialog-section-label': 'Details',
		}),
		[`${base}-section-changelog`]: element({
			'data-ran-booster-dialog-section-label': 'Changelog',
		}),
		'ran-booster-extension-action-ran-booster-bitbucket': element(),
	};
	missing.forEach((id) => delete regions[id]);
	const link = element({
		'data-ran-booster-extension-details': 'ran-booster-bitbucket',
		'data-title': 'Bitbucket Cloud',
	});
	link.closest = () => link;
	const document = {
		addEventListener: (type, fn) => listeners.set(type, fn),
		getElementById: (id) => regions[id] ?? null,
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

	return { listeners, calls, link, regions, base, click, spoken };
}

test('a plain activation opens the banner, tab sections, sidebar and card action in the shared Dialog', () => {
	const page = install();
	assert.equal(page.click(), true);
	assert.equal(page.calls.length, 1);
	const [trigger, content, options] = page.calls[0];
	const region = (suffix) => page.regions[page.base + suffix];
	assert.equal(trigger, page.link);
	assert.equal(content, null);
	assert.equal(options.title, 'Bitbucket Cloud');
	assert.equal(options.description, 'Deploy from Bitbucket.');
	assert.equal(options.size, 'large');
	assert.deepEqual(
		Array.from(options.sections, (section) => ({ ...section })),
		[
			{
				id: 'details',
				label: 'Details',
				content: region('-section-details'),
			},
		]
	);
	assert.equal(options.banner, region('-banner'));
	assert.equal(options.aside, region('-aside'));
	assert.equal(
		options.footer,
		page.regions['ran-booster-extension-action-ran-booster-bitbucket']
	);
});

test('sections follow the order the server declares', () => {
	const page = install({ sections: 'details changelog' });
	page.click();
	assert.deepEqual(
		Array.from(page.calls[0][2].sections, (section) => [
			section.id,
			section.label,
		]),
		[
			['details', 'Details'],
			['changelog', 'Changelog'],
		]
	);
});

test('absent optional regions are passed as null for the helper to skip', () => {
	const page = install({
		missing: [
			'ran-booster-extension-details-ran-booster-bitbucket-banner',
			'ran-booster-extension-action-ran-booster-bitbucket',
		],
	});
	page.click();
	assert.equal(page.calls[0][2].banner, null);
	assert.equal(page.calls[0][2].footer, null);
	const unknown = install({ sections: 'details faq' });
	unknown.click();
	assert.equal(unknown.calls[0][2].sections[1].content, null);
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
