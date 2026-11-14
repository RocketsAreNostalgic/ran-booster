import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

/*
 * A Badge is an inline box, and a vertical margin on an inline box never
 * applies. The Packages list summary cell gives its status Badge a gap below,
 * so the Badge must be a block-level box while it is shown, and the gap must
 * come from the spacing token. A hidden placeholder must stay hidden.
 */

const dir = new URL('../../assets/ran-booster/', import.meta.url);
const strip = (text) => text.replace(/\/\*[\s\S]*?\*\//g, '');
const rulesOf = (file) =>
	[
		...strip(fs.readFileSync(new URL(file, dir), 'utf8')).matchAll(
			/([^{}]+)\{([^{}]*)\}/g
		),
	].map((m) => ({
		selector: m[1].replace(/\s+/g, ' ').trim(),
		body: m[2],
	}));

test('the summary cell Badge is a block box with the tokenised gap below it', () => {
	const rule = rulesOf('60-packages.css').find((r) =>
		r.selector.includes(
			'.ran-booster-package-row__summary > [data-ran-booster-badge]'
		)
	);
	assert.ok(rule, 'the summary cell Badge rule is missing');
	assert.match(
		rule.selector,
		/\[data-ran-booster-badge\]:not\(\[hidden\]\)$/
	);
	assert.match(rule.body, /display:\s*block;/);
	assert.match(rule.body, /inline-size:\s*fit-content;/);
	assert.match(rule.body, /max-inline-size:\s*100%;/);
	assert.match(
		rule.body,
		/margin-block-end:\s*var\(--ran-booster-space-7\);/
	);
});

test('no Badge rule sets a vertical margin without making the Badge block-level', () => {
	const offenders = [];
	for (const file of fs.readdirSync(dir).filter((f) => f.endsWith('.css'))) {
		for (const r of rulesOf(file)) {
			if (
				/badge/i.test(r.selector) &&
				/margin(-block(-start|-end)?|-top|-bottom)?\s*:/.test(r.body) &&
				!/display:\s*(block|flex|grid|inline-block|inline-flex)\s*;/.test(
					r.body
				)
			) {
				offenders.push(`${file}: ${r.selector}`);
			}
		}
	}
	assert.deepEqual(offenders, []);
});
