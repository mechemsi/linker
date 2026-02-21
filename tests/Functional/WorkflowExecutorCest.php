<?php

declare(strict_types=1);

namespace App\Tests\Functional;

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
}
