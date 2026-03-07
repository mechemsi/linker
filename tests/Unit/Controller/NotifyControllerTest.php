<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\NotifyController;
use App\Exception\InvalidParametersException;
use App\Exception\LinkNotFoundException;
use App\Exception\NotificationFailedException;
use App\Service\LinkNotificationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class NotifyControllerTest extends TestCase
{
    private function createController(
        LinkNotificationService $service,
        ?LoggerInterface $logger = null,
    ): NotifyController {
        $controller = new NotifyController($service, $logger ?? $this->createStub(LoggerInterface::class));

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $controller->setContainer($container);

        return $controller;
    }

    #[Test]
    public function successfulNotificationReturns200(): void
    {
        $service = $this->createStub(LinkNotificationService::class);
        $service->method('send')->willReturn(['slack', 'telegram']);

        $controller = $this->createController($service);
        $request = Request::create('/notify/test-link', 'GET', ['server' => 'web1']);
        $response = $controller('test-link', $request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('ok', $data['status']);
        $this->assertSame('test-link', $data['link']);
        $this->assertSame(['slack', 'telegram'], $data['channels_notified']);
    }

    #[Test]
    public function linkNotFoundReturns404(): void
    {
        $service = $this->createStub(LinkNotificationService::class);
        $service->method('send')
            ->willThrowException(new LinkNotFoundException('unknown'));

        $controller = $this->createController($service);
        $request = Request::create('/notify/unknown');
        $response = $controller('unknown', $request);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('error', $data['status']);
        $this->assertStringContainsString('unknown', $data['message']);
    }

    #[Test]
    public function invalidParametersReturns400(): void
    {
        $service = $this->createStub(LinkNotificationService::class);
        $service->method('send')
            ->willThrowException(new InvalidParametersException([
                'Missing required parameter "server".',
                'Missing required parameter "status".',
            ]));

        $controller = $this->createController($service);
        $request = Request::create('/notify/test-link');
        $response = $controller('test-link', $request);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('error', $data['status']);
        $this->assertCount(2, $data['errors']);
        $this->assertStringContainsString('server', $data['errors'][0]);
    }

    #[Test]
    public function partialFailureReturns207(): void
    {
        $service = $this->createStub(LinkNotificationService::class);
        $service->method('send')
            ->willThrowException(new NotificationFailedException(
                'test-link',
                ['telegram'],
                ['slack' => 'Slack API error'],
            ));

        $controller = $this->createController($service);
        $request = Request::create('/notify/test-link', 'GET', ['msg' => 'hello']);
        $response = $controller('test-link', $request);

        $this->assertSame(Response::HTTP_MULTI_STATUS, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('partial_failure', $data['status']);
        $this->assertSame('test-link', $data['link']);
        $this->assertSame(['telegram'], $data['channels_notified']);
        $this->assertSame(['slack' => 'Slack API error'], $data['channels_failed']);
    }

    #[Test]
    public function unexpectedExceptionReturns500(): void
    {
        $service = $this->createStub(LinkNotificationService::class);
        $service->method('send')
            ->willThrowException(new \RuntimeException('Something broke'));

        $controller = $this->createController($service);
        $request = Request::create('/notify/test-link', 'GET', ['msg' => 'hello']);
        $response = $controller('test-link', $request);

        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('error', $data['status']);
        $this->assertStringContainsString('Something broke', $data['message']);
    }

    #[Test]
    public function requestAndResponseAreLogged(): void
    {
        $service = $this->createStub(LinkNotificationService::class);
        $service->method('send')->willReturn(['slack']);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))
            ->method('info')
            ->with(
                $this->logicalOr(
                    $this->stringContains('Incoming notification request'),
                    $this->stringContains('Notification request completed'),
                ),
                $this->isType('array'),
            );

        $controller = $this->createController($service, $logger);
        $request = Request::create('/notify/test-link', 'GET', ['msg' => 'hello']);
        $controller('test-link', $request);
    }

    #[Test]
    public function errorResponsesAreLogged(): void
    {
        $service = $this->createStub(LinkNotificationService::class);
        $service->method('send')
            ->willThrowException(new \RuntimeException('Boom'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info');
        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Unexpected error'),
                $this->callback(static fn (array $ctx) => 'test-link' === $ctx['link']
                    && 'Boom' === $ctx['error']),
            );

        $controller = $this->createController($service, $logger);
        $request = Request::create('/notify/test-link', 'GET', ['msg' => 'hello']);
        $controller('test-link', $request);
    }

    #[Test]
    public function queryParametersArePassedToService(): void
    {
        $service = $this->createMock(LinkNotificationService::class);
        $service->expects($this->once())
            ->method('send')
            ->with('my-link', ['server' => 'web1', 'status' => 'down'])
            ->willReturn(['slack']);

        $controller = $this->createController($service);
        $request = Request::create('/notify/my-link', 'GET', [
            'server' => 'web1',
            'status' => 'down',
        ]);
        $response = $controller('my-link', $request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }
}
