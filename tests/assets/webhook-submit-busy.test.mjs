import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-enhanced-mutations.js', import.meta.url),
	'utf8'
);
const buttonCss = fs.readFileSync(
	new URL('../../assets/ran-booster/10-buttons.css', import.meta.url),
	'utf8'
);
const mutationCss = fs.readFileSync(
	new URL(
		'../../assets/ran-booster/15-enhanced-mutations.css',
		import.meta.url
	),
	'utf8'
);

function classList() {
	const names = new Set();
	return {
		names,
		add: (...list) => list.forEach((name) => names.add(name)),
		remove: (...list) => list.forEach((name) => names.delete(name)),
		contains: (name) => names.has(name),
	};
}

function element(attributes = {}, text = '') {
	const attrs = new Map(Object.entries(attributes));
	const node = {
		attrs,
		classList: classList(),
		style: { minInlineSize: '' },
		disabled: false,
		children: [],
		_text: text,
		get textContent() {
			return this.children.length
				? this.children.map((child) => child.textContent).join('')
				: this._text;
		},
		set textContent(value) {
			this._text = value;
			this.children = [];
		},
		append(child) {
			this.children.push(child);
		},
		get childNodes() {
			return this.children.length
				? [...this.children]
				: this._text
					? [element({}, this._text)]
					: [];
		},
		replaceChildren(...nodes) {
			this._text = '';
			this.children = nodes;
		},
		getAttribute: (name) => (attrs.has(name) ? attrs.get(name) : null),
		hasAttribute: (name) => attrs.has(name),
		setAttribute: (name, value) => attrs.set(name, String(value)),
		removeAttribute: (name) => attrs.delete(name),
		getBoundingClientRect: () => ({ width: 96.4 }),
	};
	return node;
}

function setup() {
	const listeners = { document: {}, window: {} };
	const buttons = [
		element(
			{
				'data-ran-booster-busy-label': 'Setting up…',
				'data-ran-booster-busy-message': 'Creating the webhook.',
				'aria-disabled': 'false',
			},
			'Set up'
		),
		element(
			{
				'data-ran-booster-busy-label': 'Validating…',
				'data-ran-booster-busy-message': 'Comparing the webhook.',
				'aria-disabled': 'false',
			},
			'Validate'
		),
		element(
			{
				'data-ran-booster-busy-label': 'Removing…',
				'data-ran-booster-busy-message': 'Deleting the webhook.',
				'aria-disabled': 'true',
			},
			'Remove'
		),
	];
	buttons[2].disabled = true;
	const status = element({ 'data-ran-booster-submit-busy-status': '' });
	const form = element({ 'data-ran-booster-submit-busy': '' });
	form.matches = (selector) =>
		selector === 'form[data-ran-booster-submit-busy]';
	form.contains = (node) => buttons.includes(node);
	form.querySelectorAll = (selector) =>
		selector === 'button[type="submit"]' ? buttons : [];
	form.querySelector = (selector) =>
		selector === '[data-ran-booster-submit-busy-status]' ? status : null;

	const document = {
		readyState: 'loading',
		addEventListener: (type, handler) => {
			listeners.document[type] = handler;
		},
		querySelectorAll: (selector) =>
			selector === 'form[data-ran-booster-submit-busy]' ? [form] : [],
		createElement: () => element(),
	};
	const window = {
		addEventListener: (type, handler) => {
			listeners.window[type] = handler;
		},
	};
	Function('window', 'document', source)(window, document);

	function submit(submitter, { defaultPrevented = false } = {}) {
		const event = {
			target: form,
			submitter,
			defaultPrevented,
			preventDefault() {
				this.defaultPrevented = true;
			},
		};
		listeners.document.submit(event);
		return event;
	}

	return { buttons, form, status, listeners, window, submit };
}

test('the pressed button gets the shared busy pattern without being disabled', () => {
	const { buttons, form, status, submit } = setup();
	const event = submit(buttons[1]);

	assert.equal(event.defaultPrevented, false);
	assert.equal(buttons[1].disabled, false, 'Its name and value must submit.');
	assert.equal(buttons[1].getAttribute('aria-busy'), 'true');
	assert.ok(
		buttons[1].classList.contains(
			'ran-booster-enhanced-mutation__submitter--busy'
		)
	);
	assert.ok(buttons[1].classList.contains('ran-booster-update-is-active'));
	assert.equal(buttons[1].textContent, 'Validating…');
	assert.equal(
		buttons[1].children[0].attrs.has('data-ran-booster-submit-busy-label'),
		true
	);
	assert.equal(buttons[1].style.minInlineSize, '96.4px');
	assert.equal(form.getAttribute('aria-busy'), 'true');
	assert.ok(form.classList.contains('ran-booster-enhanced-mutation-is-busy'));
	assert.equal(status.textContent, 'Comparing the webhook.');
	assert.equal(buttons[0].disabled, true);
	assert.equal(buttons[0].getAttribute('aria-disabled'), 'true');
	assert.equal(buttons[0].getAttribute('aria-busy'), null);
	assert.equal(buttons[0].textContent, 'Set up');
});

test('a second submit while busy is cancelled', () => {
	const { buttons, submit } = setup();
	submit(buttons[0]);
	const second = submit(buttons[1]);

	assert.equal(second.defaultPrevented, true);
	assert.equal(buttons[1].getAttribute('aria-busy'), null);
	assert.equal(buttons[1].textContent, 'Validate');
});

test('submissions without a busy-aware submitter or already cancelled are ignored', () => {
	const { buttons, form, submit } = setup();
	submit(null);
	submit(element({}, 'Other'));
	submit(buttons[0], { defaultPrevented: true });

	assert.equal(form.getAttribute('aria-busy'), null);
	assert.equal(buttons[0].textContent, 'Set up');
});

