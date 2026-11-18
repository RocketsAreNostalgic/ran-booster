import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

/*
 * Header rhythm: a section header (Card title or prominent disclosure trigger)
 * and a nested panel header each take their inset and block padding from one
 * shared token pair, and a prominent disclosure's body takes the same inline
 * inset. See "Header rhythm" in docs/admin-composition-contract.md.
 *
 * These are CSS-structure tests. The repository has no CSS parser or DOM in its
 * dependencies, so cascade order is checked with a small specificity counter
 * over the selectors, and the measured header and body edges, mounted and
 * native fallback, desktop and 375px, are recorded in the pull request.
 */

const root = new URL('../../assets/', import.meta.url);
const read = (name) => fs.readFileSync(new URL(name, root), 'utf8');
const foundations = read('ran-booster/00-foundations.css');
const primitives = read('ran-booster/25-admin-primitives.css');
const packageSettings = read('ran-booster/65-package-settings.css');
const troubleshooting = read('ran-booster/50-troubleshooting-and-activity.css');
const wpdsTokens = read('lib/ran-booster-wpds-tokens.css');
const createView = fs.readFileSync(
	new URL('../../views/packages/create.php', import.meta.url),
	'utf8'
);
const editView = fs.readFileSync(
	new URL('../../views/packages/edit.php', import.meta.url),
	'utf8'
);

const parse = (css) =>
	[...css.replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/([^{}@]+)\{([^{}]*)\}/g)]
		.map((m) => ({
			selector: m[1].replace(/\s+/g, ' ').trim(),
			body: m[2].replace(/\s+/g, ' ').trim(),
		}))
		.filter((r) => r.selector);

const space = (name) => {
	const m = foundations.match(
		new RegExp(`${name}:\\s*var\\(--ran-booster-space-(\\d+)\\)`)
	);
	assert.ok(m, `${name} must be defined from a space token`);
	return Number(m[1]);
};

