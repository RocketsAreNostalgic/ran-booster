import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';

const read = (file) => fs.readFileSync(file, 'utf8');
const tokensCss = read('assets/lib/ran-booster-wpds-tokens.css');
const manifest = JSON.parse(read('assets/lib/ran-booster-wpds-tokens.json'));
const sha256 = (text) => createHash('sha256').update(text).digest('hex');
const definitions = (css) =>
	new Set(css.match(/--wpds-[a-z0-9-]+(?=\s*:)/g) ?? []);
const uses = (css) =>
	new Set(
		[...css.matchAll(/var\(\s*(--wpds-[a-z0-9-]+)/g)].map(
			(match) => match[1]
		)
	);
const stylesheets = (directory) =>
	fs.readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
		const full = path.join(directory, entry.name);
		if (entry.isDirectory()) {
			return stylesheets(full);
		}
		// The bundled tokens themselves are not a consumer.
		return entry.name.endsWith('.css') &&
			entry.name !== 'ran-booster-wpds-tokens.css'
			? [full]
			: [];
	});

test('the bundled tokens match their manifest and come from the pinned theme package', () => {
	assert.equal(manifest.outputSha256, sha256(tokensCss));
	assert.equal(manifest.source.package, '@wordpress/theme');
	assert.equal(manifest.tokens, definitions(tokensCss).size);
	assert.match(
		tokensCss,
		new RegExp(
			`^/\\* @wordpress/theme ${manifest.source.version} design tokens`
		)
	);
	const ui = JSON.parse(read('node_modules/@wordpress/ui/package.json'));
	assert.equal(
		ui.dependencies['@wordpress/theme'].replace(/^[\^~]/, '').split('.')[0],
		manifest.source.version.split('.')[0],
		'The tokens must come from the theme release @wordpress/ui is built against.'
	);
});

test('every design token a Booster stylesheet reads is defined by the bundle or by Booster itself', () => {
	const bundled = definitions(tokensCss);
	const missing = new Map();
	for (const file of stylesheets('assets')) {
		const css = read(file);
		const local = definitions(css);
		for (const token of uses(css)) {
			if (!bundled.has(token) && !local.has(token)) {
				missing.set(token, [...(missing.get(token) ?? []), file]);
			}
		}
	}
	assert.deepEqual(
		Object.fromEntries(missing),
		{},
		'WordPress before 7.1 only has the bundled tokens, so a token missing from the bundle renders unstyled there.'
	);
});

test('the bundle defines the type scale and the surface colours the Cards and headings read', () => {
	const bundled = definitions(tokensCss);
	for (const name of [
		'typography-font-size-xs',
		'typography-font-size-md',
		'typography-font-size-xl',
		'typography-font-weight-emphasis',
		'color-background-surface-neutral',
		'color-foreground-content-neutral',
		'dimension-gap-lg',
		'dimension-padding-2xl',
	]) {
		assert.ok(
			[...bundled].some((token) => token.endsWith(`-${name}`)),
			name
		);
	}
});
