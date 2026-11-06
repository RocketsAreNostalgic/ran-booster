import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-settings.js', import.meta.url),
	'utf8'
);
const preference = 'ran_booster[preferences][value]';
const baseline = (value) => JSON.stringify([[preference, value]]);

function harness({ enhanced = true } = {}) {
	const listeners = new Map();
	const windowListeners = new Map();
	const regions = new Map();
	const microtasks = [];
	let confirmResult = false;
	let confirms = 0;
	const on = (map, name, fn) => map.set(name, [...(map.get(name) || []), fn]);
	const fire = (map, name, data = {}) => {
		const event = {
			...data,
			defaultPrevented: false,
			preventDefault() {
				this.defaultPrevented = true;
			},
			stopImmediatePropagation() {},
		};
		for (const fn of map.get(name) || []) {
			fn(event);
		}
		return event;
	};
	const document = {
		activeElement: null,
		body: {},
		addEventListener: (name, fn) => on(listeners, name, fn),
		querySelectorAll: () =>
			[...regions.values()].map((region) => region.form),
		querySelector: () => null,
		getElementById(id) {
			for (const region of regions.values()) {
				if (region.id === id) {
					return region;
				}
				for (const element of [
					region.form.toggle,
					region.form.numeric,
					region.form.save,
					region.form.error,
				]) {
					if (element?.id === id) {
						return element;
					}
				}
			}
			return null;
		},
	};
	const window = {
		document,
		...(enhanced ? { htmx: { process() {} } } : {}),
		DOMParser: class {
			parseFromString(body) {
				const [scope, mode] = body.split(':');
				return {
					getElementById(id) {
						if (
							scope === 'missing' ||
							id !== `ran-booster-general-${scope}`
						) {
							return null;
						}
						return {
							querySelector() {
								return {
									dataset: {
										ranBoosterSettingsScope: scope,
										ranBoosterConfirmed: '0',
										ranBoosterBaseline:
											mode === 'baseline'
												? '[bad'
												: baseline('2000'),
									},
									elements: {
										namedItem() {
											return {
												value:
													mode === 'empty'
														? ''
														: 'valid-token',
											};
										},
									},
									querySelector(selector) {
										if (
											mode === 'error' &&
											selector ===
												'[data-ran-booster-admin-mutation-error]'
										) {
											return null;
										}
										if (
											mode === 'save' &&
											selector ===
												'#ran-booster-save-numeric-bottom'
										) {
											return null;
										}
										return {};
									},
								};
							},
						};
					},
				};
			}
		},
		addEventListener: (name, fn) => on(windowListeners, name, fn),
		confirm() {
			confirms++;
			return confirmResult;
		},
	};
	function make(
		scope,
		{
			value = '2000',
			checked = false,
			confirmed = checked,
			saved = false,
			failed = false,
			token = 'original',
		} = {}
	) {
		const region = {
			id: `ran-booster-general-${scope}`,
			saved,
			contains(element) {
				return element?.form === this.form;
			},
			hasAttribute(name) {
				return name === 'data-ran-booster-general-saved' && this.saved;
			},
			querySelector(selector) {
				if (selector === '[data-ran-booster-settings-scope]') {
					return this.form;
				}
				if (selector.includes(':not([hidden])')) {
					return this.form.error.hidden ? null : this.form.error;
				}
				return null;
			},
		};
		const form = {
			dataset: {
				ranBoosterSettingsScope: scope,
				ranBoosterConfirmed: confirmed ? '1' : '0',
				ranBoosterBaseline: baseline(value),
			},
			parentElement: region,
			ownerDocument: document,
			isConnected: true,
			token,
			value,
			submissions: [],
			attributes: new Map(),
			setAttribute(name, item) {
				this.attributes.set(name, item);
			},
			elements: {
				namedItem(name) {
					if (name === 'ran_booster[token]') {
						return {
							get value() {
								return form.token;
							},
							set value(item) {
								form.token = item;
							},
						};
					}
					if (name === preference) {
						return {
							get value() {
								return form.value;
							},
							set value(item) {
								form.value = item;
							},
						};
					}
					return null;
				},
			},
			classList: {
				values: new Set(),
				toggle(name, enabled) {
					if (enabled) {
						this.values.add(name);
					} else {
						this.values.delete(name);
					}
				},
			},
			closest: () => form,
			querySelector(selector) {
				if (selector === 'input[role="switch"]') {
					return this.toggle;
				}
				if (selector === '[data-ran-booster-admin-mutation-error]') {
					return this.error;
				}
				if (selector === '[data-ran-booster-toggle-save]') {
					return this.retry;
				}
				if (
					selector === '[data-ran-booster-toggle-status]' ||
					selector === '[data-ran-booster-unsaved]'
				) {
					return this.status;
				}
				return null;
			},
			querySelectorAll(selector) {
				return selector === 'input[type="number"]' && this.numeric
					? [this.numeric]
					: [];
			},
			toggleAttribute(name, enabled) {
				this.attributes.set(name, enabled);
			},
			requestSubmit() {
				const event = fire(listeners, 'submit', { target: this });
				if (event.defaultPrevented) {
					return;
				}
				this.submissions.push({
					checked: this.toggle?.checked,
					token: this.token,
					...(this.numeric
						? { value: this.value, disabled: this.numeric.disabled }
						: {}),
				});
			},
		};
		const element = (id) => ({
			id,
			form,
			closest: () => form,
			focus() {
				this.form.ownerDocument.activeElement = this;
			},
		});
		form.save =
			scope === 'numeric'
				? element('ran-booster-save-numeric-bottom')
				: null;
		form.error = {
			...element(`error-${scope}`),
			hidden: !failed,
			setAttribute() {},
		};
		form.status = { textContent: '' };
		form.retry = scope === 'numeric' ? null : { hidden: false };
		form.toggle =
			scope === 'numeric'
				? null
				: { ...element(`toggle-${scope}`), checked, disabled: false };
		form.numeric =
			scope === 'numeric'
				? { ...element('numeric-control'), disabled: false }
				: null;
		region.form = form;
		return region;
	}
	function mount(region) {
		regions.set(region.form.dataset.ranBoosterSettingsScope, region);
		return region.form;
	}
	function replace(region) {
		const old = regions.get(region.form.dataset.ranBoosterSettingsScope);
		old.form.isConnected = false;
		return mount(region);
	}
	const numeric = mount(make('numeric'));
	const toggle = mount(make('hide'));
	const context = {
		document,
		window,
		FormData: class {
			constructor(form) {
				return [
					[preference, form.value],
					['ran_booster[token]', form.token],
				];
			}
		},
		queueMicrotask: (fn) => microtasks.push(fn),
	};
	vm.runInNewContext(source, context);
	return {
		document,
		window,
		numeric,
		toggle,
		make,
		mount,
		replace,
		doc: (name, data) =>
			fire(
				listeners,
				name,
				name === 'htmx:afterSwap' &&
					!data.target &&
					data.detail?.xhr?.scope
					? {
							...data,
							target: {
								id: `ran-booster-general-${data.detail.xhr.scope}`,
							},
						}
					: data
			),
		win: (name, data) => fire(windowListeners, name, data),
		flush: () => {
			while (microtasks.length) {
				microtasks.shift()();
			}
		},
		setConfirm: (value) => {
			confirmResult = value;
		},
		get confirms() {
			return confirms;
		},
		initializeAgain: () => vm.runInNewContext(source, context),
	};
}
const pending = (form) =>
	form.classList.values.has('ran-booster-settings--pending');
