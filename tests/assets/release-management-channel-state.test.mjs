import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-release-management.js', import.meta.url),
	'utf8'
);

function declaration(name) {
	const start = source.indexOf(`\tconst ${name} = `);
	assert.notEqual(start, -1, `The ${name} declaration must exist.`);

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
				end = source.indexOf(';', index) + 1;
				break;
			}
		}
	}

	assert.notEqual(end, -1, `The ${name} declaration must be complete.`);

	return source.slice(start, end);
}

function deferred() {
	let resolve;
	const promise = new Promise((next) => {
		resolve = next;
	});

	return { promise, resolve };
}

async function flush() {
	await new Promise((resolve) => setImmediate(resolve));
}

test('client hydration normalizes the release source label', () => {
	assert.match(
		source,
		/const choiceHeading = releaseChoice\.querySelector\(\s*'\[data-ran-booster-source-heading\]'\s*\);/
	);
	assert.match(
		declaration('setChoiceState'),
		/setText\(choiceHeading, wp\.i18n\.__\(\'Releases\', \'ran-booster\'\)\);/
	);
	assert.ok(
		declaration('setChoiceState').includes(
			"__('Releases unavailable: %s', 'ran-booster')"
		)
	);
	assert.match(
		declaration('updateAdvancedSummary'),
		/advancedSummary\.textContent = wp\.i18n\.sprintf\([\s\S]*Releases · %s/
	);
	assert.match(
		declaration('showIdle'),
		/Select Releases to load stable and preview candidates\.[\s\S]*Select Releases to load eligible stable candidates\./
	);
});

test('selected release track maps to the stored release channel', () => {
	assert.match(
		declaration('releaseChannel'),
		/channelControl\?\.querySelector\(\s*'\[data-ran-booster-release-channel\]:checked'[\s\S]*\.value === 'prerelease'/
	);
	assert.match(
		source,
		/channelControl\?\.addEventListener\('change', scheduleDiscovery\)/
	);
});

test('branch release track selection updates only its local disclosure summary', () => {
	const updateReleaseTrackSummary = Function(
		`"use strict"; ${declaration('updateReleaseTrackSummary')} return updateReleaseTrackSummary;`
	)();
	const badge = { textContent: 'Stable' };
	const disclosure = {
		querySelector(selector) {
			return selector === '[data-ran-booster-release-track-summary]'
				? badge
				: null;
		},
	};
	const radio = (label, trackDisclosure = disclosure) => ({
		matches(selector) {
			return selector === '[data-ran-booster-release-channel]';
		},
		closest(selector) {
			if (selector === '#ran-booster-release-track-settings') {
				return trackDisclosure;
			}
			return selector === 'label' ? label : null;
		},
	});
	const label = (text) => ({
		querySelector(selector) {
			return selector === 'span' ? { textContent: text } : null;
		},
	});

	updateReleaseTrackSummary({ target: radio(label('Preview')) });
	assert.equal(badge.textContent, 'Preview');
	updateReleaseTrackSummary({ target: radio(label('Stable')) });
	assert.equal(badge.textContent, 'Stable');
	updateReleaseTrackSummary({ target: { matches: () => false } });
	updateReleaseTrackSummary({ target: radio(label('Preview'), null) });
	assert.equal(badge.textContent, 'Stable');
});

test('prospective client contains no managed release track dirty-state behavior', () => {
	assert.doesNotMatch(source, /ran-booster-release-track-save/);
	assert.doesNotMatch(source, /ranBoosterInitialReleaseChannel/);
	assert.match(
		source,
		/htmx:afterSwap[\s\S]*initializeManagedReleaseBrowserAfterSwap/
	);
});

test('loading state is carried by the stable release pane instead of a spinner', () => {
	assert.doesNotMatch(source, /release-status-spinner/);
	assert.match(
		source,
		/setup\.classList\.toggle\('is-checking', options\.checking === true\)/
	);
	assert.match(source, /setup\.setAttribute\(\s*'aria-busy',/);
	assert.match(
		source,
		/status\?\.classList\.toggle\(\s*'screen-reader-text',\s*options\.screenReaderOnly === true/
	);
	assert.match(
		declaration('setChoiceState'),
		/ran-booster-enhanced-mutation__submitter--busy/
	);
	assert.match(declaration('setChoiceState'), /ran-booster-update-is-active/);
});

test('loading candidates preserves provider order and uses the shared disclosure treatment', () => {
	let inspectCalls = 0;
	const candidateList = {
		children: [],
		append(label) {
			this.children.push(label);
		},
		replaceChildren() {
			this.children = [];
		},
	};
	const createInput = () => {
		const listeners = {};
		return {
			checked: false,
			type: '',
			name: '',
			value: '',
			addEventListener(name, handler) {
				listeners[name] = handler;
			},
			dispatchEvent(event) {
				if (listeners[event.type]) {
					listeners[event.type](event);
				}
			},
		};
	};
	const document = {
		createElement(tagName) {
			if (tagName === 'label') {
				return {
					append(...nodes) {
						this.children = nodes;
					},
				};
			}
			if (tagName === 'input') {
				return createInput();
			}
			return {
				append(...nodes) {
					this.children = nodes;
				},
			};
		},
	};
	const createHarness = Function(
		'document',
		'candidateList',
		'candidates',
		'releaseChannel',
		'setChoiceState',
		'setStatus',
		'showUnavailable',
		'setHidden',
		'install',
		'details',
		'inspectRelease',
		'wp',
		`"use strict";
		let requestSequence = 0;
		let selectedRelease = null;
		${declaration('showCandidates')}
		return {
			showCandidates,
			state: () => ({ requestSequence, selectedRelease }),
		};`
	)(
		document,
		candidateList,
		{ hidden: false },
		() => 'stable',
		() => {},
		() => {},
		() => {},
		() => {},
		{ hidden: false },
		{ hidden: false },
		() => {
			inspectCalls += 1;
		},
		{
			i18n: {
				__(text) {
					return text;
				},
				_n(singular, plural, count) {
					return count === 1 ? singular : plural;
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
		}
	);
	const harness = createHarness;

	harness.showCandidates({
		candidates: [
			{
				release_id: 8,
				tag: 'v1.1.0',
				version: '1.1.0',
				published_at: '2026-01-01T00:00:00Z',
			},
			{
				release_id: 9,
				tag: 'v1.2.0',
				version: '1.2.0',
				published_at: '2026-02-01T00:00:00Z',
			},
		],
	});

	assert.equal(inspectCalls, 1);
	assert.equal(harness.state().selectedRelease.id, 8);
	assert.equal(harness.state().selectedRelease.tag, 'v1.1.0');
	assert.equal(harness.state().selectedRelease.channel, 'stable');
	assert.equal(candidateList.children.length, 2);
	assert.equal(candidateList.children[0].children[0].checked, true);
	assert.equal(
		candidateList.children[1].className,
		'ran-booster-release-settings-disclosure'
	);
});

test('a failed selected release preserves earlier candidates', () => {
	assert.match(
		declaration('inspectRelease'),
		/showCandidateUnavailable\(response\.code\)/
	);
	assert.match(
		declaration('showCandidateUnavailable'),
		/setHidden\(candidates, false\)/
	);
	assert.doesNotMatch(
		declaration('showCandidateUnavailable'),
		/replaceChildren/
	);
});

test('final install submits the shared Core create form', () => {
	const installRelease = declaration('installRelease');

	assert.match(installRelease, /event\.preventDefault\(\)/);
	assert.match(installRelease, /form\.elements\.namedItem\(name\)/);
	assert.match(
		installRelease,
		/form\.setAttribute\('hx-post', config\.adminPostUrl\)/
	);
	assert.match(
		installRelease,
		/form\.elements\.namedItem\('ran_booster\[action\]'\)/
	);
	assert.match(installRelease, /branchActionWasDisabled/);
	assert.match(installRelease, /branchAction\.disabled = true/);
	assert.match(installRelease, /form\.requestSubmit\(install\)/);
	assert.doesNotMatch(installRelease, /document\.createElement\('form'\)/);
	assert.doesNotMatch(installRelease, /\.submit\(\)/);
});

test('Core source events drive release discovery without duplicating tab state', () => {
	assert.match(
		declaration('chooseRelease'),
		/releaseSelected = true;[\s\S]*listCandidates\(\);/
	);
	assert.match(
		declaration('chooseBranch'),
		/releaseSelected = false;[\s\S]*requestSequence \+= 1;/
	);
	assert.match(
		source,
		/form\.addEventListener\('ran-booster:package-source-changed'/
	);
	assert.doesNotMatch(
		source,
		/setHidden\(branchPane|setAttribute\('aria-selected'/
	);
});

test('a configured subdirectory makes Published releases unavailable and keeps Branch usable', () => {
	assert.match(
		declaration('hasSubdirectory'),
		/\[name="ran_booster\[subdirectory\]"\][\s\S]*\.value\?\.trim\(\)/
	);
	assert.match(
		declaration('setChoiceState'),
		/state === 'subdirectory'[\s\S]*'is-unavailable'[\s\S]*state === 'subdirectory'/
	);
	assert.match(
		declaration('showSubdirectoryUnsupported'),
		/Published releases require the repository root\. Branch supports the configured subdirectory\./
	);
	assert.match(
		declaration('forceBranchForSubdirectory'),
		/branchChoice\.focus\(\);[\s\S]*branchChoice\.click\(\);/
	);
	assert.match(
		declaration('setChoiceState'),
		/releaseChoice\.setAttribute\('title', disabled \? description : ''\)/
	);
	assert.match(
		declaration('chooseRelease'),
		/getAttribute\('aria-disabled'\) === 'true'/
	);
	assert.match(
		declaration('listCandidates'),
		/if \(hasSubdirectory\(\)\) \{\s*forceBranchForSubdirectory\(\);\s*return;/
	);
	assert.match(
		declaration('scheduleDiscovery'),
		/if \(hasSubdirectory\(\)\) \{\s*forceBranchForSubdirectory\(\);\s*return;/
	);

	const hasSubdirectory = Function(
		'form',
		`"use strict"; ${declaration('hasSubdirectory')} return hasSubdirectory;`
	);
	assert.equal(
		hasSubdirectory({
			querySelector: () => ({ value: ' packages/example ' }),
		})(),
		true
	);
	assert.equal(
		hasSubdirectory({ querySelector: () => ({ value: '   ' }) })(),
		false
	);
	assert.equal(hasSubdirectory({ querySelector: () => null })(), false);
});

test('an active Published releases choice returns to Branch when a subdirectory appears', () => {
	let focused = 0;
	let clicked = 0;
	let unavailable = 0;
	const releaseChoice = {
		getAttribute: (name) => (name === 'aria-pressed' ? 'true' : null),
	};
	const branchChoice = {
		focus: () => {
			focused += 1;
		},
		click: () => {
			clicked += 1;
		},
	};
	const forceBranch = Function(
		'releaseChoice',
		'branchChoice',
		'showSubdirectoryUnsupported',
		`"use strict";
		let releaseSelected = true;
		${declaration('forceBranchForSubdirectory')}
		return forceBranchForSubdirectory;`
	)(releaseChoice, branchChoice, () => {
		unavailable += 1;
	});

	forceBranch();

	assert.equal(unavailable, 1);
	assert.equal(focused, 1);
	assert.equal(clicked, 1);
});

test('subdirectory input and change events refresh published-release availability', () => {
	assert.match(source, /\[name="ran_booster\[subdirectory\]"\]/);
	assert.match(
		source,
		/form\.addEventListener\('input', repositoryContextChanged\)/
	);
	assert.match(
		source,
		/form\.addEventListener\('change', repositoryContextChanged\)/
	);
});

test('changing channel invalidates the exact candidate and ignores a stale candidate list', async () => {
	const requests = [];
	const requestChannels = [];
	let channel = 'stable';
	const presentedLists = [];
	const install = { hidden: false };
	const details = { hidden: false };
	const candidates = { hidden: false };
	const repository = { value: 'RocketsAreNostalgic/example-plugin' };
	const form = {
		querySelector() {
			return repository;
		},
	};
	const window = {
		clearTimeout() {},
		setTimeout(callback) {
			callback();
			return 1;
		},
	};
	const request = () => {
		const pending = deferred();
		requests.push(pending);
		requestChannels.push(channel);
		return pending.promise;
	};
	const setHidden = (element, hidden) => {
		element.hidden = hidden;
	};
	const showCandidates = (data) => presentedLists.push(data);

	const createHarness = Function(
		'form',
		'window',
		'request',
		'install',
		'details',
		'setHidden',
		'candidates',
		'candidateList',
		'releaseChannel',
		'showCandidates',
		'setChecking',
		'showUnavailable',
		'showIdle',
		'updateAdvancedSummary',
		'hasSubdirectory',
		'forceBranchForSubdirectory',
		'providerSupported',
		'forceBranchForUnsupportedProvider',
		`"use strict";
		let requestSequence = 0;
		let selectedRelease = {
			id: 7,
			tag: 'v1.0.0',
			version: '1.0.0',
			channel: 'stable',
			fingerprint: 'v1:${'a'.repeat(64)}'
		};
		let releaseSelected = true;
		let discoveryTimer = null;
		${declaration('listCandidates')}
		${declaration('scheduleDiscovery')}
		return {
			listCandidates,
			scheduleDiscovery,
			state: () => ({ requestSequence, selectedRelease })
		};`
	);
	const harness = createHarness(
		form,
		window,
		request,
		install,
		details,
		setHidden,
		candidates,
		null,
		() => channel,
		showCandidates,
		() => {},
		() => {},
		() => {},
		() => {},
		() => false,
		() => {},
		() => true,
		() => {}
	);

	const staleCandidates = harness.listCandidates();
	assert.deepEqual(requestChannels, ['stable']);

	channel = 'prerelease';
	harness.scheduleDiscovery();

	assert.deepEqual(requestChannels, ['stable', 'prerelease']);
	assert.equal(harness.state().selectedRelease, null);
	assert.equal(install.hidden, true);
	assert.equal(details.hidden, true);

	requests[0].resolve({
		successful: true,
		code: 'release_candidates_available',
		data: {
			candidates: [{ release_id: 7, tag: 'v1.0.0', version: '1.0.0' }],
		},
	});
	await staleCandidates;

	assert.equal(harness.state().selectedRelease, null);
	assert.deepEqual(presentedLists, []);

	requests[1].resolve({
		successful: true,
		code: 'release_candidates_available',
		data: {
			candidates: [
				{
					release_id: 8,
					tag: 'v1.1.0-alpha.1',
					version: '1.1.0-alpha.1',
					prerelease: true,
				},
			],
		},
	});
	await flush();

	assert.equal(harness.state().selectedRelease, null);
	assert.equal(presentedLists.length, 1);
	assert.equal(presentedLists[0].candidates[0].release_id, 8);
});

test('unsupported providers do not schedule discovery and supported providers recover', () => {
	const repository = { value: 'workspace/package' };
	const form = {
		querySelector() {
			return repository;
		},
	};
	let supported = false;
	let forcedBranch = 0;
	let checks = 0;
	let idle = 0;
	let timers = 0;
	const window = {
		clearTimeout() {},
		setTimeout() {
			timers += 1;
			return 1;
		},
	};
	const createHarness = Function(
		'form',
		'window',
		'install',
		'details',
		'setHidden',
		'candidates',
		'candidateList',
		'updateAdvancedSummary',
		'hasSubdirectory',
		'forceBranchForSubdirectory',
		'providerSupported',
		'forceBranchForUnsupportedProvider',
		'showWaitingForRepository',
		'showIdle',
		'setChecking',
		'listCandidates',
		`"use strict";
		let requestSequence = 0;
		let selectedRelease = { id: 7 };
		let releaseSelected = false;
		let discoveryTimer = null;
		${declaration('scheduleDiscovery')}
		return { scheduleDiscovery, state: () => ({ selectedRelease, releaseSelected }) };`
	);
	const harness = createHarness(
		form,
		window,
		{ hidden: false },
		{ hidden: false },
		(element, hidden) => {
			element.hidden = hidden;
		},
		{ hidden: false },
		null,
		() => {},
		() => false,
		() => {},
		() => supported,
		() => {
			forcedBranch += 1;
		},
		() => {},
		() => {
			idle += 1;
		},
		() => {
			checks += 1;
		},
		() => {}
	);

	harness.scheduleDiscovery();
	assert.equal(forcedBranch, 1);
	assert.equal(timers, 0);
	assert.equal(checks, 0);
	assert.equal(harness.state().selectedRelease, null);

	supported = true;
	harness.scheduleDiscovery();
	assert.equal(idle, 1);
	assert.equal(timers, 0);
	assert.equal(checks, 0);
});

test('an unsupported provider forces an active release choice back to Branch', () => {
	let focused = 0;
	let clicked = 0;
	let unsupported = 0;
	const releaseChoice = {
		getAttribute(name) {
			return name === 'aria-pressed' ? 'true' : null;
		},
	};
	const branchChoice = {
		focus() {
			focused += 1;
		},
		click() {
			clicked += 1;
		},
	};
	const createHarness = Function(
		'releaseChoice',
		'branchChoice',
		'showUnsupportedProvider',
		`"use strict";
		let releaseSelected = true;
		${declaration('forceBranchForUnsupportedProvider')}
		return forceBranchForUnsupportedProvider;`
	);
	const forceBranch = createHarness(releaseChoice, branchChoice, () => {
		unsupported += 1;
	});

	forceBranch();

	assert.equal(unsupported, 1);
	assert.equal(focused, 1);
	assert.equal(clicked, 1);
	assert.match(
		declaration('showUnsupportedProvider'),
		/selectedRelease = null;\s*releaseSelected = false;/
	);
	assert.match(
		declaration('showUnsupportedProvider'),
		/setHidden\(candidates, true\);[\s\S]*setHidden\(details, true\);[\s\S]*setHidden\(install, true\);/
	);
});

test('unsupported server responses use the provider state without retrying', () => {
	assert.match(
		declaration('showUnavailable'),
		/if \(code === 'unsupported_provider'\) \{\s*forceBranchForUnsupportedProvider\(\);\s*return;/
	);
	assert.doesNotMatch(
		declaration('showUnsupportedProvider'),
		/retry:\s*true/
	);
	assert.match(
		declaration('listCandidates'),
		/if \(!providerSupported\(\)\) \{\s*forceBranchForUnsupportedProvider\(\);\s*return;/
	);
});
