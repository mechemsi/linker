<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Dto\WorkflowDefinition;
use App\Service\WorkflowConfigLoader;
use App\Tests\Support\FunctionalTester;

class WorkflowConfigLoaderCest
{
    public function defaultWorkflowIsLoadedFromConfig(FunctionalTester $I): void
    {
        $loader = $I->grabService(WorkflowConfigLoader::class);

        $I->assertTrue($loader->hasWorkflow('default'));

        $workflow = $loader->getWorkflow('default');

        $I->assertInstanceOf(WorkflowDefinition::class, $workflow);
        $I->assertSame('default', $workflow->name);
        $I->assertSame('Default notification workflow', $workflow->description);
        $I->assertCount(3, $workflow->parameters);
        $I->assertCount(2, $workflow->steps);
    }

    public function defaultWorkflowHasExpectedParameters(FunctionalTester $I): void
    {
        $loader = $I->grabService(WorkflowConfigLoader::class);
        $workflow = $loader->getWorkflow('default');

        $paramNames = array_map(
            static fn ($p) => $p->name,
            $workflow->parameters,
        );

        $I->assertContains('server', $paramNames);
        $I->assertContains('status', $paramNames);
        $I->assertContains('message', $paramNames);
    }

    public function defaultWorkflowStepsReferenceValidLinks(FunctionalTester $I): void
    {
        $loader = $I->grabService(WorkflowConfigLoader::class);
        $workflow = $loader->getWorkflow('default');

        $I->assertSame('alert', $workflow->steps[0]->name);
        $I->assertSame('server-alert', $workflow->steps[0]->link);

        $I->assertSame('log', $workflow->steps[1]->name);
        $I->assertSame('test-slack', $workflow->steps[1]->link);
    }
}
