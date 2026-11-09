import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-announce.js', import.meta.url),
	'utf8'
);
const assets = new URL('../../assets/', import.meta.url);

function install() {
	const spoken = [];
	const window = {
		wp: {
			a11y: {
				speak: (message, politeness) =>
					spoken.push({ message, politeness }),
			},
		},
	};
	vm.runInNewContext(source, { window });
	return { announce: window.ranBoosterAnnounce, spoken, window };
}

function host(attributes = {}) {
	return {
		closest: () =>
			Object.keys(attributes).length
				? { getAttribute: (name) => attributes[name] ?? null }
				: null,
	};
}

test('speaks through wp.a11y.speak with polite by default and assertive for errors', () => {
	const { announce, spoken } = install();
	assert.equal(announce('  Saved.  '), true);
	assert.equal(announce('Failed.', 'assertive'), true);
	assert.equal(announce('Unknown level.', 'loud'), true);
	assert.deepEqual(spoken, [
		{ message: 'Saved.', politeness: 'polite' },
		{ message: 'Failed.', politeness: 'assertive' },
		{ message: 'Unknown level.', politeness: 'polite' },
	]);
});

test('empty or non-string messages are not spoken', () => {
	const { announce, spoken } = install();
	assert.equal(announce(''), false);
	assert.equal(announce('   '), false);
	assert.equal(announce(undefined), false);
	assert.equal(announce(null, 'assertive'), false);
	assert.deepEqual(spoken, []);
});

test('a host inside active rendered live markup is not spoken a second time', () => {
	const { announce, spoken } = install();
	assert.equal(
		announce('Rendered.', 'assertive', host({ role: 'alert' })),
		false
	);
	assert.equal(
		announce('Rendered.', 'polite', host({ 'aria-live': 'polite' })),
		false
	);
	assert.equal(
		announce('Muted.', 'assertive', host({ 'aria-live': 'off' })),
		true
	);
	assert.equal(announce('Plain.', 'polite', host()), true);
	assert.deepEqual(spoken, [
		{ message: 'Muted.', politeness: 'assertive' },
		{ message: 'Plain.', politeness: 'polite' },
	]);
});

test('the consolidated scripts announce only through the helper', () => {
	for (const file of [
		'ran-booster-enhanced-mutations.js',
		'ran-booster-portability.js',
		'ran-booster-settings.js',
		'ran-booster-repository-picker.js',
		'ran-booster-extensions.js',
		'notice-preference.js',
	]) {
		const script = fs.readFileSync(new URL(file, assets), 'utf8');
		assert.match(script, /window\.ranBoosterAnnounce\(/, file);
		assert.doesNotMatch(script, /wp\??\.a11y/, file);
		assert.doesNotMatch(
			script,
			/setAttribute\(\s*'(role|aria-live)'|(?<!\[)(role|aria-live)="(alert|status|polite|assertive)"/,
			file
		);
	}
});
