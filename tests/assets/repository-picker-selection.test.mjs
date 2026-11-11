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

const findFormControl = loadFunction('findFormControl');
const repositorySelectionControls = loadFunction(
	'repositorySelectionControls',
	{ findFormControl }
);
const applyRepositorySelection = loadFunction('applyRepositorySelection', {
	repositorySelectionControls,
});

function control(className, value = '') {
	return {
		className,
		value,
		matches(selector) {
			return selector === `.${this.className}`;
		},
	};
}

// The form stub mirrors the DOM split the Edit screen relies on:
// querySelector sees descendants only, while elements also lists controls
// associated through the form attribute.
function formFixture({ branch = 'inside' } = {}) {
	const controls = {
		repository: control('ran-booster-repository-input', 'old/repo'),
		branch: control('ran-booster-branch-input', 'old-branch'),
		credential: control('ran-booster-credential-input', 'old-credential'),
		provider: control('ran-booster-provider-input', 'gh'),
		providerRepositoryId: control(
			'ran-booster-provider-repository-id-input',
			'111'
		),
		identitySource: control(
			'ran-booster-provider-repository-identity-source-input',
			'stored'
		),
		publicLookupProfile: control(
			'ran-booster-public-lookup-profile-input',
			''
		),
	};
	const descendants = Object.entries(controls)
		.filter(([key]) => key !== 'branch' || branch === 'inside')
		.map(([, element]) => element);
	const elements =
		branch === 'missing'
			? descendants
			: descendants.concat(branch === 'outside' ? [controls.branch] : []);
	const appended = [];

	return {
		controls,
		appended,
		form: {
			elements,
			ownerDocument: {
				createElement() {
					return control('');
				},
			},
			querySelector(selector) {
				return (
					descendants.find((element) => element.matches(selector)) ||
					null
				);
			},
			appendChild(element) {
				appended.push(element);
				descendants.push(element);
			},
		},
	};
}

function snapshot(controls) {
	return Object.fromEntries(
		Object.entries(controls).map(([key, element]) => [key, element.value])
	);
}

function selection(calls) {
	return {
		publicLookupProfileId: 'public-api',
		credentialId: 'classic-pat',
		updateProviderForm(form) {
			calls.push(['updateProviderForm', form]);
		},
		selectCredential(select, provider, credentialId) {
			calls.push(['selectCredential', provider, credentialId]);
			select.value = credentialId;
		},
	};
}

const repository = {
	locator: 'RocketsAreNostalgic/booster-fixture-plugin',
	provider: 'gh',
	provider_repository_id: '1315521150',
	default_branch: 'main',
};

for (const branch of ['inside', 'outside']) {
	test(`selection updates every control when the branch input is ${branch} the form`, () => {
		const fixture = formFixture({ branch });
		const calls = [];

		const result = applyRepositorySelection(
			fixture.form,
			repository,
			selection(calls)
		);

		assert.equal(result, fixture.controls.repository);
		assert.deepEqual(snapshot(fixture.controls), {
			repository: 'RocketsAreNostalgic/booster-fixture-plugin',
			branch: 'main',
			credential: 'classic-pat',
			provider: 'gh',
			providerRepositoryId: '1315521150',
			identitySource: 'picker',
			publicLookupProfile: 'public-api',
		});
		assert.deepEqual(calls, [
			['updateProviderForm', fixture.form],
			['selectCredential', 'gh', 'classic-pat'],
		]);
		assert.deepEqual(fixture.appended, []);
	});
}

test('selection leaves the form unchanged when the branch input is missing', () => {
	const fixture = formFixture({ branch: 'missing' });
	const before = snapshot(fixture.controls);
	const calls = [];

	const result = applyRepositorySelection(
		fixture.form,
		repository,
		selection(calls)
	);

	assert.equal(result, null);
	assert.deepEqual(snapshot(fixture.controls), before);
	assert.deepEqual(calls, []);
	assert.deepEqual(fixture.appended, []);
});

test('form controls resolve through the form attribute association', () => {
	const fixture = formFixture({ branch: 'outside' });

	assert.equal(
		findFormControl(fixture.form, '.ran-booster-branch-input'),
		fixture.controls.branch
	);
	assert.equal(
		findFormControl(fixture.form, '.ran-booster-repository-input'),
		fixture.controls.repository
	);
	assert.equal(findFormControl(fixture.form, '.missing'), null);
	assert.equal(findFormControl(null, '.ran-booster-branch-input'), null);
	assert.equal(
		repositorySelectionControls(formFixture({ branch: 'missing' }).form),
		null
	);
});

test('a locked Edit form with a readonly repository input still takes a selection', () => {
	const readonly = control('ran-booster-repository-input', 'acme/fixed');
	readonly.readOnly = true;
	const hiddenProvider = control('ran-booster-provider-input', 'gh');
	const branch = control('ran-booster-branch-input', 'main');
	const form = {
		querySelector: (selector) =>
			[readonly, hiddenProvider, branch].find((item) =>
				item.matches(selector)
			) ?? null,
		elements: [],
	};

	const controls = repositorySelectionControls(form);
	assert.equal(controls?.provider, hiddenProvider);
	assert.equal(controls?.repository, readonly);
});

test('the picker closes and announces the change only after a full selection', () => {
	const start = source.indexOf('\t\tfunction selectRepository(');
	const end = source.indexOf('\t\tfunction closePicker(', start);

	assert.notEqual(start, -1);
	assert.notEqual(end, -1);

	const body = source.slice(start, end);

	assert.match(body, /applyRepositorySelection\(/);
	assert.match(
		body,
		/if \(repositoryInput\) \{\s*dispatchRepositoryContextChanged\(activeForm, 'picker'\);\s*\}\s*closePicker\(repositoryInput\);/
	);
	assert.doesNotMatch(body, /activeForm\.querySelector\(/);
});
