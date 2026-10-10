<?php

/**
 * REVISION-PATIENT-KTP-LIVE-FIELD-OVERLAY-OCR-1 — real-browser walk-through of
 * the live field overlay (development only; never against production).
 *
 * Drives headless Chrome whose camera is Chrome's FAKE capture device fed by a
 * Y4M clip of a FICTIONAL synthetic card (generate_live_frames.py --y4m). This
 * proves the shipped bundle's behaviour in a real browser; it is NOT a device
 * or real-card result.
 *
 *   php live_overlay_e2e.php <base_url> <email> <password> <clip.y4m> <clip.json>
 *       [--scenario=card|empty|noworker] [--width=1366] [--height=900] [--dpr=1]
 *       [--cycles=3] [--driver=http://127.0.0.1:9517]
 *
 * Prints one JSON object of measured facts (counts, timings, pixel errors —
 * never OCR text) and exits non-zero on the first failed check.
 */

require __DIR__.'/../../vendor/autoload.php';

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;

$positional = array_values(array_filter(array_slice($argv, 1), fn ($a) => ! str_starts_with($a, '--')));
$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--')) {
        [$k, $v] = array_pad(explode('=', substr($a, 2), 2), 2, '1');
        $opts[$k] = $v;
    }
}
[$base, $email, $password, $clip, $clipMeta] = array_pad($positional, 5, null);
if (! $base || ! $email || ! $password || ! is_file((string) $clip) || ! is_file((string) $clipMeta)) {
    fwrite(STDERR, "usage: php live_overlay_e2e.php <base_url> <email> <password> <clip.y4m> <clip.json> [--scenario=card|empty|noworker]\n");
    exit(2);
}
if (! preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?$#', $base)) {
    fwrite(STDERR, "refusing: the walk-through only runs against a local server\n");
    exit(2);
}
$scenario = $opts['scenario'] ?? 'card';
$width = (int) ($opts['width'] ?? 1366);
$height = (int) ($opts['height'] ?? 900);
$dpr = (float) ($opts['dpr'] ?? 1);
$cycles = (int) ($opts['cycles'] ?? 3);
$meta = json_decode((string) file_get_contents($clipMeta), true);
// The versioned template, read from the shipped source (templateBoxes(): y - h/2, x2 - x).
preg_match_all("/\\{ key: '([a-z_]+)', y: ([\\d.]+), h: ([\\d.]+), x: ([\\d.]+), x2: ([\\d.]+)/", (string) file_get_contents(__DIR__.'/../../resources/js/ktp-roi-ocr.js'), $rows, PREG_SET_ORDER);
$boxes = [];
foreach ($rows as [, $key, $y, $h, $x, $x2]) {
    $boxes[$key] = ['x' => (float) $x, 'y' => (float) $y - (float) $h / 2, 'w' => (float) $x2 - (float) $x, 'h' => (float) $h];
}
if (count($boxes) !== 11) {
    fwrite(STDERR, 'could not read the field template ('.count($boxes)." boxes)\n");
    exit(2);
}

$facts = ['scenario' => $scenario, 'viewport' => "{$width}x{$height}@{$dpr}x", 'browser' => null, 'checks' => []];
$check = function (string $name, bool $ok, $detail = null) use (&$facts) {
    $facts['checks'][] = ['check' => $name, 'ok' => $ok] + ($detail === null ? [] : ['detail' => $detail]);
    if (! $ok) {
        echo json_encode($facts, JSON_PRETTY_PRINT), "\n";
        exit(1);
    }
};

$options = (new ChromeOptions)->addArguments([
    '--headless=new', '--disable-gpu', "--window-size={$width},{$height}", "--force-device-scale-factor={$dpr}",
    '--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream',
    '--use-file-for-fake-video-capture='.realpath($clip),
]);
$caps = DesiredCapabilities::chrome();
$caps->setCapability(ChromeOptions::CAPABILITY, $options);
$caps->setCapability('goog:loggingPrefs', ['browser' => 'ALL']);
$driver = RemoteWebDriver::create($opts['driver'] ?? 'http://127.0.0.1:9517', $caps);
$facts['browser'] = $driver->getCapabilities()->getBrowserName().' '.$driver->getCapabilities()->getVersion();

