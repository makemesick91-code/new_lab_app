<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services\Pdf;

use App\Modules\LegacyRme\Interfaces\LegacyRmePdfInspectorInterface;
use App\Modules\LegacyRme\Support\LegacyRmePdfException;
use App\Modules\LegacyRme\Support\LegacyRmePdfMetadata;

/**
 * LEGACY-RME-PDF-1B — deterministic inspector for tests.
 *
 * Mirrors FakeSatusehatGateway: the fake lives beside the real adapter so the
 * binding is a one-liner and no test has to reach into a foreign namespace.
 * Lets a test drive every branch (page count, encryption, dimensions, failure)
 * without depending on Poppler being installed.
 */
class FakeLegacyRmePdfInspector implements LegacyRmePdfInspectorInterface
{
    private ?LegacyRmePdfException $failure = null;

    private int $pageCount = 1;

    private bool $encrypted = false;

    private float $pageWidth = 595.276;

    private float $pageHeight = 841.89;

    public int $calls = 0;

    public function withPages(int $pageCount): self
    {
        $this->pageCount = $pageCount;

        return $this;
    }

    public function encrypted(bool $encrypted = true): self
    {
        $this->encrypted = $encrypted;

        return $this;
    }

    public function withPageSize(float $width, float $height): self
    {
        $this->pageWidth = $width;
        $this->pageHeight = $height;

        return $this;
    }

    public function failWith(string $failureCode): self
    {
        $this->failure = LegacyRmePdfException::make($failureCode);

        return $this;
    }

    public function inspect(string $absolutePath): LegacyRmePdfMetadata
    {
        $this->calls++;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        $dimensions = [];

        for ($page = 1; $page <= $this->pageCount; $page++) {
            $dimensions[] = ['page' => $page, 'width' => $this->pageWidth, 'height' => $this->pageHeight];
        }

        return LegacyRmePdfMetadata::make($this->pageCount, $this->encrypted, '1.4', $dimensions);
    }
}
