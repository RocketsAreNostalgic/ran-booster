import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const primitives = fs.readFileSync(
	new URL(
		'../../assets/ran-booster/25-admin-primitives.css',
		import.meta.url
	),
	'utf8'
);
const onboarding = fs.readFileSync(
	new URL('../../assets/ran-booster-onboarding.css', import.meta.url),
	'utf8'
);

test('Transporter surface overrides are limited to static Core Card hosts', () => {
	assert.ok(
		primitives.includes(
			'.ran-booster-portability__flow[data-ran-booster-card="transporter"].ran-booster-card'
		)
	);
	assert.ok(
		/:is\(\s*\.ran-booster-portability__chooser,\s*\.ran-booster-portability__flow\s*\)\[data-ran-booster-card="transporter"\]\.ran-booster-card/.test(
			onboarding
		)
	);
	assert.ok(onboarding.includes('padding: var(--ran-booster-space-18);'));
	assert.ok(onboarding.includes('#ran-booster-portability-export:target'));
});
