<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\StepDefinition;
use App\Dto\StepResult;
use App\Dto\WorkflowResult;
use App\Exception\InvalidParametersException;

class WorkflowExecutor
{
    public function __construct(
        private readonly WorkflowConfigLoader $workflowConfigLoader,
        private readonly LinkNotificationService $linkNotificationService,
    ) {
    }

    /**
     * Executes a workflow by name with the given input parameters.
     *
     * @param array<string, string> $inputParameters
     */
    public function execute(string $workflowName, array $inputParameters): WorkflowResult
    {
        $workflow = $this->workflowConfigLoader->getWorkflow($workflowName);

        try {
            $resolvedParameters = $this->resolveWorkflowParameters($workflow->parameters, $inputParameters);
        } catch (InvalidParametersException $e) {
            return new WorkflowResult(
                workflowName: $workflowName,
                success: false,
                resolvedParameters: [],
                stepResults: [],
                error: $e->getMessage(),
            );
        }

        $stepResults = [];
        $allSuccessful = true;

        foreach ($workflow->steps as $step) {
            $stepResult = $this->executeStep($step, $resolvedParameters);
            $stepResults[] = $stepResult;

            if (!$stepResult->success) {
                $allSuccessful = false;
            }
        }

        return new WorkflowResult(
            workflowName: $workflowName,
            success: $allSuccessful,
            resolvedParameters: $resolvedParameters,
            stepResults: $stepResults,
        );
    }

    /**
     * @param \App\Dto\ParameterDefinition[] $parameterDefinitions
     * @param array<string, string>          $inputParameters
     *
     * @return array<string, string>
     *
     * @throws InvalidParametersException
     */
    private function resolveWorkflowParameters(array $parameterDefinitions, array $inputParameters): array
    {
        $resolved = [];
        $errors = [];

        foreach ($parameterDefinitions as $param) {
            if (isset($inputParameters[$param->name])) {
                $resolved[$param->name] = $inputParameters[$param->name];
            } elseif (!$param->required && null !== $param->default) {
                $resolved[$param->name] = $param->default;
            } elseif ($param->required) {
                $errors[] = \sprintf('Missing required parameter "%s".', $param->name);
            }
        }

        if ([] !== $errors) {
            throw new InvalidParametersException($errors);
        }

        return $resolved;
    }

    /**
     * @param array<string, string> $resolvedParameters
     */
    private function executeStep(StepDefinition $step, array $resolvedParameters): StepResult
    {
        $stepParams = $this->interpolateStepParameters($step->parameters, $resolvedParameters);

        try {
            $notified = $this->linkNotificationService->send($step->link, $stepParams);

            return new StepResult(
                stepName: $step->name,
                linkName: $step->link,
                success: true,
                notifiedTransports: $notified,
            );
        } catch (\Throwable $e) {
            return new StepResult(
                stepName: $step->name,
                linkName: $step->link,
                success: false,
                error: $e->getMessage(),
            );
        }
    }

    /**
     * Replaces {placeholder} tokens in step parameter values with resolved workflow parameters.
     *
     * @param array<string, string> $stepParameters
     * @param array<string, string> $resolvedParameters
     *
     * @return array<string, string>
     */
    private function interpolateStepParameters(array $stepParameters, array $resolvedParameters): array
    {
        $search = [];
        $replace = [];

        foreach ($resolvedParameters as $paramName => $paramValue) {
            $search[] = '{' . $paramName . '}';
            $replace[] = $paramValue;
        }

        $interpolated = [];

        foreach ($stepParameters as $key => $value) {
            $interpolated[$key] = str_replace($search, $replace, $value);
        }

        return $interpolated;
    }
}
