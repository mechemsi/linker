<?php

declare(strict_types=1);

namespace App\Dto;

readonly class StepDefinition
{
    /**
     * @param array<string, string> $parameters
     */
    public function __construct(
        public string $name,
        public string $link,
        public array $parameters = [],
    ) {
    }
}