test('pageshow from the back/forward cache restores prior state exactly', () => {
	const { buttons, form, status, listeners, submit } = setup();
	submit(buttons[0]);

	listeners.window.pageshow({ persisted: false });
	assert.equal(
		form.getAttribute('aria-busy'),
		'true',
		'A fresh load keeps its own state.'
	);

	listeners.window.pageshow({ persisted: true });
	assert.equal(form.getAttribute('aria-busy'), null);
	assert.equal(
		form.classList.contains('ran-booster-enhanced-mutation-is-busy'),
		false
	);
	assert.equal(buttons[0].textContent, 'Set up');
	assert.equal(buttons[0].getAttribute('aria-busy'), null);
	assert.equal(
		buttons[0].classList.contains('ran-booster-update-is-active'),
		false
	);
	assert.equal(buttons[0].style.minInlineSize, '');
	assert.equal(buttons[1].disabled, false);
	assert.equal(buttons[1].getAttribute('aria-disabled'), 'false');
	assert.equal(
		buttons[2].disabled,
		true,
		'Rule-disabled buttons stay disabled.'
	);
	assert.equal(buttons[2].getAttribute('aria-disabled'), 'true');
	assert.equal(status.textContent, '');
});

test('the shared busy styling covers the opt-in form and its busy label', () => {
	assert.match(mutationCss, /\[data-ran-booster-submit-busy\]/);
	assert.match(mutationCss, /cursor: progress;\s*pointer-events: none;/);
	assert.match(
		buttonCss,
		/\.ran-booster-update-is-active\s*\[data-ran-booster-submit-busy-label\]\s*\{\s*position: relative;\s*z-index: 1;/
	);
});

const trackView = fs.readFileSync(
	new URL(
		'../../RAN/Admin/ReleaseManagement/ReleaseManagementDisplay.php',
		import.meta.url
	),
	'utf8'
);

test('the release track form reuses the shared busy helper through data attributes', () => {
	assert.match(
		trackView,
		/data-ran-booster-submit-busy' : ''; \?>>/,
		'The managed track form opts in.'
	);
	assert.ok(trackView.includes("__( 'Switching…', 'ran-booster' )"));
	assert.ok(trackView.includes('data-ran-booster-busy-label="<?php'));
	assert.ok(trackView.includes('data-ran-booster-busy-message="<?php'));
	assert.match(trackView, /data-ran-booster-submit-busy-status><\/span>/);
});

test('a busy label swap restores nested markup and the pinned width', () => {
	const { buttons, submit, listeners, status } = setup();
	const inner = element({}, 'Set up');
	buttons[0].replaceChildren(inner);

	submit(buttons[0]);
	assert.equal(buttons[0].textContent, 'Setting up…');
	assert.equal(status.textContent, 'Creating the webhook.');

	listeners.window.pageshow({ persisted: true });
	assert.strictEqual(buttons[0].childNodes[0], inner);
	assert.equal(buttons[0].textContent, 'Set up');
	assert.equal(buttons[0].style.minInlineSize, '');
});

test('the HTMX mutation path applies the same busy label and restores it', () => {
	assert.match(source, /savedLabel: switchScope/);
	assert.match(
		source,
		/restoreBusyLabel\(form, submitter, state\.savedLabel\)/
	);
});

function helperFunction(name) {
	const start = source.indexOf(`\tfunction ${name}(`);
	assert.notEqual(start, -1, `${name} must exist.`);
	const end = source.indexOf('\n\t}\n', start);
	return source.slice(start, end + 4);
}

const busyHelpers = Function(
	'document',
	`"use strict";
${helperFunction('showBusyLabel')}
${helperFunction('busyStatus')}
${helperFunction('restoreBusyLabel')}
return { showBusyLabel, restoreBusyLabel };`
);

test('the busy label opt-in is generic for a plain form submitter and a button-owned HTMX request', () => {
	const helpers = busyHelpers({ createElement: () => element() });
	const status = element({ 'data-ran-booster-submit-busy-status': '' });
	const owner = element();
	owner.querySelector = (selector) =>
		selector === '[data-ran-booster-submit-busy-status]' ? status : null;

	// A plain POST form: the form contains the status.
	const plain = element(
		{
			'data-ran-booster-busy-label': 'Stopping…',
			'data-ran-booster-busy-message': 'Stopping management.',
		},
		'Stop managing'
	);
	const saved = helpers.showBusyLabel(owner, plain);
	assert.equal(plain.textContent, 'Stopping…');
	assert.equal(status.textContent, 'Stopping management.');
	helpers.restoreBusyLabel(owner, plain, saved);
	assert.equal(plain.textContent, 'Stop managing');
	assert.equal(status.textContent, '');

	// An HTMX request owned by the button itself: the status sits in the
	// form the button is associated with through its form attribute.
	const button = element(
		{ 'data-ran-booster-busy-label': 'Saving…' },
		'Save settings'
	);
	button.querySelector = () => null;
	button.form = owner;
	const buttonSaved = helpers.showBusyLabel(button, button);
	assert.equal(button.textContent, 'Saving…');
	assert.equal(status.textContent, 'Saving…', 'The label is the fallback.');
	helpers.restoreBusyLabel(button, button, buttonSaved);
	assert.equal(button.textContent, 'Save settings');
	assert.equal(status.textContent, '');

	// Without a busy label nothing changes.
	assert.equal(helpers.showBusyLabel(owner, element({}, 'Plain')), null);
});
