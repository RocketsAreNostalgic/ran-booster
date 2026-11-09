import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import { extract } from '../../tools/wp-ui-static/extract.mjs';

const uiRoot = 'node_modules/@wordpress/ui';
const css = fs.readFileSync('assets/lib/ran-booster-wp-ui-static.css', 'utf8');
const manifest = JSON.parse(
	fs.readFileSync('assets/lib/ran-booster-wp-ui-static.json', 'utf8')
);

test('committed static styles match a fresh extraction', async () => {
	const fresh = await extract({ uiRoot, prefix: 'ran-wp-ui' });
	assert.equal(css, fresh.css);
	assert.deepEqual(manifest, fresh.manifest);
});

test('the manifest records the installed @wordpress/ui version', () => {
	const { version } = JSON.parse(
		fs.readFileSync(`${uiRoot}/package.json`, 'utf8')
	);
	assert.equal(manifest.source.version, version);
	assert.match(css, new RegExp(`from @wordpress/ui ${version} `));
});

test('class names follow the Gutenberg component convention', () => {
	assert.doesNotMatch(css, /[0-9a-f]{16}__/, 'a hashed class leaked');
	const classes = new Set(css.match(/\.[a-z_][\w-]*/g) ?? []);
	for (const selector of classes) {
		const name = selector.slice(1);
		const owned = name.startsWith(`${manifest.prefix}-`);
		const state = /^(?:is|has)-[a-z0-9-]+$/.test(name);
		assert.ok(owned || state, `Unexpected class ${selector}`);
		assert.match(
			name,
			/^[a-z][a-z0-9-]*(?:__[a-z0-9-]+)?$/,
			`${selector} is not lowercase-hyphen block__element`
		);
	}
	for (const found of css.matchAll(/(.)\.((?:is|has)-[a-z0-9-]+)/g)) {
		assert.ok(
			/[a-z0-9]/.test(found[1]),
			`State .${found[2]} must compound onto a block class`
		);
	}
});

test('every manifest class exists in the stylesheet', () => {
	for (const entry of Object.values(manifest.components)) {
		assert.ok(css.includes(`.${entry.block}`), entry.block);
		for (const element of entry.elements) {
			const expected =
				entry.block.endsWith(element) &&
				entry.elements.length === 1 &&
				entry.block === `${manifest.prefix}-${element}`
					? `.${entry.block}`
					: `.${entry.block}__${element}`;
			assert.ok(css.includes(expected), expected);
		}
		for (const state of entry.states) {
			assert.ok(css.includes(`.${entry.block}.${state}`), state);
		}
		for (const modifier of entry.modifiers) {
			assert.ok(css.includes(`.${entry.block}--${modifier}`), modifier);
		}
	}
});

test('the upstream prefix produces the same stylesheet apart from the namespace', async () => {
	const upstream = await extract({ uiRoot, prefix: 'wp-ui' });
	assert.equal(upstream.css.replaceAll('.wp-ui-', '.ran-wp-ui-'), css);
});

test('link and focus ring are extracted with their stable names', () => {
	const link = manifest.components.link;
	assert.deepEqual(link.states, ['is-brand', 'is-neutral', 'is-unstyled']);
	assert.deepEqual(link.elements, ['contents', 'icon', 'with-icon']);
	assert.ok(css.includes('.ran-wp-ui-link.is-unstyled{'));
	assert.ok(
		css.includes('.ran-wp-ui-reset-a:is(:hover,:focus,:active){'),
		'the reset that reads the --_gcd-a-* variables is present'
	);
	const ring = manifest.components['outset-ring'];
	assert.ok(ring.modifiers.includes('focus-except-active'));
	assert.ok(
		css.includes('.ran-wp-ui-outset-ring--focus-except-active:focus')
	);
});

test('every recorded custom property is declared in the stylesheet', () => {
	for (const entry of Object.values(manifest.components)) {
		for (const property of entry.properties) {
			assert.match(property, /^--[a-z][a-z0-9-]*$/);
			assert.ok(css.includes(`${property}:`), property);
		}
	}
});

