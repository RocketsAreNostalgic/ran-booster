import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const css = fs.readFileSync(
	new URL('../../assets/ran-booster/30-provider-cards.css', import.meta.url),
	'utf8'
);

test('provider Card spacing targets mounted hosts and preserves native section insets', () => {
	for (const host of ['.ran-booster-provider-section']) {
		assert.ok(
			css.includes(
				`${host}[data-ran-booster-card="provider"][data-ran-booster-ui-mounted]\n\t.ran-booster-card__content {\n\tpadding: 0;`
			)
		);
	}
});
