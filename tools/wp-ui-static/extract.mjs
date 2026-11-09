import { createHash } from 'node:crypto';
import { readFile } from 'node:fs/promises';

/**
 * Static, unmounted styles for public `@wordpress/ui` components.
 *
 * The library ships each component's CSS inside its JavaScript as a hashed CSS
 * module and injects it when the component mounts. Server-rendered markup that
 * never mounts a component therefore has nothing to load. This module lifts the
 * registered style strings out of the installed package, keeps every
 * declaration verbatim, and renames the hashed classes to stable names that
 * follow the Gutenberg component convention:
 *
 * - `{prefix}-{component}`            block (the library's `root` part)
 * - `{prefix}-{component}__{element}` child element
 * - `.is-{state}`                     state, compounded onto its block
 * - `{prefix}-{block}--{modifier}`    standalone modifier (the focus ring's
 *                                     `outset-ring--*` classes are applied on
 *                                     their own, with no base class)
 *
 * This file imports nothing from the host plugin. Everything specific to a
 * consumer (prefix, paths) is an argument.
 */

export const components = [
	{
		name: 'box-sizing',
		file: 'card/root.mjs',
		match: /__box-sizing\{/,
		utility: true,
	},
	{ name: 'card', file: 'card/root.mjs', match: /__root\{/, root: 'root' },
	{
		name: 'text',
		file: 'text/text.mjs',
		match: /__text\{/,
		root: 'text',
		variants: /^(?:body|heading)-/,
	},
	{
		name: 'badge',
		file: 'badge/badge.mjs',
		match: /__badge\{/,
		root: 'badge',
	},
	{
		name: 'link',
		file: 'link/link.mjs',
		match: /__link\{/,
		root: 'link',
		strip: /^link-/,
	},
	{
		name: 'reset-a',
		file: 'link/link.mjs',
		match: /__a,\./,
		rule: /\.[0-9a-f]{16}__a,[^{]*\{[^}]*\}/,
		root: 'a',
	},
	{
		name: 'notice',
		file: 'notice/root.mjs',
		match: /__notice\{/,
		root: 'notice',
	},
	{
		name: 'outset-ring',
		file: 'link/link.mjs',
		match: /__outset-ring--focus-except-active:focus\b/,
		modifiers: true,
	},
];

const ensure = (condition, message) => {
	if (!condition) {
		throw new Error(message);
	}
};

const registered = (source) =>
	Array.from(
		source.matchAll(/registerStyle\("([0-9a-f]+)", "((?:[^"\\]|\\.)*)"\)/g),
		(found) => JSON.parse(`"${found[2]}"`)
	);

const hashed = /\._?[0-9a-f]{16}__([a-z0-9-]+)/g;

/**
 * Lists the public custom properties a style declares, such as the Card's
 * `--wp-ui-card-padding`. Consumers tune a component through these, so a
 * rename upstream shows up as manifest drift. Private `--_*` properties are
 * implementation details and are left out.
 *
 * @param {string} css Extracted style string.
 * @return {string[]} Sorted property names.
 */
const customProperties = (css) =>
	[
		...new Set(
			Array.from(
				css.matchAll(/[{;](--[a-z][a-z0-9-]*):/g),
				(found) => found[1]
			)
		),
	].sort();

/**
 * Renames one library class to its stable name, recording how it was used.
 *
 * @param {{name: string, root?: string, rule?: RegExp, utility?: boolean, modifiers?: boolean, strip?: RegExp, variants?: RegExp}} component Component definition.
 * @param {string}                                                                                                                  prefix    Namespace for generated classes.
 * @param {string}                                                                                                                  part      Source part name from the library.
 * @param {{elements: Set<string>, states: Set<string>, modifiers: Set<string>}}                                                    found     Collected class contract.
 * @return {string} Replacement selector fragment.
 */
function rename(component, prefix, part, found) {
	const block = `${prefix}-${component.name}`;
	if (component.utility) {
		found.elements.add(part);
		return `.${block}`;
	}
	if (component.modifiers) {
		ensure(
			part.startsWith(`${component.name}--`),
			`Expected ${component.name}--modifier, found ${part}`
		);
		found.modifiers.add(part.slice(component.name.length + 2));
		return `.${prefix}-${part}`;
	}
	if (part === component.root) {
		return `.${block}`;
	}
	if (/^(?:is|has)-/.test(part) || component.variants?.test(part)) {
		const state = /^(?:is|has)-/.test(part) ? part : `is-${part}`;
		found.states.add(state);
		return `.${block}.${state}`;
	}
	const element = component.strip ? part.replace(component.strip, '') : part;
	found.elements.add(element);
	return `.${block}__${element}`;
}

/**
 * Extracts the static stylesheet and its class manifest.
 *
 * @param {{uiRoot: string, prefix: string}} options uiRoot is the installed `@wordpress/ui` package directory.
 * @return {Promise<{css: string, manifest: object}>} Generated output.
 */
export async function extract({ uiRoot, prefix }) {
	ensure(/^[a-z][a-z0-9-]*$/.test(prefix), `Invalid prefix: ${prefix}`);
	const { version } = JSON.parse(
		await readFile(`${uiRoot}/package.json`, 'utf8')
	);
	const chunks = [];
	const manifest = {
		source: { package: '@wordpress/ui', version },
		prefix,
		components: {},
	};
	for (const component of components) {
		const source = await readFile(
			`${uiRoot}/build-module/${component.file}`,
			'utf8'
		);
		const styles = registered(source).filter((style) =>
			component.match.test(style)
		);
		ensure(
			styles.length === 1,
			`Expected one ${component.name} style in ${component.file}`
		);
		const found = {
			elements: new Set(),
			states: new Set(),
			modifiers: new Set(),
		};
		// A shared style string may hold many rules; `rule` lifts just one.
		const lifted = component.rule ? styles[0].match(component.rule) : null;
		ensure(
			!component.rule || lifted,
			`Expected a ${component.name} rule in ${component.file}`
		);
		const chunk = (lifted ? lifted[0] : styles[0]).replace(
			hashed,
			(_, part) => rename(component, prefix, part, found)
		);
		chunks.push(chunk);
		manifest.components[component.name] = {
			block: `${prefix}-${component.name}`,
			elements: [...found.elements].sort(),
			states: [...found.states].sort(),
			modifiers: [...found.modifiers].sort(),
			properties: customProperties(chunk),
			sourceSha256: createHash('sha256').update(styles[0]).digest('hex'),
		};
	}
	return {
		css: `/* Generated from @wordpress/ui ${version} by tools/wp-ui-static. Do not edit. */\n${chunks.join('\n')}\n`,
		manifest,
	};
}
