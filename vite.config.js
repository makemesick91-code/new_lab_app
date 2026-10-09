import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';

const require = createRequire(import.meta.url);

/**
 * REVISION-REGISTRATION-KTP-CAMERA-OCR-1 — self-host tesseract.js.
 *
 * tesseract.js defaults to downloading its worker, WASM core and language model
 * from cdn.jsdelivr.net. This plugin copies exactly the assets the KTP OCR
 * needs from node_modules into the build output (public/build/ocr/<versioned
 * dir>/), so the browser only ever fetches them from this origin. The
 * directory name embeds every version, which is the cache-busting key.
 *
 * Only the LSTM-only cores are shipped (OCR runs with OEM 1); the browser loads
 * ONE of the three variants after feature-detecting SIMD support.
 */
function selfHostedTesseract() {
    const pkgDir = (name) => dirname(require.resolve(`${name}/package.json`));
    const version = (name) => JSON.parse(readFileSync(join(pkgDir(name), 'package.json'), 'utf8')).version;

    const tesseractDir = pkgDir('tesseract.js');
    const coreDir = pkgDir('tesseract.js-core');
    const langDir = pkgDir('@tesseract.js-data/ind');
    const langVariant = '4.0.0_best_int';
    const assetDir = `ocr/tesseract-${version('tesseract.js')}-core-${version('tesseract.js-core')}-ind-${langVariant}`;

    const files = {
        'worker.min.js': join(tesseractDir, 'dist', 'worker.min.js'),
        'core/tesseract-core-lstm.wasm.js': join(coreDir, 'tesseract-core-lstm.wasm.js'),
        'core/tesseract-core-simd-lstm.wasm.js': join(coreDir, 'tesseract-core-simd-lstm.wasm.js'),
        'core/tesseract-core-relaxedsimd-lstm.wasm.js': join(coreDir, 'tesseract-core-relaxedsimd-lstm.wasm.js'),
        'lang/ind.traineddata.gz': join(langDir, langVariant, 'ind.traineddata.gz'),
    };

    return {
        name: 'dms-self-hosted-tesseract',
        config: () => ({ define: { __DMS_OCR_ASSET_DIR__: JSON.stringify(assetDir) } }),
        generateBundle() {
            for (const [name, source] of Object.entries(files)) {
                this.emitFile({ type: 'asset', fileName: `${assetDir}/${name}`, source: readFileSync(source) });
            }
        },
    };
}

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        selfHostedTesseract(),
    ],
});
