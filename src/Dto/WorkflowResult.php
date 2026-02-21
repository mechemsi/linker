<?php

declare(strict_types=1);

namespace App\Dto;

readonly class WorkflowResult
{
    /**
     * @param array<string, string> $resolvedParameters
     * @param StepResult[]          $stepResults
     */
    public function __construct(
        public string $workflowName,
        public bool $success,
        public array $resolvedParameters,
        public array $stepResults,
        public ?string $error = null,
    ) {
    }
}
