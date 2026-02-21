<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\ParameterDefinition;
use App\Dto\StepDefinition;
use App\Dto\WorkflowDefinition;
use App\Exception\WorkflowNotFoundException;
use App\Service\WorkflowConfigLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class WorkflowConfigLoaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/linker_workflow_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*.yaml');
        if (false !== $files) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        rmdir($this->tmpDir);
    }

    #[Test]
    public function itParsesValidYamlIntoWorkflowDefinition(): void
    {
        file_put_contents($this->tmpDir . '/default.yaml', <<<'YAML'
description: 'Default workflow'
parameters:
    server:
        required: true
        type: string
    message:
        required: false
        type: string
        default: 'No details'
steps:
    - name: alert
      link: server-alert
      parameters:
          server: '{server}'
          status: 'down'
          message: '{message}'
    - name: log
      link: test-slack
      parameters:
          message: 'Alert for {server}'
YAML);

        $loader = new WorkflowConfigLoader($this->tmpDir);
        $workflow = $loader->getWorkflow('default');

        $this->assertInstanceOf(WorkflowDefinition::class, $workflow);
        $this->assertSame('default', $workflow->name);
        $this->assertSame('Default workflow', $workflow->description);
        $this->assertCount(2, $workflow->parameters);
        $this->assertCount(2, $workflow->steps);

        $this->assertInstanceOf(ParameterDefinition::class, $workflow->parameters[0]);
        $this->assertSame('server', $workflow->parameters[0]->name);
        $this->assertTrue($workflow->parameters[0]->required);
        $this->assertNull($workflow->parameters[0]->default);

        $this->assertInstanceOf(ParameterDefinition::class, $workflow->parameters[1]);
        $this->assertSame('message', $workflow->parameters[1]->name);
        $this->assertFalse($workflow->parameters[1]->required);
        $this->assertSame('No details', $workflow->parameters[1]->default);

        $this->assertInstanceOf(StepDefinition::class, $workflow->steps[0]);
        $this->assertSame('alert', $workflow->steps[0]->name);
        $this->assertSame('server-alert', $workflow->steps[0]->link);
        $this->assertSame('{server}', $workflow->steps[0]->parameters['server']);

        $this->assertInstanceOf(StepDefinition::class, $workflow->steps[1]);
        $this->assertSame('log', $workflow->steps[1]->name);
        $this->assertSame('test-slack', $workflow->steps[1]->link);
    }

    #[Test]
    public function itThrowsWorkflowNotFoundExceptionForUnknownWorkflow(): void
    {
        $loader = new WorkflowConfigLoader($this->tmpDir);

        $this->expectException(WorkflowNotFoundException::class);
        $this->expectExceptionMessage('Workflow "nonexistent" not found.');

        $loader->getWorkflow('nonexistent');
    }

    #[Test]
    public function hasWorkflowReturnsTrueForExistingWorkflow(): void
    {
        file_put_contents($this->tmpDir . '/existing.yaml', <<<'YAML'
description: 'Existing'
parameters: {}
steps: []
YAML);

        $loader = new WorkflowConfigLoader($this->tmpDir);

        $this->assertTrue($loader->hasWorkflow('existing'));
    }

    #[Test]
    public function hasWorkflowReturnsFalseForMissingWorkflow(): void
    {
        $loader = new WorkflowConfigLoader($this->tmpDir);

        $this->assertFalse($loader->hasWorkflow('missing'));
    }

    #[Test]
    public function itHandlesEmptyDirectory(): void
    {
        $loader = new WorkflowConfigLoader($this->tmpDir);

        $this->assertSame([], $loader->getAllWorkflows());
    }

    #[Test]
    public function itHandlesNonexistentDirectory(): void
    {
        $loader = new WorkflowConfigLoader('/tmp/nonexistent_dir_' . uniqid());

        $this->assertSame([], $loader->getAllWorkflows());
    }

    #[Test]
    public function getAllWorkflowsReturnsAllParsedWorkflows(): void
    {
        file_put_contents($this->tmpDir . '/workflow-a.yaml', <<<'YAML'
description: 'Workflow A'
parameters: {}
steps: []
YAML);
        file_put_contents($this->tmpDir . '/workflow-b.yaml', <<<'YAML'
description: 'Workflow B'
parameters: {}
steps: []
YAML);

        $loader = new WorkflowConfigLoader($this->tmpDir);
        $all = $loader->getAllWorkflows();

        $this->assertCount(2, $all);
        $this->assertArrayHasKey('workflow-a', $all);
        $this->assertArrayHasKey('workflow-b', $all);
    }

    #[Test]
    public function itCachesResultsInMemory(): void
    {
        file_put_contents($this->tmpDir . '/cached.yaml', <<<'YAML'
description: 'Cached'
parameters: {}
steps: []
YAML);

        $loader = new WorkflowConfigLoader($this->tmpDir);

        $first = $loader->getAllWorkflows();
        $second = $loader->getAllWorkflows();

        $this->assertSame($first, $second);
    }

    #[Test]
    public function itParsesStepParameters(): void
    {
        file_put_contents($this->tmpDir . '/with-params.yaml', <<<'YAML'
description: 'With step params'
parameters: {}
steps:
    - name: notify
      link: deploy-notify
      parameters:
          app: 'myapp'
          version: '1.0.0'
          environment: 'staging'
YAML);

        $loader = new WorkflowConfigLoader($this->tmpDir);
        $workflow = $loader->getWorkflow('with-params');

        $this->assertSame('notify', $workflow->steps[0]->name);
        $this->assertSame('deploy-notify', $workflow->steps[0]->link);
        $this->assertSame('myapp', $workflow->steps[0]->parameters['app']);
        $this->assertSame('1.0.0', $workflow->steps[0]->parameters['version']);
        $this->assertSame('staging', $workflow->steps[0]->parameters['environment']);
    }

    #[Test]
    public function itHandlesStepsWithoutParameters(): void
    {
        file_put_contents($this->tmpDir . '/no-step-params.yaml', <<<'YAML'
description: 'No step params'
parameters: {}
steps:
    - name: simple
      link: test-slack
YAML);

        $loader = new WorkflowConfigLoader($this->tmpDir);
        $workflow = $loader->getWorkflow('no-step-params');

        $this->assertSame([], $workflow->steps[0]->parameters);
    }

    #[Test]
    public function itDefaultsDescriptionToEmptyString(): void
    {
        file_put_contents($this->tmpDir . '/no-desc.yaml', <<<'YAML'
parameters: {}
steps: []
YAML);

        $loader = new WorkflowConfigLoader($this->tmpDir);
        $workflow = $loader->getWorkflow('no-desc');

        $this->assertSame('', $workflow->description);
    }
}
