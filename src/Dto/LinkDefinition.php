<?php

declare(strict_types=1);

namespace App\Dto;

readonly class LinkDefinition
{
    /**
     * @param ParameterDefinition[] $parameters
     * @param ChannelDefinition[]   $channels
     */
    public function __construct(
        public string $name,
        public string $messageTemplate,
        public array $parameters,
        public array $channels,
    ) {
    }
}
