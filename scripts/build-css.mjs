import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const output = resolve(root, 'resources/dist/smart-import.css');
const binary = process.platform === 'win32' ? 'tailwindcss.cmd' : 'tailwindcss';

execFileSync(resolve(root, 'node_modules/.bin', binary), [
    '-i',
    resolve(root, 'resources/css/smart-import.css'),
    '-o',
    output,
    '--minify',
], { stdio: 'inherit' });

const css = readFileSync(output, 'utf8');
writeFileSync(output, css.replace(/:root\s*,\s*:host\s*\{/, '.smart-import{'));
