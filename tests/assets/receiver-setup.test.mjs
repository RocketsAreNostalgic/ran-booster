import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster.js', import.meta.url),
	'utf8'
);

function extract(name) {
	const start = source.indexOf(`\tfunction ${name}(`);
	assert.notEqual(start, -1, `The ${name} function must exist.`);
	let depth = 0;
	for (let i = source.indexOf('{', start); i < source.length; i++) {
		if (source[i] === '{') {
			depth += 1;
		} else if (source[i] === '}') {
			depth -= 1;
			if (depth === 0) {
				return source.slice(start, i + 1);
			}
		}
	}
	throw new Error(`${name} is incomplete.`);
}

function element(attributes = {}) {
	const attrs = new Map(Object.entries(attributes));
	const classes = new Set(attributes.class ? [attributes.class] : []);
	return {
		hidden: false,
		focused: false,
		classList: {
			remove: (name) => classes.delete(name),
			toggle: (name, on) =>
				on ? classes.add(name) : classes.delete(name),
			contains: (name) => classes.has(name),
		},
		getAttribute: (name) => attrs.get(name) ?? null,
		setAttribute: (name, value) => attrs.set(name, value),
		focus() {
			this.focused = true;
		},
	};
}

function fixture() {
	const automated = element({ class: 'hide-if-js' });
	const manual = element({ class: 'hide-if-js' });
	const panels = { 'panel-a': automated, 'panel-m': manual };
	const tabA = element({
		'aria-controls': 'panel-a',
		'aria-selected': 'true',
	});
	const tabM = element({
		'aria-controls': 'panel-m',
		'aria-selected': 'false',
	});
	const tabs = [tabA, tabM];
	const list = { querySelectorAll: () => tabs };
	const card = { querySelector: (selector) => panels[selector.slice(1)] };
	for (const tab of tabs) {
		tab.closest = (selector) =>
			selector === '[role="tablist"]' ? list : card;
	}
	return { automated, manual, tabA, tabM };
}

const selectReceiverTab = Function(
	`"use strict"; return (${extract('selectReceiverTab')});`
)();

test('selecting a receiver tab swaps the panels and roving tabindex', () => {
	const { automated, manual, tabA, tabM } = fixture();

	selectReceiverTab(tabM, true);

	assert.equal(tabM.getAttribute('aria-selected'), 'true');
	assert.equal(tabM.getAttribute('tabindex'), '0');
	assert.equal(tabM.classList.contains('current'), true);
	assert.equal(tabM.focused, true);
	assert.equal(tabA.getAttribute('aria-selected'), 'false');
	assert.equal(tabA.getAttribute('tabindex'), '-1');
	assert.equal(manual.hidden, false);
	assert.equal(manual.classList.contains('hide-if-js'), false);
	assert.equal(automated.hidden, true);

	selectReceiverTab(tabA, false);

	assert.equal(automated.hidden, false);
	assert.equal(manual.hidden, true);
	assert.equal(tabA.focused, false);
});

test('the receiver button is disabled until a repository is chosen', () => {
	const syncReceiverSubmit = Function(
		`"use strict"; return (${extract('syncReceiverSubmit')});`
	)();
	const button = { disabled: false, textContent: 'Manage webhook' };
	const select = {
		value: '',
		selectedIndex: 0,
		options: [
			{ dataset: {} },
			{ dataset: { ranBoosterWebhookSet: 'true' } },
		],
		form: { querySelector: () => button },
	};

	syncReceiverSubmit(select);
	assert.equal(button.disabled, true);
	assert.equal(button.textContent, 'Manage webhook');

	select.value = 'a';
	select.selectedIndex = 1;
	syncReceiverSubmit(select);
	assert.equal(button.disabled, false);
	assert.equal(button.textContent, 'Manage webhook');
});
