<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\LinksController;
use App\Dto\ChannelDefinition;
use App\Dto\LinkDefinition;
use App\Dto\ParameterDefinition;
use App\Service\LinkConfigLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

class LinksControllerTest extends TestCase
{
    private function createController(LinkConfigLoader $configLoader): LinksController
    {
        $controller = new LinksController($configLoader);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $controller->setContainer($container);

        return $controller;
    }

    #[Test]
    public function itReturnsAllLinksWithDetails(): void
    {
        $configLoader = $this->createStub(LinkConfigLoader::class);
        $configLoader->method('getAllLinks')->willReturn([
            'server-alert' => new LinkDefinition(
                name: 'server-alert',
                messageTemplate: '[{status}] Server {server}',
                parameters: [
                    new ParameterDefinition('server', true, 'string'),
                    new ParameterDefinition('status', true, 'string'),
                    new ParameterDefinition('message', false, 'string', 'No details'),
                ],
                channels: [
                    new ChannelDefinition('slack'),
                    new ChannelDefinition('telegram'),
                ],
            ),
        ]);

        $controller = $this->createController($configLoader);
        $response = $controller();

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('ok', $data['status']);
        $this->assertArrayHasKey('server-alert', $data['links']);

        $link = $data['links']['server-alert'];
        $this->assertSame('[{status}] Server {server}', $link['message_template']);
        $this->assertSame(['slack', 'telegram'], $link['channels']);

        $this->assertTrue($link['parameters']['server']['required']);
        $this->assertSame('string', $link['parameters']['server']['type']);
        $this->assertArrayNotHasKey('default', $link['parameters']['server']);

        $this->assertFalse($link['parameters']['message']['required']);
        $this->assertSame('No details', $link['parameters']['message']['default']);
    }

    #[Test]
    public function itReturnsEmptyLinksWhenNoneConfigured(): void
    {
        $configLoader = $this->createStub(LinkConfigLoader::class);
        $configLoader->method('getAllLinks')->willReturn([]);

        $controller = $this->createController($configLoader);
        $response = $controller();

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('ok', $data['status']);
        $this->assertSame([], $data['links']);
    }

    #[Test]
    public function itReturns500OnConfigLoadError(): void
    {
        $configLoader = $this->createStub(LinkConfigLoader::class);
        $configLoader->method('getAllLinks')
            ->willThrowException(new \RuntimeException('Config error'));

        $controller = $this->createController($configLoader);
        $response = $controller();

        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('error', $data['status']);
        $this->assertStringContainsString('Config error', $data['message']);
    }
}