$js = fn (string $script, array $args = []) => $driver->executeScript($script, $args);
$jsAsync = fn (string $script, array $args = []) => $driver->executeAsyncScript($script, $args);
$status = fn () => $driver->findElement(WebDriverBy::cssSelector('[data-ktp-status]'))->getText();
$liveChunkLoaded = fn () => $js("return performance.getEntriesByType('resource').some(e => /ktp-live-camera/.test(e.name));");
$parseRequests = fn () => $js("return performance.getEntriesByType('resource').filter(e => /parse-ocr/.test(e.name)).length;");
$streamActive = fn () => $js("const v=document.querySelector('[data-ktp-video]'); return !!(v && v.srcObject && v.srcObject.getTracks().some(t => t.readyState === 'live'));");
$click = function (string $selector) use ($driver) {
    $el = $driver->findElement(WebDriverBy::cssSelector($selector));
    $driver->executeScript('arguments[0].scrollIntoView({block: "center", inline: "center"});', [$el]);
    $el->click();
};
$stats = fn () => json_decode((string) ($js("return document.querySelector('[data-ktp-live-overlay]').dataset.liveStats || 'null';") ?? 'null'), true);

/**
 * On-screen alignment, measured through the BROWSER's layout: SVG vertices via
 * getScreenCTM() against the ground-truth card corners projected onto the video
 * box (object-fit: contain, optional mirror) with an independent homography.
 */
