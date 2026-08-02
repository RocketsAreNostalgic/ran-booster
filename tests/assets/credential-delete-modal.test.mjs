import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-secure-inputs.js', import.meta.url),
	'utf8'
);

function loadPopulateDeleteCredentialModal() {
	const signature =
		'\tfunction populateDeleteCredentialModal(modal, button) {';
	const start = source.indexOf(signature);

	assert.notEqual(
		start,
		-1,
		'The credential deletion modal population function must exist.'
	);

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

	assert.notEqual(
		end,
		-1,
		'The credential deletion modal population function must be complete.'
	);

	return Function(`"use strict"; return (${source.slice(start, end)});`)();
}

function modalFixture() {
	const elements = {
		inUse: toggleableElement(),
		unused: toggleableElement(),
		publicDefault: toggleableElement(),
		packages: toggleableElement(),
		packageList: {
			children: [],
			replaceChildren() {
				this.children = [];
			},
			appendChild(child) {
				this.children.push(child);
			},
		},
		confirm: { disabled: false },
		label: { textContent: '' },
	};
	const idInput = { value: '' };
	const form = {
		elements: {
			'ran_booster[id]': idInput,
		},
		resetCalled: false,
		reset() {
			this.resetCalled = true;
			idInput.value = '';
		},
	};
	const selectors = {
		form,
		'[data-delete-credential-in-use]': elements.inUse,
		'[data-delete-credential-unused]': elements.unused,
		'[data-delete-credential-public-default]': elements.publicDefault,
		'[data-delete-credential-packages]': elements.packages,
		'[data-delete-credential-package-list]': elements.packageList,
		'[data-delete-credential-confirm]': elements.confirm,
		'[data-delete-credential-label]': elements.label,
	};
	const templates = {
		'usage-deployment': {
			content: {
				cloneNode(deep) {
					assert.equal(deep, true);
					return { type: 'package-links' };
				},
			},
		},
	};

	return {
		elements,
		form,
		idInput,
		modal: {
			ownerDocument: {
				getElementById(id) {
					return templates[id] ?? null;
				},
			},
			querySelector(selector) {
				return selectors[selector];
			},
		},
	};
}

function toggleableElement() {
	return {
		hidden: false,
		textContent: '',
		toggleAttribute(attribute, force) {
			assert.equal(attribute, 'hidden');
			this.hidden = force;
		},
	};
}

function deleteButton(attributes) {
	return {
		getAttribute(attribute) {
			return attributes[attribute] ?? null;
		},
	};
}

test('unused credential can be confirmed and shows public lookup fallback', () => {
	const populateDeleteCredentialModal = loadPopulateDeleteCredentialModal();
	const fixture = modalFixture();
	const button = deleteButton({
		'data-id': 'public-search',
		'data-label': 'Public search',
		'data-usage-total': '0',
		'data-usage-listed': '0',
		'data-public-lookup-default': '1',
	});

	populateDeleteCredentialModal(fixture.modal, button);

	assert.equal(fixture.form.resetCalled, true);
	assert.equal(fixture.idInput.value, 'public-search');
	assert.equal(fixture.elements.label.textContent, '“Public search”');
	assert.equal(fixture.elements.unused.hidden, false);
	assert.equal(fixture.elements.inUse.hidden, true);
	assert.equal(fixture.elements.packages.hidden, true);
	assert.deepEqual(fixture.elements.packageList.children, []);
	assert.equal(fixture.elements.publicDefault.hidden, false);
	assert.equal(fixture.elements.confirm.disabled, false);
});

test('credential still in use explains the blocker and disables confirmation', () => {
	const populateDeleteCredentialModal = loadPopulateDeleteCredentialModal();
	const fixture = modalFixture();
	const button = deleteButton({
		'data-id': 'deployment',
		'data-label': 'Deployment',
		'data-usage-total': '2',
		'data-usage-listed': '2',
		'data-usage-template': 'usage-deployment',
		'data-public-lookup-default': '0',
	});

	populateDeleteCredentialModal(fixture.modal, button);

	assert.equal(fixture.elements.unused.hidden, true);
	assert.equal(fixture.elements.inUse.hidden, false);
	assert.match(
		fixture.elements.inUse.textContent,
		/still used by 2 managed packages/
	);
	assert.equal(fixture.elements.packages.hidden, false);
	assert.deepEqual(fixture.elements.packageList.children, [
		{ type: 'package-links' },
	]);
	assert.equal(fixture.elements.publicDefault.hidden, true);
	assert.equal(fixture.elements.confirm.disabled, true);
});

test('switching credentials clears package links that no longer apply', () => {
	const populateDeleteCredentialModal = loadPopulateDeleteCredentialModal();
	const fixture = modalFixture();

	populateDeleteCredentialModal(
		fixture.modal,
		deleteButton({
			'data-id': 'deployment',
			'data-label': 'Deployment',
			'data-usage-total': '2',
			'data-usage-listed': '2',
			'data-usage-template': 'usage-deployment',
			'data-public-lookup-default': '0',
		})
	);
	populateDeleteCredentialModal(
		fixture.modal,
		deleteButton({
			'data-id': 'unused',
			'data-label': 'Unused',
			'data-usage-total': '0',
			'data-usage-listed': '0',
			'data-public-lookup-default': '0',
		})
	);

	assert.equal(fixture.elements.packages.hidden, true);
	assert.deepEqual(fixture.elements.packageList.children, []);
});

test('unverifiable usage fails closed', () => {
	const populateDeleteCredentialModal = loadPopulateDeleteCredentialModal();
	const fixture = modalFixture();
	const button = deleteButton({
		'data-id': 'unknown',
		'data-label': 'Unknown',
		'data-usage-total': '',
		'data-usage-listed': '0',
		'data-public-lookup-default': '0',
	});

	populateDeleteCredentialModal(fixture.modal, button);

	assert.equal(fixture.elements.unused.hidden, true);
	assert.equal(fixture.elements.inUse.hidden, true);
	assert.equal(fixture.elements.packages.hidden, true);
	assert.deepEqual(fixture.elements.packageList.children, []);
	assert.equal(fixture.elements.confirm.disabled, true);
});
