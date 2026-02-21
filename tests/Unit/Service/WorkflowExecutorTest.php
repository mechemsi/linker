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

    #[Test]
    public function itValidatesCompleteWorkflowOutputsMatchExpectedResults(): void
    {
        $loader = new WorkflowConfigLoader(WorkflowFixtures::fixturesPath());
        $workflow = $loader->getWorkflow('complete');

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $capturedCalls = [];
        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(static function (string $linkName, array $params) use (&$capturedCalls) {
                $capturedCalls[] = ['link' => $linkName, 'params' => $params];

                return match ($linkName) {
                    'server-alert' => ['slack', 'telegram'],
                    'test-slack' => ['slack-webhook'],
                    default => [],
                };
            });

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $input = WorkflowFixtures::completeWorkflowInput();
        $result = $executor->execute('complete', $input);

        $this->assertTrue($result->success);
        $this->assertNull($result->error);
        $this->assertSame('complete', $result->workflowName);
        $this->assertSame($input, $result->resolvedParameters);
        $this->assertCount(2, $result->stepResults);

        // Verify step 1: alert → server-alert with individual param interpolation
        $this->assertSame('alert', $result->stepResults[0]->stepName);
        $this->assertSame('server-alert', $result->stepResults[0]->linkName);
        $this->assertTrue($result->stepResults[0]->success);
        $this->assertNull($result->stepResults[0]->error);
        $this->assertSame(['slack', 'telegram'], $result->stepResults[0]->notifiedTransports);

        $this->assertSame('server-alert', $capturedCalls[0]['link']);
        $this->assertSame([
            'server' => 'web-01.prod',
            'status' => 'critical',
            'message' => 'CPU usage above 95%',
        ], $capturedCalls[0]['params']);

        // Verify step 2: log → test-slack with composite interpolation
        $this->assertSame('log', $result->stepResults[1]->stepName);
        $this->assertSame('test-slack', $result->stepResults[1]->linkName);
        $this->assertTrue($result->stepResults[1]->success);
        $this->assertNull($result->stepResults[1]->error);
        $this->assertSame(['slack-webhook'], $result->stepResults[1]->notifiedTransports);

        $this->assertSame('test-slack', $capturedCalls[1]['link']);
        $this->assertSame([
            'message' => '[critical] Server web-01.prod: CPU usage above 95%',
        ], $capturedCalls[1]['params']);
    }

    #[Test]
    public function itValidatesMultiStepWorkflowOutputsMatchExpectedResults(): void
    {
        $loader = new WorkflowConfigLoader(WorkflowFixtures::fixturesPath());
        $workflow = $loader->getWorkflow('multi-step');

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $capturedCalls = [];
        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->exactly(3))
            ->method('send')
            ->willReturnCallback(static function (string $linkName, array $params) use (&$capturedCalls) {
                $capturedCalls[] = ['link' => $linkName, 'params' => $params];

                return ['slack'];
            });

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $input = WorkflowFixtures::multiStepWorkflowInput();
        $result = $executor->execute('multi-step', $input);

        $this->assertTrue($result->success);
        $this->assertNull($result->error);
        $this->assertSame($input, $result->resolvedParameters);
        $this->assertCount(3, $result->stepResults);

        // Step 1: notify-team → test-slack
        $this->assertSame([
            'message' => 'Deploy started: linker-api v2.4.0 to production by ci-bot',
        ], $capturedCalls[0]['params']);
        $this->assertTrue($result->stepResults[0]->success);
        $this->assertSame(['slack'], $result->stepResults[0]->notifiedTransports);

        // Step 2: alert-ops → server-alert
        $this->assertSame([
            'server' => 'production',
            'status' => 'deploying',
            'message' => 'linker-api v2.4.0',
        ], $capturedCalls[1]['params']);
        $this->assertTrue($result->stepResults[1]->success);

        // Step 3: log-deploy → deploy-notify
        $this->assertSame([
            'app' => 'linker-api',
            'version' => '2.4.0',
            'environment' => 'production',
        ], $capturedCalls[2]['params']);
        $this->assertTrue($result->stepResults[2]->success);
    }

    #[Test]
    public function itValidatesMultiStepDefaultParametersProduceExpectedOutputs(): void
    {
        $loader = new WorkflowConfigLoader(WorkflowFixtures::fixturesPath());
        $workflow = $loader->getWorkflow('multi-step');

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $capturedCalls = [];
        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->exactly(3))
            ->method('send')
            ->willReturnCallback(static function (string $linkName, array $params) use (&$capturedCalls) {
                $capturedCalls[] = ['link' => $linkName, 'params' => $params];

                return ['slack'];
            });

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $input = WorkflowFixtures::multiStepWorkflowInputWithDefaults();
        $result = $executor->execute('multi-step', $input);

        $this->assertTrue($result->success);
        $this->assertSame('system', $result->resolvedParameters['deployer']);

        // Verify the default "deployer" value is interpolated correctly
        $this->assertSame([
            'message' => 'Deploy started: linker-api v2.4.0 to staging by system',
        ], $capturedCalls[0]['params']);
    }

    #[Test]
    public function itValidatesAllOptionalParamsWorkflowWithNoInputUsesAllDefaults(): void
    {
        $loader = new WorkflowConfigLoader(WorkflowFixtures::fixturesPath());
        $workflow = $loader->getWorkflow('all-optional-params');

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $capturedCalls = [];
        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->once())
            ->method('send')
            ->willReturnCallback(static function (string $linkName, array $params) use (&$capturedCalls) {
                $capturedCalls[] = ['link' => $linkName, 'params' => $params];

                return ['slack-webhook'];
            });

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $result = $executor->execute('all-optional-params', []);

        $this->assertTrue($result->success);
        $this->assertSame([
            'title' => 'Default Title',
            'body' => 'Default Body',
            'priority' => 'low',
        ], $result->resolvedParameters);

        $this->assertSame('test-slack', $capturedCalls[0]['link']);
        $this->assertSame([
            'message' => '[low] Default Title: Default Body',
        ], $capturedCalls[0]['params']);

        $this->assertCount(1, $result->stepResults);
        $this->assertSame('send', $result->stepResults[0]->stepName);
        $this->assertTrue($result->stepResults[0]->success);
    }

    #[Test]
    public function itValidatesEmptyStringInputsProduceExpectedOutputs(): void
    {
        $loader = new WorkflowConfigLoader(WorkflowFixtures::fixturesPath());
        $workflow = $loader->getWorkflow('complete');

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $capturedCalls = [];
        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(static function (string $linkName, array $params) use (&$capturedCalls) {
                $capturedCalls[] = ['link' => $linkName, 'params' => $params];

                return ['slack'];
            });

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $input = WorkflowFixtures::emptyStringInput();
        $result = $executor->execute('complete', $input);

        $this->assertTrue($result->success);
        $this->assertSame($input, $result->resolvedParameters);

        // Empty values should be interpolated as empty strings
        $this->assertSame([
            'server' => '',
            'status' => '',
            'message' => '',
        ], $capturedCalls[0]['params']);

        $this->assertSame([
            'message' => '[] Server : ',
        ], $capturedCalls[1]['params']);
    }

    #[Test]
    public function itValidatesBracesInValuesDoNotCauseUnexpectedInterpolation(): void
    {
        $loader = new WorkflowConfigLoader(WorkflowFixtures::fixturesPath());
        $workflow = $loader->getWorkflow('complete');

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $capturedCalls = [];
        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(static function (string $linkName, array $params) use (&$capturedCalls) {
                $capturedCalls[] = ['link' => $linkName, 'params' => $params];

                return ['slack'];
            });

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $input = WorkflowFixtures::bracesInValuesInput();
        $result = $executor->execute('complete', $input);

        $this->assertTrue($result->success);
        $this->assertSame($input, $result->resolvedParameters);

        // Braces in values should be preserved literally
        $this->assertSame([
            'server' => 'host-{unknown}',
            'status' => '{status}',
            'message' => 'Literal {braces} in message',
        ], $capturedCalls[0]['params']);

        $this->assertSame([
            'message' => '[{status}] Server host-{unknown}: Literal {braces} in message',
        ], $capturedCalls[1]['params']);
    }

    #[Test]
    public function itValidatesPartialFailureOutputContainsCorrectStepResults(): void
    {
        $workflow = new WorkflowDefinition(
            name: 'three-steps',
            description: 'Three step workflow with middle failure',
            parameters: [new ParameterDefinition('msg', true, 'string')],
            steps: [
                new StepDefinition('first', 'link-a', ['message' => '{msg}']),
                new StepDefinition('second', 'link-b', ['message' => '{msg}']),
                new StepDefinition('third', 'link-c', ['message' => '{msg}']),
            ],
        );

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->exactly(3))
            ->method('send')
            ->willReturnCallback(static function (string $linkName) {
                return match ($linkName) {
                    'link-a' => ['slack'],
                    'link-b' => throw new \RuntimeException('Connection timeout'),
                    'link-c' => ['email', 'telegram'],
                    default => [],
                };
            });

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $result = $executor->execute('three-steps', ['msg' => 'test']);

        $this->assertFalse($result->success);
        $this->assertNull($result->error);
        $this->assertSame(['msg' => 'test'], $result->resolvedParameters);
        $this->assertCount(3, $result->stepResults);

        // Step 1: succeeded
        $this->assertTrue($result->stepResults[0]->success);
        $this->assertSame('first', $result->stepResults[0]->stepName);
        $this->assertSame('link-a', $result->stepResults[0]->linkName);
        $this->assertSame(['slack'], $result->stepResults[0]->notifiedTransports);
        $this->assertNull($result->stepResults[0]->error);

        // Step 2: failed
        $this->assertFalse($result->stepResults[1]->success);
        $this->assertSame('second', $result->stepResults[1]->stepName);
        $this->assertSame('link-b', $result->stepResults[1]->linkName);
        $this->assertSame([], $result->stepResults[1]->notifiedTransports);
        $this->assertSame('Connection timeout', $result->stepResults[1]->error);

        // Step 3: succeeded despite step 2 failure
        $this->assertTrue($result->stepResults[2]->success);
        $this->assertSame('third', $result->stepResults[2]->stepName);
        $this->assertSame('link-c', $result->stepResults[2]->linkName);
        $this->assertSame(['email', 'telegram'], $result->stepResults[2]->notifiedTransports);
        $this->assertNull($result->stepResults[2]->error);
    }

    #[Test]
    public function itValidatesAllStepsFailedProducesCorrectOutput(): void
    {
        $workflow = new WorkflowDefinition(
            name: 'all-fail',
            description: 'All steps fail',
            parameters: [new ParameterDefinition('msg', true, 'string')],
            steps: [
                new StepDefinition('step-a', 'bad-link-1', ['message' => '{msg}']),
                new StepDefinition('step-b', 'bad-link-2', ['message' => '{msg}']),
            ],
        );

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(static function (string $linkName) {
                throw new \RuntimeException(\sprintf('Link "%s" unavailable', $linkName));
            });

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $result = $executor->execute('all-fail', ['msg' => 'test']);

        $this->assertFalse($result->success);
        $this->assertNull($result->error);
        $this->assertCount(2, $result->stepResults);

        foreach ($result->stepResults as $stepResult) {
            $this->assertFalse($stepResult->success);
            $this->assertSame([], $stepResult->notifiedTransports);
            $this->assertNotNull($stepResult->error);
        }

        $this->assertSame('Link "bad-link-1" unavailable', $result->stepResults[0]->error);
        $this->assertSame('Link "bad-link-2" unavailable', $result->stepResults[1]->error);
    }

    #[Test]
    public function itHandlesWhitespaceOnlyParameterValues(): void
    {
        $loader = new WorkflowConfigLoader(WorkflowFixtures::fixturesPath());
        $workflow = $loader->getWorkflow('complete');

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $capturedCalls = [];
        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(static function (string $linkName, array $params) use (&$capturedCalls) {
                $capturedCalls[] = ['link' => $linkName, 'params' => $params];

                return ['slack'];
            });

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $input = WorkflowFixtures::whitespaceOnlyInput();
        $result = $executor->execute('complete', $input);

        $this->assertTrue($result->success);
        $this->assertSame($input, $result->resolvedParameters);

        // Whitespace values are preserved literally
        $this->assertSame('   ', $capturedCalls[0]['params']['server']);
        $this->assertSame("\t", $capturedCalls[0]['params']['status']);
        $this->assertSame(" \n ", $capturedCalls[0]['params']['message']);
    }

    #[Test]
    public function itHandlesVeryLongParameterValues(): void
    {
        $workflow = new WorkflowDefinition(
            name: 'long-values',
            description: 'Long value test',
            parameters: [new ParameterDefinition('message', true, 'string')],
            steps: [new StepDefinition('notify', 'test-slack', ['message' => '{message}'])],
        );

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $longValue = str_repeat('a', 10000);
        $capturedParams = [];
        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->once())
            ->method('send')
            ->willReturnCallback(static function (string $linkName, array $params) use (&$capturedParams) {
                $capturedParams = $params;

                return ['slack'];
            });

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $result = $executor->execute('long-values', ['message' => $longValue]);

        $this->assertTrue($result->success);
        $this->assertSame($longValue, $result->resolvedParameters['message']);
        $this->assertSame($longValue, $capturedParams['message']);
    }

    #[Test]
    public function itHandlesUnicodeMultibyteParameterValues(): void
    {
        $loader = new WorkflowConfigLoader(WorkflowFixtures::fixturesPath());
        $workflow = $loader->getWorkflow('complete');

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $capturedCalls = [];
        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(static function (string $linkName, array $params) use (&$capturedCalls) {
                $capturedCalls[] = ['link' => $linkName, 'params' => $params];

                return ['slack'];
            });

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $input = WorkflowFixtures::unicodeInput();
        $result = $executor->execute('complete', $input);

        $this->assertTrue($result->success);
        $this->assertSame($input, $result->resolvedParameters);

        // Unicode values preserved in interpolation
        $this->assertSame('サーバー-01', $capturedCalls[0]['params']['server']);
        $this->assertSame('критический', $capturedCalls[0]['params']['status']);

        // Composite interpolation with unicode
        $this->assertSame(
            '[критический] Server サーバー-01: 磁盘使用率: 99% /var/log 已满',
            $capturedCalls[1]['params']['message'],
        );
    }

    #[Test]
    public function itHandlesNullLikeStringParameterValues(): void
    {
        $loader = new WorkflowConfigLoader(WorkflowFixtures::fixturesPath());
        $workflow = $loader->getWorkflow('complete');

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $capturedCalls = [];
        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(static function (string $linkName, array $params) use (&$capturedCalls) {
                $capturedCalls[] = ['link' => $linkName, 'params' => $params];

                return ['slack'];
            });

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $input = WorkflowFixtures::nullLikeStringInput();
        $result = $executor->execute('complete', $input);

        $this->assertTrue($result->success);
        $this->assertSame($input, $result->resolvedParameters);

        // "null", "undefined", "false" are valid string values
        $this->assertSame('null', $capturedCalls[0]['params']['server']);
        $this->assertSame('undefined', $capturedCalls[0]['params']['status']);
        $this->assertSame('false', $capturedCalls[0]['params']['message']);

        $this->assertSame(
            '[undefined] Server null: false',
            $capturedCalls[1]['params']['message'],
        );
    }

    #[Test]
    public function itIgnoresExtraParametersNotDefinedInWorkflow(): void
    {
        $loader = new WorkflowConfigLoader(WorkflowFixtures::fixturesPath());
        $workflow = $loader->getWorkflow('complete');

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $capturedCalls = [];
        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(static function (string $linkName, array $params) use (&$capturedCalls) {
                $capturedCalls[] = ['link' => $linkName, 'params' => $params];

                return ['slack'];
            });

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $input = WorkflowFixtures::extraParametersInput();
        $result = $executor->execute('complete', $input);

        $this->assertTrue($result->success);

        // Extra parameters should NOT appear in resolved parameters
        $this->assertArrayNotHasKey('extra_param', $result->resolvedParameters);
        $this->assertArrayNotHasKey('another_extra', $result->resolvedParameters);
        $this->assertCount(3, $result->resolvedParameters);

        // Only defined parameters are resolved
        $this->assertSame('web-01', $result->resolvedParameters['server']);
        $this->assertSame('ok', $result->resolvedParameters['status']);
        $this->assertSame('All good', $result->resolvedParameters['message']);
    }

    #[Test]
    public function itHandlesNewlinesInParameterValues(): void
    {
        $loader = new WorkflowConfigLoader(WorkflowFixtures::fixturesPath());
        $workflow = $loader->getWorkflow('complete');

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $capturedCalls = [];
        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(static function (string $linkName, array $params) use (&$capturedCalls) {
                $capturedCalls[] = ['link' => $linkName, 'params' => $params];

                return ['slack'];
            });

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $input = WorkflowFixtures::newlinesInValuesInput();
        $result = $executor->execute('complete', $input);

        $this->assertTrue($result->success);
        $this->assertSame($input, $result->resolvedParameters);

        // Newlines preserved in interpolated output
        $this->assertSame("web-01\nweb-02", $capturedCalls[0]['params']['server']);
        $this->assertSame("line1\r\nline2", $capturedCalls[0]['params']['status']);
    }

    #[Test]
    public function itHandlesOptionalParameterWithNoDefaultNotProvided(): void
    {
        $workflow = new WorkflowDefinition(
            name: 'no-default',
            description: 'Optional without default',
            parameters: [
                new ParameterDefinition('required_param', true, 'string'),
                new ParameterDefinition('optional_no_default', false, 'string'),
            ],
            steps: [new StepDefinition('notify', 'test-slack', [
                'message' => '{required_param} - {optional_no_default}',
            ])],
        );

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $capturedCalls = [];
        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->once())
            ->method('send')
            ->willReturnCallback(static function (string $linkName, array $params) use (&$capturedCalls) {
                $capturedCalls[] = ['link' => $linkName, 'params' => $params];

                return ['slack'];
            });

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $result = $executor->execute('no-default', ['required_param' => 'hello']);

        $this->assertTrue($result->success);
        // Optional param without default is excluded from resolved parameters
        $this->assertArrayNotHasKey('optional_no_default', $result->resolvedParameters);
        // Unresolved placeholder remains in interpolated output
        $this->assertSame('hello - {optional_no_default}', $capturedCalls[0]['params']['message']);
    }

    #[Test]
    public function itHandlesStepWithUnreferencedPlaceholders(): void
    {
        $workflow = new WorkflowDefinition(
            name: 'unresolved-placeholders',
            description: 'Step has placeholders not in workflow params',
            parameters: [new ParameterDefinition('name', true, 'string')],
            steps: [new StepDefinition('notify', 'test-slack', [
                'message' => 'Hello {name}, your {role} is ready on {server}',
            ])],
        );

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $capturedCalls = [];
        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->once())
            ->method('send')
            ->willReturnCallback(static function (string $linkName, array $params) use (&$capturedCalls) {
                $capturedCalls[] = ['link' => $linkName, 'params' => $params];

                return ['slack'];
            });

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $result = $executor->execute('unresolved-placeholders', ['name' => 'Alice']);

        $this->assertTrue($result->success);
        // Known placeholder resolved, unknown ones preserved literally
        $this->assertSame(
            'Hello Alice, your {role} is ready on {server}',
            $capturedCalls[0]['params']['message'],
        );
    }

    #[Test]
    public function itHandlesMultipleMissingRequiredParametersListingAll(): void
    {
        $workflow = new WorkflowDefinition(
            name: 'many-required',
            description: 'Many required params',
            parameters: [
                new ParameterDefinition('param_a', true, 'string'),
                new ParameterDefinition('param_b', true, 'string'),
                new ParameterDefinition('param_c', true, 'string'),
                new ParameterDefinition('param_d', true, 'string'),
            ],
            steps: [new StepDefinition('notify', 'test-slack', ['message' => 'test'])],
        );

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->never())->method('send');

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $result = $executor->execute('many-required', []);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        // All four missing params mentioned in error
        $this->assertStringContainsString('param_a', $result->error);
        $this->assertStringContainsString('param_b', $result->error);
        $this->assertStringContainsString('param_c', $result->error);
        $this->assertStringContainsString('param_d', $result->error);
        $this->assertSame([], $result->stepResults);
        $this->assertSame([], $result->resolvedParameters);
    }

    #[Test]
    public function itHandlesStepWithEmptyParametersArray(): void
    {
        $workflow = new WorkflowDefinition(
            name: 'empty-step-params',
            description: 'Step with empty parameters',
            parameters: [],
            steps: [new StepDefinition('ping', 'test-slack', [])],
        );

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->once())
            ->method('send')
            ->with('test-slack', [])
            ->willReturn(['slack-webhook']);

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $result = $executor->execute('empty-step-params', []);

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->stepResults);
        $this->assertTrue($result->stepResults[0]->success);
        $this->assertSame(['slack-webhook'], $result->stepResults[0]->notifiedTransports);
    }

    #[Test]
    public function itHandlesExceptionTypesOtherThanRuntimeException(): void
    {
        $workflow = new WorkflowDefinition(
            name: 'error-types',
            description: 'Various exception types',
            parameters: [new ParameterDefinition('msg', true, 'string')],
            steps: [
                new StepDefinition('logic-error', 'link-a', ['message' => '{msg}']),
                new StepDefinition('overflow', 'link-b', ['message' => '{msg}']),
                new StepDefinition('ok', 'link-c', ['message' => '{msg}']),
            ],
        );

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->exactly(3))
            ->method('send')
            ->willReturnCallback(static function (string $linkName) {
                return match ($linkName) {
                    'link-a' => throw new \LogicException('Invalid state'),
                    'link-b' => throw new \OverflowException('Queue full'),
                    'link-c' => ['slack'],
                    default => [],
                };
            });

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $result = $executor->execute('error-types', ['msg' => 'test']);

        $this->assertFalse($result->success);
        $this->assertCount(3, $result->stepResults);

        $this->assertFalse($result->stepResults[0]->success);
        $this->assertSame('Invalid state', $result->stepResults[0]->error);

        $this->assertFalse($result->stepResults[1]->success);
        $this->assertSame('Queue full', $result->stepResults[1]->error);

        $this->assertTrue($result->stepResults[2]->success);
    }

    #[Test]
    public function itHandlesStepReturningEmptyTransportsList(): void
    {
        $workflow = new WorkflowDefinition(
            name: 'empty-transports',
            description: 'Step returns no transports',
            parameters: [new ParameterDefinition('msg', true, 'string')],
            steps: [new StepDefinition('notify', 'test-slack', ['message' => '{msg}'])],
        );

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->once())
            ->method('send')
            ->willReturn([]);

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $result = $executor->execute('empty-transports', ['msg' => 'test']);

        // Step technically succeeded (no exception), even though no transports notified
        $this->assertTrue($result->success);
        $this->assertCount(1, $result->stepResults);
        $this->assertTrue($result->stepResults[0]->success);
        $this->assertSame([], $result->stepResults[0]->notifiedTransports);
    }

    #[Test]
    public function itHandlesSameParameterValueReusedAcrossMultipleSteps(): void
    {
        $workflow = new WorkflowDefinition(
            name: 'reuse-params',
            description: 'Same param used in every step',
            parameters: [new ParameterDefinition('msg', true, 'string')],
            steps: [
                new StepDefinition('step-1', 'link-a', ['message' => 'First: {msg}']),
                new StepDefinition('step-2', 'link-b', ['message' => 'Second: {msg}']),
                new StepDefinition('step-3', 'link-c', ['message' => 'Third: {msg}']),
            ],
        );

        $configLoader = $this->createStub(WorkflowConfigLoader::class);
        $configLoader->method('getWorkflow')->willReturn($workflow);

        $capturedCalls = [];
        $notificationService = $this->createMock(LinkNotificationService::class);
        $notificationService->expects($this->exactly(3))
            ->method('send')
            ->willReturnCallback(static function (string $linkName, array $params) use (&$capturedCalls) {
                $capturedCalls[] = $params;

                return ['slack'];
            });

        $executor = new WorkflowExecutor($configLoader, $notificationService);
        $result = $executor->execute('reuse-params', ['msg' => 'shared']);

        $this->assertTrue($result->success);
        $this->assertSame('First: shared', $capturedCalls[0]['message']);
        $this->assertSame('Second: shared', $capturedCalls[1]['message']);
        $this->assertSame('Third: shared', $capturedCalls[2]['message']);
    }
}
