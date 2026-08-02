import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-repository-picker.js', import.meta.url),
	'utf8'
);

function loadFunction(name, dependencies = {}) {
	const signature = `\tfunction ${name}(`;
	const start = source.indexOf(signature);

	assert.notEqual(start, -1, `The shared ${name} function must exist.`);

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
				end = index + 1;
				break;
			}
		}
	}

	assert.notEqual(end, -1, `The shared ${name} function must be complete.`);

	return Function(
		...Object.keys(dependencies),
		`"use strict"; return (${source.slice(start, end)});`
	)(...Object.values(dependencies));
}

const emptyRepositoryPickerState = loadFunction('emptyRepositoryPickerState');
const normalizeRepositoryPickerState = loadFunction(
	'normalizeRepositoryPickerState',
	{ emptyRepositoryPickerState }
);
const readRepositoryPickerState = loadFunction('readRepositoryPickerState', {
	emptyRepositoryPickerState,
	normalizeRepositoryPickerState,
});
const writeRepositoryPickerState = loadFunction('writeRepositoryPickerState', {
	normalizeRepositoryPickerState,
});
const repositoryPickerProviderState = loadFunction(
	'repositoryPickerProviderState'
);
const repositoryPickerStorage = loadFunction('repositoryPickerStorage');
const restoreRepositoryPickerProvider = loadFunction(
	'restoreRepositoryPickerProvider'
);
const autoOpenRepositoryPicker = loadFunction('autoOpenRepositoryPicker');
const repositoryPickerPublicProfileValue = loadFunction(
	'repositoryPickerPublicProfileValue'
);
const repositoryPickerPublicProfiles = loadFunction(
	'repositoryPickerPublicProfiles'
);
const repositoryPickerShowsCredentialsNotice = loadFunction(
	'repositoryPickerShowsCredentialsNotice'
);

function memoryStorage(initialValue = null) {
	const values = new Map();
	if (initialValue !== null) {
		values.set('ran-booster-repository-picker', initialValue);
	}

	return {
		getItem(key) {
			return values.has(key) ? values.get(key) : null;
		},
		setItem(key, value) {
			values.set(key, value);
		},
		value() {
			return values.get('ran-booster-repository-picker');
		},
	};
}

function formFixture(attributes, providerValue = 'gh') {
	const input = {
		options: [{ value: 'gh' }, { value: 'bb' }],
		value: providerValue,
	};
	let clickCount = 0;
	const button = {
		disabled: false,
		hidden: false,
		click() {
			clickCount += 1;
		},
	};

	return {
		button,
		form: {
			getAttribute(name) {
				return attributes[name] ?? null;
			},
			querySelector(selector) {
				return selector === '.ran-booster-provider-input'
					? input
					: button;
			},
		},
		input,
		clickCount() {
			return clickCount;
		},
	};
}

test('picker state round-trips only allowlisted current-tab context', () => {
	const storage = memoryStorage();
	const state = {
		version: 2,
		lastProvider: 'gh',
		providers: {
			gh: {
				mode: 'public',
				accessible: {
					credentialId: 'classic-pat',
					filter: 'agency/',
					secret: 'must-not-survive',
				},
				public: {
					identity: 'profile',
					profileId: 'public-api',
					owner: 'agency',
					filter: 'plugin',
					repositories: [{ locator: 'agency/private-plugin' }],
				},
				selectedRepository: 'agency/private-plugin',
				branch: 'main',
			},
		},
	};

	writeRepositoryPickerState(storage, state);

	const stored = JSON.parse(storage.value());
	assert.deepEqual(stored, {
		version: 2,
		lastProvider: 'gh',
		providers: {
			gh: {
				mode: 'public',
				accessible: {
					credentialId: 'classic-pat',
					filter: 'agency/',
				},
				public: {
					identity: 'profile',
					profileId: 'public-api',
					owner: 'agency',
					filter: 'plugin',
				},
			},
		},
	});
	assert.deepEqual(readRepositoryPickerState(storage), stored);
	assert.doesNotMatch(
		storage.value(),
		/secret|repositories|selectedRepository|branch|private-plugin/
	);
});

