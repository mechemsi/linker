<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\ChannelDefinition;
use App\Dto\LinkDefinition;
use App\Dto\ParameterDefinition;
use App\Dto\StepDefinition;
use App\Dto\StepResult;
use App\Dto\WorkflowDefinition;
use App\Dto\WorkflowResult;
use App\Exception\WorkflowNotFoundException;
use App\Service\LinkNotificationService;
use App\Service\WorkflowConfigLoader;
use App\Service\WorkflowExecutor;
use App\Tests\Support\WorkflowFixtures;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class WorkflowExecutorTest extends TestCase
{
    #[Test]
    public function itExecutesSingleStepWorkflowSuccessfully(): void
    {
        $workflow = new WorkflowDefinition(
            name: 'test',
            description: 'Test workflow',
            parameters: [new ParameterDefinition('message', true, 'string')],
            steps: [new StepDefinition('notify', 'test-slack', ['message' => '{message}'])],
        );

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->once())
            ->method('send')
            ->with('test-slack', ['message' => 'Hello world'])
            ->willReturn(['slack-webhook']);

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $result = $executor->execute('test', ['message' => 'Hello world']);

        $this->assertInstanceOf(WorkflowResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertSame('test', $result->workflowName);
        $this->assertSame(['message' => 'Hello world'], $result->resolvedParameters);
        $this->assertNull($result->error);
        $this->assertCount(1, $result->stepResults);
        $this->assertTrue($result->stepResults[0]->success);
        $this->assertSame('notify', $result->stepResults[0]->stepName);
        $this->assertSame('test-slack', $result->stepResults[0]->linkName);
        $this->assertSame(['slack-webhook'], $result->stepResults[0]->notifiedTransports);
    }

    #[Test]
    public function itExecutesMultiStepWorkflowSuccessfully(): void
    {
        $workflow = new WorkflowDefinition(
            name: 'multi',
            description: 'Multi-step',
            parameters: [
                new ParameterDefinition('server', true, 'string'),
                new ParameterDefinition('status', true, 'string'),
            ],
            steps: [
                new StepDefinition('alert', 'server-alert', [
                    'server' => '{server}',
                    'status' => '{status}',
                    'message' => 'Alert triggered',
                ]),
                new StepDefinition('log', 'test-slack', [
                    'message' => '[{status}] Server {server}',
                ]),
            ],
        );

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(static function (string $linkName) {
                return match ($linkName) {
                    'server-alert' => ['slack', 'telegram'],
                    'test-slack' => ['slack-webhook'],
                    default => [],
                };
            });

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $result = $executor->execute('multi', ['server' => 'web-01', 'status' => 'critical']);

        $this->assertTrue($result->success);
        $this->assertCount(2, $result->stepResults);
        $this->assertTrue($result->stepResults[0]->success);
        $this->assertSame(['slack', 'telegram'], $result->stepResults[0]->notifiedTransports);
        $this->assertTrue($result->stepResults[1]->success);
        $this->assertSame(['slack-webhook'], $result->stepResults[1]->notifiedTransports);
    }

    #[Test]
    public function itResolvesOptionalParametersWithDefaults(): void
    {
        $workflow = new WorkflowDefinition(
            name: 'defaults',
            description: 'With defaults',
            parameters: [
                new ParameterDefinition('server', true, 'string'),
                new ParameterDefinition('message', false, 'string', 'No details'),
            ],
            steps: [new StepDefinition('notify', 'test-slack', ['message' => '{server}: {message}'])],
        );

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->once())
            ->method('send')
            ->with('test-slack', ['message' => 'web-01: No details'])
            ->willReturn(['slack-webhook']);

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $result = $executor->execute('defaults', ['server' => 'web-01']);

        $this->assertTrue($result->success);
        $this->assertSame(['server' => 'web-01', 'message' => 'No details'], $result->resolvedParameters);
    }

    #[Test]
    public function itFailsWithMissingRequiredParameters(): void
    {
        $workflow = new WorkflowDefinition(
            name: 'required',
            description: 'Required params',
            parameters: [
                new ParameterDefinition('server', true, 'string'),
                new ParameterDefinition('status', true, 'string'),
            ],
            steps: [new StepDefinition('notify', 'test-slack', ['message' => '{server}'])],
        );

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->never())->method('send');

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $result = $executor->execute('required', []);

        $this->assertFalse($result->success);
        $this->assertSame([], $result->stepResults);
        $this->assertNotNull($result->error);
        $this->assertStringContainsString('server', $result->error);
        $this->assertStringContainsString('status', $result->error);
    }

    #[Test]
    public function itCapturesStepFailureWithoutAbortingRemainingSteps(): void
    {
        $workflow = new WorkflowDefinition(
            name: 'partial-fail',
            description: 'Partial failure',
            parameters: [new ParameterDefinition('msg', true, 'string')],
            steps: [
                new StepDefinition('failing', 'bad-link', ['message' => '{msg}']),
                new StepDefinition('succeeding', 'test-slack', ['message' => '{msg}']),
            ],
        );

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(static function (string $linkName) {
                if ('bad-link' === $linkName) {
                    throw new \RuntimeException('Link "bad-link" not found.');
                }

                return ['slack-webhook'];
            });

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $result = $executor->execute('partial-fail', ['msg' => 'test']);

        $this->assertFalse($result->success);
        $this->assertCount(2, $result->stepResults);

        $this->assertFalse($result->stepResults[0]->success);
        $this->assertSame('Link "bad-link" not found.', $result->stepResults[0]->error);
        $this->assertSame([], $result->stepResults[0]->notifiedTransports);

        $this->assertTrue($result->stepResults[1]->success);
        $this->assertSame(['slack-webhook'], $result->stepResults[1]->notifiedTransports);
    }

    #[Test]
    public function itPropagatesWorkflowNotFoundException(): void
    {
        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')
            ->willThrowException(new WorkflowNotFoundException('nonexistent'));

        $notificationService = $this->createStub(LinkNotificationService::class);

        $executor = new WorkflowExecutor($configLoader, $notificationService);

        $this->expectException(WorkflowNotFoundException::class);
        $this->expectExceptionMessage('Workflow "nonexistent" not found.');

        $executor->execute('nonexistent', []);
    }

    #[Test]
    public function itInterpolatesMultipleParametersInStepValues(): void
    {
        $workflow = new WorkflowDefinition(
            name: 'interpolation',
            description: 'Interpolation test',
            parameters: [
                new ParameterDefinition('server', true, 'string'),
                new ParameterDefinition('status', true, 'string'),
                new ParameterDefinition('message', false, 'string', 'N/A'),
            ],
            steps: [new StepDefinition('notify', 'test-slack', [
                'message' => '[{status}] Server {server}: {message}',
            ])],
        );

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->once())
            ->method('send')
            ->with('test-slack', ['message' => '[critical] Server web-01: CPU high'])
            ->willReturn(['slack-webhook']);

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $result = $executor->execute('interpolation', [
            'server' => 'web-01',
            'status' => 'critical',
            'message' => 'CPU high',
        ]);

        $this->assertTrue($result->success);
    }

    #[Test]
    public function itHandlesWorkflowWithNoSteps(): void
    {
        $workflow = new WorkflowDefinition(
            name: 'empty',
            description: 'No steps',
            parameters: [],
            steps: [],
        );

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->never())->method('send');

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $result = $executor->execute('empty', []);

        $this->assertTrue($result->success);
        $this->assertSame([], $result->stepResults);
    }

    #[Test]
    public function itHandlesSpecialCharactersInParameterValues(): void
    {
        $workflow = new WorkflowDefinition(
            name: 'special',
            description: 'Special chars',
            parameters: [
                new ParameterDefinition('server', true, 'string'),
                new ParameterDefinition('status', true, 'string'),
                new ParameterDefinition('message', true, 'string'),
            ],
            steps: [new StepDefinition('notify', 'test-slack', [
                'message' => '{server} - {status}: {message}',
            ])],
        );

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $input = WorkflowFixtures::specialCharactersInput();

        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->once())
            ->method('send')
            ->with('test-slack', [
                'message' => 'web-01.prod (primary) - error & critical: Disk usage: 99% — /var/log full <alert>',
            ])
            ->willReturn(['slack-webhook']);

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $result = $executor->execute('special', $input);

        $this->assertTrue($result->success);
        $this->assertSame($input, $result->resolvedParameters);
    }

    #[Test]
    public function itExecutesDefaultWorkflowWithFixtureInput(): void
    {
        $loader = new WorkflowConfigLoader(WorkflowFixtures::fixturesPath());
        $workflow = $loader->getWorkflow('complete');

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->exactly(2))
            ->method('send')
            ->willReturn(['slack']);

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $input = WorkflowFixtures::completeWorkflowInput();
        $result = $executor->execute('complete', $input);

        $this->assertTrue($result->success);
        $this->assertSame('complete', $result->workflowName);
        $this->assertSame($input, $result->resolvedParameters);
        $this->assertCount(2, $result->stepResults);

        foreach ($result->stepResults as $stepResult) {
            $this->assertInstanceOf(StepResult::class, $stepResult);
            $this->assertTrue($stepResult->success);
            $this->assertNull($stepResult->error);
        }
    }

    #[Test]
    public function itExecutesCompleteWorkflowWithDefaultParameters(): void
    {
        $loader = new WorkflowConfigLoader(WorkflowFixtures::fixturesPath());
        $workflow = $loader->getWorkflow('complete');

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->exactly(2))
            ->method('send')
            ->willReturn(['slack']);

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $input = WorkflowFixtures::completeWorkflowInputWithDefaults();
        $result = $executor->execute('complete', $input);

        $this->assertTrue($result->success);
        $this->assertArrayHasKey('message', $result->resolvedParameters);
        $this->assertSame('No details provided', $result->resolvedParameters['message']);
    }

    #[Test]
    public function itExecutesMultiStepFixtureWorkflow(): void
    {
        $loader = new WorkflowConfigLoader(WorkflowFixtures::fixturesPath());
        $workflow = $loader->getWorkflow('multi-step');

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->exactly(3))
            ->method('send')
            ->willReturn(['slack']);

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $input = WorkflowFixtures::multiStepWorkflowInput();
        $result = $executor->execute('multi-step', $input);

        $this->assertTrue($result->success);
        $this->assertCount(3, $result->stepResults);
        $this->assertSame('notify-team', $result->stepResults[0]->stepName);
        $this->assertSame('alert-ops', $result->stepResults[1]->stepName);
        $this->assertSame('log-deploy', $result->stepResults[2]->stepName);
    }
}
