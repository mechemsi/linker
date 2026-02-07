<?php

declare(strict_types=1);

namespace App\Dto;

readonly class ParameterDefinition
{
    public function __construct(
        public string $name,
        public bool $required,
        public string $type,
        public ?string $default = null,
    ) {
    }
}