test('provider and mode context remain independent', () => {
	const state = emptyRepositoryPickerState();
	const github = repositoryPickerProviderState(state, 'gh');
	const bitbucket = repositoryPickerProviderState(state, 'bb');

	assert.equal(
		github.mode,
		'public',
		'first use must default to the public repository search'
	);
	github.mode = 'accessible';
	github.accessible.credentialId = 'github-pat';
	github.accessible.filter = 'github-org/';
	github.public.owner = 'github-public';
	github.public.filter = 'docs';
	bitbucket.mode = 'public';
	bitbucket.accessible.filter = 'workspace/private';
	bitbucket.public.owner = 'workspace';
	bitbucket.public.filter = 'theme';

	assert.equal(state.providers.gh.accessible.filter, 'github-org/');
	assert.equal(state.providers.gh.public.filter, 'docs');
	assert.equal(state.providers.bb.accessible.filter, 'workspace/private');
	assert.equal(state.providers.bb.public.filter, 'theme');
	assert.equal(state.providers.gh.accessible.credentialId, 'github-pat');
});

test('malformed, stale-version, and unavailable storage degrade to defaults', () => {
	assert.deepEqual(
		readRepositoryPickerState(memoryStorage('{not-json')),
		emptyRepositoryPickerState()
	);
	assert.deepEqual(
		readRepositoryPickerState(
			memoryStorage(JSON.stringify({ version: 1, lastProvider: 'gh' }))
		),
		emptyRepositoryPickerState()
	);
	assert.deepEqual(
		readRepositoryPickerState({
			getItem() {
				throw new Error('Storage denied');
			},
		}),
		emptyRepositoryPickerState()
	);
	assert.doesNotThrow(() => {
		writeRepositoryPickerState(
			{
				setItem() {
					throw new Error('Storage denied');
				},
			},
			emptyRepositoryPickerState()
		);
	});
	assert.equal(
		repositoryPickerStorage({
			get sessionStorage() {
				throw new Error('Storage denied');
			},
		}),
		null
	);
});

test('remembered provider restores only on unpinned create forms', () => {
	const state = {
		lastProvider: 'bb',
	};
	const providers = {
		gh: { browse: true },
		bb: { browse: true },
	};
	const create = formFixture({
		'data-ran-booster-package-create': '1',
		'data-ran-booster-explicit-provider': '0',
	});

	assert.equal(
		restoreRepositoryPickerProvider(create.form, state, providers),
		true
	);
	assert.equal(create.input.value, 'bb');

	const explicit = formFixture({
		'data-ran-booster-package-create': '1',
		'data-ran-booster-explicit-provider': '1',
	});
	assert.equal(
		restoreRepositoryPickerProvider(explicit.form, state, providers),
		false
	);
	assert.equal(explicit.input.value, 'gh');

	const edit = formFixture({});
	assert.equal(
		restoreRepositoryPickerProvider(edit.form, state, providers),
		false
	);
	assert.equal(edit.input.value, 'gh');

	const removed = formFixture({
		'data-ran-booster-package-create': '1',
		'data-ran-booster-explicit-provider': '0',
	});
	assert.equal(
		restoreRepositoryPickerProvider(removed.form, state, {
			gh: providers.gh,
		}),
		false
	);
	assert.equal(removed.input.value, 'gh');
});

test('picker auto-opens only from a safe create-form marker', () => {
	const repeat = formFixture({
		'data-ran-booster-package-create': '1',
		'data-ran-booster-open-picker': '1',
	});
	assert.equal(autoOpenRepositoryPicker(repeat.form), true);
	assert.equal(repeat.clickCount(), 1);

	const ordinaryCreate = formFixture({
		'data-ran-booster-package-create': '1',
		'data-ran-booster-open-picker': '0',
	});
	assert.equal(autoOpenRepositoryPicker(ordinaryCreate.form), false);
	assert.equal(ordinaryCreate.clickCount(), 0);

	const edit = formFixture({
		'data-ran-booster-open-picker': '1',
	});
	assert.equal(autoOpenRepositoryPicker(edit.form), false);
	assert.equal(edit.clickCount(), 0);

	repeat.button.disabled = true;
	assert.equal(autoOpenRepositoryPicker(repeat.form), false);
	assert.equal(repeat.clickCount(), 1);
});

