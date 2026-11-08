import { rollup } from 'rollup';
import { nodeResolve } from '@rollup/plugin-node-resolve';
import commonjs from '@rollup/plugin-commonjs';
import { transform } from 'esbuild';
import { createHash } from 'node:crypto';
import { readFile, readdir, rm, writeFile } from 'node:fs/promises';
import { gzipSync } from 'node:zlib';

const globals = {
	react: 'React',
	'react-dom': 'ReactDOM',
	'react-dom/client': 'ReactDOM',
	'react/jsx-runtime': 'ReactJSXRuntime',
	'@wordpress/i18n': 'wp.i18n',
	'@wordpress/a11y': 'wp.a11y',
};
const warnings = [];
const path = (id) => id.split('/node_modules/').pop();
const ensure = (condition, message) => {
	if (!condition) {
		throw new Error(message);
	}
};
const prefix = '\0wordpress-host:';
const host = {
	name: 'wordpress-host-globals',
	resolveId(id) {
		if (
			!/^(react|react-dom|@wordpress\/i18n|@wordpress\/a11y)(\/.*)?$/.test(
				id
			)
		) {
			return null;
		}
		ensure(Object.hasOwn(globals, id), `Unsupported host import: ${id}`);
		return `${prefix}${id}`;
	},
	load(id) {
		if (!id.startsWith(prefix)) {
			return null;
		}
		const name = id.slice(prefix.length);
		const method = { '@wordpress/i18n': '__', '@wordpress/a11y': 'speak' }[
			name
		];
		const code = method
			? `const service = globalThis.wp?.${name.slice(11)}; if (typeof service?.${method} !== "function") throw new Error("Missing WordPress host service: ${name}"); export default service;`
			: `export default globalThis.${globals[name]};`;
		return { code, syntheticNamedExports: true };
	},
};
const minify = {
	name: 'ui-minify',
	async renderChunk(code) {
		return transform(code, {
			minify: true,
			target: 'es2020',
			format: 'esm',
			define: { 'process.env.NODE_ENV': '"production"' },
			legalComments: 'none',
		});
	},
};
const bundle = await rollup({
	input: {
		foundation: 'scripts/ui-runtime-entry.js',
		overlay: 'scripts/ui-overlay-entry.js',
	},
	plugins: [
		host,
		nodeResolve({ browser: true, exportConditions: ['browser', 'import'] }),
		commonjs(),
		minify,
	],
	onwarn(warning) {
		if (warning.code === 'MODULE_LEVEL_DIRECTIVE') {
			ensure(
				warning.message.includes('"use client"') &&
					/^@base-ui\/(react|utils)\//.test(path(warning.id)),
				warning.message
			);
			warnings.push(`D:${path(warning.id)}`);
		} else if (warning.code === 'CIRCULAR_DEPENDENCY') {
			warnings.push(`C:${warning.ids.map(path).join(' > ')}`);
		} else {
			throw new Error(`Unexpected Rollup warning: ${warning.message}`);
		}
	},
});
const { output } = await bundle.generate({
	format: 'es',
	entryFileNames: 'ran-booster-ui-[name]-[hash].js',
	chunkFileNames: 'ran-booster-ui-unexpected-[hash].js',
});
await bundle.close();
// Exact reviewed set: 436 Base UI client directives and three Color.js cycles.
const digest = createHash('sha256')
	.update(warnings.sort().join('\n'))
	.digest('hex');
ensure(
	warnings.length === 439 &&
		digest ===
			'73f5904bf3a7104d6e18234d4816b98b79d887755c32a1c8f9ba7dd133d93ea3',
	'Unexpected Rollup warning set'
);
ensure(
	output.length === 2 &&
		output.every((chunk) => chunk.type === 'chunk' && chunk.isEntry),
	'Unexpected UI output'
);
const foundation = output.find((chunk) => chunk.name === 'foundation');
const overlay = output.find((chunk) => chunk.name === 'overlay');
ensure(
	foundation &&
		overlay &&
		foundation.imports.length === 0 &&
		overlay.imports.length === 1 &&
		overlay.imports[0] === foundation.fileName &&
		output.every((chunk) => chunk.dynamicImports.length === 0),
	'Unexpected UI imports'
);
const inputs = output.flatMap((chunk) =>
	Object.entries(chunk.modules)
		.filter(([, info]) => info.renderedLength > 0)
		.map(([id]) => path(id))
);
ensure(
	!inputs.some((id) =>
		/^(react|react-dom|@wordpress\/i18n|@wordpress\/a11y)\//.test(id)
	),
	'Host implementation must not be bundled'
);
const size = (chunk) => [
	Buffer.byteLength(chunk.code),
	gzipSync(chunk.code).length,
];
const adapter = await readFile('assets/ran-booster-cards.js');
const [foundationBytes, foundationGzip] = size(foundation),
	[overlayBytes, overlayGzip] = size(overlay);
ensure(
	foundationBytes + adapter.length <= 166090 &&
		foundationGzip + gzipSync(adapter).length <= 33383 &&
		overlayBytes <= 320000 &&
		overlayGzip <= 90000,
	`UI runtime exceeds reserved size: foundation+adapter ${foundationBytes + adapter.length}/${foundationGzip + gzipSync(adapter).length}, overlay ${overlayBytes}/${overlayGzip}`
);
const dir = 'assets/lib',
	manifest = { foundation: foundation.fileName, overlay: overlay.fileName };
const files = new Map(output.map((chunk) => [chunk.fileName, chunk.code]));
files.set(
	'ran-booster-ui-manifest.json',
	`${JSON.stringify(manifest, null, 2)}\n`
);
const own = (name) =>
	/^ran-booster-ui-(foundation|overlay)-[A-Za-z0-9_-]+\.js$/.test(name) ||
	name === 'ran-booster-ui.min.js';
const stale = (await readdir(dir)).filter(
	(name) => own(name) && !files.has(name)
);
if (process.argv.includes('--check')) {
	ensure(stale.length === 0, `Stale UI assets: ${stale.join(', ')}`);
	for (const [name, content] of files) {
		ensure(
			(await readFile(`${dir}/${name}`, 'utf8')) === content,
			`UI asset differs: ${name}`
		);
	}
} else {
	for (const name of stale) {
		await rm(`${dir}/${name}`);
	}
	for (const [name, content] of files) {
		await writeFile(`${dir}/${name}`, content);
	}
}
process.stdout.write(
	`UI foundation ${foundationBytes}/${foundationGzip}, overlay ${overlayBytes}/${overlayGzip}; ${inputs.length} modules; 436 client directives, 3 Color.js cycles; warning sha256=${digest}\n`
);
