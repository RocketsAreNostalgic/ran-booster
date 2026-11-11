import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';

/*
 * A mounted @wordpress/ui component root carries the library's box-sizing
 * utility: the root is border-box and every descendant inherits it. Any
 * server-rendered markup that the runtime later wraps in such a root must
 * already lay out with the same box-sizing, or the page shifts when (and
 * whenever) the runtime mounts.
 */

const read = (file) => fs.readFileSync(file, 'utf8');
const primitives = read('assets/ran-booster/25-admin-primitives.css');
const cards = read('assets/ran-booster-cards.js');
const uiModules = 'node_modules/@wordpress/ui/build-module';
const fallback = '.ran-booster-admin [data-ran-booster-ui-disclosure-fallback]';

const files = (directory, pattern) =>
	fs.readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
		const file = path.join(directory, entry.name);
		if (entry.isDirectory()) {
			return files(file, pattern);
		}
		return pattern.test(entry.name) ? [file] : [];
	});

const escape = (value) => value.replace(/[$.*+?^()[\]{}|\\]/g, '\\$&');

function mirrorBlock() {
	const start = primitives.indexOf('@layer wp-ui.utilities {');
	assert.notEqual(
		start,
		-1,
		'the disclosure fallback mirror rule is missing'
	);
	const end = primitives.indexOf('\n}\n', start);
	assert.notEqual(end, -1);
	return primitives.slice(start, end);
}

function declarationFor(block, selectors) {
	for (const rule of block.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
		const list = rule[1]
			.split(',')
			.map((selector) => selector.replace(/\s+/g, ' ').trim());
		if (selectors.every((selector) => list.includes(selector))) {
			return { declaration: rule[2].trim(), index: rule.index };
		}
	}
	return null;
}

/*
 * Every component in the shipped runtime bundles whose root composes the
 * box-sizing utility, named by the @wordpress/ui module directory that owns
 * the root's hashed class.
 */
function boxSizingComponents() {
	const owners = new Map();
	for (const module of files(uiModules, /\.mjs$/)) {
		const source = read(module);
		for (const map of source.matchAll(
			/style_module_default = (\{[^}]*\})/g
		)) {
			for (const hashed of map[1].matchAll(
				/"([0-9a-f_]{16,17}__[\w-]+)"/g
			)) {
				if (!owners.has(hashed[1])) {
					owners.set(
						hashed[1],
						path.relative(uiModules, path.dirname(module))
					);
				}
			}
		}
	}
	const found = new Set();
	for (const bundle of files('assets/lib', /^ran-booster-ui-.*\.js$/)) {
		const source = read(bundle);
		const utilities = [
			...source.matchAll(
				/(?:var|const|let) ([\w$]+)=\{"box-sizing":"[0-9a-f_]+__box-sizing"\}/g
			),
		].map((match) => match[1]);
		for (const utility of utilities) {
			const uses = source.matchAll(
				new RegExp(
					`\\(([^()]*[^\\w$.]${escape(utility)}\\["box-sizing"\\][^()]*)\\)`,
					'g'
				)
			);
			for (const use of uses) {
				const components = new Set();
				for (const reference of use[1].matchAll(
					/([\w$]+)(?:\.([\w$-]+)|\["([^"]+)"\])/g
				)) {
					if (reference[1] === utility) {
						continue;
					}
					const map = source.match(
						new RegExp(
							`(?:var|const|let) ${escape(reference[1])}=(\\{[^}]*\\})`
						)
					);
					const key = reference[2] ?? reference[3];
					const hashed = map?.[1].match(
						new RegExp(`"?${escape(key)}"?:"([^"]+)"`)
					)?.[1];
					if (hashed && owners.has(hashed)) {
						components.add(owners.get(hashed));
					}
				}
				assert.equal(
					components.size,
					1,
					`Unresolved box-sizing root in ${bundle}: ${use[1]}`
				);
				found.add([...components][0]);
			}
		}
	}
	return found;
}

const serverMarkup = () =>
	[
		...files('RAN', /\.php$/),
		...files('views', /\.php$/),
		...files('assets', /^ran-booster[\w-]*\.js$/).filter(
			(file) => !file.includes(`${path.sep}lib${path.sep}`)
		),
	].map((file) => [file, read(file)]);

const linesWithClass = (block) =>
	serverMarkup().flatMap(([file, source]) =>
		source
			.split('\n')
			.map((line, index) => [`${file}:${index + 1}`, line])
			.filter(([, line]) =>
				new RegExp(`ran-wp-ui-${block}(?:[\\s"']|$)`).test(line)
			)
	);

