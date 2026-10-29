// SECURITY-F5-TAILWIND-JS-CONTENT-1 — Tailwind must scan the scripts that apply
// classes, or those classes are purged from the production stylesheet.
//
// The KTP scan region handles in resources/js/ktp-roi-ui.js are positioned and
// styled entirely by classes set from JavaScript (`-translate-x-1/2`,
// `-translate-y-1/2`, `cursor-grab`, `max-w-[220px]`). With only Blade views in
// `content`, the build dropped them and the handles rendered off-centre.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..', '..');
// The config is not imported: it uses extensionless imports that only Vite's
// resolver accepts. Read the `content` array from the source instead.
const configSource = readFileSync(path.join(root, 'tailwind.config.js'), 'utf8');
const contentBlock = configSource.match(/content:\s*\[([\s\S]*?)\]/);
const contentGlobs = contentBlock
    ? [...contentBlock[1].replace(/\/\/.*$/gm, '').matchAll(/'([^']+)'/g)].map((m) => m[1])
    : [];

// Classes the KTP scan UI applies only from JavaScript.
const JS_ONLY_CLASSES = ['cursor-grab', '-translate-x-1/2', '-translate-y-1/2', 'max-w-[220px]'];

test('tailwind content scans the JavaScript sources', () => {
    assert.ok(
        contentGlobs.includes('./resources/js/**/*.js'),
        'tailwind.config.js content must include ./resources/js/**/*.js',
    );
});

test('the JS-only classes are still used by the KTP scan UI (so this test stays meaningful)', () => {
    const source = readFileSync(path.join(root, 'resources/js/ktp-roi-ui.js'), 'utf8');
    for (const cls of JS_ONLY_CLASSES) {
        assert.ok(source.includes(cls), `${cls} is no longer in ktp-roi-ui.js; update this test`);
    }
});

test('the built stylesheet contains the JS-only classes', (t) => {
    const dir = path.join(root, 'public/build/assets');
    const css = existsSync(dir) ? readdirSync(dir).filter((f) => /^app-.*\.css$/.test(f)) : [];
    if (css.length === 0) {
        // CI builds the assets in the step before this one; locally run `npm run build` first.
        t.skip('no built stylesheet; run npm run build');
        return;
    }
    const built = css.map((f) => readFileSync(path.join(dir, f), 'utf8')).join('\n');
    const escaped = (cls) => '.' + cls.replace(/[/[\]]/g, (c) => '\\' + c);
    for (const cls of JS_ONLY_CLASSES) {
        assert.ok(built.includes(escaped(cls)), `${cls} was purged from the production stylesheet`);
    }
});
