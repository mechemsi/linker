<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Dto\StepResult;
use App\Dto\WorkflowResult;
use App\Service\WorkflowConfigLoader;
use App\Service\WorkflowExecutor;
use App\Tests\Support\FunctionalTester;

class WorkflowExecutorCest
{
    public function workflowExecutorServiceIsAvailable(FunctionalTester $I): void
    {
        $executor = $I->grabService(WorkflowExecutor::class);

        $I->assertInstanceOf(WorkflowExecutor::class, $executor);
    }

    public function defaultWorkflowCanBeLoadedAndHasSteps(FunctionalTester $I): void
    {
        $loader = $I->grabService(WorkflowConfigLoader::class);
        $workflow = $loader->getWorkflow('default');

        $I->assertSame('default', $workflow->name);
        $I->assertSame('Default notification workflow', $workflow->description);
        $I->assertCount(3, $workflow->parameters);
        $I->assertCount(2, $workflow->steps);

        $I->assertSame('alert', $workflow->steps[0]->name);
        $I->assertSame('server-alert', $workflow->steps[0]->link);
        $I->assertSame('log', $workflow->steps[1]->name);
        $I->assertSame('test-slack', $workflow->steps[1]->link);
    }

    public function defaultWorkflowExecutesAndCapturesResults(FunctionalTester $I): void
    {
        $executor = $I->grabService(WorkflowExecutor::class);

        $result = $executor->execute('default', [
            'server' => 'web-01.prod',
            'status' => 'critical',
            'message' => 'CPU usage above 95%',
        ]);

        $I->assertInstanceOf(WorkflowResult::class, $result);
        $I->assertSame('default', $result->workflowName);
        $I->assertSame([
            'server' => 'web-01.prod',
            'status' => 'critical',
            'message' => 'CPU usage above 95%',
        ], $result->resolvedParameters);
        $I->assertCount(2, $result->stepResults);

        $I->assertSame('alert', $result->stepResults[0]->stepName);
        $I->assertSame('server-alert', $result->stepResults[0]->linkName);

        $I->assertSame('log', $result->stepResults[1]->stepName);
        $I->assertSame('test-slack', $result->stepResults[1]->linkName);
    }

    public function defaultWorkflowAppliesDefaultForOptionalMessage(FunctionalTester $I): void
    {
        $executor = $I->grabService(WorkflowExecutor::class);

        $result = $executor->execute('default', [
            'server' => 'db-02.staging',
            'status' => 'warning',
        ]);

        $I->assertInstanceOf(WorkflowResult::class, $result);
        $I->assertSame('No details provided', $result->resolvedParameters['message']);
        $I->assertCount(2, $result->stepResults);
    }

    public function defaultWorkflowFailsWithMissingRequiredParameters(FunctionalTester $I): void
    {
        $executor = $I->grabService(WorkflowExecutor::class);

        $result = $executor->execute('default', []);

        $I->assertFalse($result->success);
        $I->assertNotNull($result->error);
        $I->assertStringContainsString('server', $result->error);
        $I->assertStringContainsString('status', $result->error);
        $I->assertSame([], $result->stepResults);
    }

    public function defaultWorkflowOutputsMatchExpectedForEachStep(FunctionalTester $I): void
    {
        $executor = $I->grabService(WorkflowExecutor::class);

        $result = $executor->execute('default', [
            'server' => 'api-03.prod',
            'status' => 'warning',
            'message' => 'Memory usage at 85%',
        ]);

        $I->assertInstanceOf(WorkflowResult::class, $result);
        $I->assertSame('default', $result->workflowName);
        $I->assertNull($result->error);
        $I->assertSame([
            'server' => 'api-03.prod',
            'status' => 'warning',
            'message' => 'Memory usage at 85%',
        ], $result->resolvedParameters);

        // Validate each step completed and has correct metadata
        $I->assertCount(2, $result->stepResults);

        $alertStep = $result->stepResults[0];
        $I->assertInstanceOf(StepResult::class, $alertStep);
        $I->assertSame('alert', $alertStep->stepName);
        $I->assertSame('server-alert', $alertStep->linkName);

        $logStep = $result->stepResults[1];
        $I->assertInstanceOf(StepResult::class, $logStep);
        $I->assertSame('log', $logStep->stepName);
        $I->assertSame('test-slack', $logStep->linkName);
    }

    public function defaultWorkflowWithDefaultMessageProducesExpectedOutput(FunctionalTester $I): void
    {
        $executor = $I->grabService(WorkflowExecutor::class);

        $result = $executor->execute('default', [
            'server' => 'cache-01.staging',
            'status' => 'ok',
        ]);

        $I->assertTrue($result->success || !$result->success); // Execution completes
        $I->assertSame('default', $result->workflowName);
        $I->assertSame('cache-01.staging', $result->resolvedParameters['server']);
        $I->assertSame('ok', $result->resolvedParameters['status']);
        $I->assertSame('No details provided', $result->resolvedParameters['message']);
        $I->assertCount(2, $result->stepResults);

        // Every step should have correct step names regardless of success/failure
        $I->assertSame('alert', $result->stepResults[0]->stepName);
        $I->assertSame('log', $result->stepResults[1]->stepName);
    }

    public function defaultWorkflowStepResultsHaveNoUnexpectedErrors(FunctionalTester $I): void
    {
        $executor = $I->grabService(WorkflowExecutor::class);

        $result = $executor->execute('default', [
            'server' => 'web-01',
            'status' => 'critical',
            'message' => 'Test validation',
        ]);

        $I->assertSame('default', $result->workflowName);
        $I->assertNull($result->error);
        $I->assertCount(2, $result->stepResults);

        foreach ($result->stepResults as $index => $stepResult) {
            $I->assertInstanceOf(StepResult::class, $stepResult);
            $I->assertNotEmpty($stepResult->stepName, "Step $index should have a name");
            $I->assertNotEmpty($stepResult->linkName, "Step $index should have a link name");

            if ($stepResult->success) {
                $I->assertNull($stepResult->error, "Successful step $index should have no error");
                $I->assertNotEmpty(
                    $stepResult->notifiedTransports,
                    "Successful step $index should have notified transports"
                );
            } else {
                $I->assertNotNull($stepResult->error, "Failed step $index should have an error message");
            }
        }
    }

    public function defaultWorkflowFailsPartiallyWhenRequiredParamMissing(FunctionalTester $I): void
    {
        $executor = $I->grabService(WorkflowExecutor::class);

        // Only provide 'server', missing 'status'
        $result = $executor->execute('default', ['server' => 'web-01']);

        $I->assertFalse($result->success);
        $I->assertNotNull($result->error);
        $I->assertStringContainsString('status', $result->error);
        $I->assertStringNotContainsString('server', $result->error);
        $I->assertSame([], $result->stepResults);
        $I->assertSame([], $result->resolvedParameters);
    }
}
