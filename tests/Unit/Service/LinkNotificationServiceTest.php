<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\ChannelDefinition;
use App\Dto\LinkDefinition;
use App\Dto\ParameterDefinition;
use App\Exception\LinkNotFoundException;
use App\Service\LinkConfigLoader;
use App\Service\LinkNotificationService;
use App\Service\MessageBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\TexterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class LinkNotificationServiceTest extends TestCase
{
    #[Test]
    public function sendDispatchesChatMessage(): void
    {
        $link = new LinkDefinition(
            name: 'test',
            messageTemplate: 'Alert: {server}',
            parameters: [new ParameterDefinition('server', true, 'string')],
            channels: [new ChannelDefinition('slack')],
        );

        $configLoader = $this->createStub(LinkConfigLoader::class);
        $configLoader->method('getLink')->willReturn($link);

        $chatter = $this->createMock(ChatterInterface::class);
        $chatter->expects($this->once())
            ->method('send')
            ->with($this->callback(static fn (ChatMessage $msg) => 'Alert: web1' === $msg->getSubject()
                && 'slack' === $msg->getTransport()));

        $service = new LinkNotificationService(
            $configLoader,
            new MessageBuilder(),
            $chatter,
            $this->createStub(TexterInterface::class),
            $this->createStub(MailerInterface::class),
            $this->createStub(HttpClientInterface::class),
            'https://hooks.slack.com/services/test/test/test',
        );

        $result = $service->send('test', ['server' => 'web1']);

        $this->assertSame(['slack'], $result);
    }

    #[Test]
    public function sendDispatchesSmsMessage(): void
    {
        $link = new LinkDefinition(
            name: 'test',
            messageTemplate: 'Deploy: {app}',
            parameters: [new ParameterDefinition('app', true, 'string')],
            channels: [new ChannelDefinition('sms', ['to' => '+1234567890'])],
        );

        $configLoader = $this->createStub(LinkConfigLoader::class);
        $configLoader->method('getLink')->willReturn($link);

        $texter = $this->createMock(TexterInterface::class);
        $texter->expects($this->once())
            ->method('send')
            ->with($this->callback(static fn (SmsMessage $msg) => 'Deploy: myapp' === $msg->getSubject()
                && '+1234567890' === $msg->getPhone()));

        $service = new LinkNotificationService(
            $configLoader,
            new MessageBuilder(),
            $this->createStub(ChatterInterface::class),
            $texter,
            $this->createStub(MailerInterface::class),
            $this->createStub(HttpClientInterface::class),
            'https://hooks.slack.com/services/test/test/test',
        );

        $result = $service->send('test', ['app' => 'myapp']);

        $this->assertSame(['sms'], $result);
    }

    #[Test]
    public function sendDispatchesEmail(): void
    {
        $link = new LinkDefinition(
            name: 'test',
            messageTemplate: 'Deployed {app}',
            parameters: [new ParameterDefinition('app', true, 'string')],
            channels: [new ChannelDefinition('email', [
                'to' => 'team@example.com',
                'subject' => 'Deploy: {app}',
            ])],
        );

        $configLoader = $this->createStub(LinkConfigLoader::class);
        $configLoader->method('getLink')->willReturn($link);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())
            ->method('send')
            ->with($this->callback(static function (Email $email) {
                return 'team@example.com' === $email->getTo()[0]->getAddress()
                    && 'Deploy: myapp' === $email->getSubject()
                    && 'Deployed myapp' === $email->getTextBody();
            }));

        $service = new LinkNotificationService(
            $configLoader,
            new MessageBuilder(),
            $this->createStub(ChatterInterface::class),
            $this->createStub(TexterInterface::class),
            $mailer,
            $this->createStub(HttpClientInterface::class),
            'https://hooks.slack.com/services/test/test/test',
        );

        $result = $service->send('test', ['app' => 'myapp']);

        $this->assertSame(['email'], $result);
    }

    #[Test]
    public function sendDispatchesSlackWebhook(): void
    {
        $link = new LinkDefinition(
            name: 'test-slack',
            messageTemplate: '{message}',
            parameters: [new ParameterDefinition('message', true, 'string')],
            channels: [new ChannelDefinition('slack-webhook')],
        );

        $configLoader = $this->createStub(LinkConfigLoader::class);
        $configLoader->method('getLink')->willReturn($link);

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://hooks.slack.com/services/test/test/test',
                $this->callback(
                    static fn (array $options) => ['text' => 'Hello from Linker'] === ($options['json'] ?? null),
                ),
            )
            ->willReturn($response);

        $service = new LinkNotificationService(
            $configLoader,
            new MessageBuilder(),
            $this->createStub(ChatterInterface::class),
            $this->createStub(TexterInterface::class),
            $this->createStub(MailerInterface::class),
            $httpClient,
            'https://hooks.slack.com/services/test/test/test',
        );

        $result = $service->send('test-slack', ['message' => 'Hello from Linker']);

        $this->assertSame(['slack-webhook'], $result);
    }

    #[Test]
    public function sendReturnsAllNotifiedTransports(): void
    {
        $link = new LinkDefinition(
            name: 'test',
            messageTemplate: '{msg}',
            parameters: [new ParameterDefinition('msg', true, 'string')],
            channels: [
                new ChannelDefinition('slack'),
                new ChannelDefinition('telegram'),
            ],
        );

        $configLoader = $this->createStub(LinkConfigLoader::class);
        $configLoader->method('getLink')->willReturn($link);

        $chatter = $this->createMock(ChatterInterface::class);
        $chatter->expects($this->exactly(2))->method('send');

        $service = new LinkNotificationService(
            $configLoader,
            new MessageBuilder(),
            $chatter,
            $this->createStub(TexterInterface::class),
            $this->createStub(MailerInterface::class),
            $this->createStub(HttpClientInterface::class),
            'https://hooks.slack.com/services/test/test/test',
        );

        $result = $service->send('test', ['msg' => 'hello']);

        $this->assertSame(['slack', 'telegram'], $result);
    }

    #[Test]
    public function sendPropagatesLinkNotFoundException(): void
    {
        $configLoader = $this->createStub(LinkConfigLoader::class);
        $configLoader->method('getLink')
            ->willThrowException(new LinkNotFoundException('missing'));

        $service = new LinkNotificationService(
            $configLoader,
            new MessageBuilder(),
            $this->createStub(ChatterInterface::class),
            $this->createStub(TexterInterface::class),
            $this->createStub(MailerInterface::class),
            $this->createStub(HttpClientInterface::class),
            'https://hooks.slack.com/services/test/test/test',
        );

        $this->expectException(LinkNotFoundException::class);

        $service->send('missing', []);
    }
}
