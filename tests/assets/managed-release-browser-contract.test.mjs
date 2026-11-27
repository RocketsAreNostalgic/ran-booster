import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-release-management.js', import.meta.url),
	'utf8'
);
// The shared rate-limit sentence loads before this script on every Booster page.
const rateLimitSource = fs.readFileSync(
	new URL('../../assets/ran-booster-rate-limit.js', import.meta.url),
	'utf8'
);
const styles = fs.readFileSync(
	new URL('../../assets/ran-booster-release-management.css', import.meta.url),
	'utf8'
);
const packageStyles = fs.readFileSync(
	new URL(
		'../../assets/ran-booster/65-package-settings.css',
		import.meta.url
	),
	'utf8'
);
const primitiveStyles = fs.readFileSync(
	new URL(
		'../../assets/ran-booster/25-admin-primitives.css',
		import.meta.url
	),
	'utf8'
);

class Node {
	constructor(tagName) {
		this.tagName = tagName;
		this.children = [];
		this.dataset = {};
		this.listeners = {};
		this.dispatchedEvents = [];
		this.attributes = new Map();
		this.classes = new Set();
		this.classList = {
			add: (...names) => names.forEach((name) => this.classes.add(name)),
			contains: (name) => this.classes.has(name),
			replace: (oldName, newName) => {
				if (!this.classes.delete(oldName)) {
					return false;
				}
				this.classes.add(newName);
				return true;
			},
			remove: (...names) =>
				names.forEach((name) => this.classes.delete(name)),
			toggle: (name, force) => {
				if (force === true) {
					this.classes.add(name);
					return true;
				}
				if (force === false) {
					this.classes.delete(name);
					return false;
				}
				return this.classes.has(name)
					? !this.classes.delete(name)
					: Boolean(this.classes.add(name));
			},
		};
		this.hidden = false;
	}
	get isConnected() {
		return this.parentNode?.tagName === 'dialog';
	}
	append(...children) {
		for (const child of children) {
			if (typeof child === 'object') {
				child.remove?.();
				child.parentNode = this;
			}
			this.children.push(child);
		}
	}
	prepend(child) {
		child.remove?.();
		child.parentNode = this;
		this.children.unshift(child);
	}
	appendChild(child) {
		this.append(child);
		return child;
	}
	remove() {
		if (this.parentNode) {
			this.parentNode.children = this.parentNode.children.filter(
				(child) => child !== this
			);
			this.parentNode = null;
		}
	}
	replaceChildren(...children) {
		for (const child of this.children) {
			if (typeof child === 'object') {
				child.parentNode = null;
			}
		}
		this.children = [];
		this.append(...children);
	}
	addEventListener(type, listener) {
		this.listeners[type] ||= [];
		this.listeners[type].push(listener);
	}
	getAttribute(name) {
		return this.attributes.get(name) ?? null;
	}
	setAttribute(name, value) {
		this.attributes.set(name, value);
	}
	removeAttribute(name) {
		this.attributes.delete(name);
	}
	async dispatch(type) {
		return Promise.all(
			this.listeners[type]?.map((listener) => listener()) || []
		);
	}
	dispatchEvent(event) {
		this.dispatchedEvents.push(event);
		for (const listener of this.listeners[event.type] || []) {
			listener(event);
		}
		return true;
	}
	querySelector(selector) {
		if (
			selector ===
			'.ran-booster-release-candidate__warning[data-ran-booster-ui-notice]'
		) {
			return (
				descendants(this).find(
					(node) =>
						node.className
							?.split(/\s+/)
							.includes(
								'ran-booster-release-candidate__warning'
							) &&
						node.attributes.has('data-ran-booster-ui-notice')
				) || null
			);
		}
		if (!/^input\[data-release-id="[^"]*"\]$/.test(selector)) {
			throw new SyntaxError('Invalid CSS selector');
		}
		const releaseId = selector.match(
			/^input\[data-release-id="(.+)"\]$/
		)?.[1];
		return releaseId
			? descendants(this).find(
					(node) =>
						node.tagName === 'input' &&
						node.dataset.releaseId === releaseId
				)
			: null;
	}
}

const descendants = (node) =>
	(node.children || []).flatMap((child) => [child, ...descendants(child)]);
const flush = () => new Promise((resolve) => setImmediate(resolve));
const deferred = () => {
	let resolve;
	return { promise: new Promise((next) => (resolve = next)), resolve };
};
const candidate = (releaseId, version, relationship, publishedAt) => ({
	release_id: releaseId,
	tag: `v${version}`,
	version,
	version_relationship: relationship,
	published_at: publishedAt,
	prerelease: false,
});

const createHarness = (
	responses,
	nativeOfferReleaseId = 'offer',
	translations = {}
) => {
	const nodes = new Map();
	const browser = new Node('section');
	browser.closest = () => null;
	browser.dataset = {
		ranBoosterManagedReleaseType: 'plugin',
		ranBoosterManagedReleaseIdentifier: 'example/plugin.php',
		ranBoosterManagedReleaseRevision: 'abc',
		ranBoosterManagedReleaseChannel: 'stable',
		ranBoosterManagedReleaseListNonce: 'list',
		ranBoosterManagedReleaseInspectNonce: 'inspect',
		ranBoosterManagedReleaseAjaxUrl: '/ajax',
		ranBoosterManagedReleaseNativeUpdateVersion: '3.0.0',
		ranBoosterManagedReleaseNativeUpdateReleaseId: nativeOfferReleaseId,
	};
	for (const name of [
		'candidates',
		'placeholder',
		'chosen',
		'candidate-list',
		'review',
		'heading',
		'message',
		'retry',
		'error',
		'error-message',
		'details',
		'native-update',
		'version',
		'relationship',
	]) {
		nodes.set(name, new Node(name));
	}
	nodes.get('candidates').hidden = true;
	nodes.get('error').hidden = true;
	nodes.get('native-update').setAttribute('aria-disabled', 'true');
	nodes.get('native-update').classList.add('disabled');
	nodes.get('native-update').textContent = 'Update now';
	nodes.get('retry').textContent = 'Refresh releases';
	browser.querySelector = (selector) =>
		'[data-ran-booster-managed-release-native-update]' === selector
			? null
			: nodes.get(selector.match(/managed-release-([a-z-]+)/)?.[1]) ||
				null;
	const requests = [];
	const requestedUrls = [];
	class FormData {
		constructor() {
			this.values = new Map();
		}
		append(key, value) {
			this.values.set(key, value);
		}
	}
	const documentListeners = new Map();
	const noticeCalls = [];
	const dialogs = [];
	let swappedBrowser = null;
	const context = {
		CustomEvent: class {
			constructor(type, options) {
				this.type = type;
				this.bubbles = options?.bubbles;
			}
		},
		Date,
		FormData,
		URL,
		wp: {
			i18n: {
				_x(text) {
					return translations[text] || text;
				},
				__(text) {
					return translations[text] || text;
				},
				_n(singular, plural, count) {
					const text = count === 1 ? singular : plural;
					return translations[text] || text;
				},
				sprintf(template, ...values) {
					let index = 0;
					return template.replace(/%(?:\d+\$)?[ds]/g, function () {
						const value = values[index];
						index += 1;
						return String(value);
					});
				},
			},
		},
		document: {
			querySelector: (selector) => {
				if (selector === '[data-ran-booster-managed-release-browser]') {
					return swappedBrowser;
				}
				// Update now sits in the Package operations group, outside the browser.
				return selector ===
					'[data-ran-booster-managed-release-native-update]'
					? nodes.get('native-update')
					: null;
			},
			addEventListener: (type, listener) => {
				documentListeners.set(type, listener);
			},
			createElement: (tagName) => new Node(tagName),
			createTextNode: (textContent) => ({
				tagName: '#text',
				textContent,
				children: [],
			}),
		},
		window: {
			ranBoosterOpenDialog(trigger, body, options) {
				const bodyHost = body.parentNode;
				const footerHost = options.footer.parentNode;
				const surface = new Node('dialog');
				surface.append(body, options.footer);
				const dialog = {
					trigger,
					body,
					options,
					surface,
					closed: false,
					title: options.title,
					closeWithoutTeardown() {
						this.closed = true;
					},
					finishTeardown() {
						bodyHost.append(body);
						footerHost.append(options.footer);
					},
				};
				const close = () => {
					dialog.closeWithoutTeardown();
					dialog.finishTeardown();
				};
				dialogs.push(dialog);
				return {
					get isOpen() {
						return !dialog.closed;
					},
					setTitle(title) {
						dialog.title = title;
					},
					close,
					teardown: close,
				};
			},
			location: {
				href: 'https://example.test/wp-admin/plugins.php',
				origin: 'https://example.test',
			},
			ranBoosterNotice: {
				// Records how the warning is built; the helper's own markup
				// is covered by notice.test.mjs.
				create(intent, notice) {
					const host = new Node('div');
					const content = new Node('div');
					host.append(content);
					noticeCalls.push({ intent, ...notice });
					return { host, content };
				},
			},
			CustomEvent: class {
				constructor(type, options) {
					this.type = type;
					this.bubbles = options.bubbles;
					this.detail = options.detail;
				}
			},
			fetch: async (url, options) => {
				requestedUrls.push(url);
				requests.push(options.body.values);
				const entry = responses.shift();
				return typeof entry === 'function'
					? entry()
					: { ok: true, json: async () => entry };
			},
		},
	};
	context.globalThis = context;
	context.window.wp = context.wp;
	context.window.document = context.document;
	vm.runInNewContext(
		`${rateLimitSource}\n${source}\nglobalThis.initialize = initializeManagedReleaseBrowser;`,
		context
	);
	return {
		browser,
		nodes,
		noticeCalls,
		dialogs,
		requests,
		requestedUrls,
		initialize: context.initialize,
		window: context.window,
		afterPackageSwap: () => {
			swappedBrowser = browser;
			documentListeners.get('htmx:afterSwap')?.({
				detail: { target: { id: 'wpbody-content' } },
			});
		},
	};
};