test('public lookup selector resolves anonymous, explicit, default, and stale state', () => {
	assert.equal(
		repositoryPickerPublicProfileValue(
			{
				supports_default: true,
				configured_id: 'removed-profile',
				stale: true,
			},
			{ identity: 'default', profileId: '' },
			['public-api']
		),
		''
	);
	assert.equal(
		repositoryPickerPublicProfileValue(
			{
				supports_default: true,
				configured_id: 'public-api',
				stale: false,
			},
			{ identity: 'anonymous', profileId: '' },
			['public-api']
		),
		''
	);
	assert.equal(
		repositoryPickerPublicProfileValue(
			{
				supports_default: true,
				configured_id: 'public-api',
				stale: false,
			},
			{ identity: 'default', profileId: '' },
			['public-api']
		),
		'public-api'
	);
	assert.equal(
		repositoryPickerPublicProfileValue(
			{
				supports_default: true,
				configured_id: 'public-api',
				stale: false,
			},
			{ identity: 'profile', profileId: 'other-profile' },
			['public-api', 'other-profile']
		),
		'other-profile'
	);
});

test('public lookup exposes only the configured search profile when defaults are supported', () => {
	const profiles = [
		{ id: 'public-api', configured: true },
		{ id: 'private-repository', configured: true },
		{ id: 'unavailable', configured: false },
	];

	assert.deepEqual(
		repositoryPickerPublicProfiles(
			{
				supports_default: true,
				configured_id: 'public-api',
				stale: false,
			},
			profiles
		),
		[profiles[0]]
	);
	assert.deepEqual(
		repositoryPickerPublicProfiles(
			{
				supports_default: true,
				configured_id: 'missing',
				stale: true,
			},
			profiles
		),
		[]
	);
	assert.deepEqual(
		repositoryPickerPublicProfiles(
			{
				supports_default: false,
				configured_id: '',
				stale: false,
			},
			profiles
		),
		[profiles[0], profiles[1]]
	);
});

test('anonymous lookup guidance appears only when credentialed lookup can be configured', () => {
	const lookup = {
		supports_default: true,
		configured_id: '',
		stale: false,
	};

	assert.equal(
		repositoryPickerShowsCredentialsNotice(
			lookup,
			[],
			'admin.php?page=ran-booster&tab=gh&view=credentials'
		),
		true
	);
	assert.equal(
		repositoryPickerShowsCredentialsNotice(
			lookup,
			[{ id: 'public-api' }],
			'admin.php?page=ran-booster&tab=gh&view=credentials'
		),
		false
	);
	assert.equal(
		repositoryPickerShowsCredentialsNotice(
			null,
			[],
			'admin.php?page=ran-booster&tab=fixture&view=credentials'
		),
		false
	);
	assert.equal(repositoryPickerShowsCredentialsNotice(lookup, [], ''), false);
});

test('picker lifecycle re-fetches restored context and persists empty filters', () => {
	assert.match(
		source,
		/const pickerStorage = repositoryPickerStorage\(window\)/
	);
	assert.match(
		source,
		/currentProviderState\(\)\[activeMode\]\.filter = search\.value/
	);
	assert.match(source, /search\.value = providerState\[activeMode\]\.filter/);
	assert.match(
		source,
		/loadRepositories\(\s*'public',\s*owner,\s*'',\s*lookup\.identity/
	);
	assert.match(
		source,
		/function showAccessibleRepositories\(\)[\s\S]*loadRepositories\('accessible'/
	);
	assert.doesNotMatch(source, /window\.localStorage|localStorage\./);
});
