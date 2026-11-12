import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

/*
 * The server-rendered <details> fallback and the mounted disclosure header
 * must get the same block padding, or the page shifts by the difference when
 * the runtime mounts. The prominent-density fallback rule carries four
 * attribute selectors and a :not(), so the Installation override has to carry
 * at least as many to win for the fallback as it does for the mounted header.
 */

const css = fs.readFileSync(
	'assets/ran-booster/50-troubleshooting-and-activity.css',
	'utf8'
);

test('the Installation fallback padding outranks the prominent summary rule', () => {
	const rule = css.match(
		/\.ran-booster-admin\s+:is\(\s*\[data-ran-booster-ui-disclosure="installation-section"\],\s*\[data-ran-booster-ui-disclosure="settings-card"\]\s*\)([^{]*?)>\s*details\[data-ran-booster-ui-disclosure-fallback\]\s*>\s*summary\s*\{\s*padding-block:\s*16px;/
	);
	assert.ok(rule, 'the Installation fallback padding rule is missing');
	assert.match(
		rule[1],
		/\[data-ran-booster-ui-disclosure-density\]\[data-ran-booster-ui-disclosure\]/,
		'the fallback selector must repeat the density and disclosure attributes to outrank the prominent rule'
	);
});