test('disabled managed browser does not initialize or fetch', async () => {
	const harness = createHarness([]);
	harness.browser.dataset.ranBoosterManagedReleaseBrowserDisabled = 'true';
	delete harness.browser.dataset.ranBoosterManagedReleaseListNonce;
	delete harness.browser.dataset.ranBoosterManagedReleaseInspectNonce;

	harness.initialize(harness.browser);
	await flush();

	assert.equal(harness.requests.length, 0);
	assert.equal(harness.nodes.get('retry').listeners.click?.length || 0, 0);
});

test('branch latest marker follows the server choice while all rows remain inert', async () => {
	const harness = createHarness([
		{
			successful: true,
			code: 'release_candidates_available',
			data: {
				installed_version: '1.0.0',
				candidates: [
					{
						...candidate('missing', '4.0.0', 'newer', ''),
						latest_eligible: false,
					},
					{
						...candidate(
							'older-list-position',
							'2.0.0',
							'newer',
							''
						),
						latest_eligible: false,
					},
					{
						...candidate('selected', '3.0.0', 'newer', ''),
						latest_eligible: true,
					},
					{
						...candidate('ambiguous', '5.0.0', 'newer', ''),
						latest_eligible: false,
					},
				],
			},
		},
	]);
	Object.assign(harness.browser.dataset, { ranBoosterBranchStable: 'list' });
	harness.browser.closest = () => null;
	harness.initialize(harness.browser);
	await flush();
	const rows = descendants(harness.nodes.get('candidate-list'));
	const labels = rows.filter((node) => node.tagName === 'label');
	assert.equal(labels.length, 4);
	assert.equal(
		labels.filter((node) =>
			descendants(node).some((child) =>
				child.textContent?.includes('Latest eligible')
			)
		).length,
		1
	);
	assert.match(
		labels.find((node) =>
			descendants(node).some((child) =>
				child.textContent?.includes('Latest eligible')
			)
		).children[1].textContent,
		/3\.0\.0/
	);
	assert.ok(
		labels.every((node) =>
			descendants(node)
				.filter((child) => child.tagName === 'input')
				.every((input) => input.disabled)
		)
	);
});

for (const type of ['plugin', 'theme']) {
	for (const swapped of [false, true]) {
		test(`collapsed ${type} picker defers List after ${swapped ? 'a swap' : 'page load'}`, async () => {
			const harness = createHarness(installedAndNewer.slice());
			harness.browser.dataset.ranBoosterManagedReleaseType = type;
			const details = new Node('details');
			details.open = false;
			const host = new Node('div');
			host.querySelector = () => details;
			harness.browser.closest = () => host;
			let notify;
			harness.window.MutationObserver = class {
				constructor(callback) {
					notify = callback;
				}
				observe() {}
				disconnect() {}
			};
			if (swapped) {
				harness.afterPackageSwap();
			} else {
				harness.initialize(harness.browser);
			}
			await flush();
			assert.equal(harness.requests.length, 0);
			details.open = true;
			notify();
			await flush();
			assert.deepEqual(
				harness.requests.map((request) => request.get('action')),
				['ran_booster_managed_release_list_candidates']
			);
			details.open = false;
			notify();
			details.open = true;
			notify();
			assert.equal(harness.requests.length, 1);
		});
	}
}

test('branch track changes and refresh list metadata without selecting or inspecting releases', async () => {
	const slow = deferred();
	const reply = (version) => ({
		successful: true,
		code: 'release_candidates_available',
		data: {
			installed_version: version,
			candidates: [
				{
					...candidate('current', version, 'same', ''),
					latest_eligible: true,
				},
				candidate('old', '0.5.0', 'older', ''),
			],
		},
	});
	const harness = createHarness([
		() => slow.promise,
		reply('2.0.0-beta.1'),
		reply('2.0.0-beta.2'),
	]);
	const host = new Node('div');
	const trigger = new Node('div');
	host.querySelector = (selector) =>
		selector === ':scope > details' ? null : trigger;
	let notify;
	harness.window.MutationObserver = class {
		constructor(callback) {
			notify = callback;
		}
		observe() {}
		disconnect() {}
	};
	harness.browser.closest = () => host;
	Object.assign(harness.browser.dataset, {
		ranBoosterBranchStable: 'list',
		ranBoosterBranchPreview: 'preview-list',
	});
	delete harness.browser.dataset.ranBoosterManagedReleaseInspectNonce;
	harness.initialize(harness.browser);
	assert.equal(harness.requests.length, 0);
	trigger.setAttribute('aria-expanded', 'true');
	notify();
	assert.equal(harness.requests.length, 1);
	host.dispatchEvent({
		type: 'change',
		target: { matches: () => true, value: 'prerelease' },
	});
	await flush();
	slow.resolve({ ok: true, json: async () => reply('1.0.0') });
	await flush();
	const rows = () => descendants(harness.nodes.get('candidate-list'));
	assert.ok(
		rows().some((node) => node.textContent?.includes('2.0.0-beta.1'))
	);
	assert.ok(
		rows().some((node) => node.textContent?.includes('Installed/current'))
	);
	assert.ok(
		rows().some((node) => node.textContent?.includes('earlier release'))
	);
	assert.ok(
		rows().some((node) => node.textContent?.includes('Latest eligible'))
	);
	await harness.nodes.get('retry').dispatch('click');
	assert.ok(
		rows().some((node) => node.textContent?.includes('2.0.0-beta.2'))
	);
	assert.deepEqual(
		harness.requests.map((request) => [
			request.get('view_source'),
			request.get('release_channel'),
			request.get('_wpnonce'),
		]),
		[
			['branch', 'stable', 'list'],
			['branch', 'prerelease', 'preview-list'],
			['branch', 'prerelease', 'preview-list'],
		]
	);
	assert.ok(
		rows()
			.filter((node) => node.tagName === 'input')
			.every((node) => node.disabled && !node.checked)
	);
	assert.ok(harness.nodes.get('review').hidden);
});

test('managed browser uses an origin-relative AJAX URL', async () => {
	const harness = createHarness([
		{
			successful: true,
			code: 'no_releases',
			data: { installed_version: '2.0.0', candidates: [] },
		},
	]);
	harness.browser.dataset.ranBoosterManagedReleaseAjaxUrl =
		'http://localhost:10008/wp-admin/admin-ajax.php?context=release#managed';

	harness.initialize(harness.browser);
	await flush();

	assert.equal(
		harness.requestedUrls[0],
		'/wp-admin/admin-ajax.php?context=release#managed'
	);
});

test('managed browser selects and explicitly reviews the newest current candidate, preserving bounded release context', async () => {
	const releases = [
		candidate('same', '2.0.0', 'same', '2026-08-03T00:00:00Z'),
		candidate('old', '1.0.0', 'older', '2026-08-02T00:00:00Z'),
	];
	const harness = createHarness([
		{
			successful: true,
			code: 'release_candidates_available',
			data: { installed_version: '2.0.0', candidates: releases },
		},
		{
			successful: true,
			code: 'release_ready',
			data: {
				version: '2.0.0',
				tag: 'v2.0.0',
				installed_version: '2.0.0',
				version_relationship: 'same',
			},
		},
	]);
	harness.initialize(harness.browser);
	await flush();
	await harness.nodes.get('review').dispatch('click');
	const inputs = descendants(harness.nodes.get('candidate-list')).filter(
		(node) => node.tagName === 'input'
	);
	assert.equal(inputs[0].checked, true);
	assert.equal(inputs[0].dataset.releaseId, 'same');
	assert.equal(inputs[1].disabled, true);
	assert.equal(harness.requests[1].get('release_id'), 'same');
	assert.equal(harness.requests[1].get('release_tag'), 'v2.0.0');
	const disclosure = descendants(harness.nodes.get('candidate-list')).find(
		(node) => node.tagName === 'details'
	);
	assert.equal(disclosure.children[0].textContent, 'Show 1 earlier release');
	assert.equal(
		disclosure.children[1].className,
		'ran-booster-disclosure__body'
	);
	assert.match(
		disclosure.children[1].children[0].textContent,
		/Downgrades are unavailable/
	);
	assert.match(dialogText(harness), /Installed: 2\.0\.0 → Selected: 2\.0\.0/);
	assert.equal(
		harness.nodes.get('native-update').getAttribute('aria-disabled'),
		'true'
	);
	assert.equal(
		harness.nodes.get('native-update').classList.contains('disabled'),
		true
	);
	assert.equal(harness.nodes.get('native-update').textContent, 'Update now');
	assert.equal(harness.nodes.get('retry').disabled, false);
	assert.equal(
		harness.nodes
			.get('retry')
			.classList.contains('ran-booster-update-is-active'),
		false
	);
});

test('managed browser keeps the preselected newer candidate outside earlier releases', async () => {
	const harness = createHarness([
		{
			successful: true,
			code: 'release_candidates_available',
			data: {
				installed_version: '2.0.0',
				candidates: [
					candidate(
						'older',
						'1.0.0',
						'older',
						'2026-08-03T00:00:00Z'
					),
					candidate(
						'newer',
						'3.0.0',
						'newer',
						'2026-08-02T00:00:00Z'
					),
				],
			},
		},
		{
			successful: true,
			code: 'release_ready',
			data: {
				version: '3.0.0',
				tag: 'v3.0.0',
				installed_version: '2.0.0',
				version_relationship: 'newer',
			},
		},
	]);

	harness.initialize(harness.browser);
	await flush();
	await harness.nodes.get('review').dispatch('click');

	assert.equal(harness.requests[1].get('release_id'), 'newer');
	assert.equal(
		harness.nodes.get('candidate-list').children[0].children[0].dataset
			.releaseId,
		'newer'
	);
	assert.equal(
		harness.nodes.get('candidate-list').children[0].children[0].checked,
		true
	);
	assert.equal(
		descendants(
			descendants(harness.nodes.get('candidate-list')).find(
				(node) => node.tagName === 'details'
			)
		).find((node) => node.tagName === 'input').dataset.releaseId,
		'older'
	);
});

