<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\ChannelDefinition;
use App\Dto\LinkDefinition;
use App\Dto\ParameterDefinition;
use App\Exception\InvalidParametersException;
use App\Service\MessageBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MessageBuilderTest extends TestCase
{
    private MessageBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new MessageBuilder();
    }

    private function createLink(array $parameters = [], string $template = ''): LinkDefinition
    {
        return new LinkDefinition(
            name: 'test',
            messageTemplate: $template,
            parameters: $parameters,
            channels: [new ChannelDefinition('slack')],
        );
    }

    #[Test]
    public function resolveParametersWithAllParamsProvided(): void
    {
        $link = $this->createLink([
            new ParameterDefinition('server', true, 'string'),
            new ParameterDefinition('status', true, 'string'),
        ]);

        $resolved = $this->builder->resolveParameters($link, [
            'server' => 'web1',
            'status' => 'down',
        ]);

        $this->assertSame(['server' => 'web1', 'status' => 'down'], $resolved);
    }

    #[Test]
    public function resolveParametersThrowsOnMissingRequired(): void
    {
        $link = $this->createLink([
            new ParameterDefinition('server', true, 'string'),
            new ParameterDefinition('status', true, 'string'),
        ]);

        try {
            $this->builder->resolveParameters($link, ['server' => 'web1']);
            $this->fail('Expected InvalidParametersException');
        } catch (InvalidParametersException $e) {
            $this->assertCount(1, $e->getErrors());
            $this->assertStringContainsString('status', $e->getErrors()[0]);
        }
    }

    #[Test]
    public function resolveParametersUsesDefaultForOptionalMissing(): void
    {
        $link = $this->createLink([
            new ParameterDefinition('server', true, 'string'),
            new ParameterDefinition('message', false, 'string', 'No details'),
        ]);

        $resolved = $this->builder->resolveParameters($link, ['server' => 'web1']);

        $this->assertSame(['server' => 'web1', 'message' => 'No details'], $resolved);
    }

    #[Test]
    public function resolveParametersReportsMultipleMissing(): void
    {
        $link = $this->createLink([
            new ParameterDefinition('a', true, 'string'),
            new ParameterDefinition('b', true, 'string'),
        ]);

        try {
            $this->builder->resolveParameters($link, []);
            $this->fail('Expected InvalidParametersException');
        } catch (InvalidParametersException $e) {
            $this->assertCount(2, $e->getErrors());
        }
    }

    #[Test]
    public function buildMessageInterpolatesPlaceholders(): void
    {
        $link = $this->createLink([], '[{status}] Server {server}: {message}');

        $result = $this->builder->buildMessage($link, [
            'status' => 'down',
            'server' => 'web1',
            'message' => 'disk full',
        ]);

        $this->assertSame('[down] Server web1: disk full', $result);
    }

    #[Test]
    public function buildMessageWithNoPlaceholders(): void
    {
        $link = $this->createLink([], 'Static message');

        $result = $this->builder->buildMessage($link, []);

        $this->assertSame('Static message', $result);
    }

    #[Test]
    public function buildMessageLeavesUnmatchedPlaceholders(): void
    {
        $link = $this->createLink([], 'Hello {name}, your {thing} is ready');

        $result = $this->builder->buildMessage($link, ['name' => 'Alice']);

        $this->assertSame('Hello Alice, your {thing} is ready', $result);
    }
}