function start(h, form) {
	const xhr = {
		scope: form.dataset.ranBoosterSettingsScope,
		responseText: form.dataset.ranBoosterSettingsScope,
		getResponseHeader() {
			return JSON.stringify({
				'ran-booster:admin-mutation-success': { message: 'Saved.' },
			});
		},
	};
	const e = h.doc('htmx:beforeRequest', { detail: { elt: form, xhr } });
	assert.equal(e.defaultPrevented, false);
	return xhr;
}
function swap(h, old, options = {}) {
	const xhr = start(h, old);
	h.doc('htmx:beforeOnLoad', { detail: { xhr } });
	const next = h.replace(
		h.make(old.dataset.ranBoosterSettingsScope, options)
	);
	h.doc('htmx:afterSwap', { detail: { xhr } });
	h.doc('htmx:afterRequest', { detail: { xhr } });
	return next;
}

test('numeric edits and reversion survive successful and failed independent toggle swaps', () => {
	const h = harness();
	h.numeric.value = '2500';
	h.doc('input', { target: h.numeric.numeric });
	assert.equal(pending(h.numeric), true);
	const toggle = swap(h, h.toggle, {
		checked: false,
		confirmed: false,
		saved: true,
		token: 'new',
	});
	assert.equal(pending(h.numeric), true);
	swap(h, toggle, {
		checked: false,
		confirmed: false,
		failed: true,
	});
	assert.equal(pending(h.numeric), true);
	h.numeric.value = '2000';
	h.doc('input', { target: h.numeric.numeric });
	assert.equal(pending(h.numeric), false);
});