const readyResponse = (version, relationship, extra = {}) => ({
	successful: true,
	code: 'release_ready',
	data: {
		version,
		tag: `v${version}`,
		installed_version: '2.0.0',
		version_relationship: relationship,
		...extra,
	},
});
const checkedRadio = (harness) =>
	descendants(harness.nodes.get('candidate-list')).find(
		(node) => node.type === 'radio' && node.checked
	);
const radioFor = (harness, releaseId) =>
	descendants(harness.nodes.get('candidate-list')).find(
		(node) => node.type === 'radio' && node.dataset.releaseId === releaseId
	);
const dialogText = (harness) =>
	descendants(harness.dialogs.at(-1).body)
		.map((node) => node.textContent || '')
		.join(' ');
const listText = (harness) =>
	descendants(harness.nodes.get('candidate-list'))
		.map((node) => node.textContent || '')
		.join(' ');
const installedAndNewer = [
	{
		successful: true,
		code: 'release_candidates_available',
		data: {
			installed_version: '2.0.0',
			candidates: [
				candidate('new', '3.0.0', 'newer', '2026-08-03T00:00:00Z'),
				candidate('same', '2.0.0', 'same', '2026-08-02T00:00:00Z'),
			],
		},
	},
];

for (const [relationship, version, label] of [
	['same', '2.0.0', 'Reinstall 2.0.0'],
	['older', '1.0.0', 'Downgrade to 1.0.0'],
	['newer', '3.0.0', 'Upgrade to 3.0.0'],
]) {
	test(`chosen ${relationship} release posts the inspected identity and consent`, async () => {
		const chosen = {
			release_id: 'A',
			release_tag: 'v' + version,
			release_version: version,
			artifact_identity: 'asset-A',
			artifact_sha256: 'a'.repeat(64),
			installed_version: '2.0.0',
			_wpnonce: 'exact-nonce',
		};
		const listing = {
			successful: true,
			code: 'release_candidates_available',
			data: {
				installed_version: '2.0.0',
				candidates: [candidate('A', version, relationship, '')],
			},
		};
		const harness = createHarness([
			listing,
			readyResponse(version, relationship, { chosen_install: chosen }),
		]);
		harness.browser.dataset.ranBoosterManagedReleaseOlder = 'true';
		harness.browser.dataset.ranBoosterManagedReleasePostUrl =
			'/wp-admin/admin-post.php';
		harness.initialize(harness.browser);
		await flush();
		await harness.nodes.get('review').dispatch('click');
		const dialog = harness.dialogs.at(-1);
		const form = descendants(dialog.body).find(
			(node) => node.tagName === 'form'
		);
		assert.equal(dialog.title, label + '?');
		assert.equal(
			dialog.body.children.filter((node) => /^h[1-6]$/.test(node.tagName))
				.length,
			0
		);
		assert.equal(form.method, 'post');
		assert.equal(form.action, '/wp-admin/admin-post.php');
		assert.equal(
			form.children.find((node) => node.name === 'artifact_sha256').value,
			chosen.artifact_sha256
		);
		assert.equal(
			form.children.find((node) => node.name === 'action').value,
			'ran_booster_release_install_chosen'
		);
		assert.equal(
			dialog.options.footer.children.find(
				(node) => node.type === 'submit'
			).textContent,
			label
		);
		const consent = form.children.find((node) => node.tagName === 'label')
			?.children[0];
		assert.equal(Boolean(consent?.required), relationship === 'older');
		if (consent) {
			const submit = dialog.options.footer.children.find(
				(node) => node.type === 'submit'
			);
			assert.equal(submit.disabled, true);
			consent.checked = true;
			await consent.dispatch('change');
			assert.equal(submit.disabled, false);
			consent.checked = false;
			await consent.dispatch('change');
			assert.equal(submit.disabled, true);
		}
	});
}

for (const type of ['plugin', 'theme']) {
	test(`managed ${type} prerelease review requires a click and preserves native offer B`, async () => {
		const installed = '2.1.0-alpha.1';
		const version = '2.1.0-dev.1';
		const release = {
			...candidate('A', version, 'newer', '2026-08-03T00:00:00Z'),
			prerelease: true,
		};
		const listing = {
			successful: true,
			code: 'release_candidates_available',
			data: { installed_version: installed, candidates: [release] },
		};
		const inspection = deferred();
		const harness = createHarness([listing, listing, inspection.promise]);
		const { dataset } = harness.browser;
		dataset.ranBoosterManagedReleaseType = type;
		dataset.ranBoosterManagedReleaseIdentifier =
			type === 'plugin' ? 'example/plugin.php' : 'example';
		dataset.ranBoosterManagedReleaseChannel = 'prerelease';

		const nativeUpdate = harness.nodes.get('native-update');
		const href =
			type === 'plugin'
				? '/wp-admin/plugins.php?action=update-selected&plugins=example%2Fplugin.php&_wpnonce=B'
				: '/wp-admin/update.php?action=upgrade-theme&theme=example&_wpnonce=B';
		nativeUpdate.setAttribute('href', href);
		nativeUpdate.removeAttribute('aria-disabled');
		nativeUpdate.textContent = `Update to ${version}`;

		// This harness represents the visible picker.
		harness.initialize(harness.browser);
		await flush();
		assert.deepEqual(
			harness.requests.map((request) => request.get('action')),
			['ran_booster_managed_release_list_candidates']
		);
		assert.equal(checkedRadio(harness)?.dataset.releaseId, 'A');

		await harness.nodes.get('retry').dispatch('click');
		assert.equal(harness.requests.length, 2);
		assert.equal(
			harness.requests[1].get('action'),
			'ran_booster_managed_release_list_candidates'
		);
		assert.equal(checkedRadio(harness)?.dataset.releaseId, 'A');
		await checkedRadio(harness).dispatch('change');
		assert.equal(harness.requests.length, 2);

		const review = harness.nodes.get('review');
		assert.equal(review.getAttribute('aria-disabled'), 'false');
		const reviewed = review.dispatch('click');
		assert.match(dialogText(harness), /Checking release/);
		assert.equal(harness.requests.length, 3);
		assert.equal(
			harness.requests[2].get('action'),
			'ran_booster_managed_release_inspect'
		);
		assert.equal(harness.requests[2].get('release_id'), 'A');
		assert.equal(harness.requests[2].get('release_tag'), release.tag);
		assert.equal(harness.requests[2].get('expected_type'), type);
		assert.equal(harness.requests[2].get('_wpnonce'), 'inspect');
		inspection.resolve(
			readyResponse(version, 'newer', {
				installed_version: installed,
				native_offer: { available: true, release_id: '', version },
			})
		);
		await reviewed;
		assert.equal(harness.requests.length, 3);
		assert.equal(review.getAttribute('aria-disabled'), 'false');
		assert.match(dialogText(harness), /passed inspection/);
		assert.equal(nativeUpdate.getAttribute('href'), href);
		assert.equal(nativeUpdate.getAttribute('aria-disabled'), null);
		assert.equal(nativeUpdate.textContent, `Update to ${version}`);
	});
	test(`managed ${type} can review a current version and leaves the inverse prerelease disabled`, async () => {
		const installed = '2.1.0-dev.1';
		const old = {
			...candidate(
				'old',
				'2.1.0-alpha.1',
				'older',
				'2026-08-03T00:00:00Z'
			),
			prerelease: true,
		};
		const same = {
			...candidate('same', installed, 'same', '2026-08-02T00:00:00Z'),
			prerelease: true,
		};
		const harness = createHarness([
			{
				successful: true,
				code: 'release_candidates_available',
				data: { installed_version: installed, candidates: [old, same] },
			},
			readyResponse(same.version, 'same', {
				installed_version: installed,
			}),
		]);
		harness.browser.dataset.ranBoosterManagedReleaseType = type;
		harness.browser.dataset.ranBoosterManagedReleaseChannel = 'prerelease';
		harness.initialize(harness.browser);
		await flush();

		assert.equal(harness.requests.length, 1);
		assert.equal(checkedRadio(harness)?.dataset.releaseId, 'same');
		const disabled = radioFor(harness, 'old');
		assert.equal(disabled.disabled, true);
		await disabled.dispatch('change');
		assert.equal(harness.requests.length, 1);
		await harness.nodes.get('review').dispatch('click');
		assert.equal(harness.requests.length, 2);
		assert.equal(harness.requests[1].get('release_id'), 'same');
		assert.match(listText(harness), /Installed\/current/);
		assert.match(dialogText(harness), /matches the installed version/);
		assert.equal(
			harness.nodes.get('native-update').getAttribute('href'),
			null
		);
	});
}

