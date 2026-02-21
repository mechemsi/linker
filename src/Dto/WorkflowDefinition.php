<?php

declare(strict_types=1);

namespace App\Dto;

readonly class WorkflowDefinition
{
    /**
     * @param ParameterDefinition[] $parameters
     * @param StepDefinition[]      $steps
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters,
        public array $steps,
    ) {
    }
}
