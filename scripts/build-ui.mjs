import { build } from 'esbuild';
import { readFile, writeFile } from 'node:fs/promises';
import { gzipSync } from 'node:zlib';

const globals = {
	react: 'React',
	'react-dom': 'ReactDOM',
	'react-dom/client': 'ReactDOM',
	'react/jsx-runtime': 'ReactJSXRuntime',
};
const result = await build({
	entryPoints: ['scripts/ui-runtime-entry.js'],
	bundle: true,
	format: 'iife',
	target: 'es2020',
	minify: true,
	write: false,
	metafile: true,
	legalComments: 'none',
	define: { 'process.env.NODE_ENV': '"production"' },
	plugins: [
		{
			name: 'wordpress-react-globals',
			setup(builder) {
				builder.onResolve(
					{ filter: /^(react|react-dom)(\/.*)?$/ },
					({ path }) => {
						if (!globals[path]) {
							throw new Error(
								`Unsupported React import: ${path}`
							);
						}
						return { path, namespace: 'wordpress-react' };
					}
				);
				builder.onLoad(
					{ filter: /.*/, namespace: 'wordpress-react' },
					({ path }) => ({
						contents: `module.exports = globalThis.${globals[path]};`,
						loader: 'js',
					})
				);
			},
		},
	],
});
const inputs = Object.keys(
	result.metafile.outputs[Object.keys(result.metafile.outputs)[0]].inputs
);
if (
	inputs.some((name) =>
		/node_modules\/(?:\.pnpm\/)?(?:react|react-dom)(?:@|\/)/.test(name)
	)
) {
	throw new Error('React implementation must not be bundled');
}
const bytes = result.outputFiles[0].contents;
const gzip = gzipSync(bytes).length;
if (bytes.length > 70000 || gzip > 16000) {
	throw new Error(
		`UI runtime exceeds reserved size: ${bytes.length}/${gzip}`
	);
}
const output = 'assets/lib/ran-booster-ui.min.js';
if (process.argv.includes('--check')) {
	if (!Buffer.from(bytes).equals(await readFile(output))) {
		throw new Error('UI runtime differs from generated artifact');
	}
} else {
	await writeFile(output, bytes);
}
process.stdout.write(
	`UI runtime ${bytes.length} bytes, ${gzip} gzip; ${inputs.length} modules; externals ${inputs.filter((name) => name.startsWith('wordpress-react:')).join(', ')}\n`
);
