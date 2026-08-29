import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-release-management.js', import.meta.url),
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

class Node {
	constructor(tagName) {
		this.tagName = tagName;
		this.children = [];
		this.dataset = {};
		this.listeners = {};
		this.attributes = new Map();
		this.classes = new Set();
		this.classList = {
			add: (...names) => names.forEach((name) => this.classes.add(name)),
			contains: (name) => this.classes.has(name),
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
	append(...children) {
		this.children.push(...children);
	}
	replaceChildren(...children) {
		this.children = children;
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
	querySelector(selector) {
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
	node.children.flatMap((child) => [child, ...descendants(child)]);
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

const createHarness = (responses, nativeOfferReleaseId = 'offer') => {
	const nodes = new Map();
	const browser = new Node('section');
	browser.dataset = {
		ranBoosterManagedReleaseType: 'plugin',
		ranBoosterManagedReleaseIdentifier: 'example/plugin.php',
		ranBoosterManagedReleaseRevision: 'abc',
		ranBoosterManagedReleaseChannel: 'stable',
		ranBoosterManagedReleaseListNonce: 'list',
		ranBoosterManagedReleaseInspectNonce: 'inspect',
		ranBoosterManagedReleaseAjaxUrl: '/ajax',
		ranBoosterManagedReleaseNativeUpdateUrl:
			'/wp-admin/update.php?action=upgrade-plugin&plugin=example%2Fplugin.php&_wpnonce=upgrade',
		ranBoosterManagedReleaseNativeUpdateVersion: '3.0.0',
		ranBoosterManagedReleaseNativeUpdateReleaseId: nativeOfferReleaseId,
	};
	for (const name of [
		'candidates',
		'candidate-list',
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
	nodes.get('native-update').textContent = 'Install now';
	nodes.get('retry').textContent = 'Refresh releases';
	browser.querySelector = (selector) =>
		nodes.get(selector.match(/managed-release-([a-z-]+)/)?.[1]) || null;
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
	let swappedBrowser = null;
	const context = {
		Date,
		FormData,
		URL,
		document: {
			querySelector: (selector) =>
				selector === '[data-ran-booster-managed-release-browser]'
					? swappedBrowser
					: null,
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
			fetch: async (url, options) => {
				requestedUrls.push(url);
				requests.push(options.body.values);
				return { ok: true, json: async () => responses.shift() };
			},
		},
	};
	context.globalThis = context;
	vm.runInNewContext(
		`${source}\nglobalThis.initialize = initializeManagedReleaseBrowser;`,
		context
	);
	return {
		browser,
		nodes,
		requests,
		requestedUrls,
		initialize: context.initialize,
		afterPackageSwap: () => {
			swappedBrowser = browser;
			documentListeners.get('htmx:afterSwap')?.({
				detail: { target: { id: 'wpbody-content' } },
			});
		},
	};
};

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

test('managed browser selects and inspects the newest current candidate, preserving bounded release context', async () => {
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
	await flush();
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
	assert.match(
		disclosure.children[1].textContent,
		/Downgrades are unavailable/
	);
	assert.equal(
		descendants(harness.nodes.get('candidate-list')).find(
			(node) =>
				node.className === 'ran-booster-release-candidate__outcome'
		).hidden,
		true
	);
	assert.equal(
		harness.nodes.get('native-update').getAttribute('aria-disabled'),
		'true'
	);
	assert.equal(
		harness.nodes.get('native-update').classList.contains('disabled'),
		true
	);
	assert.equal(harness.nodes.get('native-update').textContent, 'Install now');
	assert.equal(harness.nodes.get('retry').disabled, false);
	assert.equal(
		harness.nodes
			.get('retry')
			.classList.contains('ran-booster-update-is-active'),
		false
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
	await flush();
	assert.equal(
		descendants(harness.nodes.get('candidate-list')).find((node) =>
			node.className?.includes('installed-version')
		).textContent,
		'Installed version: 2.0.0'
	);
	await harness.nodes.get('retry').dispatch('click');
	await flush();
	await flush();
	assert.equal(
		descendants(harness.nodes.get('candidate-list')).find(
			(node) =>
				node.className === 'ran-booster-release-candidate__outcome'
		).hidden,
		true
	);
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
	await flush();
	assert.equal(
		harness.nodes.get('native-update').getAttribute('aria-disabled'),
		'true'
	);
});

test('managed browser enables the native Core update only for its exact newer inspected offer', async () => {
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
				},
			},
		],
		'new'
	);
	harness.initialize(harness.browser);
	await flush();
	await flush();
	assert.equal(
		harness.nodes.get('native-update').getAttribute('aria-disabled'),
		'false'
	);
	assert.equal(
		harness.nodes.get('native-update').classList.contains('disabled'),
		false
	);
	assert.equal(
		harness.nodes.get('native-update').textContent,
		'Install 3.0.0 now'
	);
	assert.equal(
		harness.nodes.get('native-update').getAttribute('href'),
		'/wp-admin/update.php?action=upgrade-plugin&plugin=example%2Fplugin.php&_wpnonce=upgrade'
	);
	const outcome = descendants(harness.nodes.get('candidate-list')).find(
		(node) => node.className === 'ran-booster-release-candidate__outcome'
	);
	assert.equal(outcome.hidden, false);
	assert.equal(outcome.textContent, 'Version 3.0.0 is ready to install.');
});

test('managed browser prefers and marks the exact rendered update offer over a later-published candidate', async () => {
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
				version: '3.0.0',
				tag: 'v3.0.0',
				installed_version: '2.0.0',
				version_relationship: 'newer',
			},
		},
	]);
	harness.initialize(harness.browser);
	await flush();
	await flush();

	assert.equal(harness.requests[1].get('release_id'), 'offer');
	assert.equal(
		descendants(harness.nodes.get('candidate-list')).find(
			(node) => node.tagName === 'input' && node.checked
		).dataset.releaseId,
		'offer'
	);
	assert.match(
		descendants(harness.nodes.get('candidate-list'))
			.map((node) => node.textContent || '')
			.join(' '),
		/3\.0\.0 \(v3\.0\.0\).*Latest eligible/
	);
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
	await flush();

	assert.equal(
		harness.nodes.get('native-update').getAttribute('aria-disabled'),
		'true'
	);
	assert.equal(harness.nodes.get('native-update').getAttribute('href'), null);
	assert.equal(harness.nodes.get('native-update').textContent, 'Install now');
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
	await flush();

	assert.equal(
		harness.nodes.get('native-update').getAttribute('aria-disabled'),
		'true'
	);
	assert.equal(harness.nodes.get('native-update').getAttribute('href'), null);
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
		/rate-limited/
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

test('managed browser keeps empty stable and preview tracks visible', async () => {
	for (const [channel, copy] of [
		[
			'stable',
			'No Stable releases have been published for this package yet.',
		],
		[
			'prerelease',
			'No Preview releases have been published for this package yet.',
		],
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
			code: 'no_releases',
			data: { channel: 'prerelease', candidates: [] },
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
	assert.equal(
		harness.nodes.get('candidate-list').children[0].textContent,
		'No Preview releases have been published for this package yet.'
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

test('release automation and webhook setup share one disclosure shell', () => {
	assert.match(
		packageStyles,
		/\.ran-booster-package-disclosure \{[\s\S]*border-radius: var\(--ran-booster-radius-surface\)/
	);
	assert.match(
		packageStyles,
		/\.ran-booster-package-disclosure > summary \{[\s\S]*grid-template-columns: 10px minmax\(0, 1fr\) auto[\s\S]*\.ran-booster-package-disclosure > summary::before \{[\s\S]*transform: rotate\(-45deg\)/
	);
	assert.match(
		packageStyles,
		/\.ran-booster-package-disclosure\[open\] > summary \{[\s\S]*border-block-end: 1px solid var\(--ran-booster-border\)/
	);
	assert.doesNotMatch(styles, /ran-booster-release-workflow \{/);
});
