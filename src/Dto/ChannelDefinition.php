<?php

declare(strict_types=1);

namespace App\Dto;

readonly class ChannelDefinition
{
    /**
     * @param array<string, string> $options
     */
    public function __construct(
        public string $transport,
        public array $options = [],
    ) {
    }
}
