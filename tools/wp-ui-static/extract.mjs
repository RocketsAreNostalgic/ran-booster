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
 * Renames one library class to its stable name, recording how it was used.
 *
 * @param {{name: string, root?: string, utility?: boolean, variants?: RegExp}} component Component definition.
 * @param {string}                                                              prefix    Namespace for generated classes.
 * @param {string}                                                              part      Source part name from the library.
 * @param {{elements: Set<string>, states: Set<string>}}                        found     Collected class contract.
 * @return {string} Replacement selector fragment.
 */
function rename(component, prefix, part, found) {
	const block = `${prefix}-${component.name}`;
	if (component.utility) {
		found.elements.add(part);
		return `.${block}`;
	}
	if (part === component.root) {
		return `.${block}`;
	}
	if (/^(?:is|has)-/.test(part) || component.variants?.test(part)) {
		const state = /^(?:is|has)-/.test(part) ? part : `is-${part}`;
		found.states.add(state);
		return `.${block}.${state}`;
	}
	found.elements.add(part);
	return `.${block}__${part}`;
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
		const found = { elements: new Set(), states: new Set() };
		chunks.push(
			styles[0].replace(hashed, (_, part) =>
				rename(component, prefix, part, found)
			)
		);
		manifest.components[component.name] = {
			block: `${prefix}-${component.name}`,
			elements: [...found.elements].sort(),
			states: [...found.states].sort(),
			sourceSha256: createHash('sha256').update(styles[0]).digest('hex'),
		};
	}
	return {
		css: `/* Generated from @wordpress/ui ${version} by tools/wp-ui-static. Do not edit. */\n${chunks.join('\n')}\n`,
		manifest,
	};
}