$alignment = <<<'JS'
const [truth, fw, fh, boxes] = arguments;
const svg = document.querySelector('[data-ktp-live-overlay]');
const video = document.querySelector('[data-ktp-video]');
const outline = svg.querySelector('[data-ktp-live-outline]');
if (!outline || outline.getAttribute('visibility') !== 'visible') return null;
const ctm = svg.getScreenCTM();
const toScreen = (p) => { const q = svg.createSVGPoint(); q.x = p.x; q.y = p.y; const r = q.matrixTransform(ctm); return [r.x, r.y]; };
const vr = video.getBoundingClientRect();
const style = getComputedStyle(video);
const s = Math.min(vr.width / fw, vr.height / fh);
const ox = vr.left + (vr.width - fw * s) / 2, oy = vr.top + (vr.height - fh * s) / 2;
const mirrored = /^matrix\(-/.test(style.transform);
const frameToScreen = ([x, y]) => { let X = ox + x * s; if (mirrored) X = vr.left + vr.right - X; return [X, oy + y * s]; };
// Independent 4-point homography (unit card -> frame), Gaussian elimination.
const solve = (from, to) => {
  const m = [];
  for (let i = 0; i < 4; i++) { const [x, y] = from[i], [u, v] = to[i];
    m.push([x, y, 1, 0, 0, 0, -u * x, -u * y, u]); m.push([0, 0, 0, x, y, 1, -v * x, -v * y, v]); }
  for (let c = 0; c < 8; c++) { let p = c; for (let r = c + 1; r < 8; r++) if (Math.abs(m[r][c]) > Math.abs(m[p][c])) p = r;
    [m[c], m[p]] = [m[p], m[c]]; for (let r = 0; r < 8; r++) { if (r === c) continue; const f = m[r][c] / m[c][c]; for (let k = c; k < 9; k++) m[r][k] -= f * m[c][k]; } }
  return [...m.map((row, i) => row[8] / row[i]), 1];
};
const H = solve([[0,0],[1,0],[1,1],[0,1]], truth);
const apply = (u, v) => { const w = H[6]*u + H[7]*v + H[8]; return [(H[0]*u + H[1]*v + H[2]) / w, (H[3]*u + H[4]*v + H[5]) / w]; };
const outlinePts = [...outline.points].map(toScreen);
const expected = truth.map(frameToScreen);
const err = (a, b) => Math.hypot(a[0] - b[0], a[1] - b[1]);
const cardWidth = err(expected[0], expected[1]);
const outlineErr = Math.max(...outlinePts.map((p, i) => err(p, expected[i])));
// Field boxes: each normalized template box through the TRUE homography,
// against the quad the browser actually drew (vertex order tl, tr, br, bl).
let fieldErr = 0;
const fieldEls = [...svg.querySelectorAll('[data-ktp-live-field]')];
for (const g of fieldEls) {
  const b = boxes[g.dataset.ktpLiveField];
  if (!b) return { error: 'unknown field ' + g.dataset.ktpLiveField };
  const drawn = [...g.querySelectorAll('polygon')[1].points].map(toScreen);
  const want = [[b.x, b.y], [b.x + b.w, b.y], [b.x + b.w, b.y + b.h], [b.x, b.y + b.h]].map(([u, v]) => frameToScreen(apply(u, v)));
  fieldErr = Math.max(fieldErr, ...drawn.map((p, i) => err(p, want[i])));
}
return { outlineErrPx: +outlineErr.toFixed(2), outlineErrPct: +(100 * outlineErr / cardWidth).toFixed(2), cardWidthPx: +cardWidth.toFixed(1), fields: fieldEls.length, fieldErrPx: +fieldErr.toFixed(2), fieldErrPct: +(100 * fieldErr / cardWidth).toFixed(2), mirrored, letterboxY: +(oy - vr.top).toFixed(1), letterboxX: +(ox - vr.left).toFixed(1) };
JS;

try {
    $driver->get($base.'/login');
    $driver->findElement(WebDriverBy::name('email'))->sendKeys($email);
    $driver->findElement(WebDriverBy::name('password'))->sendKeys($password);
    $driver->findElement(WebDriverBy::cssSelector('form button[type=submit]'))->click();
    $driver->wait(15)->until(fn () => ! str_contains($driver->getCurrentURL(), '/login'));

    if ($scenario === 'noworker') {
        // Simulate a browser WITHOUT module workers (classic workers still work,
        // as tesseract.js needs them): the overlay must fall back to the main thread.
        $driver->executeCustomCommand('/session/:sessionId/goog/cdp/execute', 'POST', [
            'cmd' => 'Page.addScriptToEvaluateOnNewDocument',
            'params' => ['source' => '(() => { const W = window.Worker; const P = function (url, opts) { if (opts && opts.type === "module") throw new TypeError("module workers unsupported"); return new W(url, opts); }; P.prototype = W.prototype; window.Worker = P; })();'],
        ]);
    }

    $driver->get($base.'/settings/patients/create');
    $driver->wait(15)->until(WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::cssSelector('[data-ktp-scan]')));
    $root = $driver->findElement(WebDriverBy::cssSelector('[data-ktp-scan]'));
    $check('pilot operator gets OCR', $root->getAttribute('data-ocr-enabled') === '1');
    $check('live overlay markup present', count($driver->findElements(WebDriverBy::cssSelector('svg[data-ktp-live-overlay]'))) === 1);

    // ---------------------------------------------------------- consent (D7)
    $check('no live module before any consent', $liveChunkLoaded() === false);
    $click('[data-ktp-camera-open]');
    $driver->wait(10)->until(WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::cssSelector('[data-ktp-consent-decline]')));
    $check('camera stays closed while the question is open', $streamActive() === false);
    $check('no live module while the question is open', $liveChunkLoaded() === false);
    $click('[data-ktp-consent-decline]');
    usleep(800_000);
    $check('a "no" opens no camera', $streamActive() === false);
    $check('a "no" loads no live module', $liveChunkLoaded() === false);
    $check('a "no" leaves the overlay hidden', $js("return document.querySelector('[data-ktp-live-overlay]').classList.contains('hidden');") === true);
    $check('a "no" sends no parse request', $parseRequests() === 0);

    $click('[data-ktp-camera-open]');
    $driver->wait(10)->until(WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::cssSelector('[data-ktp-consent-accept]')));
    $check('pressing the camera again asks again', $streamActive() === false);
    $cameraStartMs = $jsAsync(<<<'JS'
        const done = arguments[arguments.length - 1];
        const v = document.querySelector('[data-ktp-video]');
        const t0 = performance.now();
        const ready = () => v.readyState >= 2 && v.videoWidth > 0;
        document.querySelector('[data-ktp-consent-accept]').click();
        const poll = () => ready() ? done(Math.round(performance.now() - t0)) : setTimeout(poll, 20);
        poll();
    JS);
    $facts['camera_start_ms'] = $cameraStartMs;
    $check('after a "yes" the camera opens', $streamActive() === true);
    $driver->wait(10, 100)->until(fn () => $liveChunkLoaded() === true);
    $driver->wait(10, 100)->until(fn () => $js("return !document.querySelector('[data-ktp-live-overlay]').classList.contains('hidden');") === true);
    $check('the live panel is shown', $js("return !document.querySelector('[data-ktp-live-panel]').classList.contains('hidden');") === true);
    $check('the static guide is replaced by the live hint', $js("return document.querySelector('[data-ktp-camera-guide]').classList.contains('hidden');") === true);

    $level = fn () => $js("return document.querySelector('[data-ktp-live-overlay]').dataset.liveLevel || null;");
    if ($scenario === 'empty') {
        $driver->wait(10, 100)->until(fn () => $stats() !== null && ($stats()['count'] ?? 0) >= 3);
        $check('an empty desk stays RED', $level() === 'red', $level());
        $check('the neutral hint frame is drawn', $js("return document.querySelector('[data-ktp-live-hint]').getAttribute('visibility');") === 'visible');
        $check('no card outline is claimed', $js("return document.querySelector('[data-ktp-live-outline]').getAttribute('visibility');") !== 'visible');
        $facts['guidance'] = $driver->findElement(WebDriverBy::cssSelector('[data-ktp-live-guidance]'))->getText();
        $check('RED guidance tells the operator what to do', str_contains($facts['guidance'], 'Arahkan KTP'), $facts['guidance']);
        $facts['live_stats'] = $stats();
        $click('[data-ktp-capture]');
        $driver->wait(10, 100)->until(fn () => str_contains($status(), 'tidak terkonfirmasi'));
        $check('a capture with no card passes no corners', str_contains($status(), 'seluruh bingkai disimpan'), $status());
        $check('the camera is released after capture', $streamActive() === false);
        echo json_encode($facts, JSON_PRETTY_PRINT), "\n";
        exit(0);
    }

    $driver->wait(15, 100)->until(fn () => $level() === 'green');
    $facts['first_state_ms'] = $stats()['firstStateMs'] ?? null;
    $check('a clear, steady card reaches GREEN', true);
    $check('eleven field boxes are drawn over the card', $js("return document.querySelectorAll('[data-ktp-live-field]').length;") === 11);
    $facts['labels_visible'] = $js("return [...document.querySelectorAll('[data-ktp-live-field] text')].filter(t => t.getAttribute('visibility') === 'visible').length;");
    $facts['guidance'] = $driver->findElement(WebDriverBy::cssSelector('[data-ktp-live-guidance]'))->getText();
    $check('the indicator text is accessible (role=status)', $js("return document.querySelector('[data-ktp-live-panel]').getAttribute('role');") === 'status');

    $truth = $meta['frameCorners'];
    $facts['alignment'] = $js($alignment, [$truth, $meta['frameWidth'], $meta['frameHeight'], $boxes]);
    $check('the outline sits on the card (<= 2% of card width)', ($facts['alignment']['outlineErrPct'] ?? 99) <= 2.0, $facts['alignment']);
    $check('every field box sits on its template position (<= 2% of card width)', ($facts['alignment']['fieldErrPct'] ?? 99) <= 2.0, $facts['alignment']);

    // Letterbox: a taller box than the video aspect (object-fit: contain).
    $js("document.querySelector('[data-ktp-video]').style.height = '520px';");
    usleep(700_000);
    $facts['alignment_letterbox'] = $js($alignment, [$truth, $meta['frameWidth'], $meta['frameHeight'], $boxes]);
    $check('alignment holds when the video is letterboxed', ($facts['alignment_letterbox']['letterboxY'] ?? 0) > 5 && $facts['alignment_letterbox']['outlineErrPct'] <= 2.0, $facts['alignment_letterbox']);

    // Mirrored preview (CSS transform): the overlay must flip with it.
    $js("document.querySelector('[data-ktp-video]').style.transform = 'scaleX(-1)';");
    usleep(700_000);
    $js("window.dispatchEvent(new Event('resize'));");
    $driver->wait(5, 100)->until(fn () => ($js($alignment, [$truth, $meta['frameWidth'], $meta['frameHeight'], $boxes])['mirrored'] ?? false) === true);
    usleep(600_000);
    $facts['alignment_mirrored'] = $js($alignment, [$truth, $meta['frameWidth'], $meta['frameHeight'], $boxes]);
    $check('alignment holds when the preview is mirrored', $facts['alignment_mirrored']['outlineErrPct'] <= 2.0, $facts['alignment_mirrored']);
    $js("const v=document.querySelector('[data-ktp-video]'); v.style.transform=''; v.style.height='';");
    usleep(500_000);

    $facts['live_stats'] = $stats();
    $check($scenario === 'noworker' ? 'no worker: analysis falls back to the main thread' : 'analysis runs in a worker',
        ($facts['live_stats']['mode'] ?? null) === ($scenario === 'noworker' ? 'main' : 'worker'), $facts['live_stats']);
    $check('analyses never pile up (bounded rate)', ($facts['live_stats']['intervalMs'] ?? 0) >= 200, $facts['live_stats']);

    // ---------------------------------------------------------------- capture
    $heap = [];
    for ($cycle = 1; $cycle <= $cycles; $cycle++) {
        if ($cycle > 1) {
            // After a confirmed read the operator re-opens the camera; the
            // holder's "yes" for this registration still stands (rule 180 §3).
            $click('[data-ktp-camera-open]');
            $driver->wait(10, 100)->until(fn () => $streamActive() === true);
            $check("cycle {$cycle}: re-opening the camera does not ask again", $js("return document.querySelector('[data-ktp-consent]').classList.contains('hidden');") === true);
            $driver->wait(15, 100)->until(fn () => $level() === 'green');
        }
        $captureMs = $jsAsync(<<<'JS'
            const done = arguments[arguments.length - 1];
            const t0 = performance.now();
            document.querySelector('[data-ktp-capture]').click();
            const img = document.querySelector('[data-ktp-preview]');
            const poll = () => (img.getAttribute('src') || '').startsWith('data:image') && img.complete ? done(Math.round(performance.now() - t0)) : setTimeout(poll, 20);
            poll();
        JS);
        $facts['capture_ms'][] = $captureMs;
        $check("cycle {$cycle}: the camera is released after capture", $streamActive() === false);
        $check("cycle {$cycle}: the overlay is gone after capture", $js("const s=document.querySelector('[data-ktp-live-overlay]'); return s.classList.contains('hidden') && s.childElementCount === 0;") === true);
        $check("cycle {$cycle}: capture geometry confirmed on the captured pixels", str_contains($status(), 'terkonfirmasi pada foto'), $status());
        if ($cycle === 1) {
            // "Ulangi" before confirming: the camera and the overlay come back.
            $click('[data-ktp-retake]');
            $driver->wait(10, 100)->until(fn () => $streamActive() === true);
            $driver->wait(15, 100)->until(fn () => $level() === 'green');
            $check('"Ulangi" restarts the live overlay', $js("return document.querySelectorAll('[data-ktp-live-field]').length;") === 11);
            $jsAsync(<<<'JS'
                const done = arguments[arguments.length - 1];
                document.querySelector('[data-ktp-capture]').click();
                const img = document.querySelector('[data-ktp-preview]');
                const poll = () => (img.getAttribute('src') || '').startsWith('data:image') && img.complete ? done(true) : setTimeout(poll, 20);
                poll();
            JS);
            $check('the retaken photo is confirmed on its own pixels', str_contains($status(), 'terkonfirmasi pada foto'), $status());
        }
        // The overlay is never in the photo. The capture is taken at GREEN, and
        // the fictional card holds no pixel of the GREEN stroke colour (#059669),
        // so any such pixel would be burned-in overlay. Measured twice: over the
        // whole photo, and in a band along the card edges, where a burned-in
        // outline would have to lie.
        $facts['overlay_colour_pixels'][] = $jsAsync(<<<'JS'
            const [truth] = arguments; const done = arguments[arguments.length - 1];
            const img = document.querySelector('[data-ktp-preview]');
            const c = document.createElement('canvas'); c.width = img.naturalWidth; c.height = img.naturalHeight;
            const x = c.getContext('2d'); x.drawImage(img, 0, 0);
            const d = x.getImageData(0, 0, c.width, c.height).data;
            const green = (i) => Math.abs(d[i] - 5) < 30 && Math.abs(d[i + 1] - 150) < 30 && Math.abs(d[i + 2] - 105) < 30;
            let all = 0;
            for (let i = 0; i < d.length; i += 4) if (green(i)) all++;
            // Card corners in photo pixels: the crop is the card's bounding box + 6%.
            const xs = truth.map(p => p[0]), ys = truth.map(p => p[1]);
            const mx = (Math.max(...xs) - Math.min(...xs)) * 0.06, my = (Math.max(...ys) - Math.min(...ys)) * 0.06;
            const ox = Math.max(0, Math.floor(Math.min(...xs) - mx)), oy = Math.max(0, Math.floor(Math.min(...ys) - my));
            const q = truth.map(([px, py]) => [px - ox, py - oy]);
            let band = 0, bandGreen = 0;
            for (let e = 0; e < 4; e++) {
              const [a, b] = [q[e], q[(e + 1) % 4]];
              const len = Math.hypot(b[0] - a[0], b[1] - a[1]);
              for (let t = 0; t <= len; t += 2) for (let off = -3; off <= 3; off++) {
                const nx = -(b[1] - a[1]) / len, ny = (b[0] - a[0]) / len;
                const X = Math.round(a[0] + (b[0] - a[0]) * t / len + nx * off), Y = Math.round(a[1] + (b[1] - a[1]) * t / len + ny * off);
                if (X < 0 || Y < 0 || X >= c.width || Y >= c.height) continue;
                band++; if (green((Y * c.width + X) * 4)) bandGreen++;
              }
            }
            // Control: the same measure on a copy with the GREEN outline drawn in
            // must see it — otherwise a zero above would prove nothing.
            x.strokeStyle = '#059669'; x.lineWidth = 2.5; x.beginPath(); q.forEach(([px, py], i) => (i ? x.lineTo(px, py) : x.moveTo(px, py))); x.closePath(); x.stroke();
            const d2 = x.getImageData(0, 0, c.width, c.height).data;
            let controlGreen = 0;
            for (let i = 0; i < d2.length; i += 4) if (Math.abs(d2[i] - 5) < 30 && Math.abs(d2[i + 1] - 150) < 30 && Math.abs(d2[i + 2] - 105) < 30) controlGreen++;
            done({ greenPixels: all, of: c.width * c.height, edgeBandSamples: band, edgeBandGreen: bandGreen, controlGreenWhenBurned: controlGreen, width: c.width, height: c.height });
        JS, [$truth]);
        $last = end($facts['overlay_colour_pixels']);
        $check("cycle {$cycle}: no overlay colour burned into the photo", $last['greenPixels'] === 0 && $last['edgeBandGreen'] === 0 && $last['edgeBandSamples'] > 1000 && $last['controlGreenWhenBurned'] > 1000, $last);
        $check("cycle {$cycle}: the photo is cropped to the card", $last['width'] < $meta['frameWidth'] && $last['width'] > $meta['frameWidth'] * 0.7, $last);

        $click('[data-ktp-confirm]');
        $driver->wait(120, 250)->until(fn () => str_contains($status(), 'dtk') || str_contains($status(), 'gagal') || str_contains($status(), 'tidak dapat'));
        $facts['ocr_status'][] = preg_replace('/\d{6,}/', '#', $status());
        $check("cycle {$cycle}: the field read finished", str_contains($status(), 'dtk'), $status());
        $boundary = $driver->findElement(WebDriverBy::cssSelector('[data-ktp-roi-boundary]'))->getText();
        $check("cycle {$cycle}: the read used the capture-confirmed corners", str_contains($boundary, 'dari bingkai kamera terkonfirmasi'), $boundary);
        $check("cycle {$cycle}: eleven editable boxes after capture", count($driver->findElements(WebDriverBy::cssSelector('[data-ktp-roi-box]'))) === 11);
        // Per-field correctness against the FICTIONAL ground truth — counts only.
        $values = $js(<<<'JS'
            const v = (id) => { const el = document.querySelector(`tr[data-ktp-roi-row="${id}"] td:nth-child(3) input, tr[data-ktp-roi-row="${id}"] td:nth-child(3) select`); return el ? el.value : null; };
            return { nik: v('nik'), name: v('name'), date_of_birth: v('birth'), gender: v('gender'), occupation: v('occupation') };
        JS);
        $norm = fn ($x) => $x === null ? null : strtoupper(trim(preg_replace('/\s+/', ' ', (string) $x)));
        foreach ($meta['truth'] as $field => $expected) {
            $facts['fields_correct'][$field] = ($facts['fields_correct'][$field] ?? 0) + (int) ($norm($values[$field] ?? null) === $norm($expected));
            $facts['fields_empty'][$field] = ($facts['fields_empty'][$field] ?? 0) + (int) (($values[$field] ?? '') === '');
        }
        $heap[] = $js('return performance.memory ? Math.round(performance.memory.usedJSHeapSize / 1048576) : null;');
    }
    $facts['js_heap_mb_after_each_cycle'] = $heap;
    $check('no sustained heap growth across capture/retake cycles', count($heap) < 3 || end($heap) <= $heap[1] + 25, $heap);

    // Per-field retry and apply (existing controls, after a live capture).
    $click('button[data-ktp-roi-retry="name"]');
    $driver->wait(60, 200)->until(fn () => str_contains($status(), 'dibaca ulang') || str_contains($status(), 'gagal'));
    $check('per-field BACA ULANG works after a live capture', str_contains($status(), 'dibaca ulang'), $status());
    $nameCheck = $driver->findElement(WebDriverBy::cssSelector('tr[data-ktp-roi-row="name"] input[type=checkbox]'));
    if (! $nameCheck->isSelected()) {
        $nameCheck->click();
    }
    $formBefore = $driver->findElement(WebDriverBy::cssSelector('form [name="address"]'))->getAttribute('value');
    $click('[data-ktp-apply]');
    $check('a ticked value reaches the form', $driver->findElement(WebDriverBy::cssSelector('form [name="name"]'))->getAttribute('value') !== '');
    $check('operator confirmation becomes mandatory', $js("return document.querySelector('[name=ktp_ocr_verified]').required;") === true);
    $check('registration is not submitted automatically', str_contains($driver->getCurrentURL(), '/settings/patients/create'));

    // One more camera session, closed by a capture: every track must stop.
    $click('[data-ktp-camera-open]');
    $driver->wait(10, 100)->until(fn () => $streamActive() === true);
    $driver->wait(15, 100)->until(fn () => $level() === 'green');
    $click('[data-ktp-capture]');
    $driver->wait(10, 100)->until(fn () => str_contains($status(), 'Periksa foto') || str_contains($status(), 'terkonfirmasi'));
    $check('closing the camera stopped every track', $streamActive() === false);

    // "Hapus" with the camera open: consent resets, so the camera opened under
    // it closes at once and nothing is analysed any more (D7, rule 180 §3).
    $parseBeforeClear = $parseRequests();
    $click('[data-ktp-camera-open]');
    $driver->wait(10, 100)->until(fn () => $streamActive() === true);
    $click('[data-ktp-clear]');
    $driver->wait(5, 100)->until(fn () => $streamActive() === false);
    $check('"Hapus" with the camera open closes the camera', $streamActive() === false);
    $check('"Hapus" ends the live overlay', $js("const s=document.querySelector('[data-ktp-live-overlay]'); return s.classList.contains('hidden') && s.childElementCount === 0 && document.querySelector('[data-ktp-live-panel]').classList.contains('hidden');") === true);
    $click('[data-ktp-camera-open]');
    usleep(600000);
    $check('after "Hapus" the next camera press asks for consent again', $js("return !document.querySelector('[data-ktp-consent]').classList.contains('hidden');") === true && $streamActive() === false);
    $check('"Hapus" sent no parse request', $parseRequests() === $parseBeforeClear);
    $facts['parse_requests_total'] = $parseRequests();

    $logs = array_filter($driver->manage()->getLog('browser'), fn ($l) => $l['level'] === 'SEVERE');
    $facts['console_errors'] = count($logs);
    $check('no console errors', count($logs) === 0, array_map(fn ($l) => mb_substr($l['message'], 0, 160), array_values($logs)));
} finally {
    $driver->quit();
}

echo json_encode($facts, JSON_PRETTY_PRINT), "\n";
