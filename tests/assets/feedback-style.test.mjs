import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const read = (path) =>
	fs.readFileSync(new URL(`../../assets/${path}`, import.meta.url), 'utf8');
const css = read('ran-booster/15-enhanced-mutations.css');
const foundations = read('ran-booster/00-foundations.css');
const tokens = read('lib/ran-booster-wpds-tokens.css');
const hostSource = read('ran-booster-feedback.js');
const INTENTS = ['success', 'error', 'warning'];

// Every rule of the host, with its at-rule wrapper kept so media blocks count.
const hostCss = css
	.slice(css.lastIndexOf('/*', css.indexOf('The one snackbar host')))
	.replace(/\/\*[\s\S]*?\*\//g, '')
	.split(/\n(?=\S)/)
	.join('\n');
const rules = [...hostCss.matchAll(/([^{}]+)\{([^{}]*)\}/g)].map((match) => ({
	selector: match[1].replace(/\s+/g, ' ').trim(),
	body: match[2],
}));
const rule = (pattern) =>
	rules.filter(({ selector }) => pattern.test(selector));
const listRules = rule(
	/^\.ran-booster-snackbar-host \.components-snackbar-list$/
);
// The base list rule, and the one inside the 782px media query.
const [list, mobileList] = listRules;

function token(name) {
	const match = tokens.match(new RegExp(`wpds-${name}:\\s*(#[0-9a-f]+)`));
	assert.ok(match, `wpds-${name} must exist in the bundled tokens`);
	return match[1];
}

function luminance(hex) {
	const channels = [1, 3, 5].map((index) => {
		const value = parseInt(hex.slice(index, index + 2), 16) / 255;
		return value <= 0.03928
			? value / 12.92
			: ((value + 0.055) / 1.055) ** 2.4;
	});
	return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
}

function contrast(a, b) {
	const [light, dark] = [luminance(a), luminance(b)].sort((x, y) => y - x);
	return (light + 0.05) / (dark + 0.05);
}

const page = foundations.match(
	/--ran-booster-color-neutral-100:\s*(#[0-9a-f]+)/
)[1];

// The tokens each intent maps to, read back from the CSS so a changed
// mapping is measured, not assumed.
function intentTokens(intent) {
	const block = rule(new RegExp(`\\.ran-booster-snackbar--${intent}$`))[0]
		.body;
	const used = (name) =>
		block.match(
			new RegExp(
				`--ran-booster-snackbar-${name}:\\s*var\\(\\s*--wpds-([a-z-]+)`
			)
		)[1];
	return {
		surface: used('surface'),
		stroke: used('stroke'),
		text: used('text'),
		icon: used('icon'),
	};
}

test('the host uses logical properties only, so the layout mirrors in RTL', () => {
	const physical =
		/(^|[\s;{])(left|right|margin-left|margin-right|padding-left|padding-right|border-left|border-right|border-top-left-radius|border-top-right-radius)\s*:|text-align:\s*(left|right)|float:/;
	for (const { selector, body } of rules) {
		assert.doesNotMatch(body, physical, selector);
	}
	assert.doesNotMatch(css, /--ran-booster-snackbar-center/);
	assert.doesNotMatch(
		hostSource,
		/snackbar-center|getBoundingClientRect\(\)\.left/
	);
});

test('the list sits at the bottom of the viewport and is centred in the content column', () => {
	assert.match(list.body, /position:\s*fixed/);
	assert.match(list.body, /inset-block-end:/);
	assert.match(list.body, /env\(safe-area-inset-bottom\)/);
	// The host measures the column as a size container; the list starts at
	// the static start of the column and a start margin centres it, so no
	// script measures and the writing direction mirrors the offset.
	const [host] = rule(/^\.ran-booster-snackbar-host$/);
	assert.match(host.body, /container-type:\s*inline-size/);
	assert.match(host.body, /inline-size:\s*100%/);
	assert.match(
		list.body,
		/--ran-booster-snackbar-size:\s*min\(\s*600px,\s*100cqi - 2 \* var\(--ran-booster-snackbar-gutter\)/
	);
	assert.match(
		list.body,
		/inline-size:\s*var\(--ran-booster-snackbar-size\)/
	);
	assert.match(
		list.body,
		/margin-inline-start:\s*calc\(\s*\(100cqi - var\(--ran-booster-snackbar-size\)\) \/ 2\s*\)/
	);
	assert.doesNotMatch(list.body, /inset-inline(-start)?\s*:/);
	assert.doesNotMatch(
		list.body,
		/overflow/,
		'Overflow would clip the focus ring.'
	);
	assert.doesNotMatch(list.body, /(^|[\s;])(top|bottom)\s*:/);
	// Each toast is centred in the list, so a short one is not start-aligned.
	const [container] = rule(/__notice-container$/);
	assert.match(container.body, /justify-content:\s*center/);
	assert.doesNotMatch(container.body, /flex-start/);
});

test('at 782px and below the list is centred in the viewport with a 16px gutter each side', () => {
	assert.match(hostCss, /@media \(max-width: 782px\)/);
	assert.match(
		mobileList.body,
		/--ran-booster-snackbar-size:\s*min\(\s*600px,\s*100vw - 2 \* var\(--ran-booster-snackbar-gutter\)/
	);
	assert.match(mobileList.body, /inset-inline:\s*0/);
	assert.match(mobileList.body, /margin-inline:\s*auto/);
	assert.doesNotMatch(
		mobileList.body,
		/(^|[\s;])(left|right)\s*:/,
		'Logical offsets only.'
	);
	assert.match(
		list.body,
		/--ran-booster-snackbar-gutter:\s*max\(\s*var\(--ran-booster-space-16\),\s*env\(safe-area-inset-left\),\s*env\(safe-area-inset-right\)/
	);
});

test('each intent takes its colours from the Notice and Badge tokens, never a literal', () => {
	assert.deepEqual(intentTokens('success'), {
		surface: 'color-background-surface-success-weak',
		stroke: 'color-stroke-surface-success-strong',
		text: 'color-foreground-content-success',
		icon: 'color-foreground-content-success-weak',
	});
	assert.deepEqual(intentTokens('error'), {
		surface: 'color-background-surface-error-weak',
		stroke: 'color-stroke-surface-error-strong',
		text: 'color-foreground-content-error',
		icon: 'color-foreground-content-error-weak',
	});
	assert.deepEqual(intentTokens('warning'), {
		surface: 'color-background-surface-warning-weak',
		stroke: 'color-stroke-surface-warning-strong',
		text: 'color-foreground-content-warning',
		icon: 'color-foreground-content-warning-weak',
	});
	for (const { selector, body } of rules) {
		assert.doesNotMatch(body, /#[0-9a-f]{3,8}\b|rgba?\(/i, selector);
	}
	const [base] = rule(/^\.ran-booster-snackbar-host \.components-snackbar$/);
	assert.match(
		base.body,
		/background:\s*var\(--ran-booster-snackbar-surface\)/
	);
	assert.match(base.body, /color:\s*var\(--ran-booster-snackbar-text\)/);
	assert.doesNotMatch(
		base.body,
		/rgba\(0,\s*0,\s*0/,
		'The black default is gone.'
	);
	for (const name of new Set(hostCss.match(/--wpds-[a-z-]+/g))) {
		assert.match(tokens, new RegExp(`${name}:`), `${name} is bundled`);
	}
});

test('the CSS intent classes are the ones the host applies', () => {
	for (const intent of INTENTS) {
		assert.match(hostSource, /ran-booster-snackbar--\$\{item\.intent\}/);
		assert.equal(rule(new RegExp(`--${intent}$`)).length, 1, intent);
	}
	assert.match(
		hostSource,
		/const INTENTS = \['success', 'error', 'warning'\]/
	);
});

test('text, icon, border and focus meet WCAG AA on every intent', () => {
	const measured = {};
	for (const intent of INTENTS) {
		const { surface, stroke, text, icon } = intentTokens(intent);
		const background = token(surface);
		measured[intent] = {
			text: contrast(token(text), background),
			icon: contrast(token(icon), background),
			boundaryOnPage: contrast(token(stroke), page),
			boundaryOnSurface: contrast(token(stroke), background),
			focus: contrast(token('color-stroke-focus'), background),
		};
		assert.ok(measured[intent].text >= 4.5, `${intent} text`);
		// The action and dismiss controls take the text colour.
		assert.ok(measured[intent].icon >= 3, `${intent} icon`);
		assert.ok(measured[intent].boundaryOnPage >= 3, `${intent} border`);
		assert.ok(measured[intent].boundaryOnSurface >= 3, `${intent} border`);
		assert.ok(measured[intent].focus >= 3, `${intent} focus ring`);
	}
	assert.ok(measured.success.text > 15 && measured.warning.text > 15);
});

test('reduced motion and forced colours: nothing animates here and the border and glyph survive', () => {
	for (const { selector, body } of rules) {
		assert.doesNotMatch(body, /transition|animation/, selector);
	}
	// Forced colours keep real borders and text, not shadows or backgrounds.
	const [base] = rule(/^\.ran-booster-snackbar-host \.components-snackbar$/);
	assert.match(
		base.body,
		/border:\s*1px solid var\(--ran-booster-snackbar-stroke\)/
	);
});

test('focus rings are drawn outside the item and the glyph never carries meaning alone', () => {
	const [focus] = rule(/components-snackbar:focus-visible/);
	assert.match(
		focus.body,
		/outline:\s*var\(--wpds-border-width-focus\)\s*solid\s*var\(--wpds-color-stroke-focus\)/
	);
	assert.match(focus.body, /outline-offset:\s*2px/);
	const glyphs = rules.filter(({ body }) => /(^|[\s;])content:/.test(body));
	assert.ok(glyphs.length >= 2);
	for (const { body } of glyphs) {
		assert.match(
			body,
			/content:\s*"[^"]*"\s*\/\s*""/,
			'Empty alt text hides the decoration.'
		);
	}
});

test('action and dismiss controls keep a 24px target and the text colour', () => {
	const [controls] = rule(/components-snackbar__action\.components-button/);
	assert.match(
		controls.body,
		/min-inline-size:\s*var\(--wpds-dimension-size-sm\)/
	);
	assert.match(
		controls.body,
		/min-block-size:\s*var\(--wpds-dimension-size-sm\)/
	);
	assert.match(controls.body, /color:\s*var\(--ran-booster-snackbar-text\)/);
	assert.match(tokens, /--wpds-dimension-size-sm:\s*24px/);
});

test('each intent has its own glyph with a fallback for browsers without alt text', () => {
	const glyph = (intent) => {
		const match = hostCss.match(
			new RegExp(
				`ran-booster-snackbar--${intent}\\s*\\.components-snackbar__content::before\\s*\\{([^}]*)\\}`
			)
		);
		return match?.[1];
	};
	const baseRule = rules.find(
		({ selector, body }) =>
			selector.endsWith('.components-snackbar__content::before') &&
			/content:/.test(body) &&
			!selector.includes('--')
	).body;
	const shapes = {
		success: glyph('success').match(/content:\s*"([^"]+)"\s*;/)[1],
		error: glyph('error').match(/content:\s*"([^"]+)"\s*;/)[1],
		// Warning keeps the base glyph.
		warning: baseRule.match(/content:\s*"([^"]+)"\s*;/)[1],
	};
	assert.equal(new Set(Object.values(shapes)).size, 3, 'Shapes differ');
	for (const body of [glyph('success'), glyph('error'), baseRule]) {
		assert.match(
			body,
			/content:\s*"[^"]+";\s*content:\s*"[^"]+"\s*\/\s*"";/
		);
	}
	assert.equal(glyph('warning'), undefined, 'Warning uses the base glyph.');
});

test('forced colours keep a real CanvasText border and no background reliance', () => {
	const block = css.match(
		/@media \(forced-colors: active\)\s*\{\s*\.ran-booster-snackbar-host \.components-snackbar\s*\{([^}]*)\}/
	);
	assert.ok(block, 'A forced-colors block exists.');
	assert.match(block[1], /border-color:\s*CanvasText/);
	assert.doesNotMatch(block[1], /background/);
});

test('the list ignores the pointer, sits above content and below every dialog', () => {
	assert.match(list.body, /pointer-events:\s*none/);
	const z = Number(list.body.match(/z-index:\s*(\d+)/)[1]);
	const modal = Number(
		foundations.match(/--ran-booster-layer-modal:\s*(\d+)/)[1]
	);
	assert.ok(z > 0 && z < modal);
});
