import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-release-management.js', import.meta.url),
	'utf8'
);

function createFakeDom({ hasSharedErrorHost = true } = {}) {
	const documentListeners = [];

	class FakeElement {
		constructor(tagName) {
			this.tagName = tagName.toUpperCase();
			this.children = [];
			this.parentNode = null;
			this.attributes = new Map();
			this.listeners = new Map();
			this.dataset = {};
			this.style = {};
			this.hidden = false;
			this.disabled = false;
			this.checked = false;
			this.type = '';
			this.name = '';
			this.value = '';
			this.href = '';
			this.id = '';
			this.className = '';
			this._textContent = '';
			this.focused = false;
			this.submittedWith = null;
			this.nativeSubmitted = 0;
		}

		get textContent() {
			if (this.children.length === 0) {
				return this._textContent;
			}
			return (
				this._textContent +
				this.children.map((child) => child.textContent).join('')
			);
		}

		set textContent(value) {
			this.children = [];
			this._textContent = String(value);
		}

		setAttribute(name, value) {
			this.attributes.set(name, String(value));
			if (name === 'id') {
				this.id = String(value);
			}
		}

		getAttribute(name) {
			if (name === 'id' && this.id) {
				return this.id;
			}
			return this.attributes.has(name) ? this.attributes.get(name) : null;
		}

		hasAttribute(name) {
			return this.attributes.has(name);
		}

		removeAttribute(name) {
			this.attributes.delete(name);
		}

		appendChild(node) {
			if (node.parentNode) {
				const idx = node.parentNode.children.indexOf(node);
				if (idx !== -1) {
					node.parentNode.children.splice(idx, 1);
				}
			}
			node.parentNode = this;
			this.children.push(node);
			return node;
		}

		append(...nodes) {
			for (const node of nodes) {
				this.appendChild(node);
			}
		}

		replaceChildren(...nodes) {
			for (const child of [...this.children]) {
				child.parentNode = null;
			}
			this.children = [];
			this._textContent = '';
			for (const node of nodes) {
				this.appendChild(node);
			}
		}

		after(node) {
			if (!this.parentNode) {
				return;
			}
			if (node.parentNode) {
				const oldIdx = node.parentNode.children.indexOf(node);
				if (oldIdx !== -1) {
					node.parentNode.children.splice(oldIdx, 1);
				}
			}
			const idx = this.parentNode.children.indexOf(this);
			node.parentNode = this.parentNode;
			this.parentNode.children.splice(idx + 1, 0, node);
		}

		prepend(node) {
			node.parentNode = this;
			this.children.unshift(node);
		}

		remove() {
			if (this.parentNode) {
				const idx = this.parentNode.children.indexOf(this);
				if (idx !== -1) {
					this.parentNode.children.splice(idx, 1);
				}
				this.parentNode = null;
			}
		}

		addEventListener(type, fn) {
			const list = this.listeners.get(type) || [];
			list.push(fn);
			this.listeners.set(type, list);
		}

		dispatchClick() {
			for (const fn of this.listeners.get('click') || []) {
				fn({ target: this });
			}
		}

		dispatchChange() {
			for (const fn of this.listeners.get('change') || []) {
				fn({ target: this });
			}
		}

		focus() {
			this.focused = true;
		}

		scrollIntoView() {}

		contains(node) {
			return (
				this === node ||
				this.children.some((child) => child.contains(node))
			);
		}

		matches(selector) {
			if (
				selector ===
				'.ran-booster-release-switch-form[data-ran-booster-switch-preflight-url]'
			) {
				return (
					this.className
						.split(/\s+/)
						.includes('ran-booster-release-switch-form') &&
					this.hasAttribute('data-ran-booster-switch-preflight-url')
				);
			}
			return false;
		}

		findAll(predicate) {
			const out = [];
			for (const child of this.children) {
				if (predicate(child)) {
					out.push(child);
				}
				out.push(...child.findAll(predicate));
			}
			return out;
		}

		querySelector(selector) {
			return this.querySelectorAll(selector)[0] || null;
		}

		querySelectorAll(selector) {
			if (selector === '[data-ran-booster-switch-preflight-error]') {
				return doc.body.findAll((el) =>
					el.hasAttribute('data-ran-booster-switch-preflight-error')
				);
			}
			if (selector === '[data-ran-booster-ui-notice-primary]') {
				return this.findAll((el) =>
					el.hasAttribute('data-ran-booster-ui-notice-primary')
				);
			}
			if (selector === '[data-ran-booster-switch-dialog-host]') {
				return this.findAll((el) =>
					el.hasAttribute('data-ran-booster-switch-dialog-host')
				);
			}
			const hiddenMatch = selector.match(
				/^input\[type="hidden"\]\[name="([^"]+)"\]$/
			);
			if (hiddenMatch) {
				return this.findAll(
					(el) =>
						el.tagName === 'INPUT' &&
						el.type === 'hidden' &&
						el.name === hiddenMatch[1]
				);
			}
			const inputMatch = selector.match(/^input\[name="([^"]+)"\]$/);
			if (inputMatch) {
				return this.findAll(
					(el) => el.tagName === 'INPUT' && el.name === inputMatch[1]
				);
			}
			if (selector === 'input[name="release_channel"]:checked') {
				return this.findAll(
					(el) =>
						el.tagName === 'INPUT' &&
						el.name === 'release_channel' &&
						el.checked
				);
			}
			return [];
		}
	}

	const doc = {
		body: new FakeElement('body'),
		sharedErrorHost: null,
		switchBar: null,
		createElement(tag) {
			return new FakeElement(tag);
		},
		createTextNode(text) {
			const node = new FakeElement('#text');
			node._textContent = String(text);
			return node;
		},
		getElementById(id) {
			if (
				hasSharedErrorHost &&
				id === 'ran-booster-package-mutation-error'
			) {
				return doc.sharedErrorHost;
			}
			return null;
		},
		querySelector(selector) {
			if (
				selector ===
				'[data-ran-booster-use-releases][form="ran-booster-release-track-form"]'
			) {
				return doc.submitButton || null;
			}
			if (selector === '[data-ran-booster-source-switch-bar="branch"]') {
				return doc.switchBar || null;
			}
			if (
				selector ===
				'input[name="release_channel"][form="ran-booster-release-track-form"]:checked'
			) {
				return (
					doc.form?.querySelector(
						'input[name="release_channel"]:checked'
					) || null
				);
			}
			return null;
		},
		querySelectorAll(selector) {
			if (selector === '[data-ran-booster-switch-preflight-error]') {
				return doc.body.querySelectorAll(selector);
			}
			return [];
		},
		addEventListener(type, listener, capture = false) {
			documentListeners.push({ type, listener, capture });
		},
	};

	if (hasSharedErrorHost) {
		const errorHost = new FakeElement('div');
		errorHost.id = 'ran-booster-package-mutation-error';
		errorHost.hidden = true;
		const primary = new FakeElement('p');
		primary.setAttribute('data-ran-booster-ui-notice-primary', '');
		errorHost.appendChild(primary);
		doc.body.appendChild(errorHost);
		doc.sharedErrorHost = errorHost;
	}

	const paneHeader = new FakeElement('div');
	const switchBar = new FakeElement('div');
	switchBar.setAttribute('data-ran-booster-source-switch-bar', 'branch');
	const submitButton = new FakeElement('button');
	submitButton.type = 'submit';
	submitButton.setAttribute('form', 'ran-booster-release-track-form');
	submitButton.setAttribute('data-ran-booster-use-releases', '');
	submitButton.setAttribute('aria-disabled', 'false');
	switchBar.appendChild(submitButton);
	paneHeader.appendChild(switchBar);
	doc.body.appendChild(paneHeader);
	doc.switchBar = switchBar;
	doc.submitButton = submitButton;

	const form = new FakeElement('form');
	form.id = 'ran-booster-release-track-form';
	submitButton.form = form;
	form.className = 'ran-booster-release-switch-form';
	form.setAttribute(
		'data-ran-booster-switch-preflight-url',
		'/wp-admin/admin-ajax.php'
	);
	form.setAttribute(
		'data-ran-booster-switch-preflight-action',
		'ran_booster_release_switch_preflight'
	);
	form.setAttribute(
		'data-ran-booster-switch-preflight-nonces',
		JSON.stringify({ stable: 'pf-stable', prerelease: 'pf-preview' })
	);
	for (const [name, value] of [
		['expected_type', 'plugin'],
		['expected_identifier', 'example/example.php'],
		['expected_source_revision', '3'],
	]) {
		const hidden = new FakeElement('input');
		hidden.type = 'hidden';
		hidden.name = name;
		hidden.value = value;
		form.appendChild(hidden);
	}
	const radioStable = new FakeElement('input');
	radioStable.type = 'radio';
	radioStable.name = 'release_channel';
	radioStable.value = 'stable';
	radioStable.checked = true;
	form.appendChild(radioStable);
	const radioPreview = new FakeElement('input');
	radioPreview.type = 'radio';
	radioPreview.name = 'release_channel';
	radioPreview.value = 'prerelease';
	radioPreview.checked = false;
	form.appendChild(radioPreview);
	doc.body.appendChild(form);
	doc.form = form;

	return {
		doc,
		form,
		submitButton,
		radioStable,
		radioPreview,
		documentListeners,
	};
}