test('a Card page frame composes the same classes as the mounted Card.Root', () => {
	const card = manifest.components.card;
	assert.deepEqual(card.elements, ['content', 'fullbleed', 'header']);
	assert.deepEqual(card.properties, [
		'--wp-ui-card-header-content-gap',
		'--wp-ui-card-header-content-margin',
		'--wp-ui-card-padding',
	]);
	// Card.Root renders the root part together with the box-sizing reset, so
	// a static frame needs both classes on one element.
	const root = fs.readFileSync(
		`${uiRoot}/build-module/card/root.mjs`,
		'utf8'
	);
	assert.match(
		root,
		/clsx\(style_module_default\.root, resets_module_default\["box-sizing"\]\)/
	);
	assert.ok(css.includes('.ran-wp-ui-card{'));
	assert.ok(css.includes('.ran-wp-ui-box-sizing{box-sizing:border-box;'));
	// The frame sets display:flex inside the wp-ui layer, which outranks the
	// user-agent [hidden] rule, so a hidden frame must hide on its host.
	assert.match(css, /\.ran-wp-ui-card\{[^}]*display:flex/);
});

test('a static Notice composes the same classes as the mounted Notice.Root', () => {
	const notice = manifest.components.notice;
	assert.deepEqual(notice.elements, [
		'action-button',
		'action-link',
		'actions',
		'close-icon',
		'description',
		'icon',
		'title',
	]);
	assert.deepEqual(notice.states, [
		'is-error',
		'is-info',
		'is-success',
		'is-warning',
	]);
	// Notice.Root renders the root part, its intent and the box-sizing reset
	// on one element, and draws the intent icon after its children.
	const root = fs.readFileSync(
		`${uiRoot}/build-module/notice/root.mjs`,
		'utf8'
	);
	assert.match(
		root,
		/clsx\(style_module_default\.notice, style_module_default\[`is-\$\{intent\}`\], resets_module_default\["box-sizing"\]\)/
	);
	assert.match(
		root,
		/neutral: null,\s*info,\s*warning: caution,\s*success: published,\s*error\s*\}/
	);
	// Description and Title are Text variants, which the static sheet carries.
	for (const [part, variant] of [
		['description', 'body-md'],
		['title', 'heading-md'],
	]) {
		const source = fs.readFileSync(
			`${uiRoot}/build-module/notice/${part}.mjs`,
			'utf8'
		);
		assert.match(source, new RegExp(`variant: "${variant}"`));
		assert.ok(css.includes(`.ran-wp-ui-text.is-${variant}{`), variant);
	}
});