test('serializes latest toggle desire while request is in flight', () => {
	const h = harness();
	const toggle = h.toggle;
	toggle.toggle.checked = true;
	h.doc('change', { target: toggle });
	assert.equal(toggle.submissions.length, 1);
	const xhr = start(h, toggle);
	assert.equal(toggle.toggle.disabled, false);
	toggle.toggle.checked = false;
	h.doc('change', { target: toggle });
	assert.equal(toggle.submissions.length, 1);
	h.doc('htmx:beforeOnLoad', { detail: { xhr } });
	const next = h.replace(
		h.make('hide', {
			checked: true,
			confirmed: true,
			saved: true,
			token: 'new',
		})
	);
	h.doc('htmx:afterSwap', { detail: { xhr } });
	h.doc('htmx:afterRequest', { detail: { xhr } });
	h.flush();
	assert.equal(next.toggle.checked, false);
	assert.deepEqual(next.submissions, [{ checked: false, token: 'new' }]);
});

test('unknown outcome retains original token and requires explicit retry', () => {
	const h = harness();
	const t = h.toggle;
	t.toggle.checked = true;
	h.doc('change', { target: t });
	const xhr = start(h, t);
	h.doc('htmx:afterRequest', { detail: { xhr } });
	assert.equal(pending(t), true);
	assert.equal(t.retry.hidden, false);
	assert.match(t.status.textContent, /unconfirmed/i);
	t.toggle.checked = false;
	h.doc('change', { target: t });
	assert.equal(t.submissions.length, 1);
	t.requestSubmit();
	assert.equal(t.submissions[1].token, 'original');
});

test('history restore marks unknown and initialization only registers listeners once', () => {
	const h = harness();
	h.initializeAgain();
	h.doc('htmx:historyRestore', {});
	assert.equal(pending(h.numeric), true);
	assert.equal(pending(h.toggle), true);
	assert.equal(h.toggle.retry.hidden, false);
	const nav = h.doc('htmx:beforeRequest', {
		detail: { elt: {}, target: { contains: () => true }, xhr: {} },
	});
	assert.equal(nav.defaultPrevented, true);
	assert.equal(h.confirms, 1);
});

test('native beforeunload and enhanced navigation protect pending changes', () => {
	const h = harness();
	h.numeric.value = '3000';
	h.doc('input', { target: h.numeric.numeric });
	assert.equal(h.win('beforeunload', {}).defaultPrevented, true);
	const detail = {
		elt: {},
		target: { contains: (form) => form === h.numeric },
		xhr: {},
	};
	assert.equal(
		h.doc('htmx:beforeRequest', { detail }).defaultPrevented,
		true
	);
	h.setConfirm(true);
	assert.equal(
		h.doc('htmx:beforeRequest', { detail }).defaultPrevented,
		false
	);
	const native = harness({ enhanced: false });
	native.numeric.value = '3000';
	native.doc('input', { target: native.numeric.numeric });
	native.doc('submit', { target: native.numeric });
	assert.equal(native.win('beforeunload', {}).defaultPrevented, false);
});

test('late detached request cannot reset replacement baseline', () => {
	const h = harness();
	const xhr = start(h, h.toggle);
	const next = h.replace(h.make('hide', { checked: true, confirmed: false }));
	h.doc('htmx:afterSwap', { detail: { xhr: {} } });
	const late = h.doc('htmx:beforeOnLoad', { detail: { xhr } });
	assert.equal(late.defaultPrevented, true);
	h.doc('htmx:afterRequest', { detail: { xhr } });
	assert.equal(pending(next), true);
});

test('focus follows the replaced control only when it was inside that region', () => {
	const h = harness();
	h.document.activeElement = h.toggle.toggle;
	let next = swap(h, h.toggle, { saved: true });
	assert.equal(h.document.activeElement, next.toggle);
	const outside = { id: 'outside' };
	h.document.activeElement = outside;
	next = swap(h, next, { saved: true });
	assert.equal(h.document.activeElement, outside);
});

