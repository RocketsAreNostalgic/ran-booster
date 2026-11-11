import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-settings.js', import.meta.url),
	'utf8'
);
const announceSource = fs.readFileSync(
	new URL('../../assets/ran-booster-announce.js', import.meta.url),
	'utf8'
);
const preference = 'ran_booster[preferences][value]';
const baseline = (value) => JSON.stringify([[preference, value]]);

function harness({ enhanced = true, initialActive = null } = {}) {
	const listeners = new Map();
	const windowListeners = new Map();
	const regions = new Map();
	const microtasks = [];
	const tasks = [];
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
	const bodyClasses = new Set();
	const document = {
		activeElement: null,
		body: { classList: { add: (name) => bodyClasses.add(name) } },
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
		announcements: [],
		wp: {
			a11y: {
				speak(message, politeness) {
					window.announcements.push({ message, politeness });
				},
			},
		},
		ranBoosterBadge: {
			update(badge, label, intent) {
				badge.textContent = label;
				badge.setAttribute('data-ran-booster-badge-intent', intent);
				badge.events.push({ label, intent });
			},
		},
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
											selector ===
											'[data-ran-booster-toggle-status][data-ran-booster-badge]'
										) {
											if (mode === 'badge-host') {
												return null;
											}
											return {};
										}
										if (
											selector ===
											'[data-ran-booster-admin-mutation-error][data-ran-booster-ui-notice]'
										) {
											if (
												mode === 'error' ||
												mode === 'notice-host'
											) {
												return null;
											}
											return {
												querySelector(child) {
													if (
														child.endsWith(
															'notice-content]'
														)
													) {
														if (
															mode ===
															'notice-content'
														) {
															return null;
														}
														return {
															querySelector:
																() =>
																	[
																		'notice-primary',
																		'notice-sibling',
																	].includes(
																		mode
																	)
																		? null
																		: {},
														};
													}
													if (
														child.endsWith(
															'notice-primary]'
														)
													) {
														return mode ===
															'notice-primary'
															? null
															: {};
													}
													return null;
												},
											};
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
			configNotice = false,
			legacyNotice = false,
			uiMounted = false,
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
			matches: (selector) =>
				selector === ':active' && Boolean(form.active),
			querySelector(selector) {
				if (selector === ':active') {
					return form.active ? { target: form.active } : null;
				}
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
			get isConnected() {
				return form.isConnected;
			},
			focus(options) {
				this.focusOptions = options;
				this.form.ownerDocument.activeElement = this;
			},
		});
		form.save =
			scope === 'numeric'
				? element('ran-booster-save-numeric-bottom')
				: null;
		const primary = {
			textContent: failed ? 'Settings were not saved.' : '',
		};
		const detail = { hidden: false, textContent: 'Current saved value.' };
		const requestField = {
			id: `field-${scope}`,
			textContent: 'Attempted value is invalid.',
		};
		const secondRequestField = {
			id: `field-extra-${scope}`,
			textContent: 'Another invalid value.',
		};
		const configurationField = {
			id: `configuration-${scope}`,
			textContent: 'Configuration diagnostic.',
		};
		const noticeMount = { sentinel: true };
		const content = {
			sentinel: true,
			set textContent(item) {
				throw new Error('Cannot replace notice content: ' + item);
			},
		};
		form.error = {
			...element(`error-${scope}`),
			hidden: !(failed || configNotice),
			primary,
			detail,
			requestField,
			secondRequestField,
			configurationField,
			content,
			mount: noticeMount,
			attributes: new Map(),
			closest(selector) {
				if (
					selector !== '[role="alert"], [role="status"], [aria-live]'
				) {
					return form;
				}
				return this.attributes.has('role') ||
					this.attributes.has('aria-live')
					? this
					: null;
			},
			getAttribute(name) {
				return this.attributes.get(name) ?? null;
			},
			get textContent() {
				return [
					primary.textContent,
					detail.textContent,
					requestField.textContent,
					secondRequestField.textContent,
					configurationField.textContent,
				].join(' ');
			},
			set textContent(item) {
				if (!legacyNotice) {
					throw new Error(
						'Cannot replace notice host children: ' + item
					);
				}
				primary.textContent = item;
			},
			hasAttribute(name) {
				if (name === 'data-ran-booster-ui-notice') {
					return !legacyNotice;
				}
				if (name === 'data-ran-booster-general-failed') {
					return failed;
				}
				return this.attributes.has(name);
			},
			setAttribute(name, item) {
				this.attributes.set(name, item);
			},
			querySelector(selector) {
				if (selector === '[data-ran-booster-ui-notice-primary]') {
					return legacyNotice ? null : primary;
				}
				if (selector === '[data-ran-booster-ui-notice-content]') {
					return legacyNotice ? null : content;
				}
				return null;
			},
			querySelectorAll(selector) {
				if (legacyNotice) {
					return [];
				}
				if (selector === '[data-ran-booster-ui-notice-detail]') {
					return [detail];
				}
				if (selector === '[data-ran-booster-ui-notice-request-field]') {
					return [requestField, secondRequestField];
				}
				return [];
			},
		};
		const fallback = {
			value: '',
			writes: [],
			attributes: new Map(),
			setAttribute(name, item) {
				this.attributes.set(name, item);
			},
			getAttribute(name) {
				return this.attributes.get(name) ?? null;
			},
			removeAttribute(name) {
				this.attributes.delete(name);
			},
			get textContent() {
				return this.value;
			},
			set textContent(item) {
				this.value = item;
				this.writes.push(item);
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
		};
		fallback.events = [];
		form.status = fallback;
		form.retry = scope === 'numeric' ? null : { hidden: false };
		const uiButton = element(`ui-${scope}`);
		const switchHost = {
			hasAttribute: (name) =>
				name === 'data-ran-booster-ui-mounted' && uiMounted,
			contains: (item) => item === uiButton || item === form.toggle,
			querySelector: () => uiButton,
		};
		form.uiButton = uiButton;
		form.toggle =
			scope === 'numeric'
				? null
				: {
						...element(`toggle-${scope}`),
						checked,
						disabled: false,
						events: [],
						closest: (selector) =>
							selector ===
							'[data-ran-booster-ui-switch="general-preference"]'
								? switchHost
								: form,
						dispatchEvent(event) {
							this.events.push({
								type: event.type,
								bubbles: event.bubbles,
								checked: this.checked,
								disabled: this.disabled,
							});
						},
					};
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
	const toggle = mount(make(initialActive ? initialActive.scope : 'hide'));
	if (initialActive) {
		toggle.active = initialActive.target;
	}
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
		CustomEvent: class {
			constructor(type, options) {
				this.type = type;
				this.bubbles = options.bubbles;
			}
		},
		queueMicrotask: (fn) => microtasks.push(fn),
		setTimeout: (fn) => tasks.push(fn),
	};
	vm.runInNewContext(announceSource, { window });
	vm.runInNewContext(source, context);
	return {
		document,
		window,
		bodyClasses,
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
		flushTasks: () => {
			while (tasks.length) {
				tasks.shift()();
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
// Errors are spoken once through the shared helper; the host gains no live
// role, and status changes after a failure stay silent.
const assertSpokenError = (h, error) => {
	assert.equal(error.attributes.has('role'), false);
	assert.equal(error.attributes.has('aria-live'), false);
	assert.deepEqual(
		h.window.announcements
			.filter(({ politeness }) => politeness === 'assertive')
			.at(-1),
		{
			message: (error.primary?.textContent ?? error.textContent).trim(),
			politeness: 'assertive',
		}
	);
};
const assertQuietStatus = (h, status) => {
	assert.equal(status.attributes.has('aria-live'), false);
	assert.equal(h.window.announcements.at(-1)?.politeness, 'assertive');
};
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

test('numeric network uncertainty uses one Notice and preserves stable children and draft', () => {
	const h = harness();
	const form = h.numeric;
	const {
		content,
		mount,
		requestField,
		secondRequestField,
		configurationField,
		detail,
	} = form.error;
	form.value = '2700';
	h.doc('input', { target: form.numeric });
	const xhr = start(h, form);
	h.doc('htmx:afterRequest', { detail: { xhr } });
	assert.equal(form.value, '2700');
	assert.equal(form.token, 'original');
	assert.equal(form.status.textContent, 'Unsaved numeric changes.');
	assertQuietStatus(h, form.status);
	assertSpokenError(h, form.error);
	assert.match(form.error.primary.textContent, /unconfirmed/i);
	assert.equal(form.error.content, content);
	assert.equal(form.error.mount, mount);
	assert.equal(form.error.requestField, requestField);
	assert.equal(requestField.id, 'field-numeric');
	assert.equal(requestField.textContent, '');
	assert.equal(secondRequestField.id, 'field-extra-numeric');
	assert.equal(secondRequestField.textContent, '');
	assert.equal(detail.hidden, true);
	assert.equal(configurationField.textContent, 'Configuration diagnostic.');
	form.requestSubmit();
	assert.equal(form.submissions.at(-1).token, 'original');
	assert.equal(form.error.content, content);
	assert.equal(form.error.mount, mount);
	const retry = start(h, form);
	h.doc('htmx:beforeOnLoad', { detail: { xhr: retry } });
	const confirmed = h.replace(
		h.make('numeric', { value: '2700', saved: true, token: 'next' })
	);
	h.doc('htmx:afterSwap', { detail: { xhr: retry } });
	h.doc('htmx:afterRequest', { detail: { xhr: retry } });
	assert.equal(confirmed.status.textContent, 'No pending numeric changes.');
	assert.equal(confirmed.status.attributes.has('aria-live'), false);
});

test('numeric status and failure each speak once through the shared helper', () => {
	const h = harness();
	const form = h.numeric;
	h.window.announcements.length = 0;
	form.value = '2700';
	h.doc('input', { target: form.numeric });
	assert.deepEqual(h.window.announcements, [
		{ message: 'Unsaved numeric changes.', politeness: 'polite' },
	]);
	const xhr = start(h, form);
	h.window.announcements.length = 0;
	h.doc('htmx:afterRequest', { detail: { xhr } });
	assert.deepEqual(h.window.announcements, [
		{
			message: 'Save unconfirmed. Review and retry.',
			politeness: 'assertive',
		},
	]);
	assert.equal(form.status.attributes.has('aria-live'), false);
	assert.equal(form.status.attributes.has('role'), false);
	assert.equal(form.error.attributes.has('role'), false);
	assert.equal(form.error.attributes.get('tabindex'), '-1');
});

test('a server-rendered alert host announces itself without a second speak', () => {
	const h = harness();
	const form = h.numeric;
	form.error.attributes.set('role', 'alert');
	form.value = '2700';
	h.doc('input', { target: form.numeric });
	const xhr = start(h, form);
	h.window.announcements.length = 0;
	h.doc('htmx:afterRequest', { detail: { xhr } });
	assert.match(form.error.primary.textContent, /unconfirmed/i);
	assert.equal(form.error.attributes.get('role'), 'alert');
	assert.deepEqual(h.window.announcements, []);
});

test('configuration-only Notice remains visible without blocking autosave', () => {
	const h = harness();
	const form = h.mount(h.make('discovery', { configNotice: true }));
	h.doc('htmx:afterSwap', { target: { id: 'other' }, detail: { xhr: {} } });
	assert.equal(form.error.hidden, false);
	assert.equal(
		form.error.hasAttribute('data-ran-booster-general-failed'),
		false
	);
	form.toggle.checked = true;
	h.doc('change', { target: form.toggle });
	assert.equal(form.submissions.length, 1);
	assert.equal(form.retry.hidden, true);
	assert.equal(
		form.error.configurationField.textContent,
		'Configuration diagnostic.'
	);
});

test('malformed Notice host, content or primary vetoes every scoped response', () => {
	for (const scope of ['hide', 'numeric']) {
		for (const missing of [
			'notice-host',
			'notice-content',
			'notice-primary',
			'notice-sibling',
		]) {
			const h = harness();
			const form = scope === 'hide' ? h.toggle : h.numeric;
			if (scope === 'numeric') {
				form.value = '2700';
			} else {
				form.toggle.checked = true;
			}
			const xhr = start(h, form);
			xhr.responseText = `${scope}:${missing}`;
			let headerReads = 0;
			xhr.getResponseHeader = () => {
				++headerReads;
				return JSON.stringify({
					'ran-booster:admin-mutation-success': { message: 'Saved.' },
				});
			};
			assert.equal(
				h.doc('htmx:beforeOnLoad', { detail: { xhr } })
					.defaultPrevented,
				true
			);
			assert.equal(
				headerReads,
				0,
				'Malformed markup is rejected before reading success headers.'
			);
			h.doc('htmx:afterRequest', { detail: { xhr } });
			assert.equal(form.token, 'original');
			assertSpokenError(h, form.error);
			assert.match(form.error.primary.textContent, /unconfirmed/i);
			if (scope === 'numeric') {
				assert.equal(form.value, '2700');
				assertQuietStatus(h, form.status);
			} else {
				assert.equal(form.toggle.checked, true);
				assert.equal(form.status.textContent, 'Disabled');
			}
		}
	}
});

test('legacy cached error without Notice children can report uncertainty', () => {
	const h = harness();
	const legacy = h.mount(h.make('legacy', { legacyNotice: true }));
	h.doc('htmx:afterSwap', { target: { id: 'other' }, detail: { xhr: {} } });
	legacy.toggle.checked = true;
	const xhr = start(h, legacy);
	h.doc('htmx:afterRequest', { detail: { xhr } });
	assert.equal(legacy.status.textContent, 'Disabled');
	assert.match(legacy.error.textContent, /unconfirmed/i);
	assertSpokenError(h, legacy.error);
	assert.equal(legacy.token, 'original');
});

test('numeric confirmation conflict changes primary without replacing field or mount', () => {
	const h = harness();
	h.numeric.value = '2400';
	const xhr = start(h, h.numeric);
	h.doc('htmx:beforeOnLoad', { detail: { xhr } });
	const next = h.replace(
		h.make('numeric', { value: '2600', saved: true, token: 'newer' })
	);
	const {
		content,
		mount,
		requestField,
		secondRequestField,
		configurationField,
		detail,
	} = next.error;
	h.doc('htmx:afterSwap', { detail: { xhr } });
	h.doc('htmx:afterRequest', { detail: { xhr } });
	assert.equal(next.value, '2400');
	assert.equal(next.token, 'newer');
	assert.match(next.error.primary.textContent, /2600/);
	assert.equal(next.error.content, content);
	assert.equal(next.error.mount, mount);
	assert.equal(requestField.id, 'field-numeric');
	assert.equal(requestField.textContent, '');
	assert.equal(secondRequestField.id, 'field-extra-numeric');
	assert.equal(secondRequestField.textContent, '');
	assert.equal(detail.hidden, true);
	assert.equal(configurationField.textContent, 'Configuration diagnostic.');
	assertQuietStatus(h, next.status);
});

test('Save setting is hidden only when enhanced saving is available', () => {
	assert.equal(harness().toggle.retry.hidden, true);
	const native = harness({ enhanced: false });
	assert.equal(native.toggle.retry.hidden, false);
});

test('the script releases the first-paint hold and leaves Save visible without HTMX', () => {
	assert.equal(harness().bodyClasses.has('ran-booster-settings-ready'), true);
	const native = harness({ enhanced: false });
	assert.equal(native.bodyClasses.has('ran-booster-settings-ready'), true);
	assert.equal(native.toggle.retry.hidden, false);
});

test('badge remains confirmed during pending work and adopts the successful projection', () => {
	const h = harness();
	assert.equal(h.toggle.status.textContent, 'Disabled');
	h.toggle.toggle.checked = true;
	h.doc('change', { target: h.toggle.toggle });
	const xhr = start(h, h.toggle);
	assert.equal(h.toggle.status.textContent, 'Disabled');
	assert.ok(h.toggle.status.writes.every((item) => item === 'Disabled'));
	assert.equal(h.toggle.status.events.at(-1).label, 'Disabled');
	assert.equal(h.toggle.status.events.at(-1).intent, 'none');
	h.doc('htmx:beforeOnLoad', { detail: { xhr } });
	const next = h.replace(
		h.make('hide', { checked: true, confirmed: true, saved: true })
	);
	h.doc('htmx:afterSwap', { detail: { xhr } });
	h.doc('htmx:afterRequest', { detail: { xhr } });
	assert.equal(next.status.textContent, 'Enabled');
	assert.equal(next.status.events.at(-1).intent, 'stable');
	assert.equal(
		next.status.getAttribute('data-ran-booster-badge-intent'),
		'stable'
	);
});

test('failure preserves newer opposite and B to A to B intent independently of confirmed badge', () => {
	for (const sequence of [[false], [false, true]]) {
		const h = harness();
		h.toggle.toggle.checked = true;
		h.doc('change', { target: h.toggle.toggle });
		const xhr = start(h, h.toggle);
		for (const desired of sequence) {
			h.toggle.toggle.checked = desired;
			h.doc('change', { target: h.toggle.toggle });
		}
		h.doc('htmx:beforeOnLoad', { detail: { xhr } });
		const confirmed = sequence.length === 1;
		const next = h.replace(
			h.make('hide', {
				checked: confirmed,
				confirmed,
				failed: true,
				token: 'current',
			})
		);
		h.doc('htmx:afterSwap', { detail: { xhr } });
		h.doc('htmx:afterRequest', { detail: { xhr } });
		h.flush();
		assert.equal(next.toggle.checked, sequence.at(-1));
		assert.equal(
			next.status.textContent,
			confirmed ? 'Enabled' : 'Disabled'
		);
		assert.equal(next.error.hidden, false);
		assert.equal(next.submissions.length, 0);
		assert.equal(next.token, 'current');
	}
});

test('lost explicit retry replaces a previous rejection with an unconfirmed alert', () => {
	const h = harness();
	h.toggle.toggle.checked = true;
	h.doc('change', { target: h.toggle.toggle });
	const rejected = start(h, h.toggle);
	h.doc('htmx:beforeOnLoad', { detail: { xhr: rejected } });
	const next = h.replace(
		h.make('hide', {
			checked: true,
			confirmed: false,
			failed: true,
			token: 'current',
		})
	);
	h.doc('htmx:afterSwap', { detail: { xhr: rejected } });
	h.doc('htmx:afterRequest', { detail: { xhr: rejected } });
	assert.equal(next.error.primary.textContent, 'Settings were not saved.');
	assert.equal(next.error.hidden, false);
	assert.equal(next.toggle.checked, false);
	assert.equal(next.status.textContent, 'Disabled');

	next.toggle.checked = true;
	h.doc('change', { target: next.toggle });
	next.requestSubmit();
	assert.equal(next.submissions.at(-1).token, 'current');
	const retry = start(h, next);
	h.doc('htmx:afterRequest', { detail: { xhr: retry } });

	assert.match(next.error.textContent, /save unconfirmed/i);
	assert.equal(next.error.hidden, false);
	assertSpokenError(h, next.error);
	assert.equal(next.status.textContent, 'Disabled');
	assert.equal(next.toggle.checked, true);
	assert.equal(next.token, 'current');
	assert.equal(next.retry.hidden, false);
});

test('postcommit cleanup failure shows confirmed new badge without a success', () => {
	const h = harness();
	h.toggle.toggle.checked = true;
	h.doc('change', { target: h.toggle.toggle });
	const xhr = start(h, h.toggle);
	xhr.getResponseHeader = () => null;
	h.doc('htmx:beforeOnLoad', { detail: { xhr } });
	const next = h.replace(
		h.make('hide', {
			checked: true,
			confirmed: true,
			failed: true,
			token: 'committed',
		})
	);
	h.doc('htmx:afterSwap', { detail: { xhr } });
	h.doc('htmx:afterRequest', { detail: { xhr } });
	assert.equal(next.status.textContent, 'Enabled');
	assert.equal(next.toggle.checked, true);
	assert.equal(next.retry.hidden, false);
	assert.equal(next.error.hidden, false);
	assert.equal(next.token, 'committed');
});

test('response lacking both a success marker and error stays unconfirmed with original fence', () => {
	const h = harness();
	h.toggle.toggle.checked = true;
	h.doc('change', { target: h.toggle.toggle });
	const xhr = start(h, h.toggle);
	h.doc('htmx:beforeOnLoad', { detail: { xhr } });
	const next = h.replace(
		h.make('hide', { checked: true, confirmed: true, token: 'newer' })
	);
	h.doc('htmx:afterSwap', { detail: { xhr } });
	h.doc('htmx:afterRequest', { detail: { xhr } });
	assert.equal(next.status.textContent, 'Disabled');
	assert.equal(next.toggle.checked, true);
	assert.equal(next.token, 'original');
	assert.equal(next.retry.hidden, false);
	assert.equal(next.error.hidden, false);
	assertSpokenError(h, next.error);
	assert.match(next.error.textContent, /unconfirmed/i);
});

test('malformed toggle responses retain badge, original token and retryable alert', () => {
	for (const body of [
		'missing',
		'hide:empty',
		'hide:error',
		'hide:baseline',
	]) {
		const h = harness();
		h.toggle.toggle.checked = true;
		h.doc('change', { target: h.toggle.toggle });
		const xhr = start(h, h.toggle);
		xhr.responseText = body;
		assert.equal(
			h.doc('htmx:beforeOnLoad', { detail: { xhr } }).defaultPrevented,
			true
		);
		h.doc('htmx:afterRequest', { detail: { xhr } });
		assert.equal(h.toggle.status.textContent, 'Disabled');
		assert.equal(h.toggle.toggle.checked, true);
		assert.equal(h.toggle.token, 'original');
		assert.equal(h.toggle.error.hidden, false);
		assertSpokenError(h, h.toggle.error);
		assert.equal(h.toggle.retry.hidden, false);
		h.toggle.requestSubmit();
		assert.equal(h.toggle.submissions.at(-1).token, 'original');
	}
});

test('stopped queue retains waiting toggle intent and numeric draft', () => {
	const h = harness();
	const other = h.mount(h.make('discovery'));
	h.doc('htmx:afterSwap', { target: { id: 'other' }, detail: { xhr: {} } });
	const xhr = start(h, h.toggle);
	other.toggle.checked = true;
	h.doc('change', { target: other.toggle });
	h.numeric.value = '2700';
	h.doc('input', { target: h.numeric.numeric });
	h.numeric.requestSubmit();
	h.doc('htmx:beforeOnLoad', { detail: { xhr } });
	h.replace(h.make('hide', { failed: true }));
	h.doc('htmx:afterSwap', { detail: { xhr } });
	h.doc('htmx:afterRequest', { detail: { xhr } });
	h.flush();
	assert.equal(other.toggle.checked, true);
	assert.equal(other.status.textContent, 'Disabled');
	assertSpokenError(h, other.error);
	assert.equal(other.retry.hidden, false);
	assert.equal(other.submissions.length, 0);
	assert.equal(h.numeric.value, '2700');
	assert.equal(h.numeric.numeric.disabled, false);
	assert.equal(h.numeric.submissions.length, 0);
});

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
	h.doc('change', { target: toggle.toggle });
	assert.equal(toggle.submissions.length, 1);
	const xhr = start(h, toggle);
	assert.equal(toggle.toggle.disabled, false);
	toggle.toggle.checked = false;
	h.doc('change', { target: toggle.toggle });
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
	h.doc('change', { target: t.toggle });
	const xhr = start(h, t);
	h.doc('htmx:afterRequest', { detail: { xhr } });
	assert.equal(pending(t), true);
	assert.equal(t.retry.hidden, false);
	assert.equal(t.status.textContent, 'Disabled');
	assert.match(t.error.textContent, /unconfirmed/i);
	assertSpokenError(h, t.error);
	t.toggle.checked = false;
	h.doc('change', { target: t.toggle });
	assert.equal(t.submissions.length, 1);
	t.requestSubmit();
	assert.equal(t.submissions[1].token, 'original');
});

test('an unconfirmed save opens its collapsed Card so the error is never hidden', () => {
	const h = harness();
	const t = h.toggle;
	// The fixture form stands in for the Card: its header toggle is the collapsed Card's trigger.
	let opened = 0;
	const lookup = t.querySelector.bind(t);
	t.querySelector = (selector) =>
		selector === '[role="button"]'
			? { getAttribute: () => 'false', click: () => opened++ }
			: lookup(selector);
	t.toggle.checked = true;
	h.doc('change', { target: t.toggle });
	const xhr = start(h, t);
	assert.equal(opened, 0);
	h.doc('htmx:afterRequest', { detail: { xhr } });
	assert.match(t.error.textContent, /unconfirmed/i);
	assert.equal(opened, 1);
});

test('an invalid field or a deep link opens a collapsed Settings Card, an open one and a Card header are left alone', () => {
	const h = harness();
	const card = (expanded, native = false) => {
		const host = {
			parentElement: null,
			opened: 0,
			details: { open: false },
			querySelector(selector) {
				if (selector === '[role="button"]') {
					return native
						? null
						: {
								getAttribute: () => String(expanded),
								click: () => {
									host.opened++;
								},
							};
				}
				return native ? host.details : null;
			},
		};
		host.control = { closest: () => host };
		return host;
	};
	// Mounted and collapsed: the invalid control's Card header toggle is activated once.
	const collapsed = card(false);
	h.doc('invalid', { target: collapsed.control });
	assert.equal(collapsed.opened, 1);
	// Mounted and open: nothing happens.
	const open = card(true);
	h.doc('invalid', { target: open.control });
	assert.equal(open.opened, 0);
	// Before the public Disclosure mounts, the native details opens.
	const native = card(false, true);
	h.doc('invalid', { target: native.control });
	assert.equal(native.details.open, true);
	// A Card's own header is always visible: an element that is the Card does not open it.
	const own = card(false);
	h.doc('invalid', { target: own });
	assert.equal(own.opened, 0);
	// A control outside any Card, and an event without a target, are ignored.
	h.doc('invalid', { target: { closest: () => null } });
	h.doc('invalid', {});
	// A deep link into a collapsed Card opens it and scrolls to the target.
	const linked = card(false);
	let scrolled = 0;
	linked.control.scrollIntoView = () => {
		scrolled++;
	};
	h.window.location = { hash: '#ran-booster-general-numeric' };
	h.document.getElementById = (id) =>
		id === 'ran-booster-general-numeric' ? linked.control : null;
	h.win('hashchange', {});
	assert.equal(linked.opened, 1);
	assert.equal(scrolled, 1);
	// A fragment that is not an element, or is malformed, changes nothing.
	h.window.location = { hash: '#missing' };
	h.win('hashchange', {});
	h.window.location = { hash: '#%E0%A4%A' };
	h.win('hashchange', {});
	assert.equal(linked.opened, 1);
});

test('pending numeric edits keep the leave protection and their value whatever state the Card is in', () => {
	const h = harness();
	h.numeric.value = '3000';
	h.doc('input', { target: h.numeric.numeric });
	// The protection reads the form's state, never the Card: it is the same with no Card, open or collapsed.
	assert.equal(h.win('beforeunload', {}).defaultPrevented, true);
	assert.equal(pending(h.numeric), true);
	assert.equal(h.numeric.value, '3000');
	assert.equal(h.numeric.status.textContent, 'Unsaved numeric changes.');
});

test('history restore of an idle form shows persisted state and no warning, and initialization registers listeners once', () => {
	const h = harness();
	h.initializeAgain();
	h.window.announcements.length = 0;
	h.doc('htmx:historyRestore', {});
	for (const form of [h.numeric, h.toggle]) {
		assert.equal(pending(form), false);
		assert.equal(form.error.hidden, true);
	}
	assert.equal(h.numeric.status.textContent, 'No pending numeric changes.');
	assert.deepEqual(
		h.window.announcements.filter(
			({ politeness }) => politeness === 'assertive'
		),
		[]
	);
	const nav = h.doc('htmx:beforeRequest', {
		detail: { elt: {}, target: { contains: () => true }, xhr: {} },
	});
	assert.equal(nav.defaultPrevented, false);
	assert.equal(h.confirms, 0);
});

test('Back after a completed save does not turn it unconfirmed (pageshow and history restore)', () => {
	for (const restore of [
		(h) => h.win('pageshow', { persisted: true }),
		(h) => h.doc('htmx:historyRestore', {}),
	]) {
		const h = harness();
		h.numeric.value = '2700';
		h.doc('input', { target: h.numeric.numeric });
		const saved = swap(h, h.numeric, {
			value: '2700',
			saved: true,
			token: 'next',
		});
		assert.equal(pending(saved), false);
		h.window.announcements.length = 0;
		restore(h);
		assert.equal(pending(saved), false);
		assert.equal(saved.error.hidden, true);
		assert.deepEqual(
			h.window.announcements.filter(
				({ politeness }) => politeness === 'assertive'
			),
			[]
		);
	}
});

test('Back to an idle form keeps genuine unsaved numeric edits dirty without an unconfirmed warning', () => {
	const h = harness();
	h.numeric.value = '2700';
	h.doc('input', { target: h.numeric.numeric });
	h.win('pageshow', { persisted: true });
	assert.equal(pending(h.numeric), true);
	assert.equal(h.numeric.status.textContent, 'Unsaved numeric changes.');
	assert.equal(h.numeric.error.hidden, true);
});

test('a request interrupted by leaving still restores as unconfirmed', () => {
	for (const restore of [
		(h) => h.win('pageshow', { persisted: true }),
		(h) => h.doc('htmx:historyRestore', {}),
	]) {
		const h = harness();
		h.numeric.value = '2700';
		h.doc('input', { target: h.numeric.numeric });
		start(h, h.numeric);
		restore(h);
		assert.equal(pending(h.numeric), true);
		assert.equal(h.numeric.error.hidden, false);
		assert.match(h.numeric.error.primary.textContent, /unconfirmed/i);
		// The untouched sibling scope is not blamed for it.
		assert.equal(h.toggle.error.hidden, true);
		assert.equal(pending(h.toggle), false);
	}
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

test('only the canonical checkbox creates an intent and publishes its current state', () => {
	const h = harness();
	const native = h.toggle.toggle;
	assert.deepEqual(native.events.at(-1), {
		type: 'ran-booster:ui-switch-update',
		bubbles: true,
		checked: false,
		disabled: false,
	});
	const internal = { closest: () => h.toggle };
	h.doc('change', { target: internal });
	assert.equal(h.toggle.submissions.length, 0);
	native.checked = true;
	h.doc('change', { target: native });
	assert.equal(h.toggle.submissions.length, 1);
	assert.equal(native.events.at(-1).checked, true);
	native.disabled = true;
	h.doc('change', { target: native });
	assert.equal(native.events.at(-1).disabled, true);
});

test('switch focus maps to mounted UI or the native fallback after replacement', () => {
	for (const uiMounted of [true, false]) {
		const h = harness();
		h.document.activeElement = h.toggle.uiButton;
		const next = swap(h, h.toggle, { saved: true, uiMounted });
		assert.equal(
			h.document.activeElement,
			uiMounted ? next.uiButton : next.toggle
		);
	}
});

test('HTMX-focused current native replacement sends failed switch response to Notice', () => {
	const h = harness();
	h.document.activeElement = h.toggle.uiButton;
	h.toggle.toggle.checked = true;
	h.doc('change', { target: h.toggle.toggle });
	const xhr = start(h, h.toggle);
	h.toggle.toggle.checked = false;
	h.doc('change', { target: h.toggle.toggle });
	h.toggle.toggle.checked = true;
	h.doc('change', { target: h.toggle.toggle });
	h.doc('htmx:beforeOnLoad', { detail: { xhr } });
	const next = h.replace(h.make('hide', { confirmed: false, failed: true }));
	h.document.activeElement = next.toggle;
	h.doc('htmx:afterSwap', { detail: { xhr } });
	assert.equal(h.document.activeElement, next.error);
	assert.equal(next.toggle.checked, true);
	assert.equal(next.status.textContent, 'Disabled');
	assert.equal(h.toggle.submissions.length, 1);
});

test('HTMX-focused current native replacement follows success into the mounted switch', () => {
	const h = harness();
	h.document.activeElement = h.toggle.uiButton;
	const xhr = start(h, h.toggle);
	h.doc('htmx:beforeOnLoad', { detail: { xhr } });
	const next = h.replace(h.make('hide', { saved: true, uiMounted: true }));
	h.document.activeElement = next.toggle;
	h.doc('htmx:afterSwap', { detail: { xhr } });
	assert.equal(h.document.activeElement, next.uiButton);
	assert.equal(next.uiButton.focusOptions.preventScroll, true);
});

test('a different control with the same id cannot claim replacement focus', () => {
	const h = harness();
	h.document.activeElement = h.toggle.uiButton;
	const xhr = start(h, h.toggle);
	h.doc('htmx:beforeOnLoad', { detail: { xhr } });
	const next = h.replace(h.make('hide', { failed: true }));
	const outside = { id: next.toggle.id };
	h.document.activeElement = outside;
	h.doc('htmx:afterSwap', { detail: { xhr } });
	assert.equal(h.document.activeElement, outside);
});

test('replacement never reclaims public switch focus after an outside move', () => {
	const h = harness();
	h.document.activeElement = h.toggle.uiButton;
	const xhr = start(h, h.toggle);
	h.doc('htmx:beforeOnLoad', { detail: { xhr } });
	const outside = { id: 'outside' };
	h.document.activeElement = outside;
	h.replace(h.make('hide', { saved: true, uiMounted: true }));
	h.doc('htmx:afterSwap', { detail: { xhr } });
	assert.equal(h.document.activeElement, outside);
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

test('only persisted pageshow re-evaluates the cached form', () => {
	const h = harness();
	h.numeric.value = '2700';
	h.doc('input', { target: h.numeric.numeric });
	start(h, h.numeric);
	h.win('pageshow', { persisted: false });
	assert.equal(h.numeric.error.hidden, true);
	h.win('pageshow', { persisted: true });
	assert.equal(h.numeric.error.hidden, false);
	assert.equal(pending(h.toggle), false);
});

test('failed server response restores confirmed value and requires explicit retry', () => {
	const h = harness();
	h.toggle.toggle.checked = true;
	h.doc('change', { target: h.toggle.toggle });
	const next = swap(h, h.toggle, {
		checked: true,
		confirmed: false,
		failed: true,
		token: 'original',
	});
	assert.equal(next.toggle.checked, false);
	assert.equal(pending(next), false);
	assert.equal(next.status.textContent, 'Disabled');
	assert.equal(next.error.hidden, false);
	assert.equal(next.retry.hidden, false);
	next.toggle.checked = true;
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
		assert.match(h.numeric.error.primary.textContent, /unconfirmed/i);
		assertQuietStatus(h, h.numeric.status);
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
		assert.equal(next.status.textContent, 'Disabled');
		assert.match(next.error.textContent, /unconfirmed/i);
		assertSpokenError(h, next.error);
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
	assert.equal(next.toggle.checked, false);
	assert.equal(next.submissions.length, 0);
	assert.equal(next.retry.hidden, false);
	assert.equal(pending(next), false);
	assert.equal(next.status.textContent, 'Disabled');
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
	assert.equal(next.status.textContent, 'Unsaved numeric changes.');
	assertQuietStatus(h, next.status);
	assert.match(next.error.primary.textContent, /2600/);
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
	assert.equal(next.status.textContent, 'Disabled');
	assert.match(next.error.textContent, /current saved values/i);
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
	assert.match(next.error.primary.textContent, /unconfirmed/i);
	assertQuietStatus(h, next.status);
});

test('out-of-band and duplicate swaps do not process the General response', () => {
	const h = harness();
	h.toggle.toggle.checked = true;
	const xhr = start(h, h.toggle);
	h.doc('htmx:beforeOnLoad', { detail: { xhr } });
	h.doc('htmx:afterSwap', { target: { id: 'notices' }, detail: { xhr } });
	assert.equal(h.toggle.status.textContent, 'Disabled');
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
		assert.match(h.numeric.error.primary.textContent, /unconfirmed/i);
		assertQuietStatus(h, h.numeric.status);
	}
});

test('malformed badge response is vetoed before swap', () => {
	for (const mode of ['badge-host']) {
		const h = harness();
		const xhr = start(h, h.toggle);
		xhr.responseText = `hide:${mode}`;
		assert.equal(
			h.doc('htmx:beforeOnLoad', { detail: { xhr } }).defaultPrevented,
			true
		);
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
		assert.equal(next.status.textContent, 'Disabled');
		assert.match(next.error.textContent, /unconfirmed/i);
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

test('startup keeps held General controls stable until activation completes', () => {
	for (const scope of ['hide_github', 'self_update_discovery']) {
		for (const target of [
			'track',
			'above-label',
			'save-button',
			'keyboard-space',
		]) {
			const h = harness({ initialActive: { scope, target } });
			assert.equal(h.toggle.retry.hidden, false, `${scope} ${target}`);
			h.toggle.toggle.checked = true;
			h.doc('change', { target: h.toggle.toggle });
			assert.equal(h.toggle.submissions.length, 1);
			h.doc('pointerup', {});
			h.flushTasks();
			assert.equal(
				h.toggle.retry.hidden,
				false,
				'A still-active gesture stays deferred.'
			);
			h.toggle.active = null;
			h.doc('click', {});
			assert.equal(
				h.toggle.retry.hidden,
				false,
				'Reconcile only after the click task.'
			);
			h.flushTasks();
			assert.equal(h.toggle.retry.hidden, true);
			assert.equal(
				h.toggle.submissions.length,
				1,
				'No change or save is replayed.'
			);
		}
	}
});

test('startup release rechecks a newly held General gesture and current forms', () => {
	const h = harness({
		initialActive: { scope: 'hide_github', target: 'track' },
	});
	h.toggle.active = null;
	h.doc('pointerup', {});
	const other = h.mount(h.make('self_update_discovery'));
	other.active = 'above-label';
	h.flushTasks();
	assert.equal(h.toggle.retry.hidden, false);
	other.active = null;
	const replacement = h.replace(h.make('hide_github'));
	h.doc('htmx:afterSwap', { target: { id: 'elsewhere' }, detail: {} });
	h.doc('pointercancel', {});
	h.flushTasks();
	assert.equal(replacement.retry.hidden, true);
	assert.equal(other.retry.hidden, true);
});

test('startup hold preserves failure retry and no-HTMX fallback', () => {
	const h = harness({
		initialActive: { scope: 'hide_github', target: 'track' },
	});
	h.toggle.retry.hidden = true;
	const xhr = start(h, h.toggle);
	h.doc('htmx:afterRequest', { detail: { xhr } });
	assert.equal(
		h.toggle.retry.hidden,
		false,
		'Unknown outcome reveals retry immediately.'
	);
	h.toggle.active = null;
	h.doc('keyup', { key: ' ' });
	h.flushTasks();
	assert.equal(h.toggle.retry.hidden, false);
	const native = harness({
		enhanced: false,
		initialActive: { scope: 'hide_github', target: 'track' },
	});
	native.toggle.active = null;
	native.doc('pointercancel', {});
	native.flushTasks();
	assert.equal(native.toggle.retry.hidden, false);
});

test('startup cancellation outside the form settles on the next task', () => {
	const h = harness({
		initialActive: { scope: 'self_update_discovery', target: 'track' },
	});
	h.toggle.active = null;
	h.doc('pointercancel', { target: { id: 'outside' } });
	assert.equal(h.toggle.retry.hidden, false);
	h.flushTasks();
	assert.equal(h.toggle.retry.hidden, true);
	assert.equal(h.toggle.submissions.length, 0);
});

// HTMX 2 fires `htmx:afterRequest` and then `htmx:timeout` when a configured
// request timeout elapses; the settings runtime handles the former.
const timeOut = (h, xhr, elt) => {
	h.doc('htmx:afterRequest', { detail: { xhr, elt, successful: false } });
	h.doc('htmx:timeout', { detail: { xhr, elt } });
};

test('a switch save that times out ends unconfirmed with retry and no success', () => {
	const h = harness();
	const t = h.toggle;
	t.toggle.checked = true;
	h.doc('change', { target: t.toggle });
	const xhr = start(h, t);
	h.window.announcements.length = 0;
	timeOut(h, xhr, t);

	assert.match(t.error.textContent, /save unconfirmed/i);
	assert.equal(t.error.hidden, false);
	assertSpokenError(h, t.error);
	assert.equal(t.retry.hidden, false);
	assert.equal(t.status.textContent, 'Disabled');
	assert.equal(t.status.events.at(-1).intent, 'none');
	assert.equal(
		h.window.announcements.filter(({ message }) => /saved/i.test(message))
			.length,
		0
	);
	// The timeout released the request: a second timeout is idempotent and the
	// fenced token still requires an explicit retry.
	timeOut(h, xhr, t);
	assert.equal(t.submissions.length, 1);
	t.requestSubmit();
	assert.equal(t.submissions[1].token, 'original');
});

test('a numeric save that times out keeps the draft, shows unconfirmed and unblocks the form', () => {
	const h = harness();
	const form = h.numeric;
	form.value = '2700';
	h.doc('input', { target: form.numeric });
	const xhr = start(h, form);
	timeOut(h, xhr, form);

	assert.equal(form.value, '2700');
	assert.match(form.error.primary.textContent, /unconfirmed/i);
	assert.equal(form.error.hidden, false);
	assertSpokenError(h, form.error);
	assert.equal(form.status.textContent, 'Unsaved numeric changes.');
	assert.equal(form.numeric.disabled, false);
	assert.equal(form.token, 'original');
	// Retry is available and sends the original token.
	form.requestSubmit();
	assert.equal(form.submissions.at(-1).token, 'original');
});

test('a late response after a timeout cannot turn the unconfirmed save into success', () => {
	const h = harness();
	const t = h.toggle;
	t.toggle.checked = true;
	h.doc('change', { target: t.toggle });
	const xhr = start(h, t);
	timeOut(h, xhr, t);
	h.window.announcements.length = 0;

	const late = h.doc('htmx:beforeOnLoad', { detail: { xhr } });
	assert.equal(late.defaultPrevented, true);
	assert.match(t.error.textContent, /save unconfirmed/i);
	assert.equal(t.error.hidden, false);
	assert.equal(t.status.textContent, 'Disabled');
	assert.equal(t.retry.hidden, false);
	assert.equal(h.window.announcements.length, 0);
});

test('a timeout on a queued scope releases the queue instead of leaving it busy', () => {
	const h = harness();
	const t = h.toggle;
	t.toggle.checked = true;
	h.doc('change', { target: t.toggle });
	const xhr = start(h, t);
	const numeric = h.numeric;
	numeric.value = '2800';
	h.doc('input', { target: numeric.numeric });
	numeric.requestSubmit();
	timeOut(h, xhr, t);
	h.flush();

	assert.match(t.error.textContent, /unconfirmed/i);
	assert.match(numeric.error.textContent, /unconfirmed/i);
	assert.equal(numeric.numeric.disabled, false);
});
