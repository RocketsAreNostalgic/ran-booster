import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const assetsDirectory = new URL('../../assets/', import.meta.url);
const read = (path) => fs.readFileSync(new URL(path, assetsDirectory), 'utf8');
const foundations = read('ran-booster/00-foundations.css');

// Every body-mounted Booster portal is a bridge root, found from the scripts.
const portals = fs
	.readdirSync(assetsDirectory)
	.filter((file) => file.endsWith('.js'))
	.flatMap((file) =>
		[
			...read(file).matchAll(
				/className\s*=\s*'(ran-booster-[a-z-]*-portal)'/g
			),
		].map((match) => `.${match[1]}`)
	);
const BRAND_ROOTS = ['.ran-booster-admin', ...new Set(portals)];

const bridge = (() => {
	const start = foundations.indexOf(`${BRAND_ROOTS[0]},\n`);
	assert.notEqual(start, -1, 'The brand bridge rule must exist.');
	return foundations.slice(start, foundations.indexOf('\n}', start) + 2);
})();

const mapped = Object.fromEntries(
	[...bridge.matchAll(/(--wpds-color-[a-z-]+):\s*var\(([\s\S]*?)\);/g)].map(
		(match) => [match[1], match[2].replace(/\s+/g, ' ').trim()]
	)
);

test('every body-mounted portal is found and is a bridge root', () => {
	assert.ok(portals.includes('.ran-booster-ui-dialog-portal'));
	assert.ok(portals.includes('.ran-booster-repository-picker-portal'));
});

test('the bridge is scoped to Booster roots only', () => {
	const selector = bridge.slice(0, bridge.indexOf('{'));
	assert.deepEqual(
		selector
			.split(',')
			.map((part) => part.trim())
			.sort(),
		[...BRAND_ROOTS].sort()
	);
});

test('the bridge maps every brand token the Button and focus ring read', () => {
	const suffixes = [
		'background-interactive-brand-strong',
		'background-interactive-brand-strong-active',
		'background-interactive-brand-weak-active',
		'foreground-interactive-brand',
		'foreground-interactive-brand-active',
		'foreground-interactive-brand-strong',
		'foreground-interactive-brand-strong-active',
		'stroke-focus',
		'stroke-interactive-brand',
		'stroke-interactive-brand-active',
	];
	assert.deepEqual(
		Object.keys(mapped)
			.map((name) => name.replace(/^--wpds-color-/, ''))
			.sort(),
		suffixes
	);
});

test('every mapping is an overridable Booster brand token defaulting to the admin colour', () => {
	for (const [name, value] of Object.entries(mapped)) {
		assert.match(value, /^--ran-booster-brand-action(-[a-z]+)?, /, name);
		assert.ok(
			/var\(--wp-admin-theme-color(-darker-(10|20))?\)$/.test(value) ||
				/, var\(--ran-booster-color-neutral-0\)$/.test(value) ||
				/^--ran-booster-brand-action-tint, color-mix\(/.test(value),
			`${name} must default to the WordPress admin colour or white.`
		);
	}
});

test('disabled and non-brand tokens are not bridged', () => {
	assert.doesNotMatch(
		Object.keys(mapped).join(' '),
		/-disabled|neutral|error/
	);
});

test('no other Booster stylesheet sets a brand token outside the bridge', () => {
	const sheets = [
		...fs
			.readdirSync(new URL('ran-booster/', assetsDirectory))
			.map((file) => `ran-booster/${file}`),
		...fs
			.readdirSync(assetsDirectory)
			.filter((file) => file.endsWith('.css')),
	];
	for (const sheet of sheets) {
		const css = read(sheet).replace(bridge, '');
		assert.doesNotMatch(
			css,
			/--wpds-color-[a-z-]*(interactive-brand|stroke-focus)[a-z-]*:/,
			`${sheet} must not set a brand token; use the --ran-booster-brand-action-* seam.`
		);
	}
});

test('the bridge never reaches :root, html or body', () => {
	assert.doesNotMatch(
		foundations.replace(bridge, ''),
		/(^|\n)(:root|html|body)[^{]*\{[^}]*interactive-brand/
	);
	assert.doesNotMatch(bridge, /:root|html|body/);
});

test('the secondary Button is the brand outline', () => {
	const buttons = fs.readFileSync(
		new URL('../../RAN/Admin/Component/ButtonClasses.php', import.meta.url),
		'utf8'
	);
	assert.match(
		buttons,
		/function secondary[^{]*\{\s*return self::for\( 'brand', 'outline'/
	);
});