test('late swap does not reclaim focus after the user moves elsewhere', () => {
	const h = harness();
	h.document.activeElement = h.toggle.toggle;
	const xhr = start(h, h.toggle);
	h.doc('htmx:beforeOnLoad', { detail: { xhr } });
	const outside = { id: 'outside' };
	h.document.activeElement = outside;
	h.replace(h.make('hide', { saved: true }));
	h.doc('htmx:afterSwap', { detail: { xhr } });
	assert.equal(h.document.activeElement, outside);
});

test('failed swap does not steal focus from outside the region', () => {
	const h = harness();
	const outside = { id: 'outside' };
	h.document.activeElement = outside;
	swap(h, h.toggle, { failed: true });
	assert.equal(h.document.activeElement, outside);
});

test('only persisted pageshow invalidates the cached form', () => {
	const h = harness();
	h.win('pageshow', { persisted: false });
	assert.equal(pending(h.numeric), false);
	h.win('pageshow', { persisted: true });
	assert.equal(pending(h.numeric), true);
	assert.equal(pending(h.toggle), true);
});

test('failed server response retains attempted value and requires explicit retry', () => {
	const h = harness();
	h.toggle.toggle.checked = true;
	h.doc('change', { target: h.toggle.toggle });
	const next = swap(h, h.toggle, {
		checked: true,
		confirmed: false,
		failed: true,
		token: 'original',
	});
	assert.equal(next.toggle.checked, true);
	assert.equal(pending(next), true);
	assert.equal(next.retry.hidden, false);
	next.toggle.checked = false;
	h.doc('change', { target: next.toggle });
	assert.equal(next.submissions.length, 0);
	next.requestSubmit();
	assert.equal(next.submissions[0].token, 'original');
});

test('a numeric save waits behind another scope and freezes its snapshot until dispatch', () => {
	const h = harness();
	const xhr = start(h, h.toggle);
	h.numeric.value = '2700';
	h.doc('input', { target: h.numeric.numeric });
	h.numeric.requestSubmit();
	assert.equal(h.numeric.numeric.disabled, true);
	assert.equal(h.numeric.submissions.length, 0);
	assert.match(h.numeric.status.textContent, /waiting/i);
	h.doc('htmx:beforeOnLoad', { detail: { xhr } });
	h.replace(h.make('hide', { saved: true }));
	h.doc('htmx:afterSwap', { detail: { xhr } });
	assert.equal(h.numeric.numeric.disabled, true);
	h.doc('htmx:afterRequest', { detail: { xhr } });
	h.flush();
	assert.equal(h.numeric.numeric.disabled, false);
	assert.deepEqual(h.numeric.submissions, [
		{
			checked: undefined,
			token: 'original',
			value: '2700',
			disabled: false,
		},
	]);
});

test('a failed or unconfirmed response stops the queue and unlocks numeric controls', () => {
	for (const header of [null, 'valid']) {
		const h = harness();
		const xhr = start(h, h.toggle);
		if (header === null) {
			xhr.getResponseHeader = () => null;
		}
		h.numeric.value = '2600';
		h.numeric.requestSubmit();
		assert.equal(h.numeric.numeric.disabled, true);
		h.doc('htmx:beforeOnLoad', { detail: { xhr } });
		h.replace(
			h.make('hide', { saved: header === null, failed: header !== null })
		);
		h.doc('htmx:afterSwap', { detail: { xhr } });
		h.doc('htmx:afterRequest', { detail: { xhr } });
		h.flush();
		assert.equal(h.numeric.numeric.disabled, false);
		assert.equal(h.numeric.submissions.length, 0);
		assert.match(h.numeric.status.textContent, /unconfirmed/i);
		assert.equal(pending(h.numeric), true);
	}
});

test('unrelated afterRequest does not disrupt an active General request', () => {
	const h = harness();
	const xhr = start(h, h.toggle);
	h.doc('htmx:afterRequest', { detail: { xhr: {} } });
	h.numeric.requestSubmit();
	assert.equal(h.numeric.numeric.disabled, true);
	h.doc('htmx:beforeOnLoad', { detail: { xhr } });
	h.replace(h.make('hide', { saved: true }));
	h.doc('htmx:afterSwap', { detail: { xhr } });
	h.doc('htmx:afterRequest', { detail: { xhr } });
	h.flush();
	assert.equal(h.numeric.submissions.length, 1);
});