for (const type of ['plugin', 'theme']) {
	for (const panel of ['initial', 'replacement']) {
		test(`managed ${type} ${panel} panel shows the successful review of an equivalent version`, async () => {
			const selected = candidate(
				'A',
				'2.1.0',
				'same',
				'2026-08-03T00:00:00Z'
			);
			const harness = createHarness([
				{
					successful: true,
					code: 'release_candidates_available',
					data: { installed_version: '2.1', candidates: [selected] },
				},
				readyResponse(selected.version, 'same', {
					installed_version: '2.1',
					native_offer: {
						available: true,
						release_id: '',
						version: '3.0.0',
					},
				}),
			]);
			const { dataset } = harness.browser;
			dataset.ranBoosterManagedReleaseType = type;
			dataset.ranBoosterManagedReleaseIdentifier =
				type === 'plugin' ? 'example/plugin.php' : 'example-theme';
			const nativeUpdate = harness.nodes.get('native-update');
			const href =
				type === 'plugin'
					? '/wp-admin/plugins.php?action=update-selected&plugins=example%2Fplugin.php&_wpnonce=B'
					: '/wp-admin/update.php?action=upgrade-theme&theme=example-theme&_wpnonce=B';
			nativeUpdate.setAttribute('href', href);
			nativeUpdate.removeAttribute('aria-disabled');
			nativeUpdate.textContent = 'Update to 3.0.0';

			if (panel === 'replacement') {
				harness.afterPackageSwap();
			} else {
				harness.initialize(harness.browser);
			}
			await flush();
			assert.deepEqual(
				harness.requests.map((request) => request.get('action')),
				['ran_booster_managed_release_list_candidates']
			);
			assert.equal(checkedRadio(harness)?.dataset.releaseId, 'A');
			assert.equal(harness.dialogs.length, 0);

			await harness.nodes.get('review').dispatch('click');
			assert.equal(harness.requests.length, 2);
			assert.equal(
				harness.requests[1].get('action'),
				'ran_booster_managed_release_inspect'
			);
			assert.equal(harness.requests[1].get('release_id'), 'A');
			assert.match(
				dialogText(harness),
				/Installed: 2\.1 → Selected: 2\.1\.0/
			);
			assert.match(dialogText(harness), /matches the installed version/);
			assert.equal(
				descendants(harness.dialogs.at(-1).body).some(
					(node) => node.tagName === 'form'
				),
				false
			);
			assert.equal(nativeUpdate.getAttribute('href'), href);
			assert.equal(nativeUpdate.getAttribute('aria-disabled'), null);
			assert.equal(nativeUpdate.textContent, 'Update to 3.0.0');
		});
	}
}

test('managed browser preselects and explicitly reviews the installed release even when a newer release is listed first', async () => {
	const harness = createHarness(
		[...installedAndNewer, readyResponse('2.0.0', 'same')],
		'new'
	);
	harness.initialize(harness.browser);
	await flush();
	await harness.nodes.get('review').dispatch('click');

	assert.equal(checkedRadio(harness).dataset.releaseId, 'same');
	assert.equal(harness.requests.length, 2);
	assert.equal(harness.requests[1].get('release_id'), 'same');
	assert.equal(
		harness.nodes.get('candidate-list').children[0].children[0].dataset
			.releaseId,
		'new',
		'the newer release stays first in the list'
	);
	const text = listText(harness);
	assert.match(text, /3\.0\.0.*Latest eligible.*Newer than installed/);
	assert.match(text, /2\.0\.0.*Installed\/current/);
	assert.match(dialogText(harness), /matches the installed version/);
});

test('managed browser leaves Update now disabled, with no fallback link, while the installed release is selected', async () => {
	const harness = createHarness(installedAndNewer.slice(), 'new');
	harness.browser.dataset.ranBoosterManagedReleaseWordpressUrl =
		'/wp-admin/plugins.php';
	harness.initialize(harness.browser);
	await flush();
	assert.equal(harness.requests.length, 1);

	const update = harness.nodes.get('native-update');
	assert.equal(update.getAttribute('aria-disabled'), 'true');
	assert.equal(update.getAttribute('href'), null);
	assert.equal(update.textContent, 'Update now');
	const outcomes = descendants(harness.nodes.get('candidate-list')).filter(
		(node) => node.className === 'ran-booster-release-candidate__outcome'
	);
	assert.ok(outcomes.every((node) => node.hidden === true));
	assert.equal(
		descendants(harness.nodes.get('candidate-list')).some(
			(node) => node.tagName === 'a'
		),
		false
	);
});

test('managed browser inspects the newer release without changing the toolbar', async () => {
	const harness = createHarness(
		[
			...installedAndNewer,
			readyResponse('3.0.0', 'newer', {
				native_offer: {
					available: true,
					release_id: 'new',
					version: '3.0.0',
				},
			}),
		],
		'new'
	);
	harness.initialize(harness.browser);
	await flush();
	assert.equal(harness.requests.length, 1);
	assert.equal(
		harness.nodes.get('native-update').getAttribute('aria-disabled'),
		'true'
	);

	const newer = radioFor(harness, 'new');
	newer.checked = true;
	await newer.dispatch('change');
	assert.equal(harness.requests.length, 1);
	await harness.nodes.get('review').dispatch('click');

	assert.equal(harness.requests.length, 2);
	assert.equal(harness.requests[1].get('release_id'), 'new');
	const update = harness.nodes.get('native-update');
	assert.equal(update.getAttribute('aria-disabled'), 'true');
	assert.equal(update.getAttribute('href'), null);
	assert.match(dialogText(harness), /passed inspection/);
});

test('managed browser shows the WordPress screen link only after the newer release without an offer is reviewed', async () => {
	const harness = createHarness(
		[...installedAndNewer, readyResponse('3.0.0', 'newer')],
		'other'
	);
	harness.browser.dataset.ranBoosterManagedReleaseWordpressUrl =
		'/wp-admin/plugins.php';
	harness.initialize(harness.browser);
	await flush();
	assert.equal(harness.requests.length, 1);
	const links = () =>
		harness.dialogs.length
			? descendants(harness.dialogs.at(-1).body).filter(
					(node) => node.tagName === 'a'
				)
			: [];
	assert.equal(links().length, 0);

	const newer = radioFor(harness, 'new');
	newer.checked = true;
	await newer.dispatch('change');
	assert.equal(harness.requests.length, 1);
	await harness.nodes.get('review').dispatch('click');

	assert.equal(links().length, 1);
	assert.equal(
		harness.nodes.get('native-update').getAttribute('aria-disabled'),
		'true'
	);
});

test('managed browser preselects the installed release when only it and older releases are listed', async () => {
	const harness = createHarness([
		{
			successful: true,
			code: 'release_candidates_available',
			data: {
				installed_version: '2.0.0',
				candidates: [
					candidate('old', '1.0.0', 'older', '2026-08-03T00:00:00Z'),
					candidate('same', '2.0.0', 'same', '2026-08-02T00:00:00Z'),
				],
			},
		},
	]);
	harness.initialize(harness.browser);
	await flush();
	assert.equal(harness.requests.length, 1);

	assert.equal(checkedRadio(harness).dataset.releaseId, 'same');
	assert.equal(radioFor(harness, 'old').disabled, true);
	assert.equal(radioFor(harness, 'old').checked, undefined);
});

test('managed browser keeps a review busy while radio events are dispatched', async () => {
	const inspection = deferred();
	const harness = createHarness([
		{
			successful: true,
			code: 'release_candidates_available',
			data: {
				installed_version: '2.0.0',
				candidates: [
					candidate('same', '2.0.0', 'same', '2026-08-03T00:00:00Z'),
				],
			},
		},
		inspection.promise,
	]);

	harness.initialize(harness.browser);
	await flush();

	const reviewButton = harness.nodes.get('review');
	const reviewed = reviewButton.dispatch('click');
	await reviewButton.dispatch('click');
	await checkedRadio(harness).dispatch('change');
	assert.equal(harness.requests.length, 2);
	assert.match(dialogText(harness), /Checking release/);
	assert.equal(harness.nodes.get('retry').disabled, false);

	inspection.resolve({
		successful: true,
		code: 'release_ready',
		data: {
			version: '2.0.0',
			tag: 'v2.0.0',
			installed_version: '2.0.0',
			version_relationship: 'same',
		},
	});
	await reviewed;
	assert.equal(harness.nodes.get('candidates').disabled, false);
	assert.equal(harness.nodes.get('retry').disabled, false);
});

test('managed browser preserves provider candidate order when the installed release is not listed and there is no native update offer', async () => {
	const providerFirst = candidate(
		'provider-first',
		'3.0.0',
		'newer',
		'2026-08-01T00:00:00Z'
	);
	const laterTimestamp = candidate(
		'later-timestamp',
		'3.1.0',
		'newer',
		'2026-08-02T00:00:00Z'
	);
	const harness = createHarness([
		{
			successful: true,
			code: 'release_candidates_available',
			data: {
				installed_version: '2.0.0',
				candidates: [providerFirst, laterTimestamp],
			},
		},
		{
			successful: true,
			code: 'release_ready',
			data: {
				version: '3.0.0',
				tag: 'v3.0.0',
				installed_version: '2.0.0',
				version_relationship: 'newer',
			},
		},
	]);
	harness.initialize(harness.browser);
	await flush();
	await harness.nodes.get('review').dispatch('click');

	assert.equal(harness.requests[1].get('release_id'), 'provider-first');
	assert.equal(
		descendants(harness.nodes.get('candidate-list')).find(
			(node) => node.tagName === 'input' && node.checked
		).dataset.releaseId,
		'provider-first'
	);
});

test('managed browser supports opaque release IDs without selector interpolation', async () => {
	const opaqueId = 'release"opaque';
	const harness = createHarness([
		{
			successful: true,
			code: 'release_candidates_available',
			data: {
				installed_version: '2.0.0',
				candidates: [
					candidate(
						opaqueId,
						'3.0.0',
						'newer',
						'2026-08-03T00:00:00Z'
					),
				],
			},
		},
		{
			successful: true,
			code: 'release_ready',
			data: {
				version: '3.0.0',
				tag: 'v3.0.0',
				installed_version: '2.0.0',
				version_relationship: 'newer',
			},
		},
	]);
	harness.initialize(harness.browser);
	await flush();
	await harness.nodes.get('review').dispatch('click');

	assert.equal(harness.requests[1].get('release_id'), opaqueId);
	assert.equal(
		descendants(harness.nodes.get('candidate-list')).find(
			(node) => node.tagName === 'input' && node.checked
		).dataset.releaseId,
		opaqueId
	);
});