test('the Notice keeps its modern CSS and leaves announcing to the host', () => {
	const start = css.indexOf('.ran-wp-ui-notice{');
	assert.ok(start > 0);
	const notice = css.slice(
		css.lastIndexOf('\n', start),
		css.indexOf('\n', start)
	);
	assert.match(notice, /\.ran-wp-ui-notice\{[^}]*display:grid/);
	assert.match(notice, /container-type:inline-size/);
	assert.match(
		notice,
		/@container \(max-width: 320px\)\{\.ran-wp-ui-notice:has\(\.ran-wp-ui-notice__title\)/
	);
	assert.match(
		notice,
		/\.ran-wp-ui-notice__action-link\{flex-shrink:0;&:not\(:first-child\)\{/
	);
	assert.match(
		notice,
		/mix-blend-mode:luminosity;@media \(forced-colors:active\)\{mix-blend-mode:normal\}/
	);
	for (const intent of ['info', 'warning', 'success', 'error']) {
		assert.ok(notice.includes(`.ran-wp-ui-notice.is-${intent}{`), intent);
	}
	// Notice.List's live region is a runtime behaviour, not CSS: a static
	// notice carries no politeness data or list semantics.
	assert.doesNotMatch(css, /politeness|spoken-message|notice-list/);
});

const buttonLine = () => {
	const start = css.indexOf('.ran-wp-ui-button{');
	assert.ok(start > 0);
	return css.slice(
		css.lastIndexOf('\n', start) + 1,
		css.indexOf('\n', start)
	);
};

test('a static Button composes the same classes as the mounted Button', () => {
	const button = manifest.components.button;
	assert.deepEqual(button.elements, ['icon', 'spinner']);
	assert.deepEqual(button.states, [
		'is-brand',
		'is-compact',
		'is-loading',
		'is-minimal',
		'is-neutral',
		'is-outline',
		'is-small',
		'is-solid',
		'is-unstyled',
	]);
	assert.ok(button.properties.includes('--wp-ui-button-height'));
	assert.ok(button.properties.includes('--wp-ui-button-background-color'));
	// Button renders the global-css-defense button reset, the box-sizing
	// reset, the focus ring, its root part, then tone, variant and size.
	const source = fs.readFileSync(
		`${uiRoot}/build-module/button/button.mjs`,
		'utf8'
	);
	assert.match(
		source,
		/clsx\(global_css_defense_module_default\.button, resets_module_default\["box-sizing"\], focus_module_default\["outset-ring--focus-except-active"\], variant !== "unstyled" && style_module_default\.button, style_module_default\[`is-\$\{tone\}`\], style_module_default\[`is-\$\{variant\}`\], style_module_default\[`is-\$\{size\}`\]/
	);
	for (const selector of [
		'.ran-wp-ui-box-sizing{',
		'.ran-wp-ui-outset-ring--focus-except-active:focus',
		'.ran-wp-ui-button.is-compact{',
		'.ran-wp-ui-button.is-brand{',
		'.ran-wp-ui-button.is-neutral{',
	]) {
		assert.ok(css.includes(selector), selector);
	}
	// The defense reset is unlayered, as in the library: wp-admin's
	// unlayered `button { font-weight: inherit }` would otherwise win.
	assert.match(
		css,
		/\n\.ran-wp-ui-reset-button\{box-sizing:var\(--_gcd-button-box-sizing,border-box\);[^}]*font-weight:var\(--_gcd-button-font-weight,inherit\)\}\n/
	);
	assert.match(
		buttonLine(),
		/--_gcd-button-font-weight:var\(--wp-ui-button-font-weight\)/
	);
});

test('the Button bridges data-disabled to native and ARIA disabled', () => {
	const bridge = '[data-disabled],:disabled,[aria-disabled=true]';
	assert.deepEqual(manifest.components.button.bridge, {
		'[data-disabled]': [':disabled', '[aria-disabled=true]'],
	});
	const line = buttonLine();
	const source = fs.readFileSync(
		`${uiRoot}/build-module/button/button.mjs`,
		'utf8'
	);
	const upstream = source.match(/\[data-disabled\]/g).length;
	assert.equal(line.split(`:is(${bridge})`).length - 1, upstream);
	// No data-disabled selector is left unbridged anywhere.
	assert.equal(
		css.replaceAll(`:is(${bridge})`, '').includes('data-disabled'),
		false
	);
	assert.ok(
		line.includes(
			`&:not(:is(${bridge})):is(:hover,:active,:focus){background-color:var(--wp-ui-button-background-color-active)`
		),
		'hover and focus skip every disabled form'
	);
	assert.ok(line.includes(`&:not(:is(${bridge})){cursor:`));
	assert.ok(
		line.includes(
			`&:is(${bridge}):not(.ran-wp-ui-button.is-loading){background-color:var(--wp-ui-button-background-color-disabled)`
		)
	);
	assert.match(
		line,
		/@media \(forced-colors:active\)\{border-bottom-color:GrayText/
	);
	// Pressed toggles key on a real ARIA attribute, so no bridge is needed.
	assert.ok(line.includes('.ran-wp-ui-button.is-minimal[aria-pressed=true]'));
	// The bridge is scoped to the Button.
	assert.equal(css.split(':disabled').length - 1, upstream);
});

test('the Button loading spinner is carried but not animated', () => {
	// The loading state and spinner placement come with the shared style
	// string; the spinner's own SVG and keyframes live in spinner.mjs, which
	// is not extracted, so static markup must not use is-loading.
	const line = buttonLine();
	assert.ok(line.includes('.ran-wp-ui-button.is-loading:not('));
	assert.ok(line.includes('.ran-wp-ui-button__spinner{'));
	assert.doesNotMatch(css, /@keyframes/);
	assert.ok(
		!Object.keys(manifest.components).includes('spinner'),
		'the Spinner component is not extracted'
	);
});