test('a canceled native submission does not suppress later leave warnings', () => {
	const h = harness({ enhanced: false });
	h.numeric.value = '2500';
	h.doc('input', { target: h.numeric.numeric });
	h.doc('submit', { target: h.numeric });
	assert.equal(h.win('beforeunload', {}).defaultPrevented, false);
	assert.equal(h.win('beforeunload', {}).defaultPrevented, true);
	assert.equal(h.win('beforeunload', {}).defaultPrevented, true);
});

test('a submission canceled by another handler still warns on the next native leave', () => {
	const h = harness({ enhanced: false });
	h.numeric.value = '2500';
	h.doc('input', { target: h.numeric.numeric });
	const submitEvent = h.doc('submit', { target: h.numeric });
	submitEvent.preventDefault();
	h.flush();
	assert.equal(h.win('beforeunload', {}).defaultPrevented, true);
});

test('saved marker without a valid success header remains unconfirmed', () => {
	for (const header of [null, '{invalid', '{}']) {
		const h = harness();
		h.toggle.toggle.checked = true;
		const xhr = start(h, h.toggle);
		xhr.getResponseHeader = () => header;
		h.doc('htmx:beforeOnLoad', { detail: { xhr } });
		const next = h.replace(
			h.make('hide', { checked: true, confirmed: true, saved: true })
		);
		h.doc('htmx:afterSwap', { detail: { xhr } });
		h.doc('htmx:afterRequest', { detail: { xhr } });
		assert.equal(pending(next), true);
		assert.equal(next.retry.hidden, false);
		assert.match(next.status.textContent, /unconfirmed/i);
	}
});

test('a later conflicting toggle snapshot does not replay the captured intent', () => {
	const h = harness();
	h.toggle.toggle.checked = true;
	const xhr = start(h, h.toggle);
	h.doc('htmx:beforeOnLoad', { detail: { xhr } });
	const next = h.replace(
		h.make('hide', {
			checked: false,
			confirmed: false,
			saved: true,
			token: 'newer',
		})
	);
	h.doc('htmx:afterSwap', { detail: { xhr } });
	h.doc('htmx:afterRequest', { detail: { xhr } });
	h.flush();
	assert.equal(next.toggle.checked, true);
	assert.equal(next.submissions.length, 0);
	assert.equal(next.retry.hidden, false);
	assert.equal(pending(next), true);
	assert.match(next.status.textContent, /needs review/i);
	assert.match(next.error.textContent, /current saved values/i);
	assert.equal(next.token, 'newer');
	assert.equal(
		h.toggle.attributes.has('data-ran-booster-general-unconfirmed'),
		true
	);
});

test('numeric confirmation conflict keeps submitted values and exposes the newer saved snapshot', () => {
	const h = harness();
	h.numeric.value = '2400';
	const xhr = start(h, h.numeric);
	h.doc('htmx:beforeOnLoad', { detail: { xhr } });
	const next = h.replace(
		h.make('numeric', { value: '2600', saved: true, token: 'newer' })
	);
	h.doc('htmx:afterSwap', { detail: { xhr } });
	h.doc('htmx:afterRequest', { detail: { xhr } });
	h.flush();
	assert.equal(next.value, '2400');
	assert.equal(next.token, 'newer');
	assert.equal(pending(next), true);
	assert.match(next.status.textContent, /needs review/i);
	assert.match(next.error.textContent, /2600/);
	assert.equal(next.submissions.length, 0);
});

test('toggle change during flight is not replayed if the saved snapshot conflicts', () => {
	const h = harness();
	h.toggle.toggle.checked = true;
	const xhr = start(h, h.toggle);
	h.toggle.toggle.checked = false;
	h.doc('change', { target: h.toggle.toggle });
	h.doc('htmx:beforeOnLoad', { detail: { xhr } });
	const next = h.replace(
		h.make('hide', {
			checked: false,
			confirmed: false,
			saved: true,
			token: 'newer',
		})
	);
	h.doc('htmx:afterSwap', { detail: { xhr } });
	h.doc('htmx:afterRequest', { detail: { xhr } });
	h.flush();
	assert.equal(next.submissions.length, 0);
	assert.equal(next.retry.hidden, false);
	assert.match(next.status.textContent, /needs review/i);
});

