<?php

/**
 * REVISION-PATIENT-KTP-OCR-FIELD-BASED-ROI-1 — real-browser walk-through of the
 * field-based KTP read (dev only; never against production).
 *
 * Drives headless Chrome through the operator flow on a LOCAL server: log in,
 * upload a FICTIONAL synthetic KTP through the manual-upload control, wait for
 * the hybrid read, then exercise every operator control — drag/resize a box,
 * keyboard nudge, accept a value, BACA ULANG (the accepted value must survive),
 * pick a conflict alternative, apply to the form, open/close the corner editor,
 * and clear a photo mid-read (the next read must still complete).
 *
 *   php browser_e2e.php <base_url> <email> <password> <image.jpg> [width] [height] [driver_url]
 *
 * Prints one JSON object of measured facts; exits non-zero on the first failed check.
 * The server must run the pilot gate armed for <email> (local env only).
 */

require __DIR__.'/../../vendor/autoload.php';

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Interactions\WebDriverActions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\WebDriverKeys;

[$script, $base, $email, $password, $image] = array_pad($argv, 5, null);
$width = (int) ($argv[5] ?? 1366);
$height = (int) ($argv[6] ?? 900);
$driverUrl = $argv[7] ?? 'http://127.0.0.1:9517';
if (! $base || ! $email || ! $password || ! is_file((string) $image)) {
    fwrite(STDERR, "usage: php browser_e2e.php <base_url> <email> <password> <image.jpg> [width] [height] [driver_url]\n");
    exit(2);
}
if (! preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?$#', $base)) {
    fwrite(STDERR, "refusing: the walk-through only runs against a local server\n");
    exit(2);
}

$facts = ['viewport' => "{$width}x{$height}", 'checks' => []];
$check = function (string $name, bool $ok, $detail = null) use (&$facts) {
    $facts['checks'][] = ['check' => $name, 'ok' => $ok] + ($detail === null ? [] : ['detail' => $detail]);
    if (! $ok) {
        echo json_encode($facts, JSON_PRETTY_PRINT), "\n";
        exit(1);
    }
};

$options = (new ChromeOptions)->addArguments(['--headless=new', '--disable-gpu', "--window-size={$width},{$height}", '--force-device-scale-factor=1']);
$caps = DesiredCapabilities::chrome();
$caps->setCapability(ChromeOptions::CAPABILITY, $options);
$caps->setCapability('goog:loggingPrefs', ['browser' => 'ALL']);
$driver = RemoteWebDriver::create($driverUrl, $caps);
$scroll = fn ($el) => $driver->executeScript('arguments[0].scrollIntoView({block: "center", inline: "center"});', [$el]);