function bootSwitchRuntime({
	fetchImpl,
	openDialogImpl,
	noticeCreateImpl = null,
	hasSharedErrorHost = true,
} = {}) {
	const dom = createFakeDom({ hasSharedErrorHost });
	const win = {
		location: {
			href: 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins',
			origin: 'https://example.test',
		},
		wp: {
			i18n: {
				__(text) {
					return text;
				},
				sprintf(format, ...args) {
					let out = format.replace(/%(\d+)\$s/g, (_, idx) =>
						String(args[Number(idx) - 1] ?? '')
					);
					let i = 0;
					out = out.replace(/%s/g, () => String(args[i++] ?? ''));
					return out;
				},
			},
		},
		fetch: fetchImpl,
		ranBoosterOpenDialog: openDialogImpl,
		ranBoosterNotice: noticeCreateImpl
			? { create: noticeCreateImpl }
			: undefined,
	};

	const context = {
		window: win,
		document: dom.doc,
		wp: win.wp,
		URL,
		URLSearchParams,
		WeakSet,
		JSON,
		setTimeout,
	};
	vm.runInNewContext(source, context);

	const submitEntry = dom.documentListeners.find(
		(entry) => entry.type === 'submit' && entry.capture === true
	);
	assert.ok(submitEntry, 'Expected capture-phase submit handler.');

	async function triggerSubmit(submitter = dom.submitButton) {
		const event = {
			target: dom.form,
			submitter,
			defaultPrevented: false,
			propagationStopped: false,
			preventDefault() {
				this.defaultPrevented = true;
			},
			stopPropagation() {
				this.propagationStopped = true;
			},
		};
		await submitEntry.listener(event);
		return event;
	}

	return { ...dom, win, triggerSubmit };
}

