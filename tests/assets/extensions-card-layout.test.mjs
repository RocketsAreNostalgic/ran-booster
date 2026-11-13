import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const css = fs.readFileSync(
	new URL('../../assets/ran-booster/55-extensions.css', import.meta.url),
	'utf8'
);

// Strip comments so assertions read declarations only.
const bare = css.replace(/\/\*[\s\S]*?\*\//g, '');

function block(source, opener) {
	const start = source.indexOf(opener);
	assert.notEqual(start, -1, `missing ${opener}`);
	let depth = 0;
	for (let i = source.indexOf('{', start); i < source.length; i++) {
		if (source[i] === '{') {
			depth++;
		}
		if (source[i] === '}' && --depth === 0) {
			return source.slice(source.indexOf('{', start) + 1, i);
		}
	}
	throw new Error(`unterminated ${opener}`);
}

const query = block(bare, '@container ran-booster-extension-card');
const base = bare.replace(query, '');
const actionLinks = /\.plugin-card\s+\.action-links\s*\{([^}]*)\}/;

test('each Extensions card is a named inline-size container', () => {
	assert.match(
		base,
		/__grid\s*>\s*\.plugin-card\s*\{[^}]*container:\s*ran-booster-extension-card\s*\/\s*inline-size;/
	);
});

test('the card layout is decided by the card, not the viewport', () => {
	assert.match(
		bare,
		/@container ran-booster-extension-card \(min-inline-size: 32rem\)/
	);
	// The only viewport queries left in this file belong to the details dialog.
	for (const [, condition] of bare.matchAll(/@media\s+([^{]+)\{/g)) {
		assert.doesNotMatch(condition, /1100|782px\)\s*and|481|480/);
	}
});

test('actions stack by default and sit in a column only inside the query', () => {
	assert.match(base.match(actionLinks)[1], /position:\s*static;/);
	assert.match(query.match(actionLinks)[1], /position:\s*absolute;/);
	assert.match(query, /inline-size:\s*120px;/);
	assert.match(query, /inset-inline:\s*auto var\(--ran-booster-space-24\);/);
});

test('action items share one flex alignment instead of inline-block', () => {
	assert.match(
		base,
		/\.plugin-action-buttons\s*\{[^}]*display:\s*flex;[^}]*flex-wrap:\s*wrap;[^}]*align-items:\s*center;/
	);
	assert.match(base, /\.plugin-action-buttons\s+br\s*\{\s*display:\s*none;/);
	assert.doesNotMatch(bare, /inline-block/);
});

test('footer groups wrap instead of floating at narrow widths', () => {
	assert.match(
		base,
		/\.plugin-card-bottom\s*\{[^}]*display:\s*flex;[^}]*flex-wrap:\s*wrap;/
	);
	assert.match(
		base,
		/:is\(\.column-rating,\s*\.column-compatibility\)\s*\{[^}]*float:\s*none;/
	);
});

test('card layout rules use logical properties only', () => {
	const layout = bare.slice(
		bare.indexOf('.plugin-card .name'),
		bare.indexOf('.ran-booster-extension-card__metadata')
	);
	assert.doesNotMatch(
		layout,
		/(?:^|[\s;{])(?:left|right|margin-left|margin-right|padding-left|padding-right|text-align:\s*(?:left|right)|float:\s*(?:left|right))\b/m
	);
});