test('unconfirmed numeric response preserves submitted draft and original token', () => {
	const h = harness();
	h.numeric.value = '2500';
	const xhr = start(h, h.numeric);
	xhr.getResponseHeader = () => null;
	h.doc('htmx:beforeOnLoad', { detail: { xhr } });
	const next = h.replace(
		h.make('numeric', { value: '3000', saved: true, token: 'newer' })
	);
	h.doc('htmx:afterSwap', { detail: { xhr } });
	h.doc('htmx:afterRequest', { detail: { xhr } });
	assert.equal(next.value, '2500');
	assert.equal(next.token, 'original');
	assert.equal(pending(next), true);
	assert.match(next.status.textContent, /unconfirmed/i);
});

test('out-of-band and duplicate swaps do not process the General response', () => {
	const h = harness();
	h.toggle.toggle.checked = true;
	const xhr = start(h, h.toggle);
	h.doc('htmx:beforeOnLoad', { detail: { xhr } });
	h.doc('htmx:afterSwap', { target: { id: 'notices' }, detail: { xhr } });
	assert.match(h.toggle.status.textContent, /saving/i);
	const next = h.replace(
		h.make('hide', { checked: true, confirmed: true, saved: true })
	);
	h.doc('htmx:afterSwap', {
		target: { id: 'ran-booster-general-hide' },
		detail: { xhr },
	});
	next.toggle.checked = false;
	h.doc('htmx:afterSwap', {
		target: { id: 'ran-booster-general-hide' },
		detail: { xhr },
	});
	assert.equal(next.toggle.checked, false);
	h.doc('htmx:afterRequest', { detail: { xhr } });
	h.flush();
	assert.equal(next.submissions.length, 0);
});

test('malformed response body or missing token vetoes swap and preserves numeric draft', () => {
	for (const body of [
		'missing',
		'numeric:empty',
		'numeric:error',
		'numeric:save',
		'numeric:baseline',
	]) {
		const h = harness();
		h.numeric.value = '2500';
		const xhr = start(h, h.numeric);
		xhr.responseText = body;
		const event = h.doc('htmx:beforeOnLoad', { detail: { xhr } });
		assert.equal(event.defaultPrevented, true);
		h.doc('htmx:afterRequest', { detail: { xhr } });
		assert.equal(h.numeric.value, '2500');
		assert.equal(h.numeric.token, 'original');
		assert.equal(pending(h.numeric), true);
		assert.match(h.numeric.status.textContent, /unconfirmed/i);
	}
});

test('non-string success message does not confirm a saved marker', () => {
	for (const message of [1, { text: 'saved' }]) {
		const h = harness();
		const xhr = start(h, h.toggle);
		xhr.getResponseHeader = () =>
			JSON.stringify({
				'ran-booster:admin-mutation-success': { message },
			});
		h.doc('htmx:beforeOnLoad', { detail: { xhr } });
		const next = h.replace(h.make('hide', { saved: true, token: 'newer' }));
		h.doc('htmx:afterSwap', { detail: { xhr } });
		h.doc('htmx:afterRequest', { detail: { xhr } });
		assert.equal(pending(next), true);
		assert.equal(next.token, 'original');
		assert.match(next.status.textContent, /unconfirmed/i);
	}
});

test('focused numeric Save restores button or focuses error after busy blur', () => {
	for (const saved of [true, false]) {
		const h = harness();
		h.document.activeElement = h.numeric.save;
		const xhr = start(h, h.numeric);
		h.document.activeElement = h.document.body;
		h.doc('htmx:beforeOnLoad', { detail: { xhr } });
		const next = h.replace(h.make('numeric', { saved, failed: !saved }));
		h.doc('htmx:afterSwap', { detail: { xhr } });
		assert.equal(h.document.activeElement, saved ? next.save : next.error);
	}
});

test('numeric Save does not reclaim focus when user moved outside during flight', () => {
	const h = harness();
	h.document.activeElement = h.numeric.save;
	const xhr = start(h, h.numeric);
	const outside = { id: 'outside' };
	h.document.activeElement = outside;
	h.doc('htmx:beforeOnLoad', { detail: { xhr } });
	h.replace(h.make('numeric', { failed: true }));
	h.doc('htmx:afterSwap', { detail: { xhr } });
	assert.equal(h.document.activeElement, outside);
});
