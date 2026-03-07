<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\HealthController;
use App\Service\LinkConfigLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

class HealthControllerTest extends TestCase
{
    private function createController(LinkConfigLoader $configLoader): HealthController
    {
        $controller = new HealthController($configLoader);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $controller->setContainer($container);

        return $controller;
    }

    #[Test]
    public function healthyAppReturns200WithLinkCount(): void
    {
        $configLoader = $this->createStub(LinkConfigLoader::class);
        $configLoader->method('getAllLinks')->willReturn(['a' => 'x', 'b' => 'y']);

        $controller = $this->createController($configLoader);
        $response = $controller();

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('ok', $data['status']);
        $this->assertSame(2, $data['links_loaded']);
    }

    #[Test]
    public function configLoaderFailureReturns503(): void
    {
        $configLoader = $this->createStub(LinkConfigLoader::class);
        $configLoader->method('getAllLinks')
            ->willThrowException(new \RuntimeException('Failed to read config directory'));

        $controller = $this->createController($configLoader);
        $response = $controller();

        $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('error', $data['status']);
        $this->assertStringContainsString('Failed to read config directory', $data['message']);
    }

    #[Test]
    public function emptyLinksDirectoryStillReturns200(): void
    {
        $configLoader = $this->createStub(LinkConfigLoader::class);
        $configLoader->method('getAllLinks')->willReturn([]);

        $controller = $this->createController($configLoader);
        $response = $controller();

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('ok', $data['status']);
        $this->assertSame(0, $data['links_loaded']);
    }
}