test('managed browser keeps installed version separate and ignores stale responses', async () => {
	const newer = candidate('new', '3.0.0', 'newer', '2026-08-03T00:00:00Z');
	const same = candidate('same', '2.0.0', 'same', '2026-08-04T00:00:00Z');
	const staleInspection = deferred();
	const harness = createHarness([
		{
			successful: true,
			code: 'release_candidates_available',
			data: { installed_version: '2.0.0', candidates: [newer] },
		},
		staleInspection.promise,
		{
			successful: true,
			code: 'release_candidates_available',
			data: { installed_version: '2.0.0', candidates: [same] },
		},
	]);
	harness.initialize(harness.browser);
	await flush();
	const reviewed = harness.nodes.get('review').dispatch('click');
	assert.equal(
		descendants(harness.nodes.get('candidate-list')).find((node) =>
			node.className?.includes('installed-version')
		).textContent,
		'Installed version: 2.0.0'
	);
	await harness.nodes.get('retry').dispatch('click');
	const staleDialog = harness.dialogs[0];
	assert.equal(staleDialog.closed, true);
	staleInspection.resolve({
		successful: true,
		code: 'release_ready',
		data: {
			version: '3.0.0',
			tag: 'v3.0.0',
			installed_version: '2.0.0',
			version_relationship: 'newer',
		},
	});
	await reviewed;
	assert.equal(checkedRadio(harness)?.dataset.releaseId, 'same');
	assert.equal(harness.requests.length, 3);
	assert.equal(
		descendants(staleDialog.body).some((node) => node.tagName === 'form'),
		false
	);
	assert.equal(
		harness.nodes.get('native-update').getAttribute('aria-disabled'),
		'true'
	);
});

test('managed browser keeps inspection separate from the native offer identity', async () => {
	const harness = createHarness(
		[
			{
				successful: true,
				code: 'release_candidates_available',
				data: {
					installed_version: '2.0.0',
					candidates: [
						candidate(
							'new',
							'3.0.0',
							'newer',
							'2026-08-03T00:00:00Z'
						),
					],
				},
			},
			{
				successful: true,
				code: 'release_ready',
				data: {
					version: '3.0.0',
					tag: 'v3.0.0',
					installed_version: '2.0.0',
					version_relationship: 'newer',
					native_offer: {
						available: true,
						release_id: 'new',
						version: '3.0.0',
					},
				},
			},
		],
		'new'
	);
	harness.initialize(harness.browser);
	await flush();
	await harness.nodes.get('review').dispatch('click');
	assert.match(dialogText(harness), /Installed: 2\.0\.0 → Selected: 3\.0\.0/);
	assert.match(dialogText(harness), /passed inspection/);
});

test('managed browser preserves provider order when previous page data names another offer', async () => {
	const harness = createHarness([
		{
			successful: true,
			code: 'release_candidates_available',
			data: {
				installed_version: '2.0.0',
				candidates: [
					candidate(
						'later',
						'3.1.0',
						'newer',
						'2026-08-04T00:00:00Z'
					),
					candidate(
						'offer',
						'3.0.0',
						'newer',
						'2026-08-03T00:00:00Z'
					),
				],
			},
		},
		{
			successful: true,
			code: 'release_ready',
			data: {
				version: '3.1.0',
				tag: 'v3.1.0',
				installed_version: '2.0.0',
				version_relationship: 'newer',
			},
		},
	]);
	harness.initialize(harness.browser);
	await flush();
	await harness.nodes.get('review').dispatch('click');

	assert.equal(harness.requests[1].get('release_id'), 'later');
	assert.equal(
		descendants(harness.nodes.get('candidate-list')).find(
			(node) => node.tagName === 'input' && node.checked
		).dataset.releaseId,
		'later'
	);
	assert.match(
		descendants(harness.nodes.get('candidate-list'))
			.map((node) => node.textContent || '')
			.join(' '),
		/3\.1\.0.*Latest eligible/
	);
});

test('managed browser sends a newer theme release without an offer to the Themes screen', async () => {
	for (const [type, expected] of [
		['plugin', /Open WordPress Plugins/],
		['theme', /Open WordPress Themes/],
	]) {
		const harness = createHarness([
			{
				successful: true,
				code: 'release_candidates_available',
				data: {
					installed_version: '2.0.0',
					candidates: [
						candidate(
							'other',
							'3.1.0',
							'newer',
							'2026-08-03T00:00:00Z'
						),
					],
				},
			},
			{
				successful: true,
				code: 'release_ready',
				data: {
					version: '3.1.0',
					tag: 'v3.1.0',
					installed_version: '2.0.0',
					version_relationship: 'newer',
				},
			},
		]);
		harness.browser.dataset.ranBoosterManagedReleaseType = type;
		harness.browser.dataset.ranBoosterManagedReleaseWordpressUrl =
			'/wp-admin/' + type + 's.php';
		harness.initialize(harness.browser);
		await flush();
		await harness.nodes.get('review').dispatch('click');

		assert.match(dialogText(harness), expected, type);
		assert.doesNotMatch(
			dialogText(harness),
			type === 'theme'
				? /Open WordPress Plugins/
				: /Open WordPress Themes/,
			type
		);
	}
});

test('managed browser requires identity to match a same-version WordPress offer', async () => {
	const harness = createHarness(
		[
			{
				successful: true,
				code: 'release_candidates_available',
				data: {
					installed_version: '2.0.0',
					candidates: [
						candidate(
							'other',
							'3.0.0',
							'newer',
							'2026-08-04T00:00:00Z'
						),
						candidate(
							'new',
							'3.0.0',
							'newer',
							'2026-08-03T00:00:00Z'
						),
					],
				},
			},
			{
				successful: true,
				code: 'release_ready',
				data: {
					version: '3.0.0',
					tag: 'v3.0.0',
					installed_version: '2.0.0',
					version_relationship: 'newer',
					native_offer: {
						available: true,
						release_id: '',
						version: '3.0.0',
					},
				},
			},
		],
		''
	);
	harness.initialize(harness.browser);
	await flush();
	await harness.nodes.get('review').dispatch('click');

	const checked = descendants(harness.nodes.get('candidate-list')).find(
		(node) => node.type === 'radio' && node.checked
	);
	assert.equal(
		checked?.dataset.releaseId,
		'other',
		'the provider order is preserved'
	);
	assert.match(dialogText(harness), /passed inspection/);
	assert.doesNotMatch(
		dialogText(harness),
		/matches the current WordPress update offer/
	);
});

test('managed browser shows a visible WordPress screen link beside a newer release WordPress has not offered', async () => {
	const harness = createHarness([
		{
			successful: true,
			code: 'release_candidates_available',
			data: {
				installed_version: '2.0.0',
				candidates: [
					candidate(
						'other',
						'3.1.0',
						'newer',
						'2026-08-03T00:00:00Z'
					),
				],
			},
		},
		{
			successful: true,
			code: 'release_ready',
			data: {
				version: '3.1.0',
				tag: 'v3.1.0',
				installed_version: '2.0.0',
				version_relationship: 'newer',
			},
		},
	]);
	harness.browser.dataset.ranBoosterManagedReleaseWordpressUrl =
		'/wp-admin/plugins.php';
	harness.initialize(harness.browser);
	await flush();
	await harness.nodes.get('review').dispatch('click');

	const link = harness.dialogs
		.at(-1)
		.body.children.find((node) => node?.tagName === 'a');
	assert.ok(link, 'a visible link to the WordPress screen is expected');
	assert.equal(link.href, '/wp-admin/plugins.php');
	assert.equal(link.textContent, 'Open WordPress Plugins');
});

test('managed browser leaves the native Core update disabled for a newer release outside the rendered offer', async () => {
	const harness = createHarness([
		{
			successful: true,
			code: 'release_candidates_available',
			data: {
				installed_version: '2.0.0',
				candidates: [
					candidate(
						'other',
						'3.1.0',
						'newer',
						'2026-08-03T00:00:00Z'
					),
				],
			},
		},
		{
			successful: true,
			code: 'release_ready',
			data: {
				version: '3.1.0',
				tag: 'v3.1.0',
				installed_version: '2.0.0',
				version_relationship: 'newer',
			},
		},
	]);
	harness.initialize(harness.browser);
	await flush();
	await harness.nodes.get('review').dispatch('click');

	assert.equal(
		harness.nodes.get('native-update').getAttribute('aria-disabled'),
		'true'
	);
	assert.equal(harness.nodes.get('native-update').getAttribute('href'), null);
	assert.equal(harness.nodes.get('native-update').textContent, 'Update now');
});

test('managed browser leaves the native Core update disabled when a same-version release has a different identity', async () => {
	const harness = createHarness([
		{
			successful: true,
			code: 'release_candidates_available',
			data: {
				installed_version: '2.0.0',
				candidates: [
					candidate(
						'different-release',
						'3.0.0',
						'newer',
						'2026-08-03T00:00:00Z'
					),
				],
			},
		},
		{
			successful: true,
			code: 'release_ready',
			data: {
				version: '3.0.0',
				tag: 'v3.0.0',
				installed_version: '2.0.0',
				version_relationship: 'newer',
			},
		},
	]);
	harness.initialize(harness.browser);
	await flush();
	await harness.nodes.get('review').dispatch('click');

	assert.equal(
		harness.nodes.get('native-update').getAttribute('aria-disabled'),
		'true'
	);
	assert.equal(harness.nodes.get('native-update').getAttribute('href'), null);
});

test('managed browser keeps native Core update disabled when the offer changes after inspection', async () => {
	const harness = createHarness(
		[
			{
				successful: true,
				code: 'release_candidates_available',
				data: {
					installed_version: '2.0.0',
					candidates: [
						candidate(
							'offer',
							'3.0.0',
							'newer',
							'2026-08-03T00:00:00Z'
						),
					],
				},
			},
			{
				successful: true,
				code: 'release_ready',
				data: {
					version: '3.0.0',
					tag: 'v3.0.0',
					installed_version: '2.0.0',
					version_relationship: 'newer',
					native_offer: {
						available: true,
						release_id: 'replacement',
						version: '3.1.0',
					},
				},
			},
		],
		'offer'
	);
	harness.initialize(harness.browser);
	await flush();
	await harness.nodes.get('review').dispatch('click');

	assert.equal(
		harness.nodes.get('native-update').getAttribute('aria-disabled'),
		'true'
	);
	assert.equal(harness.nodes.get('native-update').getAttribute('href'), null);
});

