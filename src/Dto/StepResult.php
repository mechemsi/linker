<?php

declare(strict_types=1);

namespace App\Dto;

readonly class StepResult
{
    /**
     * @param string[] $notifiedTransports
     */
    public function __construct(
        public string $stepName,
        public string $linkName,
        public bool $success,
        public array $notifiedTransports = [],
        public ?string $error = null,
    ) {
    }
}
