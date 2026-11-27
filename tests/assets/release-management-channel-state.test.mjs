import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-release-management.js', import.meta.url),
	'utf8'
);

const earlierDisclosureHelpers = source.slice(
	source.indexOf('const earlierReleasesDisclosure ='),
	source.indexOf('const initializeManagedReleaseBrowser =')
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

function observationNode() {
	return {
		hidden: true,
		children: [],
		dataset: {},
		textContent: '',
		addEventListener() {},
		append(...rows) {
			this.children.push(...rows);
		},
		replaceChildren() {
			this.children = [];
		},
		set innerHTML(value) {
			throw new Error(`Unexpected HTML sink: ${value}`);
		},
	};
}

const observationI18n = {
	i18n: {
		__: (text) => text,
		_n: (singular, plural, count) => (count === 1 ? singular : plural),
		sprintf: (text, ...values) =>
			text.replace(/%(\d+)\$[ds]/g, (_, index) =>
				String(values[Number(index) - 1])
			),
	},
};

test('listing observation renderer uses fixed reasons and text nodes, bounds samples and resets', () => {
	const render = Function(
		'document',
		'wp',
		`${earlierDisclosureHelpers}; return renderListingObservations;`
	)({ createElement: observationNode }, observationI18n);
	const target = observationNode();
	render(target, {
		search_exhausted: true,
		diagnostics: {
			scanned_count: 40,
			dropped_count: 40,
			truncated: true,
			drops: [
				{
					reason: 'release_metadata_invalid',
					tag: '<img onerror=evil()>',
				},
				{ reason: 'release_immutable_invalid', tag: null },
				{ reason: 'release_publication_invalid', tag: null },
				{ reason: 'release_page_invalid', tag: null },
				{ reason: 'release_draft', tag: null },
				{ reason: 'tag_not_version', tag: 'V1.2+RC.1' },
				{ reason: 'prerelease_not_allowed', tag: null },
				{ reason: 'constructor', tag: 'withheld_unknown' },
				{ reason: 'release_draft', tag: 'withheld_ninth' },
			],
		},
	});
	const text = target.children.map((row) => row.textContent).join('\n');
	assert.equal(target.hidden, false);
	assert.match(text, /search stopped at its limit/);
	assert.match(text, /40 release records checked; 40 skipped/);
	assert.match(text, /<img onerror=evil\(\)>: Release metadata is invalid/);
	assert.match(
		text,
		/V1.2\+RC.1: The release tag is not an accepted version/
	);
	assert.match(text, /sample is incomplete/);
	assert.doesNotMatch(
		text,
		/withheld_unknown|withheld_ninth|8 newest|Enter a tag/
	);
	render(target, {
		candidates: [],
		search_exhausted: false,
		diagnostics: null,
	});
	assert.equal(target.children.length, 1);
	assert.match(target.children[0].textContent, /details are unavailable/);
	render(target);
	assert.equal(target.hidden, true);
	assert.equal(target.children.length, 0);
});

for (const picker of ['managed', 'prospective']) {
	test(`${picker} empty-list loader preserves current observations and ignores stale responses`, async () => {
		const observations = observationNode();
		const pending = [];
		const load = Function(
			'document',
			'wp',
			'observations',
			'request',
			`
			${earlierDisclosureHelpers}
			let sequence=0, requestSequence=0, selected=null; const window={}; const branch=false;
			const candidateList=document.createElement('div'), candidates={hidden:true}; const review=null;
			const form={querySelector:()=>({value:'example'})};
			const hasSubdirectory=()=>false, providerSupported=()=>true;
			const forceBranchForSubdirectory=()=>{}, forceBranchForUnsupportedProvider=()=>{};
			const clearChosen=()=>{}, clearError=()=>{}, setListBusy=()=>{};
			const showPlaceholder=()=>{}, setStatus=()=>{}, setChecking=()=>{}, showUnavailable=()=>{}, showError=()=>{};
			const showCandidates=()=>{}, inspect=()=>{};
			const candidateElements=new Map(); let selectedOutcome=null;
			${declaration(picker === 'managed' ? 'list' : 'listCandidates')}
			return ${picker === 'managed' ? 'list' : 'listCandidates'};
		`
		)(
			{
				createElement: observationNode,
				createTextNode: (textContent) => ({ textContent }),
			},
			observationI18n,
			observations,
			() => {
				const next = deferred();
				pending.push(next);
				return next.promise;
			}
		);
		const response = (count) => ({
			successful: picker === 'managed',
			code: 'no_releases',
			data: {
				candidates: [],
				search_exhausted: true,
				diagnostics: {
					scanned_count: count,
					dropped_count: count,
					drops: [],
					truncated: true,
				},
			},
		});
		const first = load();
		pending[0].resolve(response(3));
		await first;
		assert.equal(observations.hidden, false);
		assert.match(
			observations.children[1].textContent,
			/3 release records checked/
		);
		const old = load();
		assert.equal(observations.hidden, true);
		assert.equal(observations.children.length, 0);
		const fresh = load();
		pending[2].resolve(response(4));
		await fresh;
		pending[1].resolve(response(2));
		await old;
		assert.match(
			observations.children[1].textContent,
			/4 release records checked/
		);
		const failed = load();
		pending[3].resolve({
			successful: false,
			code: 'unable_to_check',
			data: {},
		});
		await failed;
		assert.equal(observations.hidden, true);
		assert.equal(observations.children.length, 0);
		for (const count of [0, 1]) {
			for (const stopped of [false, true]) {
				const next = load();
				pending.at(-1).resolve({
					successful: true,
					code: count
						? 'release_candidates_available'
						: 'no_releases',
					data: {
						candidates: count
							? [
									{
										release_id: '1',
										version: '1.0.0',
										tag: 'v1.0.0',
										prerelease: false,
										version_relationship: 'newer',
									},
								]
							: [],
						search_exhausted: stopped,
						diagnostics: null,
					},
				});
				await next;
				const text = observations.children
					.map((row) => row.textContent)
					.join('\n');
				assert.equal(/details are unavailable/.test(text), count === 0);
				assert.equal(/search stopped at its limit/.test(text), stopped);
				assert.equal(observations.hidden, count === 1 && !stopped);
				assert.equal(
					observations.children.length,
					Number(count === 0) + Number(stopped)
				);
			}
		}
	});
}

test('client hydration normalizes the release source label', () => {
	// The card keeps its server-rendered heading, description and meta-free layout.
	assert.doesNotMatch(source, /choiceHeading|choiceMeta|source-meta/);
	assert.doesNotMatch(source, /setText\(choiceDescription/);
	assert.match(
		declaration('setChoiceState'),
		/^\s*const setChoiceState = \(state\) =>/
	);
	assert.doesNotMatch(
		source,
		/Choose repository first|Stable by default|Choose another'|Branch only|Check failed|Checking repository…|Inspected'|Repository root required|Git provider capability unavailable|%d available/
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

test('release management stays parseable beside another WordPress i18n alias user', () => {
	assert.doesNotThrow(() => {
		new Function('wp', 'const { __, sprintf } = wp.i18n;\n' + source);
	});
	assert.doesNotMatch(source, /^const \{ __, sprintf \} = wp\.i18n;/m);
	assert.match(
		source,
		/const initializeManagedReleaseBrowser = \(managedBrowser\) => \{\n\tconst \{ __, sprintf \} = wp\.i18n;/
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
		`"use strict"; ${declaration('syncUseReleasesState')} ${declaration('updateReleaseTrackSummary')} return updateReleaseTrackSummary;`
	)();
	globalThis.document = { querySelector: () => null };
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
			if (
				selector ===
				'#ran-booster-release-track-settings, [data-ran-booster-ui-disclosure="package-release-track"]'
			) {
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
	delete globalThis.document;
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
					children: [],
					append(...nodes) {
						this.children.push(...nodes);
					},
				};
			}
			if (tagName === 'input') {
				return createInput();
			}
			return {
				children: [],
				attributes: {},
				setAttribute(name, value) {
					this.attributes[name] = value;
				},
				dispatchEvent(event) {
					this.dispatched = event;
				},
				append(...nodes) {
					this.children.push(...nodes);
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
		${earlierDisclosureHelpers}
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
	const host = candidateList.children[1];
	assert.equal(host.className, 'ran-booster-release-settings-disclosure');
	assert.equal(
		host.attributes['data-ran-booster-ui-disclosure'],
		'release-earlier-releases'
	);
	assert.equal(host.dispatched.type, 'ran-booster:ui-disclosure-update');
	const details = host.children[0];
	assert.equal(details.className, 'ran-booster-disclosure');
	assert.equal(
		details.attributes['data-ran-booster-ui-disclosure-fallback'],
		''
	);
	assert.equal(details.children[1].className, 'ran-booster-disclosure__body');
	assert.equal(
		details.children[1].attributes[
			'data-ran-booster-ui-disclosure-content'
		],
		''
	);
});

test('a failed selected release preserves earlier candidates', () => {
	assert.match(
		declaration('inspectRelease'),
		/showCandidateUnavailable\(response\.code, response\.data\)/
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
		/releaseChoice\.setAttribute\('title', disabled \? staticDescription : ''\)/
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
		const observations = null, renderListingObservations = () => {};
		let requestSequence = 0;
		let selectedRelease = {
			id: 7,
			tag: 'v1.0.0',
			version: '1.0.0',
			channel: 'stable',
			fingerprint: 'v2:${'a'.repeat(64)}'
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
		const observations = null, renderListingObservations = () => {};
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

test('the Add Releases failure adds the lookup-credential hint only for a retryable provider failure', () => {
	const hint = {
		text: 'A dedicated lookup credential helps avoid rate limits.',
		link: 'Choose a lookup credential',
		url: 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh#ran-booster-public-lookup-profile-region',
	};
	const run = (code, lookupHint) => {
		const message = {
			children: [],
			append(...nodes) {
				this.children.push(...nodes);
			},
		};
		const factory = new Function(
			'env',
			`
			const { document, window, message, wp } = env;
			const noop = () => {};
			const forceBranchForUnsupportedProvider = noop, setChoiceState = noop, setHidden = noop;
			const includesPrereleases = () => false;
			const install = {}, details = {}, candidates = {};
			let selectedRelease = null;
			const setStatus = noop;
			const observations = null;
			${earlierDisclosureHelpers.slice(earlierDisclosureHelpers.indexOf('const appendLookupHint ='))}
			${declaration('showUnavailable')}
			return showUnavailable;
		`
		);
		factory({
			document: {
				createElement: (tag) => ({ tagName: tag }),
			},
			window: {
				location: {
					href: 'https://example.test/wp-admin/post.php',
					origin: 'https://example.test',
				},
			},
			message,
			wp: { i18n: { __: (text) => text } },
		})(code, lookupHint);
		return message.children;
	};

	const shown = run('unable_to_check', hint);
	assert.equal(shown[0], ` ${hint.text} `);
	assert.equal(shown[1].href, hint.url);
	assert.equal(shown[1].textContent, hint.link);
	assert.deepEqual(run('unable_to_check', null), []);
	assert.deepEqual(run('no_releases', hint), []);
	assert.deepEqual(run('release_invalid', hint), []);
	assert.deepEqual(
		run('unable_to_check', { ...hint, url: 'https://evil.test/' }),
		[]
	);
});

test('inspection context and its nonce travel together to final form submission', () => {
	const fields = new Map();
	let submissions = 0;
	const form = {
		elements: {
			namedItem(name) {
				if (!fields.has(name)) {
					fields.set(name, { value: '', disabled: false });
				}
				return fields.get(name);
			},
		},
		getAttribute: () => '/create',
		setAttribute() {},
		addEventListener() {},
		requestSubmit() {
			submissions += 1;
		},
	};
	const createHarness = new Function(
		'env',
		`
		const { form, config, wp, setup } = env;
		const install = {}, details = {};
		const releaseChannel = () => 'stable';
		const validFingerprint = value => /^v2:[a-f0-9]{64}$/.test(value);
		const showUnavailable = () => { throw new Error('Unexpected unavailable'); };
		const setChoiceState = () => {}, setStatus = () => {}, setHidden = () => {}, setText = () => {};
		let selectedRelease = { id: '42', tag: 'v1.0.0', channel: 'stable' };
		${declaration('showReady')}
		${declaration('installRelease')}
		return { showReady, installRelease };
	`
	);
	const harness = createHarness({
		form,
		config: { type: 'plugin', adminPostUrl: '/apply' },
		wp: { i18n: { __: (text) => text, sprintf: (text) => text } },
		setup: { querySelector: () => ({}) },
	});
	for (const expectation of [
		null,
		{
			provider_code: 'gh',
			selection_generation: 7,
			implementation_fingerprint: 'b'.repeat(64),
		},
	]) {
		harness.showReady({
			release_id: '42',
			tag: 'v1.0.0',
			version: '1.0.0',
			fingerprint: `v2:${'a'.repeat(64)}`,
			provider_expectation: expectation,
			install_nonce: 'review-bound-nonce',
		});
		harness.installRelease({ preventDefault() {} });
		assert.deepEqual(
			JSON.parse(fields.get('provider_expectation').value),
			expectation
		);
		assert.equal(
			fields.get('ran_booster_release_install_nonce').value,
			'review-bound-nonce'
		);
	}
	assert.equal(submissions, 2);
});

test('a stale in-flight inspection cannot restore install authority after source change', async () => {
	const pending = deferred();
	let ready = 0;
	const createHarness = new Function(
		'request',
		'showReady',
		`
		let selectedRelease = { id: '42', tag: 'v1.0.0' };
		const observations = null, renderListingObservations = () => {};
		let requestSequence = 0, discoveryTimer = null;
		const setup = {}, install = {}, details = {};
		const window = { clearTimeout() {} };
		const form = { querySelector: () => ({ value: '' }) };
		const setChecking = () => {}, showCandidateUnavailable = () => {}, setHidden = () => {};
		const updateAdvancedSummary = () => {}, hasSubdirectory = () => false, providerSupported = () => true;
		const forceBranchForSubdirectory = () => {}, forceBranchForUnsupportedProvider = () => {}, showWaitingForRepository = () => {};
		${declaration('inspectRelease')}
		${declaration('scheduleDiscovery')}
		return { inspectRelease, scheduleDiscovery, selected: () => selectedRelease };
	`
	);
	const harness = createHarness(
		() => pending.promise,
		() => {
			ready += 1;
		}
	);
	const inspection = harness.inspectRelease();
	harness.scheduleDiscovery();
	pending.resolve({
		successful: true,
		code: 'release_ready',
		data: {
			provider_expectation: {
				provider_code: 'gh',
				selection_generation: 1,
				implementation_fingerprint: 'a'.repeat(64),
			},
			install_nonce: 'old-review-nonce',
		},
	});
	await inspection;
	assert.equal(ready, 0);
	assert.equal(harness.selected(), null);
});

test('managed release failures show the static warning Notice without a redraw event', () => {
	const errorMessage = { textContent: '' };
	const errorNotice = { hidden: true };
	const showError = Function(
		'errorMessage',
		'errorNotice',
		`const appendLookupHint = () => {}; ${declaration('showError')} return showError;`
	)(errorMessage, errorNotice);
	// The PHP-rendered Notice has no dispatchEvent here: calling one throws.
	showError('Could not load releases.');
	assert.equal(errorMessage.textContent, 'Could not load releases.');
	assert.equal(errorNotice.hidden, false);
});

for (const enhanced of [true, false]) {
	test(`managed candidate refresh uses ${enhanced ? 'HTMX swap' : 'native removal'}`, async () => {
		const order = [];
		const candidateList = {
			replaceChildren: () => order.push('native-clear'),
		};
		const window = { CustomEvent: class {} };
		if (enhanced) {
			window.htmx = {
				swap: (target, html, spec) => {
					assert.equal(target, candidateList);
					assert.equal(html, '');
					assert.equal(spec.swapStyle, 'innerHTML');
					assert.equal(spec.swapDelay, 0);
					assert.equal(spec.settleDelay, 0);
					order.push('htmx-swap');
				},
			};
		}
		const review = { hidden: false };
		const list = Function(
			'candidateList',
			'review',
			'window',
			'wp',
			'request',
			`let sequence = 0, selected = null; const clearChosen=()=>{}, clearError=()=>{}, setListBusy=()=>{}, setStatus=()=>{}, showError=()=>{}, showPlaceholder=()=>{}; const candidates=null, observations=null; const renderListingObservations=()=>{}; ${declaration('list')} return list;`
		)(
			candidateList,
			review,
			window,
			{ i18n: { __: (text) => text } },
			async () => ({ successful: false })
		);
		await list();
		assert.equal(review.hidden, true);
		assert.deepEqual(order, [enhanced ? 'htmx-swap' : 'native-clear']);
	});
}

const eligibleMessage =
	'Branch currently active. Switch to releases using the %s track.';
const notReadyMessage =
	'Releases aren’t ready. Fix the readiness checks below.';

function switchBarHarness({ ready, track = 'Stable', disabled = false }) {
	const attributes = new Map();
	const wrapperAttributes = new Map([
		...(ready ? [] : [['data-ran-booster-reason-tooltip', '']]),
	]);
	const reason = { id: 'reason-id', hidden: !ready };
	const wrapper = {
		removeAttribute: (name) => wrapperAttributes.delete(name),
		setAttribute: (name, value) => wrapperAttributes.set(name, value),
		querySelector: () => reason,
	};
	const button = {
		disabled,
		dataset: { ranBoosterUseReleasesReady: ready ? 'true' : 'false' },
		closest: () => wrapper,
		getAttribute: (name) => attributes.get(name) ?? null,
		setAttribute: (name, value) => attributes.set(name, value),
		removeAttribute: (name) => attributes.delete(name),
	};
	const summary = {
		textContent: '',
		dataset: {
			ranBoosterUseReleasesSummary: eligibleMessage,
			ranBoosterUseReleasesSummaryUnavailable: notReadyMessage,
		},
	};
	const badge = { textContent: track };
	const document = {
		querySelector: (selector) =>
			({
				'[data-ran-booster-use-releases]': button,
				'[data-ran-booster-use-releases-summary]': summary,
				'[data-ran-booster-release-track-summary]': badge,
			})[selector] ?? null,
	};
	const sync = new Function(
		'document',
		`${declaration('syncUseReleasesState')}\nreturn syncUseReleasesState;`
	)(document);
	return {
		sync,
		button,
		summary,
		badge,
		attributes,
		wrapperAttributes,
		reason,
	};
}

test('the source switch bar message follows the selected release track when releases are ready', () => {
	const bar = switchBarHarness({ ready: true, track: 'Preview' });
	bar.sync();
	assert.equal(
		bar.summary.textContent,
		'Branch currently active. Switch to releases using the Preview track.'
	);
	assert.equal(bar.attributes.get('aria-disabled'), 'false');
	assert.equal(
		bar.wrapperAttributes.has('data-ran-booster-reason-tooltip'),
		false
	);
});

test('the source switch bar switches to the not-ready message and tooltip when readiness is lost', () => {
	const bar = switchBarHarness({ ready: true });
	bar.sync();
	bar.button.dataset.ranBoosterUseReleasesReady = 'false';
	bar.sync();
	assert.equal(bar.summary.textContent, notReadyMessage);
	assert.equal(bar.attributes.get('aria-disabled'), 'true');
	assert.equal(bar.attributes.get('aria-describedby'), 'reason-id');
	assert.equal(
		bar.wrapperAttributes.has('data-ran-booster-reason-tooltip'),
		true
	);
	bar.badge.textContent = 'Preview';
	bar.sync();
	assert.equal(bar.summary.textContent, notReadyMessage);
});

test('the source switch bar returns to the eligible message with the current track when readiness is gained', () => {
	const bar = switchBarHarness({ ready: false });
	bar.sync();
	assert.equal(bar.summary.textContent, notReadyMessage);
	bar.button.dataset.ranBoosterUseReleasesReady = 'true';
	bar.badge.textContent = 'Preview';
	bar.sync();
	assert.equal(
		bar.summary.textContent,
		'Branch currently active. Switch to releases using the Preview track.'
	);
	assert.equal(bar.attributes.get('aria-disabled'), 'false');
	assert.equal(bar.attributes.has('aria-describedby'), false);
	assert.equal(bar.reason.hidden, true);
});

test('the Use releases message follows the selected release track radio', () => {
	const bar = switchBarHarness({ ready: true });
	const target = {
		matches: () => true,
		closest: (selector) =>
			selector === 'label'
				? { querySelector: () => ({ textContent: 'Preview' }) }
				: { querySelector: () => bar.badge },
	};
	new Function(
		'document',
		'event',
		`${declaration('syncUseReleasesState')}\n${declaration('updateReleaseTrackSummary')}\nupdateReleaseTrackSummary(event);`
	)(
		{
			querySelector: (selector) =>
				({
					'[data-ran-booster-use-releases]': bar.button,
					'[data-ran-booster-use-releases-summary]': bar.summary,
					'[data-ran-booster-release-track-summary]': bar.badge,
				})[selector] ?? null,
		},
		{ target }
	);
	assert.equal(bar.badge.textContent, 'Preview');
	assert.equal(
		bar.summary.textContent,
		'Branch currently active. Switch to releases using the Preview track.'
	);
});

test('the script watches the ready flag so readiness changes reach the switch bar', () => {
	assert.match(
		source,
		/attributeFilter: \['data-ran-booster-use-releases-ready'\]/
	);
});
