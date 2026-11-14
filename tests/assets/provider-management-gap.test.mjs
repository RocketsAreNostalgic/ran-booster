import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

/*
 * The provider management block (paused notice and review disclosure) is a
 * stacked item on the provider overview. It takes the documented Card gap
 * below it, like the Cards, so the disclosure never touches the Card below.
 */

const css = fs.readFileSync(
	new URL('../../assets/ran-booster/30-provider-cards.css', import.meta.url),
	'utf8'
);
const view = fs.readFileSync(
	new URL('../../views/provider.php', import.meta.url),
	'utf8'
);

const rules = [...css.matchAll(/([^{}]+)\{([^{}]*)\}/g)].map((m) => ({
	selector: m[1]
		.replace(/\/\*[\s\S]*?\*\//g, '')
		.replace(/\s+/g, ' ')
		.trim(),
	body: m[2],
}));

test('the management block takes the Card gap through the shared stack rule', () => {
	const rule = rules.find(
		(r) =>
			r.selector.includes('.ran-booster-provider--overview') &&
			r.selector.includes('.ran-booster-provider__management') &&
			r.selector.includes('.ran-booster-provider-section')
	);
	assert.ok(rule, 'the stack rule must name the management block');
	assert.match(rule.selector, /\.ran-booster-provider--overview > :is\(/);
	assert.match(
		rule.body,
		/margin-block-end:\s*var\(--ran-booster-card-gap\);/
	);
});

test('the management section carries the hook the rule targets', () => {
	assert.match(
		view,
		/<section class="ran-booster-provider__management" aria-label="<\?php esc_attr_e\( 'GitHub provider management'/
	);
});

test('no direct overview child hand-picks a bottom margin besides the Card gap', () => {
	const offenders = rules.filter(
		(r) =>
			/\.ran-booster-provider--overview\s*>/.test(r.selector) &&
			/margin-block-end:\s*(?!\s|var\(--ran-booster-card-gap\))/.test(
				r.body
			)
	);
	assert.deepEqual(
		offenders.map((r) => r.selector),
		[],
		'overview stack items take the Card gap, not a one-off margin'
	);
});