test('managed browser leaves incomparable releases disabled without claiming they are older', async () => {
	const harness = createHarness([
		{
			successful: true,
			code: 'release_candidates_available',
			data: {
				installed_version: '2.0.0',
				candidates: [
					candidate(
						'unknown',
						'3.0.0',
						'invalid',
						'2026-08-03T00:00:00Z'
					),
					candidate('old', '1.0.0', 'older', '2026-08-02T00:00:00Z'),
				],
			},
		},
	]);
	harness.browser.dataset.ranBoosterManagedReleaseChannel = 'prerelease';
	harness.initialize(harness.browser);
	await flush();
	await flush();

	const radio = radioFor(harness, 'unknown');
	assert.equal(radio.disabled, true);
	assert.equal(Boolean(radio.checked), false);
	await radio.dispatch('change');
	assert.equal(harness.requests.length, 1);
	assert.match(listText(harness), /Version comparison unavailable/);
	assert.doesNotMatch(
		listText(harness),
		/Newer than installed|only versions older/
	);
	assert.match(
		harness.nodes.get('message').textContent,
		/could not compare these release versions/
	);
});

test('managed inspection reports an unavailable comparison after selection', async () => {
	const harness = createHarness([
		{
			successful: true,
			code: 'release_candidates_available',
			data: {
				installed_version: '2.0.0',
				candidates: [
					candidate('new', '3.0.0', 'newer', '2026-08-03T00:00:00Z'),
				],
			},
		},
		readyResponse('3.0.0', 'invalid'),
	]);
	harness.browser.dataset.ranBoosterManagedReleaseWordpressUrl =
		'/wp-admin/plugins.php';
	const nativeUpdate = harness.nodes.get('native-update');
	nativeUpdate.setAttribute(
		'href',
		'/wp-admin/plugins.php?action=update-selected&_wpnonce=B'
	);
	nativeUpdate.removeAttribute('aria-disabled');
	harness.initialize(harness.browser);
	await flush();
	assert.equal(checkedRadio(harness)?.dataset.releaseId, 'new');
	assert.match(listText(harness), /Newer than installed/);
	await harness.nodes.get('review').dispatch('click');

	assert.equal(harness.requests.length, 2);
	assert.match(dialogText(harness), /Version comparison unavailable/);
	const radio = radioFor(harness, 'new');
	assert.equal(radio.disabled, true);
	assert.equal(radio.checked, false);
	assert.match(listText(harness), /Version comparison unavailable/);
	assert.doesNotMatch(
		listText(harness),
		/Newer than installed|Latest eligible/
	);
	assert.equal(
		harness.nodes.get('review').getAttribute('aria-disabled'),
		'true'
	);
	assert.equal(harness.nodes.get('retry').disabled, false);
	await radio.dispatch('change');
	await harness.nodes.get('review').dispatch('click');
	assert.equal(harness.requests.length, 2);
	assert.equal(nativeUpdate.getAttribute('aria-disabled'), null);
	assert.match(nativeUpdate.getAttribute('href'), /_wpnonce=B$/);
	assert.equal(
		descendants(harness.dialogs.at(-1).body).some(
			(node) => node.tagName === 'form'
		),
		false
	);
});

test('managed browser shows an explicit no-downgrade warning when Preview offers only older releases', async () => {
	const harness = createHarness([
		{
			successful: true,
			code: 'release_candidates_available',
			data: {
				installed_version: '2.0.0',
				candidates: [
					candidate('old', '1.0.0', 'older', '2026-08-03T00:00:00Z'),
				],
			},
		},
	]);
	harness.browser.dataset.ranBoosterManagedReleaseChannel = 'prerelease';
	harness.initialize(harness.browser);
	await flush();
	await flush();

	assert.match(
		descendants(harness.nodes.get('candidate-list'))
			.map((node) => node.textContent || '')
			.join(' '),
		/Preview track currently offers only versions older than installed\. WordPress Updates will not downgrade this package\./
	);
	// The warning is the shared static Notice, placed in the candidate list
	// with no event asking anything to redraw it.
	assert.deepEqual(harness.noticeCalls, [
		{
			intent: 'warning',
			className: 'ran-booster-release-candidate__warning',
			scope: 'global',
		},
	]);
	const warning = harness.nodes.get('candidate-list').children.at(-1);
	assert.equal(warning.children[0].children[0].tagName, 'p');
	assert.deepEqual(warning.dispatchedEvents, []);
});

test('managed browser keeps provider failures visible beside Refresh releases', async () => {
	const harness = createHarness([
		{ successful: false, code: 'unable_to_check', data: {} },
	]);

	harness.initialize(harness.browser);
	await flush();
	await flush();

	assert.equal(harness.nodes.get('candidates').hidden, true);
	assert.equal(harness.nodes.get('error').hidden, false);
	assert.match(
		harness.nodes.get('error-message').textContent,
		/could not list releases for this package/i
	);
	assert.equal(harness.nodes.get('retry').disabled, false);
	assert.equal(harness.nodes.get('retry').textContent, 'Refresh releases');
	assert.equal(
		harness.nodes
			.get('retry')
			.classList.contains('ran-booster-update-is-active'),
		false
	);
});

test('managed release listing distinguishes an HTTP failure from a missing or unusable response', async () => {
	for (const [reply, expected] of [
		[() => ({ ok: false, status: 502 }), /site returned HTTP 502/i],
		[() => ({ ok: false, status: 429 }), /site returned HTTP 429/i],
		[
			() => Promise.reject(new Error('offline')),
			/usable response from this site/i,
		],
		[
			() => ({ ok: true, json: async () => JSON.parse('<html>') }),
			/usable response from this site/i,
		],
	]) {
		const harness = createHarness([reply]);
		harness.initialize(harness.browser);
		await flush();
		const message = harness.nodes.get('error-message').textContent;
		assert.match(message, expected);
		assert.doesNotMatch(message, /rate.limit|credential/i);
		assert.equal(harness.nodes.get('retry').disabled, false);
	}
});

test('managed browser adds the lookup-credential hint only when the server supplies one', async () => {
	const hint = {
		text: 'A dedicated lookup credential helps avoid rate limits.',
		link: 'Choose a lookup credential',
		url: 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh#ran-booster-public-lookup-profile-region',
	};
	const hinted = createHarness([
		{
			successful: false,
			code: 'unable_to_check',
			data: { lookup_hint: hint },
		},
	]);
	hinted.initialize(hinted.browser);
	await flush();
	await flush();
	const message = hinted.nodes.get('error-message');
	assert.equal(hinted.nodes.get('error').hidden, false);
	assert.match(
		message.textContent,
		/^The Git provider is limiting lookups from this site\. Try again later\./
	);
	assert.equal(message.children[0], ` ${hint.text} `);
	assert.equal(message.children[1].tagName, 'a');
	assert.equal(message.children[1].href, hint.url);
	assert.equal(message.children[1].textContent, hint.link);

	for (const data of [
		{},
		{ lookup_hint: { ...hint, url: 'https://evil.test/' } },
		{ lookup_hint: { ...hint, url: 'javascript:alert(1)' } },
	]) {
		const plain = createHarness([
			{ successful: false, code: 'unable_to_check', data },
		]);
		plain.initialize(plain.browser);
		await flush();
		await flush();
		assert.equal(plain.nodes.get('error').hidden, false);
		assert.deepEqual(plain.nodes.get('error-message').children, []);
	}
});

test('a rate limit names the provider and the retry time on the browser clock', async () => {
	const zone = process.env.TZ;
	// A zone far from UTC: the time shown must be the browser's, never the server's.
	process.env.TZ = 'Pacific/Auckland';
	try {
		const retryAt = Math.floor(Date.now() / 1000) + 3540;
		const options = { hour: 'numeric', minute: '2-digit' };
		const shown = new Date(Math.ceil(retryAt / 60) * 60000);
		const local = shown.toLocaleTimeString(undefined, options);
		assert.notEqual(
			local,
			shown.toLocaleTimeString(undefined, { ...options, timeZone: 'UTC' })
		);
		for (const [rateLimit, expected] of [
			[
				{ provider: 'GitHub', retry_at: retryAt },
				`GitHub is limiting lookups from this site. Try again after ${local}.`,
			],
			[
				{ provider: 'GitHub', retry_at: null },
				'GitHub is limiting lookups from this site. Try again later.',
			],
			[
				{ provider: 'GitHub', retry_at: retryAt - 7200 },
				'GitHub is limiting lookups from this site. Try again later.',
			],
			[
				{ provider: 'GitHub', retry_at: retryAt + 86400 },
				'GitHub is limiting lookups from this site. Try again later.',
			],
			[
				{ provider: '', retry_at: String(retryAt) },
				'The Git provider is limiting lookups from this site. Try again later.',
			],
		]) {
			const harness = createHarness([
				{
					successful: false,
					code: 'unable_to_check',
					data: {
						rejection_reason: 'rate_limited',
						rate_limit: rateLimit,
					},
				},
			]);
			harness.initialize(harness.browser);
			await flush();
			await flush();
			assert.equal(
				harness.nodes.get('error-message').textContent,
				expected
			);
		}
	} finally {
		if (zone === undefined) {
			delete process.env.TZ;
		} else {
			process.env.TZ = zone;
		}
	}
});