/* [ids, classes/attributes/pseudo-classes, elements]; :not() counts its argument. */
const specificity = (selector) => {
	const s = selector.replace(/\s+/g, ' ');
	const flat = s.replace(/:not\(/g, '(').replace(/:is\(|:where\(/g, '(');
	const ids = (flat.match(/#[\w-]+/g) || []).length;
	const classes = (flat.match(/\.[\w-]+|\[[^\]]*\]|:[a-z-]+(?!\()/g) || [])
		.length;
	const elements = (
		flat
			.replace(/\[[^\]]*\]|\.[\w-]+|#[\w-]+/g, ' ')
			.match(/(?:^|[\s>+~(])[a-z][\w-]*/g) || []
	).length;
	return [ids, classes, elements];
};
const outranks = (a, b) => {
	for (let i = 0; i < 3; i += 1) {
		if (a[i] !== b[i]) {
			return a[i] > b[i];
		}
	}
	return false;
};
const branches = (selector) =>
	selector.split(/,(?![^(]*\))/).map((x) => x.trim());

const rules = parse(primitives);
const withBody = (needle) => rules.filter((r) => r.body.includes(needle));
const sectionTrigger = withBody(
	'padding: var(--ran-booster-header-trigger-inset-block) var(--ran-booster-header-inset-inline)'
);
const nestedTrigger = withBody(
	'padding: var(--ran-booster-panel-header-inset-block) var(--ran-booster-panel-header-inset-inline)'
);
const prominent = '[data-ran-booster-ui-disclosure-density="prominent"]';
const depth = (r) => r.selector.split(',')[0].split(prominent).length - 1;
const createScope = '.ran-booster-package-settings--create';

const narrowMedia = () => {
	const start = primitives.indexOf('@media screen and (max-width: 480px) {');
	assert.notEqual(start, -1, 'the 480px media block is missing');
	const open = primitives.indexOf('{', start);
	let nesting = 1;
	for (let i = open + 1; i < primitives.length; i += 1) {
		if (primitives[i] === '{') {
			nesting += 1;
		}
		if (primitives[i] === '}') {
			nesting -= 1;
		}
		if (nesting === 0) {
			return primitives.slice(open + 1, i);
		}
	}
	assert.fail('the 480px media block is not closed');
};

test('the narrow advanced fallback grid outranks the generic fallback flex', () => {
	const grid = rules.find(
		(r) =>
			r.selector.includes('package-advanced-settings') &&
			r.selector.includes(createScope) &&
			r.body.includes('display: grid')
	);
	const flex = rules.find(
		(r) =>
			r.selector.endsWith('> summary') && r.body.includes('display: flex')
	);
	assert.ok(grid);
	assert.ok(flex);
	const createGrid = branches(grid.selector).find((s) =>
		s.includes(createScope)
	);
	assert.ok(createGrid);
	assert.ok(outranks(specificity(createGrid), specificity(flex.selector)));
	assert.match(grid.body, /align-items: start/);
	assert.match(grid.body, /row-gap: var\(--ran-booster-space-4\)/);
	assert.ok(parse(narrowMedia()).some((r) => r.selector === grid.selector));
});

test('advanced summary typography applies only at the narrow breakpoint', () => {
	const summaries = rules.filter(
		(r) =>
			r.selector.includes('package-advanced-settings') &&
			r.selector.endsWith('.ran-booster-advanced-source-summary') &&
			r.body.includes('font-size:')
	);
	assert.equal(summaries.length, 1, 'one scoped font-size correction exists');
	const [summary] = summaries;
	const fallback = parse(packageSettings).find(
		(r) =>
			r.selector.endsWith(
				'.ran-booster-settings-disclosure > summary small'
			) && r.body.includes('font-size:')
	);
	assert.ok(summary);
	assert.ok(fallback);
	assert.match(summary.body, /font-size: var\(--ran-booster-font-size-sm\)/);
	assert.ok(summary.selector.includes(createScope));
	assert.ok(
		parse(narrowMedia()).some((r) => r.selector === summary.selector),
		'the font-size correction must not apply at 512px'
	);
	assert.ok(
		outranks(specificity(summary.selector), specificity(fallback.selector))
	);
});

test('the narrow fallback title and metadata keep the mounted row geometry', () => {
	const title = rules.find(
		(r) =>
			r.selector.includes('package-advanced-settings') &&
			r.selector.includes(createScope) &&
			r.selector.endsWith('> .ran-booster-section__title')
	);
	const meta = rules.find(
		(r) =>
			r.selector.includes('package-advanced-settings') &&
			r.selector.includes(createScope) &&
			r.selector.endsWith('> [data-ran-booster-ui-disclosure-meta]')
	);
	const small = parse(packageSettings).filter((r) =>
		r.selector.endsWith('.ran-booster-settings-disclosure > summary small')
	);
	assert.ok(title);
	assert.ok(meta);
	assert.equal(
		rules.filter(
			(r) =>
				r.selector.includes('package-advanced-settings') &&
				r.selector.endsWith('> .ran-booster-section__title') &&
				r.body.includes('line-height: inherit')
		).length,
		1,
		'the title correction must not escape the Create scope'
	);
	assert.ok(parse(narrowMedia()).some((r) => r.selector === title.selector));
	assert.ok(parse(narrowMedia()).some((r) => r.selector === meta.selector));
	assert.match(title.body, /line-height: inherit/);
	assert.match(meta.body, /display: flex/);
	assert.match(meta.body, /grid-column: 1;/);
	assert.match(meta.body, /margin-block-start: 0/);
	for (const rule of small) {
		assert.ok(
			outranks(specificity(meta.selector), specificity(rule.selector))
		);
	}
});

test('Edit retains its original fallback grid and meta rules', () => {
	assert.match(
		createView,
		/class="ran-booster-package-settings ran-booster-package-settings--create"/
	);
	assert.match(editView, /class="ran-booster-package-settings"/);
	assert.doesNotMatch(editView, /ran-booster-package-settings--create/);
	const narrow = parse(narrowMedia());
	const originalGrid = narrow.find(
		(r) =>
			r.selector.includes('package-advanced-settings') &&
			branches(r.selector).some(
				(s) => !s.includes(createScope) && s.endsWith('> summary')
			) &&
			r.body.includes('grid-template-columns:')
	);
	const originalMeta = narrow.find(
		(r) =>
			r.selector.includes('package-advanced-settings') &&
			!r.selector.includes(createScope) &&
			r.selector.endsWith('> [data-ran-booster-ui-disclosure-meta]')
	);
	assert.ok(originalGrid);
	assert.match(
		originalGrid.body,
		/grid-template-columns: minmax\(0, 1fr\) auto/
	);
	assert.ok(originalMeta);
	assert.match(originalMeta.body, /grid-column: 1 \/ -1/);
	assert.doesNotMatch(originalMeta.body, /display: flex|margin-block-start/);
});

test('the header rhythm tokens are defined once, from the space scale', () => {
	assert.equal(space('--ran-booster-header-inset-inline'), 24);
	assert.equal(space('--ran-booster-header-trigger-inset-block'), 18);
	assert.equal(space('--ran-booster-panel-header-inset-inline'), 18);
	assert.equal(space('--ran-booster-panel-header-inset-block'), 16);
});

test('the section inset narrows once, at the compact boundary, for every consumer', () => {
	const narrow = foundations.match(
		/@media screen and \(max-width: 480px\)\s*\{\s*:root\s*\{([^}]*)\}/
	);
	assert.ok(narrow, 'the 480px token override is missing');
	assert.match(
		narrow[1],
		/--ran-booster-header-inset-inline:\s*var\(--ran-booster-space-16\)/
	);
});

test('a prominent trigger is as tall as a section header without a fixed height', () => {
	const section = parse(packageSettings).find(
		(r) =>
			r.selector ===
			'.ran-booster-admin .ran-booster-settings-section__header'
	);
	assert.ok(section, 'the section header rule is missing');
	const pad = section.body.match(
		/padding-block: var\(--ran-booster-card-inset-block-start\) var\(--ran-booster-space-(\d+)\)/
	);
	assert.ok(pad, 'the section header block padding must use the Card tokens');
	const lineFactor = Number(
		parse(primitives)
			.find(
				(r) =>
					r.selector ===
					'.ran-booster-admin .ran-booster-section__title'
			)
			.body.match(/line-height: ([\d.]+)/)[1]
	);
	const titleSize = Number(
		wpdsTokens.match(/--wpds-typography-font-size-lg: (\d+)px/)[1]
	);
	const sectionHeader =
		space('--ran-booster-card-inset-block-start') +
		Number(pad[1]) +
		lineFactor * titleSize;
	const triggerLine = Number(
		sectionTrigger[0].body.match(/line-height: (\d+)px/)[1]
	);
	const trigger =
		space('--ran-booster-header-trigger-inset-block') * 2 + triggerLine;
	assert.ok(
		Math.abs(sectionHeader - trigger) <= 1,
		`trigger ${trigger}px must be within 1px of the section header ${sectionHeader}px`
	);
	for (const r of rules.filter((x) => x.selector.includes(prominent))) {
		assert.doesNotMatch(
			r.body,
			/(?:^|[\s;])(?:min-|max-)?(?:block-size|height)\s*:/,
			'the prominent profile must not fix a height'
		);
	}
});

test('Card headers and bodies take their inline inset from the shared token', () => {
	for (const part of ['__header', '__body']) {
		const rule = parse(packageSettings).find(
			(r) =>
				r.selector ===
				`.ran-booster-admin .ran-booster-settings-section${part}`
		);
		assert.ok(rule, `settings-section${part} rule is missing`);
		assert.match(
			rule.body,
			/padding-inline: var\(--ran-booster-header-inset-inline\)/
		);
	}
});

test('section triggers cover the mounted button and the native fallback', () => {
	const sections = sectionTrigger.filter((r) => depth(r) === 1);
	assert.ok(sections.some((r) => r.selector.endsWith('> [role="button"]')));
	assert.ok(sections.some((r) => r.selector.endsWith('> summary')));
	assert.deepEqual(
		sectionTrigger.filter((r) => depth(r) > 2).map((r) => r.selector),
		[]
	);
});

test('a nested prominent disclosure takes the panel pair and outranks the section rules', () => {
	const nested = nestedTrigger.filter((r) => depth(r) === 2);
	assert.equal(nested.length, 1, 'one rule covers the nested trigger');
	assert.match(
		nested[0].body,
		/font-size: var\(--ran-booster-font-size-md\)/
	);
	const [mounted, fallback] = branches(nested[0].selector);
	assert.ok(mounted.endsWith('> [role="button"]'));
	assert.ok(fallback.endsWith('> summary'));
	const sectionMounted = sectionTrigger.find((r) =>
		r.selector.endsWith('> [role="button"]')
	);
	const sectionFallback = sectionTrigger.find((r) =>
		r.selector.endsWith('> summary')
	);
	assert.ok(
		outranks(specificity(mounted), specificity(sectionMounted.selector)),
		'the nested mounted trigger must outrank the section trigger'
	);
	assert.ok(
		outranks(specificity(fallback), specificity(sectionFallback.selector)),
		'the nested fallback summary must outrank the section summary'
	);
	assert.ok(
		rules.indexOf(nested[0]) > rules.indexOf(sectionTrigger[0]),
		'the nested rule must follow the section rules'
	);
});

test('the nested fallback title follows the summary size and outranks the section title', () => {
	const title = rules.find(
		(r) =>
			r.selector.endsWith('> summary .ran-booster-section__title') &&
			depth(r) === 2
	);
	assert.ok(title, 'the nested fallback title rule is missing');
	assert.match(title.body, /^font-size: inherit;?$/);
	assert.ok(
		outranks(
			specificity(title.selector),
			specificity('.ran-booster-admin .ran-booster-section__title')
		)
	);
});

test('a prominent body shares its trigger text edge and a compact body keeps its own', () => {
	const bodies = rules.filter(
		(r) =>
			r.selector.includes(prominent) &&
			r.selector.includes('ran-booster-disclosure__body')
	);
	const section = bodies.find((r) => depth(r) === 1);
	const nested = bodies.find((r) => depth(r) === 2);
	assert.match(
		section.body,
		/^padding-inline: var\(--ran-booster-header-inset-inline\);?$/
	);
	assert.match(
		nested.body,
		/^padding-inline: var\(--ran-booster-panel-header-inset-inline\);?$/
	);
	for (const r of [section, nested]) {
		assert.match(
			r.selector,
			/:not\( ?\[data-ran-booster-ui-disclosure-density="compact"\] \*/,
			'a compact disclosure inside keeps its own body padding'
		);
	}
	const base = rules.find(
		(r) => r.selector === '.ran-booster-admin .ran-booster-disclosure__body'
	);
	assert.ok(
		outranks(specificity(section.selector), specificity(base.selector)),
		'the prominent body must outrank the generic 16px body'
	);
	assert.ok(
		outranks(specificity(nested.selector), specificity(section.selector))
	);
});

test('the nested panel header matches the readiness panel header', () => {
	const top = parse(packageSettings).find(
		(r) =>
			r.selector ===
			'.ran-booster-admin .ran-booster-readiness-panel__top'
	);
	assert.ok(top, 'readiness panel header rule is missing');
	assert.match(
		top.body,
		/padding-block: var\(--ran-booster-panel-header-inset-block\)/
	);
	assert.match(
		top.body,
		/padding-inline: var\(--ran-booster-panel-header-inset-inline\)/
	);
});

const TOKENS =
	/--ran-booster-(?:header-inset-inline|panel-header-inset-inline|settings-card-inset|receiver-inset)\b/;
const HEADER_OR_BODY =
	/(?:header|[-_]body|entry-row|__actions|\[role="button"\]|> summary)$/;

/* The declared inline values of a padding declaration, each as written. */
const inlineValues = (property, value) => {
	const parts = [];
	let nesting = 0;
	let current = '';
	for (const ch of value.trim()) {
		if (ch === '(') {
			nesting += 1;
		}
		if (ch === ')') {
			nesting -= 1;
		}
		if (/\s/.test(ch) && nesting === 0) {
			if (current) {
				parts.push(current);
			}
			current = '';
		} else {
			current += ch;
		}
	}
	if (current) {
		parts.push(current);
	}
	switch (property) {
		case 'padding-inline':
			return parts.slice(0, 2);
		case 'padding-inline-start':
		case 'padding-inline-end':
		case 'padding-left':
		case 'padding-right':
			return parts.slice(0, 1);
		case 'padding':
			if (parts.length === 1) {
				return parts;
			}
			if (parts.length === 2) {
				return [parts[1]];
			}
			if (parts.length === 3) {
				return [parts[1]];
			}
			return [parts[1], parts[3]];
		default:
			return [];
	}
};

/* Every header, body, entry row, trigger or summary declaration whose inline padding is not a token and not zero. */
const bypasses = (css) => {
	const found = [];
	for (const r of parse(css)) {
		if (!branches(r.selector).some((b) => HEADER_OR_BODY.test(b))) {
			continue;
		}
		for (const declaration of r.body.split(';')) {
			const [property, ...rest] = declaration.split(':');
			const name = property.trim();
			const value = rest.join(':').trim();
			const bad = inlineValues(name, value).filter(
				(v) => !TOKENS.test(v) && !/^0(?:px)?$/.test(v)
			);
			if (bad.length) {
				found.push(`${r.selector} => ${name}: ${value}`);
			}
		}
	}
	return found;
};

test('a header or body inline-padding bypass is detected whatever its spelling', () => {
	const bypass = [
		'.a .ran-booster-troubleshooting__disclosure-body { padding-inline: 12px; }',
		'.a .ran-booster-package-danger-zone__actions { padding-inline: 12px; }',
		'.a .ran-booster-settings-section__header { padding-left: 12px; }',
		'.a .ran-booster-settings-section__header { padding-inline-start: 12px; }',
		'.a .ran-booster-settings-section__body { padding-inline: 1rem; }',
		'.a .ran-booster-settings-section__body { padding-inline: 12px; margin-inline: var(--ran-booster-header-inset-inline); }',
		'.a .ran-booster-settings-section__body { padding: 8px var(--ran-booster-space-16) 8px; }',
		'.a .x > summary { padding: 8px 12px 8px 12px; }',
	];
	for (const css of bypass) {
		assert.equal(bypasses(css).length, 1, css);
	}
	for (const ok of [
		'.a .ran-booster-settings-section__body { padding-inline: var(--ran-booster-header-inset-inline); }',
		'.a .ran-booster-troubleshooting__entry-row { padding: 16px var(--ran-booster-header-inset-inline); }',
		'.a .ran-booster-disclosure__body > :first-child { margin-block-start: 0; }',
		'.a .x > summary { padding-block: 16px; }',
	]) {
		assert.deepEqual(bypasses(ok), [], ok);
	}
});

test('no stylesheet gives a Card or disclosure header or body its own inline inset', () => {
	/*
	 * Documented exceptions, each with its role in the contract: the page frame,
	 * the modal dialog header, the Documentation chapter summary (compact
	 * profile) and the compact disclosure baseline (generic body, mounted button
	 * and native fallback), which prominent rules outrank.
	 */
	const exceptions = [
		'25-admin-primitives.css :: .ran-booster-admin .ran-booster-page-shell__header => padding-inline: var(--ran-booster-space-24)',
		'25-admin-primitives.css :: .ran-booster-admin .ran-booster-page-shell__body => padding-inline: var(--ran-booster-space-24)',
		'25-admin-primitives.css :: .ran-booster-admin .ran-booster-disclosure > summary => padding-inline: var(--ran-booster-space-16)',
		'25-admin-primitives.css :: .ran-booster-admin .ran-booster-disclosure__body => padding: var(--ran-booster-space-16)',
		'25-admin-primitives.css :: .ran-booster-admin .ran-booster-page-shell__header, .ran-booster-admin .ran-booster-page-shell__body => padding-inline: var(--ran-booster-space-18)',
		'25-admin-primitives.css :: .ran-booster-dialog__header => padding-inline-start: var(--ran-booster-space-20)',
		'25-admin-primitives.css :: .ran-booster-admin .ran-booster-ui-disclosure__header > [role="button"] => padding: 8px 16px',
		'25-admin-primitives.css :: .ran-booster-admin [data-ran-booster-ui-disclosure]:not( [data-ran-booster-ui-disclosure="documentation"] ) > details[data-ran-booster-ui-disclosure-fallback] > summary => padding: 8px 16px',
		'ran-booster-documentation.css :: .ran-booster-admin .ran-booster-documentation__section.ran-booster-disclosure > summary => padding-inline-end: calc(3 * var(--ran-booster-space-16))',
	];
	const sheets = [
		...fs
			.readdirSync(new URL('ran-booster/', root))
			.map((f) => `ran-booster/${f}`),
		...fs
			.readdirSync(root)
			.filter((f) => f.endsWith('.css') && f !== 'ran-admin-shell.css'),
	];
	const found = sheets.flatMap((sheet) =>
		bypasses(read(sheet)).map((d) => `${sheet.split('/').pop()} :: ${d}`)
	);
	assert.deepEqual(found, exceptions);
});

test('no page re-compensates the shared header inset', () => {
	const offenders = [
		...parse(packageSettings),
		...parse(troubleshooting),
	].filter(
		(r) =>
			/package-operation-settings[^,]*settings-section__header/.test(
				r.selector
			) ||
			(/settings-card/.test(r.selector) &&
				/ui-disclosure__header|summary/.test(r.selector) &&
				/padding-inline/.test(r.body))
	);
	assert.deepEqual(
		offenders.map((r) => r.selector),
		[]
	);
	assert.match(
		packageSettings,
		/--ran-booster-settings-card-inset: var\(--ran-booster-header-inset-inline\)/
	);
	const warning = parse(troubleshooting).find(
		(r) =>
			r.selector.endsWith(
				'.ran-booster-troubleshooting__entry-warning'
			) && r.body.includes('padding:')
	);
	assert.match(
		warning.body,
		/padding: 0 var\(--ran-booster-header-inset-inline\) 16px/
	);
});

test('Installation and Settings headers keep 16px block padding and take only the shared inset', () => {
	assert.match(
		troubleshooting,
		/\[data-ran-booster-ui-disclosure="settings-card"\][^{]*\{\s*padding-block:\s*16px;/
	);
});

test('a sub-heading inside a section uses the shared sub-heading modifier', () => {
	const title = rules.findIndex(
		(r) => r.selector === '.ran-booster-admin .ran-booster-section__title'
	);
	const sub = rules.findIndex(
		(r) =>
			r.selector === '.ran-booster-admin .ran-booster-section__title--sub'
	);
	assert.ok(sub > title, 'the modifier must follow the section title rule');
	assert.match(
		rules[sub].body,
		/font-size: var\(--ran-booster-font-size-md\)/
	);
	const view = fs.readFileSync(
		new URL('../../views/packages/source-choices.php', import.meta.url),
		'utf8'
	);
	assert.match(
		view,
		/<h4 id="ran-booster-package-source-heading" class="ran-booster-section__title ran-booster-section__title--sub">/
	);
});