test('newer release preflight opens DOM-hosted Dialog and tears down before confirmed submit', async () => {
	const fetchCalls = [];
	const events = [];
	let dialogCall = null;

	const env = bootSwitchRuntime({
		async fetchImpl(url, init) {
			fetchCalls.push({ url, init });
			return {
				ok: true,
				async json() {
					return {
						successful: true,
						code: 'ready',
						data: {
							installed_version: '1.5.0-dev',
							release_version: '1.6.0',
							version_relationship: 'newer',
						},
					};
				},
			};
		},
		openDialogImpl(trigger, body, options) {
			assert.ok(
				body.parentNode,
				'body must stay attached when opening Dialog'
			);
			assert.ok(
				options.footer.parentNode,
				'footer must stay attached when opening Dialog'
			);
			dialogCall = { trigger, body, options };
			return {
				close() {
					events.push('close');
				},
				teardown() {
					events.push('teardown');
				},
			};
		},
	});

	env.form.requestSubmit = function (submitter) {
		events.push('requestSubmit:' + (submitter === env.submitButton));
		assert.equal(env.form.dataset.ranBoosterSwitchConfirmed, '1');
		const confirmedInput = env.form.querySelector(
			'input[type="hidden"][name="confirm_switch"]'
		);
		const olderInput = env.form.querySelector(
			'input[type="hidden"][name="confirm_older_release"]'
		);
		assert.equal(confirmedInput?.value, '1');
		assert.equal(olderInput, null);
	};

	const submitEvent = await env.triggerSubmit();
	assert.equal(submitEvent.defaultPrevented, true);
	assert.equal(submitEvent.propagationStopped, true);
	assert.equal(fetchCalls.length, 1);
	assert.equal(fetchCalls[0].url, '/wp-admin/admin-ajax.php');
	const params = new URLSearchParams(fetchCalls[0].init.body);
	assert.equal(params.get('action'), 'ran_booster_release_switch_preflight');
	assert.equal(params.get('release_channel'), 'stable');
	assert.equal(params.get('_wpnonce'), 'pf-stable');

	assert.ok(dialogCall);
	assert.equal(dialogCall.options.title, 'Switch to releases?');
	assert.match(
		dialogCall.body.textContent,
		/Installed: 1\.5\.0-dev from branch\. Installing: release 1\.6\.0\./
	);
	assert.match(
		dialogCall.body.textContent,
		/cannot roll back database changes/
	);
	assert.equal(dialogCall.body.children.length, 2);

	const confirmBtn = dialogCall.options.footer.children[1];
	assert.equal(confirmBtn.disabled, false);
	confirmBtn.dispatchClick();
	assert.deepEqual(events, ['teardown', 'requestSubmit:true']);

	// Subsequent confirmed submit bypasses dialog capture handler.
	const secondEvent = await env.triggerSubmit();
	assert.equal(secondEvent.defaultPrevented, false);
});