test('managed browser keeps empty stable and preview tracks visible', async () => {
	for (const [channel, copy] of [
		['stable', 'No eligible releases were found in this search.'],
		['prerelease', 'No eligible releases were found in this search.'],
	]) {
		const harness = createHarness([
			{
				successful: true,
				code: 'no_releases',
				data: { channel, candidates: [] },
			},
		]);
		harness.browser.dataset.ranBoosterManagedReleaseChannel = channel;
		harness.initialize(harness.browser);
		await flush();
		await flush();

		assert.equal(harness.nodes.get('candidates').hidden, false);
		assert.equal(harness.nodes.get('error').hidden, true);
		assert.equal(harness.nodes.get('retry').disabled, false);
		assert.equal(
			harness.nodes.get('retry').textContent,
			'Refresh releases'
		);
		assert.equal(
			harness.nodes.get('candidate-list').children[0].textContent,
			copy
		);
	}
});

test('managed browser initializes a swapped panel once without duplicate listeners or requests', async () => {
	const harness = createHarness([
		{
			successful: true,
			code: 'release_candidates_available',
			data: {
				installed_version: '2.1.0-alpha.1',
				candidates: [
					candidate(
						'A',
						'2.1.0-dev.1',
						'newer',
						'2026-08-03T00:00:00Z'
					),
				],
			},
		},
	]);
	harness.browser.dataset.ranBoosterManagedReleaseChannel = 'prerelease';

	harness.afterPackageSwap();
	harness.afterPackageSwap();
	await flush();
	await flush();

	assert.equal(harness.requests.length, 1);
	assert.equal(harness.nodes.get('retry').listeners.click.length, 1);
	assert.equal(harness.nodes.get('candidates').hidden, false);
	assert.equal(harness.nodes.get('review').listeners.click.length, 1);
	assert.equal(checkedRadio(harness)?.dataset.releaseId, 'A');
	assert.equal(
		harness.requests[0].get('action'),
		'ran_booster_managed_release_list_candidates'
	);
});

test('managed browser restores visible refresh state after a list read completes', async () => {
	const pendingResponse = deferred();
	const harness = createHarness([pendingResponse.promise]);
	harness.initialize(harness.browser);

	assert.equal(harness.nodes.get('retry').disabled, true);
	assert.equal(harness.nodes.get('retry').textContent, 'Refresh releases');
	assert.equal(
		harness.nodes
			.get('retry')
			.classList.contains('ran-booster-update-is-active'),
		true
	);
	assert.equal(
		harness.nodes
			.get('retry')
			.classList.contains(
				'ran-booster-enhanced-mutation__submitter--busy'
			),
		true
	);
	assert.equal(harness.browser.getAttribute('aria-busy'), 'true');

	pendingResponse.resolve({
		successful: true,
		code: 'no_releases',
		data: { channel: 'stable', candidates: [] },
	});
	await flush();
	await flush();

	assert.equal(harness.nodes.get('retry').disabled, false);
	assert.equal(harness.nodes.get('retry').textContent, 'Refresh releases');
	assert.equal(
		harness.nodes
			.get('retry')
			.classList.contains('ran-booster-update-is-active'),
		false
	);
	assert.equal(harness.browser.getAttribute('aria-busy'), 'false');
});

test('managed browser leaves refresh busy for the current request when a stale list read completes', async () => {
	const firstResponse = deferred();
	const secondResponse = deferred();
	const harness = createHarness([
		firstResponse.promise,
		secondResponse.promise,
	]);
	harness.initialize(harness.browser);
	await flush();
	harness.nodes.get('retry').dispatch('click');
	await flush();

	firstResponse.resolve({
		successful: true,
		code: 'no_releases',
		data: { channel: 'stable', candidates: [] },
	});
	await flush();

	assert.equal(harness.nodes.get('retry').disabled, true);
	assert.equal(harness.nodes.get('retry').textContent, 'Refresh releases');
	assert.equal(
		harness.nodes
			.get('retry')
			.classList.contains('ran-booster-update-is-active'),
		true
	);
	assert.equal(harness.browser.getAttribute('aria-busy'), 'true');

	secondResponse.resolve({
		successful: true,
		code: 'no_releases',
		data: { channel: 'stable', candidates: [] },
	});
	await flush();
	await flush();

	assert.equal(harness.nodes.get('retry').disabled, false);
	assert.equal(harness.nodes.get('retry').textContent, 'Refresh releases');
	assert.equal(
		harness.nodes
			.get('retry')
			.classList.contains('ran-booster-update-is-active'),
		false
	);
	assert.equal(harness.browser.getAttribute('aria-busy'), 'false');
});

test('managed browser removes stale candidates while Refresh releases is pending', async () => {
	const pendingRefresh = deferred();
	const harness = createHarness([
		{
			successful: true,
			code: 'release_candidates_available',
			data: {
				installed_version: '2.0.0',
				candidates: [
					candidate(
						'offer',
						'3.0.0',
						'newer',
						'2026-08-03T00:00:00Z'
					),
				],
			},
		},
		pendingRefresh.promise,
	]);
	harness.initialize(harness.browser);
	await flush();
	await flush();

	assert.notEqual(harness.nodes.get('candidate-list').children.length, 0);
	harness.nodes.get('retry').dispatch('click');

	assert.equal(harness.nodes.get('candidate-list').children.length, 0);
	assert.equal(harness.nodes.get('candidates').hidden, true);
	pendingRefresh.resolve({
		successful: true,
		code: 'no_releases',
		data: { channel: 'stable', candidates: [] },
	});
	await flush();
});

