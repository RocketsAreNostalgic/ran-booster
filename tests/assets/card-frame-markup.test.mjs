import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';

// A Card whose last block is a footer band adds the --footed modifier to its content.
const frame =
	/^<div class="ran-wp-ui-card ran-wp-ui-box-sizing ran-booster-card__surface"><div class="ran-booster-card__content(?: ran-booster-card__content--footed)?">/;

function files(directory) {
	return fs
		.readdirSync(directory, { withFileTypes: true })
		.flatMap((entry) => {
			const full = path.join(directory, entry.name);
			if (entry.isDirectory()) {
				return files(full);
			}
			return entry.name.endsWith('.php') ? [full] : [];
		});
}

// Template PHP is dropped so only the rendered markup is compared. A
// conditional attribute such as `$is_card ? ' data-…="provider"' : ''` keeps
// its literal, and string concatenation in PHP renderers is joined.
function markup(source) {
	return source
		.replace(/<\?php[\s\S]*?\?>/g, (block) => {
			const literal = block.match(
				/\?\s*'([^']*data-ran-booster-card=[^']*)'/
			);
			return literal ? literal[1] : '';
		})
		.replace(/'\s*\.\s*'/g, '');
}

test('every page Card host renders the static public Card frame', () => {
	let hosts = 0;
	for (const file of [...files('views'), ...files('RAN')]) {
		const source = markup(fs.readFileSync(file, 'utf8'));
		for (const found of source.matchAll(
			/<(?:section|div)\b[^>]*\bdata-ran-booster-card="[^"]*"[^>]*>/g
		)) {
			hosts++;
			const rest = source
				.slice(found.index + found[0].length)
				.trimStart();
			assert.ok(
				frame.test(rest),
				`${file}: Card host without the static frame: ${found[0]}`
			);
		}
	}
	assert.equal(hosts, 32);
});

test('Card styles no longer depend on a mounted React Card', () => {
	const sheets = [
		...fs
			.readdirSync('assets/ran-booster')
			.map((name) => `assets/ran-booster/${name}`),
		'assets/ran-booster-onboarding.css',
	];
	for (const sheet of sheets) {
		const css = fs.readFileSync(sheet, 'utf8');
		assert.doesNotMatch(css, /ran-booster-card__mount/, sheet);
		assert.doesNotMatch(
			css,
			/data-ran-booster-card[^{]*data-ran-booster-ui-mounted/,
			sheet
		);
	}
	const adapter = fs.readFileSync('assets/ran-booster-cards.js', 'utf8');
	assert.doesNotMatch(adapter, /CardRoot|data-ran-booster-card-content/);
});
