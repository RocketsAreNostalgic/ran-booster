import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const read = (path) =>
	fs.readFileSync(new URL(`../../assets/${path}`, import.meta.url), 'utf8');
const buttons = read('ran-booster/10-buttons.css');
const foundations = read('ran-booster/00-foundations.css');

const rule = (() => {
	const start = buttons.indexOf(
		'.ran-wp-ui-button.is-brand.is-solid:is([disabled], [aria-disabled="true"])'
	);
	assert.notEqual(start, -1, 'The shared disabled primary rule must exist.');
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

test('disabled and unavailable brand solid buttons share one treatment', () => {
	assert.match(rule, /\):not\(\s*\.is-loading\s*\) \{/);
	assert.match(
		rule,
		/--wp-ui-button-background-color-disabled: var\(--ran-booster-surface\);/
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

test('the disabled treatment uses tokens only and never dims or recolours on hover', () => {
	assert.doesNotMatch(rule, /#[0-9a-f]{3,8}\b|rgba?\(|hsla?\(/i);
	assert.doesNotMatch(rule, /opacity: (0|0?\.\d+);/);
	assert.doesNotMatch(rule, /:(hover|active|focus)/);
});

test('the disabled treatment meets 4.5:1 for text and 3:1 for the stroke', () => {
	const fill = token('--ran-booster-surface');
	const text = token('--ran-booster-text-muted');
	const stroke = token('--ran-booster-control-border');
	const grey = token('--ran-booster-surface-subtle');
	assert.ok(contrast(text, fill) >= 4.5, 'text on the white fill');
	assert.ok(contrast(stroke, fill) >= 3, 'stroke on the white fill');
	assert.ok(contrast(stroke, grey) >= 3, 'stroke on the grey page surface');
});

test('the static wp-ui stylesheet is not edited for the treatment', () => {
	assert.doesNotMatch(
		read('lib/ran-booster-wp-ui-static.css'),
		/--ran-booster-control-border/
	);
});