// How the pre-mount markup gets the box-sizing each mounted root will have.
const coverage = {
	// Static Button markup (ButtonClasses) carries the static utility.
	button: () => {
		assert.match(
			read('RAN/Admin/Component/ButtonClasses.php'),
			/'ran-wp-ui-box-sizing'/
		);
	},
	// Static Card surfaces carry the static utility; the mounted Disclosure
	// (CollapsibleCard.Root) wraps the fallback <details>, which the mirror
	// rule gives the same box-sizing.
	card: () => {
		const unclassed = linesWithClass('card').filter(
			([, line]) => !line.includes('ran-wp-ui-box-sizing')
		);
		assert.deepEqual(unclassed, [], 'a Card surface lacks box-sizing');
		assert.ok(
			cards.includes('details[data-ran-booster-ui-disclosure-fallback]')
		);
		assert.ok(declarationFor(mirrorBlock(), [fallback]));
	},
	// Static notices carry the static utility and never mount; a mounted
	// notice is only the core-screen global notice.
	notice: () => {
		const unclassed = linesWithClass('notice').filter(
			([, line]) =>
				!line.includes('ran-wp-ui-box-sizing') &&
				!line.includes("staticNotice = '.ran-wp-ui-notice'")
		);
		assert.deepEqual(unclassed, [], 'a static Notice lacks box-sizing');
	},
	// The Switch renders into an empty server mount; no server markup moves
	// into it.
	'form/primitives/switch': () => {
		const mounts = serverMarkup().flatMap(([, source]) => [
			...source.matchAll(
				/<span data-ran-booster-ui-switch-mount>(.*?)<\/span>/g
			),
		]);
		assert.ok(mounts.length > 0);
		mounts.forEach((mount) => assert.equal(mount[1], ''));
	},
	// Overlay-only: tooltips and Dialog inputs render on the client.
	tooltip: () => {},
	'form/primitives/input-layout': () => {},
};

test('every runtime root with the box-sizing utility has pre-mount coverage', () => {
	const found = boxSizingComponents();
	assert.ok(found.size > 0, 'no box-sizing roots found in the UI bundles');
	for (const component of found) {
		assert.ok(
			Object.hasOwn(coverage, component),
			`${component} mounts a box-sizing root with no pre-mount coverage`
		);
		coverage[component]();
	}
	assert.deepEqual(
		[...found].sort(),
		Object.keys(coverage).sort(),
		'the coverage table lists a component the bundles no longer mount'
	);
});

test('the disclosure fallback mirrors the mounted root box-sizing', () => {
	const block = mirrorBlock();
	assert.ok(
		primitives.indexOf(block) >
			primitives.indexOf('.ran-booster-disclosure__body {'),
		'the mirror rule follows the disclosure body rules'
	);
	const inherit = declarationFor(block, [
		`${fallback} *`,
		`${fallback} ::before`,
		`${fallback} ::after`,
	]);
	const border = declarationFor(block, [fallback, `${fallback} > *`]);
	assert.equal(inherit?.declaration, 'box-sizing: inherit;');
	assert.equal(border?.declaration, 'box-sizing: border-box;');
	assert.ok(
		border.index > inherit.index,
		'the summary and body rule must follow the descendant rule'
	);
	assert.doesNotMatch(block, /!important/);
	assert.doesNotMatch(block, /#[0-9a-f]{3,8}\b|rgba?\(/i);
});

test('the mirror rule shares the runtime utility layer', () => {
	const runtime = read('assets/lib/ran-booster-wp-ui-static.css');
	assert.match(
		runtime,
		/@layer wp-ui\{@layer utilities, components, compositions, overrides;@layer utilities\{\.ran-wp-ui-box-sizing\{box-sizing:border-box;\*,:after,:before\{box-sizing:inherit\}\}\}\}/
	);
	assert.match(mirrorBlock(), /^@layer wp-ui\.utilities \{/);
});

test('the disclosure mount wraps the fallback summary and body', () => {
	assert.ok(
		cards.includes("':scope > div[data-ran-booster-ui-disclosure-content]'")
	);
	assert.match(cards, /ui\?\.Root/);
	const views = serverMarkup()
		.filter(([, source]) =>
			source.includes('data-ran-booster-ui-disclosure-fallback')
		)
		.map(([file]) => file);
	assert.ok(views.length > 0);
});
