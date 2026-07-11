<?php

namespace App\Modules\LabOrder\Support;

/**
 * Result of the canonical Lab Workflow evidence compression pipeline.
 * Carries the size-before/size-after audit trail persisted on the evidence row.
 */
final class OptimizedEvidenceImage
{
    public function __construct(
        public readonly string $binary,
        public readonly string $mime,
        public readonly string $extension,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly int $originalSize,
        public readonly string $method,
    ) {}

    public function size(): int
    {
        return strlen($this->binary);
    }
}
