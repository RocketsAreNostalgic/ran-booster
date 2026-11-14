import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

/*
 * Header rhythm: a section header (Card title or prominent disclosure trigger)
 * and a nested panel header each take their inset and block padding from one
 * shared token pair, so equivalent headers line up in mixed compositions and
 * nothing re-compensates per page. See "Header rhythm" in
 * docs/admin-composition-contract.md.
 */

const read = (name) =>
	fs.readFileSync(
		new URL(`../../assets/ran-booster/${name}`, import.meta.url),
		'utf8'
	);
const foundations = read('00-foundations.css');
const primitives = read('25-admin-primitives.css');
const packageSettings = read('65-package-settings.css');
const troubleshooting = read('50-troubleshooting-and-activity.css');

const parse = (css) =>
	[...css.replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/([^{}]+)\{([^{}]*)\}/g)]
		.map((m) => ({
			selector: m[1].replace(/\s+/g, ' ').trim(),
			body: m[2].replace(/\s+/g, ' ').trim(),
		}))
		.filter((r) => r.selector && !r.selector.startsWith('@'));

const token = (name) => {
	const m = foundations.match(
		new RegExp(`${name}:\\s*var\\(--ran-booster-space-(\\d+)\\)`)
	);
	assert.ok(m, `${name} must be defined from a space token`);
	return Number(m[1]);
};

test('the header rhythm tokens are defined once, from the space scale', () => {
	assert.equal(token('--ran-booster-header-inset-inline'), 24);
	assert.equal(token('--ran-booster-header-trigger-inset-block'), 18);
	assert.equal(token('--ran-booster-panel-header-inset-inline'), 18);
	assert.equal(token('--ran-booster-panel-header-inset-block'), 16);
});

test('the section inset narrows once, at the compact boundary, for every consumer', () => {
	const narrow = foundations.match(
		/@media screen and \(max-width: 480px\)\s*\{\s*:root\s*\{([^}]*)\}/
	);
	assert.ok(narrow, 'the 480px token override is missing');
	assert.match(
		narrow[1],
		/--ran-booster-header-inset-inline:\s*var\(--ran-booster-space-16\)/
	);
});

test('a prominent trigger is as tall as a section header without a fixed height', () => {
	const cardHeader = 24 + 22.5 + 14; /* title inset, 15px at 1.5, rule gap */
	const trigger = token('--ran-booster-header-trigger-inset-block') * 2 + 24;
	assert.ok(
		Math.abs(cardHeader - trigger) <= 1,
		`trigger ${trigger}px must be within 1px of the section header ${cardHeader}px`
	);
	assert.doesNotMatch(
		primitives
			.match(
				/\[data-ran-booster-ui-disclosure-density="prominent"\][^{}]*\{[^}]*\}/g
			)
			.join('\n'),
		/(?:^|[\s;])(?:min-|max-)?(?:block-size|height)\s*:/,
		'the prominent profile must not fix a height'
	);
});

test('Card headers and bodies take their inline inset from the shared token', () => {
	const rules = parse(packageSettings);
	for (const part of ['__header', '__body']) {
		const rule = rules.find(
			(r) =>
				r.selector ===
				`.ran-booster-admin .ran-booster-settings-section${part}`
		);
		assert.ok(rule, `settings-section${part} rule is missing`);
		assert.match(
			rule.body,
			/padding-inline: var\(--ran-booster-header-inset-inline\)/
		);
	}
});

test('prominent triggers and their native fallback share the section padding', () => {
	const rules = parse(primitives).filter(
		(r) =>
			r.selector.includes('density="prominent"') &&
			/> (?:\[role="button"\]|summary)$/.test(r.selector) &&
			r.body.startsWith('padding:')
	);
	assert.equal(
		rules.length,
		3,
		'section trigger, section fallback and one nested rule'
	);
	const section = rules.filter(
		(r) => (r.selector.match(/density="prominent"/g) || []).length === 1
	);
	assert.equal(section.length, 2);
	for (const rule of section) {
		assert.match(
			rule.body,
			/padding: var\(--ran-booster-header-trigger-inset-block\) var\(--ran-booster-header-inset-inline\)/
		);
		assert.doesNotMatch(rule.body, /\d+px \d+px;?/);
	}
});

test('a prominent disclosure inside another is a nested panel with sub-heading type', () => {
	const rules = parse(primitives);
	const nested = rules.find(
		(r) =>
			r.body.startsWith('padding: var(--ran-booster-panel-header') &&
			r.selector.includes('> [role="button"]') &&
			r.selector.includes('> summary')
	);
	assert.ok(
		nested,
		'the nested prominent rule must cover the trigger and the fallback'
	);
	assert.match(
		nested.body,
		/padding: var\(--ran-booster-panel-header-inset-block\) var\(--ran-booster-panel-header-inset-inline\)/
	);
	assert.match(nested.body, /font-size: var\(--ran-booster-font-size-md\)/);
	assert.ok(
		primitives.indexOf(
			'padding: var(--ran-booster-panel-header-inset-block)'
		) >
			primitives.lastIndexOf(
				'padding: var(--ran-booster-header-trigger-inset-block)'
			),
		'the nested rule must follow the section rules it overrides'
	);
});

test('the nested panel header matches the readiness panel header', () => {
	const top = parse(packageSettings).find(
		(r) =>
			r.selector ===
			'.ran-booster-admin .ran-booster-readiness-panel__top'
	);
	assert.ok(top, 'readiness panel header rule is missing');
	assert.match(
		top.body,
		/padding-block: var\(--ran-booster-panel-header-inset-block\)/
	);
	assert.match(
		top.body,
		/padding-inline: var\(--ran-booster-panel-header-inset-inline\)/
	);
});

test('no page re-compensates the shared header inset', () => {
	const rules = [...parse(packageSettings), ...parse(troubleshooting)];
	const offenders = rules.filter(
		(r) =>
			/package-operation-settings[^,]*settings-section__header/.test(
				r.selector
			) ||
			(/settings-card/.test(r.selector) &&
				/ui-disclosure__header|summary/.test(r.selector) &&
				/padding-inline/.test(r.body))
	);
	assert.deepEqual(
		offenders.map((r) => r.selector),
		[],
		'section header inset belongs to the shared token only'
	);
	assert.match(
		packageSettings,
		/--ran-booster-settings-card-inset: var\(--ran-booster-header-inset-inline\)/
	);
	const warning = parse(troubleshooting).find(
		(r) =>
			r.selector.endsWith(
				'.ran-booster-troubleshooting__entry-warning'
			) && r.body.includes('padding:')
	);
	assert.match(
		warning.body,
		/padding: 0 var\(--ran-booster-header-inset-inline\) 16px/
	);
});

test('Installation and Settings headers keep 16px block padding and take only the shared inset', () => {
	assert.match(
		troubleshooting,
		/\[data-ran-booster-ui-disclosure="settings-card"\][^{]*\{\s*padding-block:\s*16px;/
	);
});