test('same version shows notice copy without consent checkbox', async () => {
	let dialogBody = null;
	let footer = null;
	const env = bootSwitchRuntime({
		async fetchImpl() {
			return {
				ok: true,
				async json() {
					return {
						successful: true,
						code: 'ready',
						data: {
							installed_version: '1.5.0-dev',
							release_version: '1.5.0',
							version_relationship: 'same',
						},
					};
				},
			};
		},
		openDialogImpl(trigger, body, options) {
			dialogBody = body;
			footer = options.footer;
			return { close() {}, teardown() {} };
		},
	});

	await env.triggerSubmit();
	assert.match(
		dialogBody.textContent,
		/Same version: this replaces the branch build with the release\./
	);
	assert.equal(footer.children[1].disabled, false);
});

test('older release disables confirm until consent checkbox is checked and sends signed older tokens', async () => {
	let dialogBody = null;
	let footer = null;
	let submittedFields = null;
	const env = bootSwitchRuntime({
		async fetchImpl() {
			return {
				ok: true,
				async json() {
					return {
						successful: true,
						code: 'ready',
						data: {
							installed_version: '2.0.0',
							release_version: '1.9.0',
							release_identity: '101',
							release_tag: 'v1.9.0',
							older_release_nonce: 'older-nonce-101',
							version_relationship: 'older',
							channel: 'prerelease',
						},
					};
				},
			};
		},
		openDialogImpl(trigger, body, options) {
			dialogBody = body;
			footer = options.footer;
			return { close() {}, teardown() {} };
		},
	});
	env.radioStable.checked = false;
	env.radioPreview.checked = true;
	env.form.requestSubmit = function () {
		const val = (name) =>
			env.form.querySelector('input[type="hidden"][name="' + name + '"]')
				?.value ?? null;
		submittedFields = {
			confirm_older_release: val('confirm_older_release'),
			identity: val('confirmed_older_release_identity'),
			tag: val('confirmed_older_release_tag'),
			nonce: val('confirmed_older_release_nonce'),
		};
	};

	await env.triggerSubmit();
	assert.match(
		dialogBody.textContent,
		/This release is older than what's installed\./
	);
	assert.match(
		dialogBody.textContent,
		/I understand the database won't be rolled back\./
	);
	const confirmBtn = footer.children[1];
	assert.equal(confirmBtn.disabled, true);
	confirmBtn.dispatchClick();
	assert.equal(submittedFields, null);

	const checkbox = dialogBody.findAll((el) => el.tagName === 'INPUT')[0];
	assert.ok(checkbox);
	checkbox.checked = true;
	checkbox.dispatchChange();
	assert.equal(confirmBtn.disabled, false);
	confirmBtn.dispatchClick();
	assert.deepEqual(submittedFields, {
		confirm_older_release: '1',
		identity: '101',
		tag: 'v1.9.0',
		nonce: 'older-nonce-101',
	});
});

