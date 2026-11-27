import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const read = (path) =>
	fs.readFileSync(new URL(`../../assets/${path}`, import.meta.url), 'utf8');
const buttons = read('ran-booster/10-buttons.css');
const foundations = read('ran-booster/00-foundations.css');

const selector =
	'.ran-wp-ui-button:is(:disabled, [aria-disabled="true"]):not(.is-loading) {';
const rule = (() => {
	const start = buttons.indexOf(selector);
	assert.notEqual(start, -1, 'The shared disabled button rule must exist.');
	return buttons.slice(start, buttons.indexOf('}', start) + 1);
})();

const token = (name) => {
	let value = `var(${name})`;
	for (let depth = 0; depth < 5; depth += 1) {
		const match = value.match(/^var\((--[a-z0-9-]+)\)$/);
		if (!match) {
			break;
		}
		const declaration = foundations.match(
			new RegExp(`${match[1]}:\\s*([^;]+);`)
		);
		assert.ok(declaration, `${match[1]} must be defined.`);
		value = declaration[1].trim();
	}
	return value;
};

const luminance = (color) => {
	const hex =
		color.length === 4
			? `#${[...color.slice(1)].map((digit) => digit + digit).join('')}`
			: color;
	const channels = hex
		.slice(1)
		.match(/../g)
		.map((part) => parseInt(part, 16) / 255)
		.map((value) =>
			value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4
		);
	return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
};
const contrast = (first, second) => {
	const [light, dark] = [luminance(first), luminance(second)].sort(
		(a, b) => b - a
	);
	return (light + 0.05) / (dark + 0.05);
};

test('every disabled or unavailable button variant shares one treatment', () => {
	assert.match(
		rule,
		/--wp-ui-button-background-color-disabled: var\(--ran-booster-surface-subtle\);/
	);
	assert.match(
		rule,
		/--wp-ui-button-border-color-disabled: var\(--ran-booster-control-border\);/
	);
	assert.match(
		rule,
		/--wp-ui-button-foreground-color-disabled: var\(--ran-booster-text-muted\);/
	);
	assert.match(rule, /cursor: not-allowed;/);
	assert.match(rule, /opacity: 1;/);
});

test('no tone or variant rule overrides the shared disabled colours', () => {
	assert.doesNotMatch(
		buttons.replace(rule, ''),
		/--wp-ui-button-[a-z-]+-disabled:/
	);
});

test('the disabled treatment uses tokens only and never dims or recolours on hover', () => {
	assert.doesNotMatch(rule, /#[0-9a-f]{3,8}\b|rgba?\(|hsla?\(/i);
	assert.doesNotMatch(rule, /opacity: (0|0?\.\d+);/);
	assert.doesNotMatch(rule, /:(hover|active|focus)/);
});

test('the disabled treatment meets 4.5:1 for text and 3:1 for the stroke', () => {
	const fill = token('--ran-booster-surface-subtle');
	const text = token('--ran-booster-text-muted');
	const stroke = token('--ran-booster-control-border');
	const white = token('--ran-booster-surface');
	assert.notEqual(fill, white, 'the fill is distinct from white');
	assert.ok(contrast(text, fill) >= 4.5, 'text on the pale-grey fill');
	assert.ok(contrast(stroke, fill) >= 3, 'stroke on the fill and grey page');
	assert.ok(contrast(stroke, white) >= 3, 'stroke on a white surface');
});

/*
 * A button disabled while busy has no loading look of its own: Booster never
 * adds the library's is-loading class, so the shared rule paints it and the
 * busy stripes sit on top.
 */
test('buttons disabled while busy keep the shared colours under the stripes', () => {
	const root = new URL('../../', import.meta.url);
	for (const folder of ['assets/', 'views/', 'RAN/']) {
		for (const file of fs.readdirSync(new URL(folder, root), {
			recursive: true,
		})) {
			if (!/\.(js|php)$/.test(file) || file.startsWith('lib/')) {
				continue;
			}
			assert.doesNotMatch(
				fs.readFileSync(new URL(`${folder}${file}`, root), 'utf8'),
				/is-loading/,
				`${folder}${file}`
			);
		}
	}

	const busy = [...buttons.matchAll(/([^{}]+)\{([^{}]*)\}/g)].filter(
		([, selectors]) => selectors.includes('.ran-booster-update-is-active')
	);
	assert.ok(busy.length > 0, 'The busy rules must exist.');
	for (const [, , body] of busy) {
		assert.doesNotMatch(
			body,
			/--wp-ui-button|(?<![-\w])(color|background|background-color|border-color):/
		);
	}

	const stripe = token('--ran-booster-strong-divider');
	const striped = (color) =>
		`#${[1, 3, 5]
			.map((at) =>
				Math.round(
					0.9 * parseInt(color.slice(at, at + 2), 16) +
						0.1 * parseInt(stripe.slice(at, at + 2), 16)
				)
					.toString(16)
					.padStart(2, '0')
			)
			.join('')}`;
	assert.ok(
		contrast(
			striped(token('--ran-booster-text-muted')),
			striped(token('--ran-booster-surface-subtle'))
		) >= 4.5,
		'text and fill under the darkest stripe'
	);
});

test('the static wp-ui stylesheet is not edited for the treatment', () => {
	assert.doesNotMatch(
		read('lib/ran-booster-wp-ui-static.css'),
		/--ran-booster-control-border/
	);
});
