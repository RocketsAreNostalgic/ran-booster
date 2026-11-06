import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-settings.js', import.meta.url),
	'utf8'
);

function setup(available = true) {
	const listeners = new Map();
	const windowListeners = new Map();
	const on = (map, name, fn) => map.set(name, [...(map.get(name) || []), fn]);
	const fire = (map, name, event) =>
		(map.get(name) || []).forEach((fn) => fn(event));
	let props;
	let unmounts = 0;
	let submissions = 0;
	const input = {};
	const host = { contains: (target) => target === input };
	const row = {
		hidden: false,
		querySelector: () => ({ textContent: 'Preference' }),
	};
	const form = {
		dataset: { ranBoosterSettingsScope: 'hide', ranBoosterConfirmed: '0' },
		isConnected: true,
		classList: { toggle() {} },
		requestSubmit() {
			submissions++;
		},
		querySelector(selector) {
			return (
				{
					'input[role="switch"]': native,
					'[data-ran-booster-core-toggle]': host,
					'[data-ran-booster-native-toggle]': row,
					'[data-ran-booster-admin-mutation-error]': { hidden: true },
				}[selector] || null
			);
		},
	};
	const native = {
		checked: false,
		disabled: false,
		closest: () => form,
		getAttribute: (name) =>
			name === 'aria-describedby' ? 'help error' : null,
		dispatchEvent(event) {
			fire(listeners, event.type, { target: native });
		},
	};
	const document = {
		querySelectorAll: () => [form],
		addEventListener: (name, fn) => on(listeners, name, fn),
	};
	const wp = available
		? {
				components: { ToggleControl: function ToggleControl() {} },
				element: {
					createElement: (component, attributes) => ({
						component,
						attributes,
					}),
					flushSync: (callback) => callback(),
					createRoot: () => ({
						render(element) {
							props = element.attributes;
							props.ref(input);
						},
						unmount() {
							unmounts++;
							props.ref(null);
						},
					}),
				},
			}
		: {};
	const window = {
		wp,
		htmx: { process() {} },
		addEventListener: (name, fn) => on(windowListeners, name, fn),
	};
	class Event {
		constructor(type) {
			this.type = type;
		}
	}
	vm.runInNewContext(source, {
		window,
		document,
		Event,
		FormData: class {},
		queueMicrotask,
	});
	return {
		row,
		native,
		input,
		host,
		form,
		get props() {
			return props;
		},
		get submissions() {
			return submissions;
		},
		get unmounts() {
			return unmounts;
		},
		fire: (name, event) => fire(listeners, name, event),
		windowFire: (name, event) => fire(windowListeners, name, event),
	};
}

test('Core control reflects native field and sends one existing change intent', () => {
	const h = setup();
	assert.equal(h.row.hidden, true);
	assert.equal(h.props.label, 'Preference');
	assert.equal(h.props['aria-describedby'], 'help error');
	assert.equal(h.props.name, undefined);
	assert.equal(h.props.id, undefined);
	h.props.onChange(true);
	assert.equal(h.native.checked, true);
	assert.equal(h.props.checked, true);
	assert.equal(h.submissions, 1);
	h.fire('change', {
		target: Object.assign(h.input, { closest: () => h.form }),
	});
	assert.equal(h.submissions, 1);
});

test('missing component keeps fallback and cleanup restores it before history', () => {
	assert.equal(setup(false).row.hidden, false);
	const h = setup();
	h.fire('htmx:beforeHistorySave', {});
	assert.equal(h.row.hidden, false);
	assert.equal(h.unmounts, 1);
});