test('older release fails closed without identity/tag/nonce and does not open dialog', async () => {
	let dialogOpened = 0;
	const env = bootSwitchRuntime({
		async fetchImpl() {
			return {
				ok: true,
				async json() {
					return {
						successful: true,
						code: 'ready',
						data: {
							installed_version: '2.0.0',
							release_version: '1.9.0',
							version_relationship: 'older',
							channel: 'stable',
						},
					};
				},
			};
		},
		openDialogImpl() {
			dialogOpened += 1;
			return { close() {}, teardown() {} };
		},
	});

	await env.triggerSubmit();
	assert.equal(dialogOpened, 0);
	assert.equal(env.doc.sharedErrorHost.hidden, false);
	assert.match(
		env.doc.sharedErrorHost.textContent,
		/Published releases could not be checked\./
	);
});

test('track radios lock during pending preflight, restore afterwards, and discard mismatched responses', async () => {
	let resolveFetch;
	let dialogOpened = 0;
	const env = bootSwitchRuntime({
		fetchImpl() {
			return new Promise((resolve) => {
				resolveFetch = resolve;
			});
		},
		openDialogImpl() {
			dialogOpened += 1;
			return { close() {}, teardown() {} };
		},
	});

	const pending = env.triggerSubmit();
	assert.equal(env.radioStable.disabled, true);
	assert.equal(env.radioPreview.disabled, true);

	// Simulate an external script mutating the selected radio mid-flight.
	env.radioStable.checked = false;
	env.radioPreview.checked = true;
	resolveFetch({
		ok: true,
		async json() {
			return {
				successful: true,
				code: 'ready',
				data: {
					installed_version: '1.0.0',
					release_version: '2.0.0',
					version_relationship: 'newer',
					channel: 'stable',
				},
			};
		},
	});
	await pending;
	assert.equal(env.radioStable.disabled, false);
	assert.equal(env.radioPreview.disabled, false);
	assert.equal(dialogOpened, 0);
});

test('cancel closes dialog without mutating hidden inputs or submitting', async () => {
	let closed = 0;
	let submitted = 0;
	const env = bootSwitchRuntime({
		async fetchImpl() {
			return {
				ok: true,
				async json() {
					return {
						successful: true,
						code: 'ready',
						data: {
							installed_version: '1.0.0',
							release_version: '2.0.0',
							version_relationship: 'newer',
						},
					};
				},
			};
		},
		openDialogImpl(trigger, body, options) {
			return {
				close() {
					closed += 1;
				},
				teardown() {},
				footer: options.footer,
				cancel: options.footer.children[0],
			};
		},
	});
	env.form.requestSubmit = function () {
		submitted += 1;
	};

	const origOpen = env.win.ranBoosterOpenDialog;
	let cancelBtn = null;
	env.win.ranBoosterOpenDialog = function (t, b, o) {
		cancelBtn = o.footer.children[0];
		return origOpen(t, b, o);
	};

	await env.triggerSubmit();
	cancelBtn.dispatchClick();
	assert.equal(closed, 1);
	assert.equal(submitted, 0);
	assert.equal(
		env.form.querySelector('input[type="hidden"][name="confirm_switch"]'),
		null
	);
});

