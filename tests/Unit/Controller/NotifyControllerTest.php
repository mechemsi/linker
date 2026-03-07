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
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

class NotifyControllerTest extends TestCase
{
    private function createAcceptedRateLimiter(): RateLimiterFactoryInterface
    {
        $rateLimit = new RateLimit(59, new \DateTimeImmutable('+1 minute'), true, 60);

        $limiter = $this->createStub(LimiterInterface::class);
        $limiter->method('consume')->willReturn($rateLimit);

        $factory = $this->createStub(RateLimiterFactoryInterface::class);
        $factory->method('create')->willReturn($limiter);

        return $factory;
    }

    private function createRejectedRateLimiter(): RateLimiterFactoryInterface
    {
        $rateLimit = new RateLimit(0, new \DateTimeImmutable('+1 minute'), false, 60);

        $limiter = $this->createStub(LimiterInterface::class);
        $limiter->method('consume')->willReturn($rateLimit);

        $factory = $this->createStub(RateLimiterFactoryInterface::class);
        $factory->method('create')->willReturn($limiter);

        return $factory;
    }

    private function createController(
        LinkNotificationService $service,
        ?LoggerInterface $logger = null,
        ?RateLimiterFactoryInterface $rateLimiter = null,
    ): NotifyController {
        $controller = new NotifyController(
            $service,
            $logger ?? $this->createStub(LoggerInterface::class),
            $rateLimiter ?? $this->createAcceptedRateLimiter(),
        );

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $controller->setContainer($container);

        return $controller;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(Response $response): array
    {
        return json_decode((string) $response->getContent(), true);
    }

    private function assertHasValidRequestId(array $data): void
    {
        $this->assertArrayHasKey('request_id', $data);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $data['request_id']);
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

        $data = $this->decodeResponse($response);
        $this->assertSame('ok', $data['status']);
        $this->assertSame('test-link', $data['link']);
        $this->assertSame(['slack', 'telegram'], $data['channels_notified']);
        $this->assertHasValidRequestId($data);
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

        $data = $this->decodeResponse($response);
        $this->assertSame('error', $data['status']);
        $this->assertStringContainsString('unknown', $data['message']);
        $this->assertHasValidRequestId($data);
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

        $data = $this->decodeResponse($response);
        $this->assertSame('error', $data['status']);
        $this->assertCount(2, $data['errors']);
        $this->assertStringContainsString('server', $data['errors'][0]);
        $this->assertHasValidRequestId($data);
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

        $data = $this->decodeResponse($response);
        $this->assertSame('partial_failure', $data['status']);
        $this->assertSame('test-link', $data['link']);
        $this->assertSame(['telegram'], $data['channels_notified']);
        $this->assertSame(['slack' => 'Slack API error'], $data['channels_failed']);
        $this->assertHasValidRequestId($data);
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

        $data = $this->decodeResponse($response);
        $this->assertSame('error', $data['status']);
        $this->assertStringContainsString('Something broke', $data['message']);
        $this->assertHasValidRequestId($data);
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
                $this->callback(static fn (array $ctx) => isset($ctx['request_id'])
                    && 1 === preg_match('/^[0-9a-f]{32}$/', $ctx['request_id'])),
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
                    && 'Boom' === $ctx['error']
                    && isset($ctx['request_id'])),
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

    #[Test]
    public function rateLimitExceededReturns429(): void
    {
        $service = $this->createStub(LinkNotificationService::class);

        $controller = $this->createController($service, null, $this->createRejectedRateLimiter());
        $request = Request::create('/notify/test-link', 'GET', ['msg' => 'hello']);
        $response = $controller('test-link', $request);

        $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());

        $data = $this->decodeResponse($response);
        $this->assertSame('error', $data['status']);
        $this->assertStringContainsString('Rate limit exceeded', $data['message']);
        $this->assertHasValidRequestId($data);
    }

    #[Test]
    public function rateLimitExceededDoesNotCallService(): void
    {
        $service = $this->createMock(LinkNotificationService::class);
        $service->expects($this->never())->method('send');

        $controller = $this->createController($service, null, $this->createRejectedRateLimiter());
        $request = Request::create('/notify/test-link', 'GET', ['msg' => 'hello']);
        $controller('test-link', $request);
    }

    #[Test]
    public function rateLimitExceededIsLogged(): void
    {
        $service = $this->createStub(LinkNotificationService::class);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Rate limit exceeded'),
                $this->callback(static fn (array $ctx) => 'test-link' === $ctx['link']
                    && isset($ctx['request_id'])),
            );

        $controller = $this->createController($service, $logger, $this->createRejectedRateLimiter());
        $request = Request::create('/notify/test-link', 'GET', ['msg' => 'hello']);
        $controller('test-link', $request);
    }

    #[Test]
    public function requestIdIsUniquePerRequest(): void
    {
        $service = $this->createStub(LinkNotificationService::class);
        $service->method('send')->willReturn(['slack']);

        $controller = $this->createController($service);

        $request1 = Request::create('/notify/test-link', 'GET', ['msg' => 'hello']);
        $response1 = $controller('test-link', $request1);

        $request2 = Request::create('/notify/test-link', 'GET', ['msg' => 'world']);
        $response2 = $controller('test-link', $request2);

        $data1 = $this->decodeResponse($response1);
        $data2 = $this->decodeResponse($response2);

        $this->assertNotSame($data1['request_id'], $data2['request_id']);
    }
}