test('release automation and webhook setup use the shared native disclosure shell', () => {
	assert.match(
		primitiveStyles,
		/:is\(\.ran-booster-settings-section, \.ran-booster-disclosure\),[\s\S]*?\{\s*border-radius: var\(--ran-booster-radius-card\)/
	);
	assert.match(
		primitiveStyles,
		/\.ran-booster-disclosure > summary \{[\s\S]*display: list-item;[\s\S]*cursor: pointer/
	);
	assert.match(
		primitiveStyles,
		/\.ran-booster-disclosure\[open\] > summary \{[\s\S]*border-block-end: 1px solid var\(--ran-booster-divider\)/
	);
	assert.match(
		primitiveStyles,
		/\.ran-booster-disclosure__body \{[\s\S]*padding: var\(--ran-booster-space-16\)/
	);
	assert.doesNotMatch(
		packageStyles,
		/ran-booster-package-disclosure > summary::before/
	);
	assert.doesNotMatch(styles, /ran-booster-release-workflow \{/);
});

test('managed browser hides the pending placeholder once real rows render', async () => {
	const harness = createHarness([
		{
			successful: true,
			code: 'release_candidates_available',
			data: {
				installed_version: '2.0.0',
				candidates: [
					candidate('old', '1.0.0', 'older', '2026-08-02T00:00:00Z'),
				],
			},
		},
		{
			successful: true,
			code: 'release_ready',
			data: {
				version: '1.0.0',
				tag: 'v1.0.0',
				installed_version: '2.0.0',
				version_relationship: 'older',
			},
		},
	]);
	harness.nodes.get('placeholder').hidden = false;
	harness.initialize(harness.browser);
	assert.equal(harness.nodes.get('placeholder').hidden, false);
	assert.equal(harness.nodes.get('candidates').hidden, true);
	await flush();
	await flush();
	assert.equal(harness.nodes.get('candidates').hidden, false);
	assert.equal(harness.nodes.get('placeholder').hidden, true);
});

test('managed browser hides the pending placeholder beside an empty or failed list', async () => {
	const empty = createHarness([
		{
			successful: true,
			code: 'no_releases',
			data: { installed_version: '2.0.0', candidates: [] },
		},
	]);
	empty.nodes.get('placeholder').hidden = false;
	empty.initialize(empty.browser);
	await flush();
	assert.equal(empty.nodes.get('placeholder').hidden, true);

	const failed = createHarness([
		{ successful: false, code: 'provider_error', data: {} },
	]);
	failed.nodes.get('placeholder').hidden = false;
	failed.initialize(failed.browser);
	await flush();
	assert.equal(failed.nodes.get('placeholder').hidden, true);
});

test('managed browser shows the pending placeholder again while Refresh releases reads', async () => {
	const harness = createHarness([
		{
			successful: true,
			code: 'no_releases',
			data: { installed_version: '2.0.0', candidates: [] },
		},
		{
			successful: true,
			code: 'no_releases',
			data: { installed_version: '2.0.0', candidates: [] },
		},
	]);
	harness.initialize(harness.browser);
	await flush();
	assert.equal(harness.nodes.get('placeholder').hidden, true);
	harness.nodes.get('retry').listeners.click[0]();
	assert.equal(harness.nodes.get('placeholder').hidden, false);
	await flush();
	assert.equal(harness.nodes.get('placeholder').hidden, true);
});

test('managed browser has no footer wrapper and the helper copy is gone', () => {
	assert.doesNotMatch(styles, /managed-release-browser__footer/);
	assert.doesNotMatch(styles, /browser__header p/);
	assert.match(
		styles,
		/\.ran-booster-release-candidate__skeleton \{[^}]*block-size: 12px;[^}]*inline-size: 55%/
	);
	assert.doesNotMatch(styles, /animation/);
	assert.match(
		styles,
		/@media \(max-width: 782px\)[\s\S]*?\.ran-wp-ui-button \{\s*align-self: flex-end;/
	);
});

test('the source switch bar is one shared state row with the message at the start and the action at the end', () => {
	assert.match(
		packageStyles,
		/\.ran-booster-source-switch-bar \{[^}]*display: flex;[^}]*justify-content: space-between;[^}]*flex-wrap: wrap;[^}]*border: 1px solid var\(--ran-booster-border\);[^}]*background: var\(--ran-booster-surface-subtle\)/
	);
	assert.match(
		packageStyles,
		/\.ran-booster-source-switch-bar > p \{[^}]*margin: 0;[^}]*min-inline-size: 0/
	);
	assert.doesNotMatch(
		packageStyles,
		/\.ran-booster-release-switch-actions \{/
	);
	assert.doesNotMatch(packageStyles, /\.ran-booster-source-transition \{/);
});

test('the standalone native update link survives inspection and a candidate refresh', async () => {
	const harness = createHarness(
		[
			{
				successful: true,
				code: 'release_candidates_available',
				data: {
					installed_version: '2.0.0',
					candidates: [
						candidate(
							'new',
							'3.0.0',
							'newer',
							'2026-08-03T00:00:00Z'
						),
					],
				},
			},
			{
				successful: true,
				code: 'release_ready',
				data: {
					version: '3.0.0',
					tag: 'v3.0.0',
					installed_version: '2.0.0',
					version_relationship: 'newer',
					native_offer: {
						available: true,
						release_id: 'new',
						version: '3.0.0',
					},
				},
			},
			{
				successful: true,
				code: 'no_releases',
				data: { installed_version: '2.0.0', candidates: [] },
			},
		],
		'new'
	);
	const button = harness.nodes.get('native-update');
	const href =
		'/wp-admin/plugins.php?action=update-selected&plugins=example%2Fplugin.php&_wpnonce=bulk';
	button.removeAttribute('aria-disabled');
	button.classList.remove('disabled');
	button.setAttribute('href', href);
	button.textContent = 'Update to 3.0.0';
	// The browser subtree does not contain the button.
	assert.equal(
		harness.browser.querySelector(
			'[data-ran-booster-managed-release-native-update]'
		),
		null
	);

	harness.initialize(harness.browser);
	await flush();
	await harness.nodes.get('review').dispatch('click');

	assert.equal(button.getAttribute('aria-disabled'), null);
	assert.equal(button.getAttribute('tabindex'), null);
	assert.equal(button.classList.contains('disabled'), false);
	assert.match(button.getAttribute('href'), /update-selected/);
	assert.equal(button.textContent, 'Update to 3.0.0');

	await harness.nodes.get('retry').dispatch('click');
	await flush();
	await flush();

	assert.equal(button.getAttribute('aria-disabled'), null);
	assert.equal(button.getAttribute('href'), href);
	assert.equal(button.textContent, 'Update to 3.0.0');
});

test('review opens checking immediately and cancelling prevents a late install form', async () => {
	const pending = deferred();
	const harness = createHarness([
		installedAndNewer[0],
		() => pending.promise,
	]);
	harness.browser.dataset.ranBoosterManagedReleasePostUrl =
		'/wp-admin/admin-post.php';
	harness.initialize(harness.browser);
	await flush();
	assert.equal(harness.requests.length, 1);
	assert.equal(
		harness.nodes.get('review').textContent,
		'Review release 2.0.0'
	);
	const review = harness.nodes.get('review').dispatch('click');
	const dialog = harness.dialogs[0];
	assert.equal(dialog.options.title, 'Review 2.0.0');
	assert.equal(
		dialog.body.children[0].textContent,
		'Checking release 2.0.0…'
	);
	assert.equal(harness.requests.length, 2);
	await dialog.options.footer.children[0].dispatch('click');
	pending.resolve({
		ok: true,
		json: async () =>
			readyResponse('2.0.0', 'same', {
				chosen_install: {
					release_id: 'same',
					release_tag: 'v2.0.0',
					release_version: '2.0.0',
					installed_version: '2.0.0',
					artifact_identity: 'asset',
					artifact_sha256: 'a'.repeat(64),
					_wpnonce: 'nonce',
				},
			}),
	});
	await review;
	assert.equal(dialog.closed, true);
	assert.equal(
		descendants(dialog.body).some((node) => node.tagName === 'form'),
		false
	);
});

test('listing omits redundant tags, stable markers and zero skip counts', async () => {
	const distinct = {
		...candidate('distinct', '3.0.0', 'newer', ''),
		tag: 'release-three',
		prerelease: true,
	};
	const harness = createHarness([
		{
			successful: true,
			code: 'release_candidates_available',
			data: {
				installed_version: '2.0.0',
				candidates: [candidate('same', '2.0.0', 'same', ''), distinct],
				diagnostics: { scanned_count: 2, dropped_count: 0 },
			},
		},
	]);
	harness.initialize(harness.browser);
	await flush();
	assert.doesNotMatch(listText(harness), /v2\.0\.0|Stable|0 skipped/);
	assert.match(listText(harness), /release-three.*Preview/);
	assert.equal(harness.requests.length, 1);
	const radio = radioFor(harness, 'distinct');
	radio.checked = true;
	await radio.dispatch('change');
	assert.equal(harness.requests.length, 1);
	assert.equal(
		harness.nodes.get('review').textContent,
		'Review release 3.0.0'
	);
});

test('reselecting and reopening ignores the previous inspection response', async () => {
	const pending = deferred();
	const harness = createHarness([
		installedAndNewer[0],
		() => pending.promise,
		readyResponse('3.0.0', 'newer'),
	]);
	harness.initialize(harness.browser);
	await flush();
	const firstReview = harness.nodes.get('review').dispatch('click');
	const firstDialog = harness.dialogs[0];
	await firstDialog.options.footer.children[0].dispatch('click');
	const newer = radioFor(harness, 'new');
	newer.checked = true;
	await newer.dispatch('change');
	await harness.nodes.get('review').dispatch('click');
	const secondDialog = harness.dialogs[1];
	assert.match(dialogText(harness), /Selected: 3\.0\.0/);
	pending.resolve({
		ok: true,
		json: async () =>
			readyResponse('2.0.0', 'same', {
				chosen_install: {
					release_id: 'same',
					release_tag: 'v2.0.0',
					release_version: '2.0.0',
					installed_version: '2.0.0',
					artifact_identity: 'asset',
					artifact_sha256: 'a'.repeat(64),
					_wpnonce: 'nonce',
				},
			}),
	});
	await firstReview;
	assert.equal(firstDialog.closed, true);
	assert.equal(secondDialog.closed, false);
	assert.match(dialogText(harness), /Selected: 3\.0\.0/);
	assert.equal(
		descendants(firstDialog.body).some((node) => node.tagName === 'form'),
		false
	);
	assert.equal(
		descendants(secondDialog.body).some((node) => node.tagName === 'form'),
		false
	);
});

test('external close blocks a late inspection before asynchronous teardown', async () => {
	const pending = deferred();
	const harness = createHarness([
		installedAndNewer[0],
		() => pending.promise,
	]);
	harness.browser.dataset.ranBoosterManagedReleasePostUrl =
		'/wp-admin/admin-post.php';
	harness.initialize(harness.browser);
	await flush();
	const review = harness.nodes.get('review').dispatch('click');
	const dialog = harness.dialogs[0];
	assert.equal(dialog.title, 'Review 2.0.0');
	dialog.closeWithoutTeardown();
	assert.equal(dialog.body.parentNode, dialog.surface);
	pending.resolve({
		ok: true,
		json: async () =>
			readyResponse('2.0.0', 'same', {
				chosen_install: {
					release_id: 'same',
					release_tag: 'v2.0.0',
					release_version: '2.0.0',
					installed_version: '2.0.0',
					artifact_identity: 'asset',
					artifact_sha256: 'a'.repeat(64),
					_wpnonce: 'nonce',
				},
			}),
	});
	await review;
	assert.equal(
		descendants(dialog.body).some((node) => node.tagName === 'form'),
		false
	);
	assert.equal(
		dialog.options.footer.children.some((node) => node.type === 'submit'),
		false
	);
	assert.equal(dialog.title, 'Review 2.0.0');
	dialog.finishTeardown();
});

test('inspection rate limits stay inside the review dialog for every relationship', async () => {
	const hint = {
		text: 'A dedicated lookup credential helps avoid rate limits.',
		link: 'Choose a lookup credential',
		url: 'https://example.test/wp-admin/admin.php?page=ran-booster#lookup',
	};
	for (const [relationship, version] of [
		['same', '2.0.0'],
		['older', '1.0.0'],
		['newer', '3.0.0'],
	]) {
		for (const lookupHint of [hint, undefined]) {
			const listing = {
				successful: true,
				code: 'release_candidates_available',
				data: {
					installed_version: '2.0.0',
					candidates: [candidate('A', version, relationship, '')],
				},
			};
			const failure = {
				successful: false,
				code: 'unable_to_check',
				data: {
					rejection_reason: 'rate_limited',
					lookup_hint: lookupHint,
				},
			};
			const harness = createHarness([
				listing,
				failure,
				readyResponse(version, relationship),
			]);
			harness.browser.dataset.ranBoosterManagedReleaseOlder = 'true';
			harness.initialize(harness.browser);
			await flush();
			await harness.nodes.get('review').dispatch('click');
			assert.match(
				dialogText(harness),
				/The Git provider is limiting lookups from this site\. Try again later\./
			);
			assert.equal(harness.noticeCalls.at(-1).intent, 'warning');
			assert.equal(harness.nodes.get('error').hidden, true);
			const dialog = harness.dialogs.at(-1);
			const links = descendants(dialog.body).filter(
				(node) => node.tagName === 'a'
			);
			assert.equal(links.length, lookupHint ? 1 : 0);
			if (lookupHint) {
				assert.equal(links[0].href, hint.url);
			}
			assert.equal(
				descendants(dialog.body).some(
					(node) => node.tagName === 'form'
				),
				false
			);
			assert.equal(
				dialog.options.footer.children.some(
					(node) => node.type === 'submit'
				),
				false
			);
			await dialog.options.footer.children[0].dispatch('click');
			await harness.nodes.get('review').dispatch('click');
			assert.match(dialogText(harness), /Installed: 2\.0\.0/);
		}
	}
});

test('generic inspection failure is not described as a rate limit', async () => {
	const harness = createHarness([
		...installedAndNewer,
		{ successful: false, code: 'unable_to_check', data: {} },
	]);
	harness.initialize(harness.browser);
	await flush();
	await harness.nodes.get('review').dispatch('click');
	assert.match(dialogText(harness), /could not check the selected release/i);
	assert.doesNotMatch(dialogText(harness), /rate.limit|credential/i);
	assert.equal(harness.nodes.get('error').hidden, true);
});
