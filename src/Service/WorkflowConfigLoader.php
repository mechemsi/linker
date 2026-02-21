<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ParameterDefinition;
use App\Dto\StepDefinition;
use App\Dto\WorkflowDefinition;
use App\Exception\WorkflowNotFoundException;
use Symfony\Component\Yaml\Yaml;

class WorkflowConfigLoader
{
    /** @var array<string, WorkflowDefinition>|null */
    private ?array $workflows = null;

    public function __construct(
        private readonly string $workflowsDirectory,
    ) {
    }

    public function getWorkflow(string $name): WorkflowDefinition
    {
        $workflows = $this->loadAll();

        if (!isset($workflows[$name])) {
            throw new WorkflowNotFoundException($name);
        }

        return $workflows[$name];
    }

    public function hasWorkflow(string $name): bool
    {
        return isset($this->loadAll()[$name]);
    }

    /**
     * @return array<string, WorkflowDefinition>
     */
    public function getAllWorkflows(): array
    {
        return $this->loadAll();
    }

    /**
     * @return array<string, WorkflowDefinition>
     */
    private function loadAll(): array
    {
        if (null !== $this->workflows) {
            return $this->workflows;
        }

        $this->workflows = [];

        if (!is_dir($this->workflowsDirectory)) {
            return $this->workflows;
        }

        $files = glob($this->workflowsDirectory . '/*.yaml');

        if (false === $files) {
            return $this->workflows;
        }

        foreach ($files as $file) {
            $name = basename($file, '.yaml');
            $data = Yaml::parseFile($file);

            if (!is_array($data)) {
                continue;
            }

            $this->workflows[$name] = $this->parseWorkflow($name, $data);
        }

        return $this->workflows;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function parseWorkflow(string $name, array $data): WorkflowDefinition
    {
        $parameters = [];
        foreach ($data['parameters'] ?? [] as $paramName => $paramConfig) {
            $parameters[] = new ParameterDefinition(
                name: $paramName,
                required: (bool) ($paramConfig['required'] ?? true),
                type: $paramConfig['type'] ?? 'string',
                default: $paramConfig['default'] ?? null,
            );
        }

        $steps = [];
        foreach ($data['steps'] ?? [] as $index => $stepConfig) {
            if (!isset($stepConfig['name'], $stepConfig['link'])) {
                throw new \InvalidArgumentException(
                    \sprintf('Step #%d in workflow "%s" is missing required "name" or "link" field.', $index, $name),
                );
            }

            $steps[] = new StepDefinition(
                name: $stepConfig['name'],
                link: $stepConfig['link'],
                parameters: $stepConfig['parameters'] ?? [],
            );
        }

        return new WorkflowDefinition(
            name: $name,
            description: $data['description'] ?? '',
            parameters: $parameters,
            steps: $steps,
        );
    }
}