try {
    $driver->get($base.'/login');
    $driver->findElement(WebDriverBy::name('email'))->sendKeys($email);
    $driver->findElement(WebDriverBy::name('password'))->sendKeys($password);
    $driver->findElement(WebDriverBy::cssSelector('form button[type=submit]'))->click();
    $driver->wait(15)->until(fn () => ! str_contains($driver->getCurrentURL(), '/login'));

    $driver->get($base.'/settings/patients/create');
    $driver->wait(15)->until(WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::cssSelector('[data-ktp-scan]')));
    $root = $driver->findElement(WebDriverBy::cssSelector('[data-ktp-scan]'));
    $check('pilot operator gets OCR', $root->getAttribute('data-ocr-enabled') === '1');
    $check('camera control rendered', count($driver->findElements(WebDriverBy::cssSelector('[data-ktp-camera-open]'))) === 1);

    $driver->findElement(WebDriverBy::cssSelector('[data-ktp-scan] details summary'))->click();
    $driver->findElement(WebDriverBy::cssSelector('[data-ktp-manual]'))->sendKeys(realpath($image));
    $driver->wait(10)->until(WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::cssSelector('[data-ktp-confirm]')));

    $driver->findElement(WebDriverBy::cssSelector('[data-ktp-confirm]'))->click();
    // PHASE-3 (decision D7): OCR never starts before the KTP holder's answer.
    // The fictional card has no holder; this local walk-through records a yes.
    $driver->wait(15)->until(WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::cssSelector('[data-ktp-consent-accept]')));
    $check('consent asked before OCR', count($driver->findElements(WebDriverBy::cssSelector('tr[data-ktp-roi-row]'))) === 0);
    $started = microtime(true);
    $driver->findElement(WebDriverBy::cssSelector('[data-ktp-consent-accept]'))->click();
    $status = fn () => $driver->findElement(WebDriverBy::cssSelector('[data-ktp-status]'))->getText();
    $driver->wait(90, 200)->until(fn () => str_contains($status(), 'dtk') || str_contains($status(), 'gagal') || str_contains($status(), 'tidak dapat'));
    $facts['read_seconds_wall'] = round(microtime(true) - $started, 2);
    $facts['status'] = $status();
    $check('hybrid read finished', str_contains($facts['status'], 'dtk'), $facts['status']);

    $rows = $driver->findElements(WebDriverBy::cssSelector('tr[data-ktp-roi-row]'));
    $check('one row per verification row', count($rows) === 12, count($rows));
    $boxes = $driver->findElements(WebDriverBy::cssSelector('[data-ktp-roi-box]'));
    $check('one editable box per field', count($boxes) === 11, count($boxes));
    $thumbs = $driver->executeScript("return [...document.querySelectorAll('canvas[data-ktp-roi-thumb]')].map(c => c.width > 1 && c.height > 1).filter(Boolean).length;");
    $check('every field crop is drawn', $thumbs === 11, $thumbs);
    $facts['boundary_message'] = $driver->findElement(WebDriverBy::cssSelector('[data-ktp-roi-boundary]'))->getText();

    $facts['rows'] = $driver->executeScript(<<<'JS'
        return [...document.querySelectorAll('tr[data-ktp-roi-row]')].map(tr => {
            const input = tr.querySelector('input:not([type=checkbox]):not([type=radio]), select');
            const mono = tr.querySelector('td:nth-child(3) span.font-mono');
            return {
                row: tr.dataset.ktpRoiRow,
                value: input ? input.value : (mono ? mono.textContent : null),
                status: tr.querySelector('td:nth-child(4) span')?.textContent ?? null,
                applyChecked: tr.querySelector('input[type=checkbox]')?.checked ?? null,
                alternatives: tr.querySelectorAll('input[type=radio]').length,
            };
        });
    JS);

    // Drag the "name" box right and down; its normalized position must change
    // and stay inside the card.
    $nameBox = $driver->findElement(WebDriverBy::cssSelector('[data-ktp-roi-box="name"]'));
    $before = $nameBox->getAttribute('style');
    $scroll($nameBox);
    (new WebDriverActions($driver))->clickAndHold($nameBox)->moveByOffset(25, 8)->release()->perform();
    $after = $nameBox->getAttribute('style');
    $check('dragging moves the box', $before !== $after, ['before' => $before, 'after' => $after]);
    $inside = $driver->executeScript("const b=document.querySelector('[data-ktp-roi-box=\"name\"]'); const s=b.parentElement.getBoundingClientRect(), r=b.getBoundingClientRect(); return r.left>=s.left-1 && r.right<=s.right+1 && r.top>=s.top-1 && r.bottom<=s.bottom+1;");
    $check('the dragged box stays inside the card', $inside === true);

    // Resize from the south-east handle of the selected box.
    $handle = $driver->findElement(WebDriverBy::cssSelector('[data-ktp-roi-box="name"] [data-ktp-roi-handle="se"]'));
    $scroll($handle);
    $w0 = $driver->executeScript("return document.querySelector('[data-ktp-roi-box=\"name\"]').style.width;");
    (new WebDriverActions($driver))->clickAndHold($handle)->moveByOffset(30, 0)->release()->perform();
    $w1 = $driver->executeScript("return document.querySelector('[data-ktp-roi-box=\"name\"]').style.width;");
    $check('resizing changes the box width', $w0 !== $w1, ['before' => $w0, 'after' => $w1]);

    // Keyboard: arrow keys move a focused box.
    $nik = $driver->findElement(WebDriverBy::cssSelector('[data-ktp-roi-box="nik"]'));
    $l0 = $nik->getAttribute('style');
    $driver->executeScript("document.querySelector('[data-ktp-roi-box=\"nik\"]').focus();");
    $driver->getKeyboard()->sendKeys(WebDriverKeys::ARROW_RIGHT);
    $check('arrow key nudges the focused box', $nik->getAttribute('style') !== $l0);

    // The operator accepts the name, then retries it: the accepted value must
    // survive. A tick the system set is not an acceptance (rule 179 §9), so a
    // pre-ticked row is confirmed the way an operator would: untick, re-tick.
    $nameInput = $driver->findElement(WebDriverBy::cssSelector('tr[data-ktp-roi-row="name"] td:nth-child(3) input'));
    $nameCheck = $driver->findElement(WebDriverBy::cssSelector('tr[data-ktp-roi-row="name"] input[type=checkbox]'));
    if ($nameCheck->isSelected()) {
        $nameCheck->click();
    }
    $nameCheck->click();
    $check('the operator ticked the name', $nameCheck->isSelected());
    $accepted = $nameInput->getAttribute('value');
    $retryStart = microtime(true);
    $driver->findElement(WebDriverBy::cssSelector('button[data-ktp-roi-retry="name"]'))->click();
    $driver->wait(60, 200)->until(fn () => str_contains($status(), 'dibaca ulang') || str_contains($status(), 'gagal'));
    $facts['retry_seconds_wall'] = round(microtime(true) - $retryStart, 2);
    $check('BACA ULANG finished', str_contains($status(), 'dibaca ulang'), $status());
    $nameAfter = $driver->findElement(WebDriverBy::cssSelector('tr[data-ktp-roi-row="name"] td:nth-child(3) input'))->getAttribute('value');
    $check('an accepted value survives BACA ULANG', $nameAfter === $accepted, ['accepted' => $accepted, 'after' => $nameAfter]);
    $facts['pending_offered_after_retry'] = $driver->findElement(WebDriverBy::cssSelector('[data-ktp-roi-pending="name"]'))->getText();

    // A conflict, when present, is resolved by the operator's explicit choice.
    $facts['conflict_choices_offered'] = count($driver->findElements(WebDriverBy::cssSelector('tr[data-ktp-roi-row] input[type=radio]')));
    // A row the operator has not touched (the name row was accepted above).
    $radios = $driver->findElements(WebDriverBy::cssSelector('tr[data-ktp-roi-row]:not([data-ktp-roi-row="name"]) input[type=radio]'));
    if (count($radios) > 0) {
        $row = $radios[0]->findElement(WebDriverBy::xpath('ancestor::tr'));
        $rowId = $row->getAttribute('data-ktp-roi-row');
        $wasChecked = $row->findElement(WebDriverBy::cssSelector('input[type=checkbox]'))->isSelected();
        $check('a conflict is never pre-selected', $wasChecked === false);
        $scroll($radios[0]);
        $radios[0]->click();
        $row = $driver->findElement(WebDriverBy::cssSelector("tr[data-ktp-roi-row=\"{$rowId}\"]"));
        $check('choosing an alternative fills and accepts the row',
            $row->findElement(WebDriverBy::cssSelector('input[type=checkbox]'))->isSelected()
            && $row->findElement(WebDriverBy::cssSelector('td:nth-child(3) input, td:nth-child(3) select'))->getAttribute('value') !== '');
    }

    // Apply to the form: values land in the real inputs and verification becomes required.
    $apply = $driver->findElement(WebDriverBy::cssSelector('[data-ktp-apply]'));
    $scroll($apply);
    $apply->click();
    $formName = $driver->findElement(WebDriverBy::cssSelector('form [name="name"]'))->getAttribute('value');
    $check('applied value reaches the patient form', $formName === $accepted, ['form' => $formName]);
    $required = $driver->executeScript("return document.querySelector('[name=ktp_ocr_verified]').required && !document.querySelector('[data-ktp-ocr-verify-wrap]').classList.contains('hidden');");
    $check('operator confirmation becomes mandatory', $required === true);
    $check('applied marker is set', $driver->findElement(WebDriverBy::cssSelector('[data-ktp-ocr-applied]'))->getAttribute('value') === '1');

    // Corner editor: four handles on the original photo; cancel leaves it closed.
    $open = $driver->findElement(WebDriverBy::cssSelector('[data-ktp-roi-corners-open]'));
    $scroll($open);
    $open->click();
    $corners = $driver->findElements(WebDriverBy::cssSelector('[data-ktp-roi-corner]'));
    $check('corner editor shows four handles', count($corners) === 4, count($corners));
    $c0 = $corners[0]->getAttribute('style');
    $scroll($corners[0]);
    (new WebDriverActions($driver))->clickAndHold($corners[0])->moveByOffset(10, 10)->release()->perform();
    $check('a corner handle can be dragged', $corners[0]->getAttribute('style') !== $c0);
    $cancel = $driver->findElement(WebDriverBy::cssSelector('[data-ktp-roi-corners-cancel]'));
    $scroll($cancel);
    $cancel->click();
    $check('cancel closes the corner editor', $driver->executeScript("return document.querySelector('[data-ktp-roi-corner-editor]').classList.contains('hidden');") === true);

    $facts['js_heap_mb'] = $driver->executeScript('return performance.memory ? Math.round(performance.memory.usedJSHeapSize / 1048576) : null;');

    // Clearing the photo while a read is still running must not block the next
    // read: the session is closed mid-flight and every step is raced against it.
    $uploadAndConfirm = function () use ($driver, $image, $scroll): void {
        if ($driver->findElement(WebDriverBy::cssSelector('[data-ktp-scan] details'))->getAttribute('open') === null) {
            $driver->findElement(WebDriverBy::cssSelector('[data-ktp-scan] details summary'))->click();
        }
        $driver->findElement(WebDriverBy::cssSelector('[data-ktp-manual]'))->sendKeys(realpath($image));
        $driver->wait(10)->until(WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::cssSelector('[data-ktp-confirm]')));
        $confirm = $driver->findElement(WebDriverBy::cssSelector('[data-ktp-confirm]'));
        $scroll($confirm);
        $confirm->click();
    };
    $uploadAndConfirm();
    $driver->wait(30, 50)->until(fn () => preg_match('/Membaca|Mendeteksi|Meluruskan/', $status()) === 1);
    $clear = $driver->findElement(WebDriverBy::cssSelector('[data-ktp-clear]'));
    $scroll($clear);
    $clear->click();
    usleep(300000);
    $uploadAndConfirm();
    // PHASE-3 (D7): clearing the photo may mean a different KTP holder, so the
    // answer is asked again before the next read.
    $driver->wait(15)->until(WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::cssSelector('[data-ktp-consent-accept]')));
    $check('clearing the photo asks for consent again', true);
    $accept = $driver->findElement(WebDriverBy::cssSelector('[data-ktp-consent-accept]'));
    $scroll($accept);
    $accept->click();
    $driver->wait(90, 200)->until(fn () => str_contains($status(), 'dtk') || str_contains($status(), 'gagal') || str_contains($status(), 'tidak dapat'));
    $check('a read cancelled mid-way does not block the next one', str_contains($status(), 'dtk'), $status());

    $facts['js_heap_mb_after_three_reads'] = $driver->executeScript('return performance.memory ? Math.round(performance.memory.usedJSHeapSize / 1048576) : null;');
    $facts['no_horizontal_page_scroll'] = $driver->executeScript('return document.documentElement.scrollWidth <= window.innerWidth + 1;');
    $logs = $driver->manage()->getLog('browser');
    $errors = array_values(array_filter($logs, fn ($l) => ($l['level'] ?? '') === 'SEVERE'));
    $facts['console_errors'] = array_map(fn ($l) => mb_substr((string) $l['message'], 0, 160), $errors);
    $check('no console errors', $errors === [], $facts['console_errors']);
    $facts['all_checks_passed'] = true;
} catch (Throwable $e) {
    $facts['error'] = get_class($e).': '.mb_substr($e->getMessage(), 0, 300);
    echo json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    $driver->quit();
    exit(1);
} finally {
    $driver->quit();
}

echo json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
