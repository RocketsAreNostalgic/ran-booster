import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-repository-picker.js', import.meta.url),
	'utf8'
);
const legacySource = fs.readFileSync(
	new URL('../../assets/ran-booster.js', import.meta.url),
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

function fixture() {
	const automatic = {
		disabled: false,
		value: 'automatic',
	};
	const manual = {
		disabled: false,
		value: 'manual',
	};
	const form = {
		querySelector(selector) {
			return selector.includes('ran-booster-deployment-policy-input')
				? select
				: null;
		},
	};
	const select = {
		options: [manual, automatic],
		value: 'automatic',
	};

	return { automatic, form, select };
}

function pickerFixture() {
	const button = {
		disabled: false,
		hidden: false,
		title: '',
	};
	const form = {
		querySelector(selector) {
			return selector === '.ran-booster-open-repository-picker'
				? button
				: null;
		},
	};

	return { button, form };
}

test('repository picker initializes only from its dedicated asset', () => {
	assert.match(source, /onDomReady\(initRepositoryPicker\)/);
	assert.doesNotMatch(legacySource, /initRepositoryPicker/);
});

test('provider switching disables Automatic and falls back to Manual without webhook support', () => {
	const update = loadFunction('updateDeploymentPolicyAvailability');
	const { automatic, form, select } = fixture();

	update(form, { code: 'fixture-provider', webhooks: false });

	assert.equal(automatic.disabled, true);
	assert.equal(select.value, 'manual');
});

test('provider switching enables Automatic without changing Manual selection', () => {
	const update = loadFunction('updateDeploymentPolicyAvailability');
	const { automatic, form, select } = fixture();

	select.value = 'manual';
	automatic.disabled = true;
	update(form, { code: 'gh', webhooks: true });

	assert.equal(automatic.disabled, false);
	assert.equal(select.value, 'manual');
});

test('provider switching hides the picker without browsing support', () => {
	const update = loadFunction('updateRepositoryPickerAvailability', {
		__: (message) => message,
	});
	const { button, form } = pickerFixture();

	update(form, { code: 'fixture-provider', browse: false });

	assert.equal(button.disabled, true);
	assert.equal(button.hidden, true);
	assert.match(button.title, /not available/);
});

test('provider switching restores the picker for browsing providers', () => {
	const update = loadFunction('updateRepositoryPickerAvailability', {
		__: (message) => message,
	});
	const { button, form } = pickerFixture();

	button.disabled = true;
	button.hidden = true;
	button.title = 'Unavailable';
	update(form, { code: 'bb', browse: true });

	assert.equal(button.disabled, false);
	assert.equal(button.hidden, false);
	assert.equal(button.title, '');
});

test('repository picker uses one selected credential and reports partial results', () => {
	assert.doesNotMatch(source, /All configured credentials/);
	assert.match(source, /data\.partial === true/);
	assert.match(source, /credentialSelect\.value/);
});

test('public lookup identity stays distinct from the durable package credential', () => {
	assert.match(source, /public_lookup_identity: publicLookupIdentity/);
	assert.match(source, /public_lookup_profile_id: publicLookupProfileId/);
	assert.match(source, /loadedPublicLookupProfileId/);
	assert.match(source, /ran-booster-public-lookup-profile-input/);
	assert.match(source, /Repository access profile/);
	assert.match(
		source,
		/new window\.Option\(__\('Anonymous', 'ran-booster'\), ''\)/
	);
	assert.doesNotMatch(source, /Use Anonymous for this lookup/);
	assert.match(
		source,
		/Anonymous API requests have lower rate limits\.[\s\S]*Manage credentials[\s\S]*search credential/
	);
	assert.match(
		source,
		/publicLimitNotice\.toggleAttribute\('hidden', !showCredentialsNotice\)/
	);
	assert.match(source, /provider\.credentials_url/);
	assert.match(
		source,
		/class="ran-booster-source-choice ran-booster-repository-picker__mode"/
	);
	assert.ok(
		source.indexOf(
			'<button type="button" class="ran-booster-source-choice ran-booster-repository-picker__mode" data-mode="public"'
		) <
			source.indexOf(
				'<button type="button" class="ran-booster-source-choice ran-booster-repository-picker__mode" data-mode="accessible"'
			),
		'Public repositories must be the left-hand option.'
	);
	assert.match(
		source,
		/accessibleModeButton\.disabled\s*=\s*accessibleProfiles\.length === 0/
	);
	assert.match(
		source,
		/mode === 'accessible' && !accessibleModeButton\.disabled/
	);
	assert.match(
		source,
		/button\.classList\.toggle\('is-selected', isActive\)/
	);
	assert.doesNotMatch(
		source,
		/button\.classList\.toggle\('button-primary', isActive\)/
	);
	assert.doesNotMatch(
		source,
		/activeProvider\.code\s*===\s*['"](?:gh|bb)['"]/
	);
});

test('repository filtering preserves the bounded partial-results warning', () => {
	const status = loadFunction('repositoryResultStatus', {
		_n: (single, plural, count) => (count === 1 ? single : plural),
		sprintf: (message, count) => message.replace('%d', String(count)),
	});
	const warning =
		'Some repositories are shown. The provider request limit was reached.';

	assert.equal(status(3, warning), `3 repositories. ${warning}`);
	assert.equal(status(1, warning), `1 repository. ${warning}`);
	assert.equal(status(0, ''), '0 repositories');
	assert.match(source, /repositoryResultStatus\(\s*filtered\.length,/);
});

test('repository count and accessibility copy use the Core JavaScript catalogue', () => {
	const translations = {
		'%d repository': '%d dépôt',
		'%d repositories': '%d dépôts',
	};
	const status = loadFunction('repositoryResultStatus', {
		_n: (single, plural, count) =>
			translations[count > 1 ? plural : single],
		sprintf: (message, count) => message.replace('%d', String(count)),
	});

	assert.equal(status(1, ''), '1 dépôt');
	assert.equal(status(3, ''), '3 dépôts');
	assert.match(
		source,
		/\.setAttribute\(\s*'aria-label',\s*__\('Repository source', 'ran-booster'\)\s*\)/
	);
	assert.match(source, /__\('Close repository picker', 'ran-booster'\)/);
});

test('credential editing contains no unreleased legacy-profile branch', () => {
	assert.doesNotMatch(
		source,
		/isLegacy|Legacy profile labels|data-id.*legacy/
	);
});