test('absent or null openDialog falls back to unconfirmed submit without confirm_switch=1', async () => {
	const envAbsent = bootSwitchRuntime({
		openDialogImpl: undefined,
		fetchImpl: async () => ({}),
	});
	const absentEvent = await envAbsent.triggerSubmit();
	assert.equal(absentEvent.defaultPrevented, false);

	let fallbackRequests = 0;
	const envNull = bootSwitchRuntime({
		async fetchImpl() {
			return {
				ok: true,
				async json() {
					return {
						successful: true,
						code: 'ready',
						data: { version_relationship: 'newer' },
					};
				},
			};
		},
		openDialogImpl() {
			return null;
		},
	});
	envNull.form.requestSubmit = function () {
		fallbackRequests += 1;
		assert.equal(
			envNull.form.querySelector(
				'input[type="hidden"][name="confirm_switch"]'
			),
			null
		);
		assert.equal(envNull.form.dataset.ranBoosterSwitchNativeFallback, '1');
	};

	await envNull.triggerSubmit();
	assert.equal(fallbackRequests, 1);
});

test('preflight errors render same-origin links safely and block cross-origin or javascript URLs', async () => {
	const envSameOrigin = bootSwitchRuntime({
		async fetchImpl() {
			return {
				ok: true,
				async json() {
					return {
						successful: false,
						code: 'switch_held',
						data: {
							message_template:
								"Install couldn't complete. Check this plugin, then %s.",
							link_label: 'release the hold',
							link_url: '#ran-booster-native-hold',
						},
					};
				},
			};
		},
		openDialogImpl() {
			return { close() {}, teardown() {} };
		},
	});

	await envSameOrigin.triggerSubmit();
	assert.equal(envSameOrigin.doc.sharedErrorHost.hidden, false);
	const primary = envSameOrigin.doc.sharedErrorHost.children[0];
	const anchor = primary.children.find((node) => node.tagName === 'A');
	assert.ok(anchor);
	assert.equal(anchor.href, '#ran-booster-native-hold');
	assert.equal(anchor.textContent, 'release the hold');

	const envUnsafe = bootSwitchRuntime({
		hasSharedErrorHost: false,
		async fetchImpl() {
			return {
				ok: true,
				async json() {
					return {
						successful: false,
						code: 'switch_other_held',
						data: {
							message_template:
								"GitHub installs are on hold: the last install of %s couldn't complete.",
							link_label: 'Evil Plugin',
							link_url: 'https://evil.example/phish',
						},
					};
				},
			};
		},
		noticeCreateImpl(intent, opts) {
			const host = envUnsafe.doc.createElement('div');
			const content = envUnsafe.doc.createElement('div');
			host.setAttribute('data-notice-intent', intent);
			host.className = opts.className;
			host.appendChild(content);
			return { host, content };
		},
		openDialogImpl() {
			return { close() {}, teardown() {} };
		},
	});

	await envUnsafe.triggerSubmit();
	const inlineNotice = envUnsafe.doc.querySelectorAll(
		'[data-ran-booster-switch-preflight-error]'
	)[0];
	assert.ok(inlineNotice);
	assert.equal(inlineNotice.findAll((el) => el.tagName === 'A').length, 0);
	assert.match(inlineNotice.textContent, /Evil Plugin/);
});

test('aria-disabled submitter does not issue preflight fetch', async () => {
	let fetches = 0;
	const env = bootSwitchRuntime({
		async fetchImpl() {
			fetches += 1;
			return {
				ok: true,
				async json() {
					return {};
				},
			};
		},
		openDialogImpl() {
			return { close() {}, teardown() {} };
		},
	});
	env.submitButton.setAttribute('aria-disabled', 'true');
	const ev = await env.triggerSubmit();
	assert.equal(ev.defaultPrevented, true);
	assert.equal(fetches, 0);
});
