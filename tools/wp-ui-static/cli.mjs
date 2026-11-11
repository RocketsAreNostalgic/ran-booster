import { readFile, writeFile } from 'node:fs/promises';
import { extract } from './extract.mjs';

const flag = (name, fallback) => {
	const at = process.argv.indexOf(`--${name}`);
	return at === -1 ? fallback : process.argv[at + 1];
};

const uiRoot = flag('ui', 'node_modules/@wordpress/ui');
const prefix = flag('prefix', 'wp-ui');
const out = flag('out', 'wp-ui-static.css');
const manifestOut = out.replace(/\.css$/, '.json');
const { css, manifest } = await extract({ uiRoot, prefix });
const json = `${JSON.stringify(manifest, null, '\t')}\n`;

if (process.argv.includes('--check')) {
	for (const [path, expected] of [
		[out, css],
		[manifestOut, json],
	]) {
		if ((await readFile(path, 'utf8')) !== expected) {
			throw new Error(`Static UI output differs: ${path}`);
		}
	}
} else {
	await writeFile(out, css);
	await writeFile(manifestOut, json);
}
process.stdout.write(
	`Static UI styles: ${Buffer.byteLength(css)} bytes from @wordpress/ui ${manifest.source.version}, prefix ${prefix}\n`
);
