import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';

const icons = path.join(
	fs.realpathSync('node_modules/@wordpress/ui'),
	'../icons/build-module/library'
);

test('the Settings error host renders the static public Notice', () => {
	const view = fs.readFileSync('views/settings-general.php', 'utf8');
	const host = view.match(
		/<div id="ran-booster-general-<\?php echo esc_attr\( \$scope \); \?>-error"[\s\S]*?NoticeIcon::print_intent\( 'error' \); \?><\/div>/
	);
	assert.ok(host, 'scope error host');
	assert.match(
		host[0],
		/^<div id="[^"]*" class="notice notice-error inline ran-wp-ui-notice ran-wp-ui-box-sizing is-error" data-ran-booster-admin-mutation-error data-ran-booster-ui-notice/
	);
	assert.match(
		host[0],
		/><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content><p data-ran-booster-ui-notice-primary>/
	);
	// The icon follows the content, as Notice.Root draws it after children.
	assert.match(
		host[0],
		/<\/div><\?php \\RAN\\Admin\\Component\\NoticeIcon::print_intent\( 'error' \); \?><\/div>$/
	);
	assert.doesNotMatch(host[0], /politeness|notice-list|aria-live/);
});

test('NoticeIcon draws the glyphs the installed Notice.Root uses', () => {
	const php = fs.readFileSync('RAN/Admin/Component/NoticeIcon.php', 'utf8');
	for (const [intent, glyph] of [
		['info', 'info'],
		['warning', 'caution'],
		['success', 'published'],
		['error', 'error'],
	]) {
		const source = fs.readFileSync(`${icons}/${glyph}.mjs`, 'utf8');
		const expected = Array.from(
			source.matchAll(
				/jsx\(Path, \{\s*d: "([^"]+)"(,\s*strokeLinejoin: "round")?/g
			),
			(found) => `array( '${found[1]}', ${found[2] ? 'true' : 'false'} )`
		);
		assert.ok(expected.length > 0, glyph);
		assert.match(source, /viewBox: "0 0 24 24"/);
		assert.match(source, /stroke: "currentColor",\s*strokeWidth: "1.5"/);
		const entry = php.match(
			new RegExp(`'${intent}'\\s*=> array\\(([\\s\\S]*?)\\n\\t\\t\\),`)
		);
		assert.ok(entry, intent);
		assert.deepEqual(
			Array.from(
				entry[1].matchAll(/array\( '[^']+', (?:true|false) \)/g),
				(found) => found[0]
			),
			expected,
			intent
		);
	}
});

test('static notices hand their frame to the layer and are never a fallback', () => {
	const primitives = fs.readFileSync(
		'assets/ran-booster/25-admin-primitives.css',
		'utf8'
	);
	// Inside .ran-booster-admin, and for a global notice wherever it renders.
	const frame = primitives.match(
		/\.ran-booster-admin \.notice\.ran-wp-ui-notice,\n\.notice\.ran-wp-ui-notice\[data-ran-booster-ui-notice-scope="global"\] \{([^}]*)\}/
	);
	assert.ok(frame, 'core .notice reset');
	assert.match(frame[1], /\n\tbox-sizing: border-box;/);
	for (const property of [
		'padding',
		'border',
		'border-radius',
		'box-shadow',
		'background',
	]) {
		assert.match(frame[1], new RegExp(`\\n\\t${property}: revert-layer;`));
	}
	assert.match(
		primitives,
		/\.ran-booster-admin \.ran-wp-ui-notice\[hidden\],\n\.ran-wp-ui-notice\[data-ran-booster-ui-notice-scope="global"\]\[hidden\] \{\n\tdisplay: none;\n\}/
	);
	// Core's dismiss button sits in the Notice's close-icon grid slot.
	const dismiss = primitives.match(
		/\.ran-booster-admin \.notice\.ran-wp-ui-notice > \.notice-dismiss,\n\.notice\.ran-wp-ui-notice\[data-ran-booster-ui-notice-scope="global"\]\n\t> \.notice-dismiss \{([^}]*)\}/
	);
	assert.ok(dismiss, 'dismiss button placement');
	for (const declaration of [
		'position: static;',
		'grid-column: 3;',
		'grid-row: 1;',
		'margin-inline-start: var(--wpds-dimension-gap-xs);',
	]) {
		assert.ok(dismiss[1].includes(`\n\t${declaration}`), declaration);
	}
	for (const sheet of [
		'assets/ran-booster/25-admin-primitives.css',
		'assets/ran-booster-notice-compat.css',
	]) {
		const css = fs.readFileSync(sheet, 'utf8');
		assert.doesNotMatch(
			css,
			/:not\(\[data-ran-booster-ui-mounted\]\)/,
			`${sheet}: a fallback rule would style a static notice`
		);
		assert.match(
			css,
			/:not\(\[data-ran-booster-ui-mounted\], \.ran-wp-ui-notice\)/
		);
	}
});
