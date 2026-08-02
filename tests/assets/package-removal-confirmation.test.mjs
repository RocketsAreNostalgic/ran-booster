import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-packages.js', import.meta.url),
	'utf8'
);

function loadFunction(name) {
	const signature = `\tfunction ${name}(`;
	const start = source.indexOf(signature);

	assert.notEqual(start, -1, `The ${name} function must exist.`);

	let depth = 0;
	let end = -1;
	for (
		let index = source.indexOf('{', start);
		index < source.length;
		index++
	) {
		if (source[index] === '{') {
			depth += 1;
		} else if (source[index] === '}') {
			depth -= 1;
			if (depth === 0) {
				end = index + 1;
				break;
			}
		}
	}

	assert.notEqual(end, -1, `The ${name} function must be complete.`);

	return Function(
		'document',
		`"use strict"; return (${source.slice(start, end)});`
	);
}

function removalForm() {
	const listeners = new Map();
	const attributes = new Map();
	const confirmation = {
		checked: false,
		focused: false,
		addEventListener(type, listener) {
			listeners.set(`confirmation:${type}`, listener);
		},
		focus() {
			this.focused = true;
		},
	};
	const submit = { disabled: false };
	const form = {
		dataset: {},
		addEventListener(type, listener) {
			listeners.set(`form:${type}`, listener);
		},
		querySelector(selector) {
			if (selector === '[data-ran-booster-package-removal-confirm]') {
				return confirmation;
			}
			if (selector === '[data-ran-booster-package-removal-submit]') {
				return submit;
			}
			return null;
		},
		setAttribute(name, value) {
			attributes.set(name, String(value));
		},
	};

	return { attributes, confirmation, form, listeners, submit };
}

function environment(forms) {
	return {
		querySelectorAll(selector) {
			assert.equal(
				selector,
				'[data-ran-booster-confirmed-package-removal]'
			);
			return forms.map((fixture) => fixture.form);
		},
	};
}

test('each package-removal submit follows only its own confirmation checkbox', () => {
	const first = removalForm();
	const second = removalForm();
	const initialize = loadFunction('initConfirmedPackageRemovals')(
		environment([first, second])
	);

	initialize();
	assert.equal(first.submit.disabled, true);
	assert.equal(second.submit.disabled, true);

	first.confirmation.checked = true;
	first.listeners.get('confirmation:change')();
	assert.equal(first.submit.disabled, false);
	assert.equal(second.submit.disabled, true);

	first.confirmation.checked = false;
	first.listeners.get('confirmation:change')();
	assert.equal(first.submit.disabled, true);
});

test('an unchecked package-removal submit is blocked and focuses confirmation', () => {
	const fixture = removalForm();
	const initialize = loadFunction('initConfirmedPackageRemovals')(
		environment([fixture])
	);
	initialize();

	let prevented = false;
	fixture.listeners.get('form:submit')({
		preventDefault() {
			prevented = true;
		},
	});

	assert.equal(prevented, true);
	assert.equal(fixture.confirmation.focused, true);
	assert.equal(fixture.submit.disabled, true);
	assert.equal(fixture.attributes.has('aria-busy'), false);
});

test('a confirmed package-removal submit leaves busy ownership to the shared runtime', () => {
	const fixture = removalForm();
	const initialize = loadFunction('initConfirmedPackageRemovals')(
		environment([fixture])
	);
	initialize();
	fixture.confirmation.checked = true;
	fixture.listeners.get('confirmation:change')();

	let prevented = false;
	fixture.listeners.get('form:submit')({
		preventDefault() {
			prevented = true;
		},
	});

	assert.equal(prevented, false);
	assert.equal(fixture.submit.disabled, false);
	assert.equal(fixture.attributes.has('aria-busy'), false);
});

test('package-removal initialization is idempotent', () => {
	const fixture = removalForm();
	const initialize = loadFunction('initConfirmedPackageRemovals')(
		environment([fixture])
	);
	initialize();
	const listenerCount = fixture.listeners.size;
	initialize();

	assert.equal(fixture.listeners.size, listenerCount);
	assert.equal(fixture.form.dataset.ranBoosterPackageRemovalBound, 'true');
});
