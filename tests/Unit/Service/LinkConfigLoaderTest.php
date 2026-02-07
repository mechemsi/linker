<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\ChannelDefinition;
use App\Dto\LinkDefinition;
use App\Dto\ParameterDefinition;
use App\Exception\LinkNotFoundException;
use App\Service\LinkConfigLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LinkConfigLoaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/linker_test_' . uniqid();
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
    public function itParsesValidYamlIntoLinkDefinition(): void
    {
        file_put_contents($this->tmpDir . '/test-link.yaml', <<<'YAML'
parameters:
    server:
        required: true
        type: string
    message:
        required: false
        type: string
        default: 'No info'
message_template: '[{server}]: {message}'
channels:
    - transport: slack
    - transport: telegram
YAML);

        $loader = new LinkConfigLoader($this->tmpDir);
        $link = $loader->getLink('test-link');

        $this->assertInstanceOf(LinkDefinition::class, $link);
        $this->assertSame('test-link', $link->name);
        $this->assertSame('[{server}]: {message}', $link->messageTemplate);
        $this->assertCount(2, $link->parameters);
        $this->assertCount(2, $link->channels);

        $this->assertInstanceOf(ParameterDefinition::class, $link->parameters[0]);
        $this->assertSame('server', $link->parameters[0]->name);
        $this->assertTrue($link->parameters[0]->required);
        $this->assertNull($link->parameters[0]->default);

        $this->assertInstanceOf(ParameterDefinition::class, $link->parameters[1]);
        $this->assertSame('message', $link->parameters[1]->name);
        $this->assertFalse($link->parameters[1]->required);
        $this->assertSame('No info', $link->parameters[1]->default);

        $this->assertInstanceOf(ChannelDefinition::class, $link->channels[0]);
        $this->assertSame('slack', $link->channels[0]->transport);
        $this->assertSame([], $link->channels[0]->options);
    }

    #[Test]
    public function itThrowsLinkNotFoundExceptionForUnknownLink(): void
    {
        $loader = new LinkConfigLoader($this->tmpDir);

        $this->expectException(LinkNotFoundException::class);
        $this->expectExceptionMessage('Link "nonexistent" not found.');

        $loader->getLink('nonexistent');
    }

    #[Test]
    public function hasLinkReturnsTrueForExistingLink(): void
    {
        file_put_contents($this->tmpDir . '/existing.yaml', <<<'YAML'
parameters: {}
message_template: 'test'
channels: []
YAML);

        $loader = new LinkConfigLoader($this->tmpDir);

        $this->assertTrue($loader->hasLink('existing'));
    }

    #[Test]
    public function hasLinkReturnsFalseForMissingLink(): void
    {
        $loader = new LinkConfigLoader($this->tmpDir);

        $this->assertFalse($loader->hasLink('missing'));
    }

    #[Test]
    public function itHandlesEmptyDirectory(): void
    {
        $loader = new LinkConfigLoader($this->tmpDir);

        $this->assertSame([], $loader->getAllLinks());
    }

    #[Test]
    public function itHandlesNonexistentDirectory(): void
    {
        $loader = new LinkConfigLoader('/tmp/nonexistent_dir_' . uniqid());

        $this->assertSame([], $loader->getAllLinks());
    }

    #[Test]
    public function itParsesChannelOptions(): void
    {
        file_put_contents($this->tmpDir . '/with-options.yaml', <<<'YAML'
parameters: {}
message_template: 'test'
channels:
    - transport: email
      options:
          to: 'team@example.com'
          subject: 'Alert'
YAML);

        $loader = new LinkConfigLoader($this->tmpDir);
        $link = $loader->getLink('with-options');

        $this->assertSame('email', $link->channels[0]->transport);
        $this->assertSame('team@example.com', $link->channels[0]->options['to']);
        $this->assertSame('Alert', $link->channels[0]->options['subject']);
    }

    #[Test]
    public function getAllLinksReturnsAllParsedLinks(): void
    {
        file_put_contents($this->tmpDir . '/link-a.yaml', <<<'YAML'
parameters: {}
message_template: 'A'
channels: []
YAML);
        file_put_contents($this->tmpDir . '/link-b.yaml', <<<'YAML'
parameters: {}
message_template: 'B'
channels: []
YAML);

        $loader = new LinkConfigLoader($this->tmpDir);
        $all = $loader->getAllLinks();

        $this->assertCount(2, $all);
        $this->assertArrayHasKey('link-a', $all);
        $this->assertArrayHasKey('link-b', $all);
    }

    #[Test]
    public function itCachesResultsInMemory(): void
    {
        file_put_contents($this->tmpDir . '/cached.yaml', <<<'YAML'
parameters: {}
message_template: 'cached'
channels: []
YAML);

        $loader = new LinkConfigLoader($this->tmpDir);

        $first = $loader->getAllLinks();
        $second = $loader->getAllLinks();

        $this->assertSame($first, $second);
    }
}
