<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\ChannelDefinition;
use App\Dto\LinkDefinition;
use App\Dto\ParameterDefinition;
use App\Exception\InvalidLinkConfigException;
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
channels:
    - transport: slack
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
channels:
    - transport: slack
YAML);
        file_put_contents($this->tmpDir . '/link-b.yaml', <<<'YAML'
parameters: {}
message_template: 'B'
channels:
    - transport: slack
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
channels:
    - transport: slack
YAML);

        $loader = new LinkConfigLoader($this->tmpDir);

        $first = $loader->getAllLinks();
        $second = $loader->getAllLinks();

        $this->assertSame($first, $second);
    }

    #[Test]
    public function itInvalidatesCacheWhenFileIsModified(): void
    {
        $filePath = $this->tmpDir . '/mutable.yaml';
        file_put_contents($filePath, <<<'YAML'
parameters: {}
message_template: 'original'
channels:
    - transport: slack
YAML);

        $loader = new LinkConfigLoader($this->tmpDir);
        $link = $loader->getLink('mutable');
        $this->assertSame('original', $link->messageTemplate);

        // Advance mtime by 1 second to ensure change is detected
        sleep(1);
        file_put_contents($filePath, <<<'YAML'
parameters: {}
message_template: 'updated'
channels:
    - transport: slack
YAML);

        $link = $loader->getLink('mutable');
        $this->assertSame('updated', $link->messageTemplate);
    }

    #[Test]
    public function itInvalidatesCacheWhenFileIsAdded(): void
    {
        file_put_contents($this->tmpDir . '/first.yaml', <<<'YAML'
parameters: {}
message_template: 'first'
channels:
    - transport: slack
YAML);

        $loader = new LinkConfigLoader($this->tmpDir);
        $this->assertCount(1, $loader->getAllLinks());

        file_put_contents($this->tmpDir . '/second.yaml', <<<'YAML'
parameters: {}
message_template: 'second'
channels:
    - transport: slack
YAML);

        $this->assertCount(2, $loader->getAllLinks());
    }

    #[Test]
    public function itInvalidatesCacheWhenFileIsRemoved(): void
    {
        file_put_contents($this->tmpDir . '/one.yaml', <<<'YAML'
parameters: {}
message_template: 'one'
channels:
    - transport: slack
YAML);
        file_put_contents($this->tmpDir . '/two.yaml', <<<'YAML'
parameters: {}
message_template: 'two'
channels:
    - transport: slack
YAML);

        $loader = new LinkConfigLoader($this->tmpDir);
        $this->assertCount(2, $loader->getAllLinks());

        unlink($this->tmpDir . '/two.yaml');

        $this->assertCount(1, $loader->getAllLinks());
        $this->assertTrue($loader->hasLink('one'));
        $this->assertFalse($loader->hasLink('two'));
    }

    #[Test]
    public function itThrowsOnMissingMessageTemplate(): void
    {
        file_put_contents($this->tmpDir . '/bad.yaml', <<<'YAML'
parameters: {}
channels:
    - transport: slack
YAML);

        $loader = new LinkConfigLoader($this->tmpDir);

        $this->expectException(InvalidLinkConfigException::class);
        $this->expectExceptionMessage('Missing or invalid "message_template"');

        $loader->getLink('bad');
    }

    #[Test]
    public function itThrowsOnMissingChannels(): void
    {
        file_put_contents($this->tmpDir . '/bad.yaml', <<<'YAML'
parameters: {}
message_template: 'test'
YAML);

        $loader = new LinkConfigLoader($this->tmpDir);

        $this->expectException(InvalidLinkConfigException::class);
        $this->expectExceptionMessage('Missing or invalid "channels"');

        $loader->getLink('bad');
    }

    #[Test]
    public function itThrowsOnEmptyChannels(): void
    {
        file_put_contents($this->tmpDir . '/bad.yaml', <<<'YAML'
parameters: {}
message_template: 'test'
channels: []
YAML);

        $loader = new LinkConfigLoader($this->tmpDir);

        $this->expectException(InvalidLinkConfigException::class);
        $this->expectExceptionMessage('"channels" must not be empty');

        $loader->getLink('bad');
    }

    #[Test]
    public function itThrowsOnChannelMissingTransport(): void
    {
        file_put_contents($this->tmpDir . '/bad.yaml', <<<'YAML'
parameters: {}
message_template: 'test'
channels:
    - options:
          to: 'someone@example.com'
YAML);

        $loader = new LinkConfigLoader($this->tmpDir);

        $this->expectException(InvalidLinkConfigException::class);
        $this->expectExceptionMessage('missing required "transport" key');

        $loader->getLink('bad');
    }

    #[Test]
    public function itThrowsOnUnsupportedTransport(): void
    {
        file_put_contents($this->tmpDir . '/bad.yaml', <<<'YAML'
parameters: {}
message_template: 'test'
channels:
    - transport: pigeon
YAML);

        $loader = new LinkConfigLoader($this->tmpDir);

        $this->expectException(InvalidLinkConfigException::class);
        $this->expectExceptionMessage('unsupported transport "pigeon"');

        $loader->getLink('bad');
    }

    #[Test]
    public function itThrowsOnInvalidEmailAddress(): void
    {
        file_put_contents($this->tmpDir . '/bad.yaml', <<<'YAML'
parameters: {}
message_template: 'test'
channels:
    - transport: email
      options:
          to: 'not-an-email'
          subject: 'Alert'
YAML);

        $loader = new LinkConfigLoader($this->tmpDir);

        try {
            $loader->getLink('bad');
            $this->fail('Expected InvalidLinkConfigException');
        } catch (InvalidLinkConfigException $e) {
            $this->assertCount(1, $e->getErrors());
            $this->assertStringContainsString('invalid email address', $e->getErrors()[0]);
            $this->assertStringContainsString('not-an-email', $e->getErrors()[0]);
        }
    }

    #[Test]
    public function itThrowsOnMissingEmailTo(): void
    {
        file_put_contents($this->tmpDir . '/bad.yaml', <<<'YAML'
parameters: {}
message_template: 'test'
channels:
    - transport: email
      options:
          subject: 'Alert'
YAML);

        $loader = new LinkConfigLoader($this->tmpDir);

        try {
            $loader->getLink('bad');
            $this->fail('Expected InvalidLinkConfigException');
        } catch (InvalidLinkConfigException $e) {
            $this->assertCount(1, $e->getErrors());
            $this->assertStringContainsString('missing required "to" option', $e->getErrors()[0]);
        }
    }

    #[Test]
    public function itThrowsOnEmailWithoutOptions(): void
    {
        file_put_contents($this->tmpDir . '/bad.yaml', <<<'YAML'
parameters: {}
message_template: 'test'
channels:
    - transport: email
YAML);

        $loader = new LinkConfigLoader($this->tmpDir);

        try {
            $loader->getLink('bad');
            $this->fail('Expected InvalidLinkConfigException');
        } catch (InvalidLinkConfigException $e) {
            $this->assertCount(1, $e->getErrors());
            $this->assertStringContainsString('email', $e->getErrors()[0]);
            $this->assertStringContainsString('missing required "to" option', $e->getErrors()[0]);
        }
    }

    #[Test]
    public function itAcceptsValidEmailAddress(): void
    {
        file_put_contents($this->tmpDir . '/good.yaml', <<<'YAML'
parameters: {}
message_template: 'test'
channels:
    - transport: email
      options:
          to: 'team@example.com'
          subject: 'Alert'
YAML);

        $loader = new LinkConfigLoader($this->tmpDir);
        $link = $loader->getLink('good');

        $this->assertSame('team@example.com', $link->channels[0]->options['to']);
    }

    #[Test]
    public function itThrowsOnInvalidPhoneNumber(): void
    {
        file_put_contents($this->tmpDir . '/bad.yaml', <<<'YAML'
parameters: {}
message_template: 'test'
channels:
    - transport: sms
      options:
          to: '12345'
YAML);

        $loader = new LinkConfigLoader($this->tmpDir);

        try {
            $loader->getLink('bad');
            $this->fail('Expected InvalidLinkConfigException');
        } catch (InvalidLinkConfigException $e) {
            $this->assertCount(1, $e->getErrors());
            $this->assertStringContainsString('invalid phone number', $e->getErrors()[0]);
            $this->assertStringContainsString('E.164', $e->getErrors()[0]);
        }
    }

    #[Test]
    public function itThrowsOnMissingSmsTo(): void
    {
        file_put_contents($this->tmpDir . '/bad.yaml', <<<'YAML'
parameters: {}
message_template: 'test'
channels:
    - transport: sms
YAML);

        $loader = new LinkConfigLoader($this->tmpDir);

        try {
            $loader->getLink('bad');
            $this->fail('Expected InvalidLinkConfigException');
        } catch (InvalidLinkConfigException $e) {
            $this->assertCount(1, $e->getErrors());
            $this->assertStringContainsString('sms', $e->getErrors()[0]);
            $this->assertStringContainsString('missing required "to" option', $e->getErrors()[0]);
        }
    }

    #[Test]
    public function itAcceptsValidE164PhoneNumber(): void
    {
        file_put_contents($this->tmpDir . '/good.yaml', <<<'YAML'
parameters: {}
message_template: 'test'
channels:
    - transport: sms
      options:
          to: '+1234567890'
YAML);

        $loader = new LinkConfigLoader($this->tmpDir);
        $link = $loader->getLink('good');

        $this->assertSame('+1234567890', $link->channels[0]->options['to']);
    }

    #[Test]
    public function itCollectsMultipleChannelOptionErrors(): void
    {
        file_put_contents($this->tmpDir . '/bad.yaml', <<<'YAML'
parameters: {}
message_template: 'test'
channels:
    - transport: email
      options:
          to: 'bad-email'
          subject: 'Alert'
    - transport: sms
      options:
          to: 'bad-phone'
YAML);

        $loader = new LinkConfigLoader($this->tmpDir);

        try {
            $loader->getLink('bad');
            $this->fail('Expected InvalidLinkConfigException');
        } catch (InvalidLinkConfigException $e) {
            $this->assertCount(2, $e->getErrors());
            $this->assertStringContainsString('invalid email', $e->getErrors()[0]);
            $this->assertStringContainsString('invalid phone', $e->getErrors()[1]);
        }
    }

    #[Test]
    public function itThrowsOnUndefinedTemplatePlaceholder(): void
    {
        file_put_contents($this->tmpDir . '/bad.yaml', <<<'YAML'
parameters:
    name:
        required: true
        type: string
message_template: 'Hello {name}, your {item} is ready'
channels:
    - transport: slack
YAML);

        $loader = new LinkConfigLoader($this->tmpDir);

        try {
            $loader->getLink('bad');
            $this->fail('Expected InvalidLinkConfigException');
        } catch (InvalidLinkConfigException $e) {
            $this->assertCount(1, $e->getErrors());
            $this->assertStringContainsString('{item}', $e->getErrors()[0]);
            $this->assertStringContainsString('no matching parameter definition', $e->getErrors()[0]);
        }
    }

    #[Test]
    public function itThrowsOnMultipleUndefinedPlaceholders(): void
    {
        file_put_contents($this->tmpDir . '/bad.yaml', <<<'YAML'
parameters: {}
message_template: '{greeting} {name}, welcome to {place}'
channels:
    - transport: slack
YAML);

        $loader = new LinkConfigLoader($this->tmpDir);

        try {
            $loader->getLink('bad');
            $this->fail('Expected InvalidLinkConfigException');
        } catch (InvalidLinkConfigException $e) {
            $this->assertCount(3, $e->getErrors());
        }
    }

    #[Test]
    public function itAcceptsTemplateWithAllPlaceholdersDefined(): void
    {
        file_put_contents($this->tmpDir . '/good.yaml', <<<'YAML'
parameters:
    server:
        required: true
        type: string
    status:
        required: true
        type: string
message_template: '[{status}] Server {server}'
channels:
    - transport: slack
YAML);

        $loader = new LinkConfigLoader($this->tmpDir);
        $link = $loader->getLink('good');

        $this->assertSame('[{status}] Server {server}', $link->messageTemplate);
    }

    #[Test]
    public function itAcceptsTemplateWithNoPlaceholders(): void
    {
        file_put_contents($this->tmpDir . '/static.yaml', <<<'YAML'
parameters: {}
message_template: 'Static message with no placeholders'
channels:
    - transport: slack
YAML);

        $loader = new LinkConfigLoader($this->tmpDir);
        $link = $loader->getLink('static');

        $this->assertSame('Static message with no placeholders', $link->messageTemplate);
    }

    #[Test]
    public function itCollectsMultipleValidationErrors(): void
    {
        file_put_contents($this->tmpDir . '/bad.yaml', <<<'YAML'
parameters: {}
YAML);

        $loader = new LinkConfigLoader($this->tmpDir);

        try {
            $loader->getLink('bad');
            $this->fail('Expected InvalidLinkConfigException');
        } catch (InvalidLinkConfigException $e) {
            $this->assertSame('bad', $e->getLinkName());
            $this->assertCount(2, $e->getErrors());
            $this->assertStringContainsString('message_template', $e->getErrors()[0]);
            $this->assertStringContainsString('channels', $e->getErrors()[1]);
        }
    }
}
