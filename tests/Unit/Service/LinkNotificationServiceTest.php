<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\ChannelDefinition;
use App\Dto\LinkDefinition;
use App\Dto\ParameterDefinition;
use App\Exception\LinkNotFoundException;
use App\Exception\NotificationFailedException;
use App\Service\LinkConfigLoader;
use App\Service\LinkNotificationService;
use App\Service\MessageBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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
            $this->createStub(LoggerInterface::class),
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
            $this->createStub(LoggerInterface::class),
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
            $this->createStub(LoggerInterface::class),
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
                    static fn (array $options) => ['text' => 'Hello from Linker'] === ($options['json'] ?? null)
                        && 10.0 === ($options['timeout'] ?? null),
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
            $this->createStub(LoggerInterface::class),
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
            $this->createStub(LoggerInterface::class),
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
            $this->createStub(LoggerInterface::class),
            'https://hooks.slack.com/services/test/test/test',
        );

        $this->expectException(LinkNotFoundException::class);

        $service->send('missing', []);
    }

    #[Test]
    public function sendThrowsNotificationFailedExceptionOnTransportFailure(): void
    {
        $link = new LinkDefinition(
            name: 'test',
            messageTemplate: '{msg}',
            parameters: [new ParameterDefinition('msg', true, 'string')],
            channels: [new ChannelDefinition('slack')],
        );

        $configLoader = $this->createStub(LinkConfigLoader::class);
        $configLoader->method('getLink')->willReturn($link);

        $chatter = $this->createStub(ChatterInterface::class);
        $chatter->method('send')
            ->willThrowException(new \RuntimeException('Slack API error'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Failed to dispatch'),
                $this->callback(static fn (array $ctx) => 'slack' === $ctx['transport']
                    && 'test' === $ctx['link']
                    && 'Slack API error' === $ctx['error']),
            );

        $service = new LinkNotificationService(
            $configLoader,
            new MessageBuilder(),
            $chatter,
            $this->createStub(TexterInterface::class),
            $this->createStub(MailerInterface::class),
            $this->createStub(HttpClientInterface::class),
            $logger,
            'https://hooks.slack.com/services/test/test/test',
        );

        try {
            $service->send('test', ['msg' => 'hello']);
            $this->fail('Expected NotificationFailedException');
        } catch (NotificationFailedException $e) {
            $this->assertSame('test', $e->getLinkName());
            $this->assertSame([], $e->getSucceededTransports());
            $this->assertSame(['slack' => 'Slack API error'], $e->getFailedTransports());
        }
    }

    #[Test]
    public function sendContinuesDispatchingAfterTransportFailure(): void
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

        $callCount = 0;
        $chatter = $this->createMock(ChatterInterface::class);
        $chatter->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function () use (&$callCount): void {
                $callCount++;
                if (1 === $callCount) {
                    throw new \RuntimeException('Slack down');
                }
            });

        $service = new LinkNotificationService(
            $configLoader,
            new MessageBuilder(),
            $chatter,
            $this->createStub(TexterInterface::class),
            $this->createStub(MailerInterface::class),
            $this->createStub(HttpClientInterface::class),
            $this->createStub(LoggerInterface::class),
            'https://hooks.slack.com/services/test/test/test',
        );

        try {
            $service->send('test', ['msg' => 'hello']);
            $this->fail('Expected NotificationFailedException');
        } catch (NotificationFailedException $e) {
            $this->assertSame(['telegram'], $e->getSucceededTransports());
            $this->assertSame(['slack' => 'Slack down'], $e->getFailedTransports());
        }
    }

    #[Test]
    public function sendLogsSuccessfulDispatches(): void
    {
        $link = new LinkDefinition(
            name: 'test',
            messageTemplate: '{msg}',
            parameters: [new ParameterDefinition('msg', true, 'string')],
            channels: [new ChannelDefinition('slack')],
        );

        $configLoader = $this->createStub(LinkConfigLoader::class);
        $configLoader->method('getLink')->willReturn($link);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))
            ->method('info')
            ->with(
                $this->logicalOr(
                    $this->stringContains('Dispatching notification'),
                    $this->stringContains('Successfully dispatched'),
                ),
                $this->isType('array'),
            );

        $service = new LinkNotificationService(
            $configLoader,
            new MessageBuilder(),
            $this->createStub(ChatterInterface::class),
            $this->createStub(TexterInterface::class),
            $this->createStub(MailerInterface::class),
            $this->createStub(HttpClientInterface::class),
            $logger,
            'https://hooks.slack.com/services/test/test/test',
        );

        $service->send('test', ['msg' => 'hello']);
    }

    #[Test]
    public function slackWebhookNon200ResponseCausesFailure(): void
    {
        $link = new LinkDefinition(
            name: 'test',
            messageTemplate: '{msg}',
            parameters: [new ParameterDefinition('msg', true, 'string')],
            channels: [new ChannelDefinition('slack-webhook')],
        );

        $configLoader = $this->createStub(LinkConfigLoader::class);
        $configLoader->method('getLink')->willReturn($link);

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(403);
        $response->method('getContent')->willReturn('invalid_token');

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $service = new LinkNotificationService(
            $configLoader,
            new MessageBuilder(),
            $this->createStub(ChatterInterface::class),
            $this->createStub(TexterInterface::class),
            $this->createStub(MailerInterface::class),
            $httpClient,
            $this->createStub(LoggerInterface::class),
            'https://hooks.slack.com/services/test/test/test',
        );

        try {
            $service->send('test', ['msg' => 'hello']);
            $this->fail('Expected NotificationFailedException');
        } catch (NotificationFailedException $e) {
            $this->assertSame([], $e->getSucceededTransports());
            $this->assertArrayHasKey('slack-webhook', $e->getFailedTransports());
            $this->assertStringContainsString('403', $e->getFailedTransports()['slack-webhook']);
        }
    }

    #[Test]
    public function slackWebhookHttpExceptionCausesFailure(): void
    {
        $link = new LinkDefinition(
            name: 'test',
            messageTemplate: '{msg}',
            parameters: [new ParameterDefinition('msg', true, 'string')],
            channels: [new ChannelDefinition('slack-webhook')],
        );

        $configLoader = $this->createStub(LinkConfigLoader::class);
        $configLoader->method('getLink')->willReturn($link);

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')
            ->willThrowException(new \RuntimeException('Connection timed out'));

        $service = new LinkNotificationService(
            $configLoader,
            new MessageBuilder(),
            $this->createStub(ChatterInterface::class),
            $this->createStub(TexterInterface::class),
            $this->createStub(MailerInterface::class),
            $httpClient,
            $this->createStub(LoggerInterface::class),
            'https://hooks.slack.com/services/test/test/test',
        );

        try {
            $service->send('test', ['msg' => 'hello']);
            $this->fail('Expected NotificationFailedException');
        } catch (NotificationFailedException $e) {
            $this->assertSame([], $e->getSucceededTransports());
            $this->assertStringContainsString('Connection timed out', $e->getFailedTransports()['slack-webhook']);
        }
    }

    #[Test]
    public function smsMissingToOptionCausesFailure(): void
    {
        $link = new LinkDefinition(
            name: 'test',
            messageTemplate: '{msg}',
            parameters: [new ParameterDefinition('msg', true, 'string')],
            channels: [new ChannelDefinition('sms')],
        );

        $configLoader = $this->createStub(LinkConfigLoader::class);
        $configLoader->method('getLink')->willReturn($link);

        $service = new LinkNotificationService(
            $configLoader,
            new MessageBuilder(),
            $this->createStub(ChatterInterface::class),
            $this->createStub(TexterInterface::class),
            $this->createStub(MailerInterface::class),
            $this->createStub(HttpClientInterface::class),
            $this->createStub(LoggerInterface::class),
            'https://hooks.slack.com/services/test/test/test',
        );

        try {
            $service->send('test', ['msg' => 'hello']);
            $this->fail('Expected NotificationFailedException');
        } catch (NotificationFailedException $e) {
            $this->assertStringContainsString('SMS channel requires "to" option', $e->getFailedTransports()['sms']);
        }
    }

    #[Test]
    public function smsTexterExceptionCausesFailure(): void
    {
        $link = new LinkDefinition(
            name: 'test',
            messageTemplate: '{msg}',
            parameters: [new ParameterDefinition('msg', true, 'string')],
            channels: [new ChannelDefinition('sms', ['to' => '+1234567890'])],
        );

        $configLoader = $this->createStub(LinkConfigLoader::class);
        $configLoader->method('getLink')->willReturn($link);

        $texter = $this->createStub(TexterInterface::class);
        $texter->method('send')
            ->willThrowException(new \RuntimeException('Twilio service unavailable'));

        $service = new LinkNotificationService(
            $configLoader,
            new MessageBuilder(),
            $this->createStub(ChatterInterface::class),
            $texter,
            $this->createStub(MailerInterface::class),
            $this->createStub(HttpClientInterface::class),
            $this->createStub(LoggerInterface::class),
            'https://hooks.slack.com/services/test/test/test',
        );

        try {
            $service->send('test', ['msg' => 'hello']);
            $this->fail('Expected NotificationFailedException');
        } catch (NotificationFailedException $e) {
            $this->assertStringContainsString('Twilio service unavailable', $e->getFailedTransports()['sms']);
        }
    }

    #[Test]
    public function emailMissingToOptionCausesFailure(): void
    {
        $link = new LinkDefinition(
            name: 'test',
            messageTemplate: '{msg}',
            parameters: [new ParameterDefinition('msg', true, 'string')],
            channels: [new ChannelDefinition('email')],
        );

        $configLoader = $this->createStub(LinkConfigLoader::class);
        $configLoader->method('getLink')->willReturn($link);

        $service = new LinkNotificationService(
            $configLoader,
            new MessageBuilder(),
            $this->createStub(ChatterInterface::class),
            $this->createStub(TexterInterface::class),
            $this->createStub(MailerInterface::class),
            $this->createStub(HttpClientInterface::class),
            $this->createStub(LoggerInterface::class),
            'https://hooks.slack.com/services/test/test/test',
        );

        try {
            $service->send('test', ['msg' => 'hello']);
            $this->fail('Expected NotificationFailedException');
        } catch (NotificationFailedException $e) {
            $this->assertStringContainsString('Email channel requires "to" option', $e->getFailedTransports()['email']);
        }
    }

    #[Test]
    public function emailMailerExceptionCausesFailure(): void
    {
        $link = new LinkDefinition(
            name: 'test',
            messageTemplate: '{msg}',
            parameters: [new ParameterDefinition('msg', true, 'string')],
            channels: [new ChannelDefinition('email', [
                'to' => 'team@example.com',
                'subject' => 'Test',
            ])],
        );

        $configLoader = $this->createStub(LinkConfigLoader::class);
        $configLoader->method('getLink')->willReturn($link);

        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')
            ->willThrowException(new \RuntimeException('SMTP connection refused'));

        $service = new LinkNotificationService(
            $configLoader,
            new MessageBuilder(),
            $this->createStub(ChatterInterface::class),
            $this->createStub(TexterInterface::class),
            $mailer,
            $this->createStub(HttpClientInterface::class),
            $this->createStub(LoggerInterface::class),
            'https://hooks.slack.com/services/test/test/test',
        );

        try {
            $service->send('test', ['msg' => 'hello']);
            $this->fail('Expected NotificationFailedException');
        } catch (NotificationFailedException $e) {
            $this->assertStringContainsString('SMTP connection refused', $e->getFailedTransports()['email']);
        }
    }

    #[Test]
    public function allTransportsFailReportsAllFailures(): void
    {
        $link = new LinkDefinition(
            name: 'test',
            messageTemplate: '{msg}',
            parameters: [new ParameterDefinition('msg', true, 'string')],
            channels: [
                new ChannelDefinition('slack'),
                new ChannelDefinition('email', ['to' => 'a@b.com']),
            ],
        );

        $configLoader = $this->createStub(LinkConfigLoader::class);
        $configLoader->method('getLink')->willReturn($link);

        $chatter = $this->createStub(ChatterInterface::class);
        $chatter->method('send')
            ->willThrowException(new \RuntimeException('Slack down'));

        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')
            ->willThrowException(new \RuntimeException('SMTP timeout'));

        $service = new LinkNotificationService(
            $configLoader,
            new MessageBuilder(),
            $chatter,
            $this->createStub(TexterInterface::class),
            $mailer,
            $this->createStub(HttpClientInterface::class),
            $this->createStub(LoggerInterface::class),
            'https://hooks.slack.com/services/test/test/test',
        );

        try {
            $service->send('test', ['msg' => 'hello']);
            $this->fail('Expected NotificationFailedException');
        } catch (NotificationFailedException $e) {
            $this->assertSame([], $e->getSucceededTransports());
            $this->assertCount(2, $e->getFailedTransports());
            $this->assertArrayHasKey('slack', $e->getFailedTransports());
            $this->assertArrayHasKey('email', $e->getFailedTransports());
        }
    }

    #[Test]
    public function emailSubjectSanitizesCrlfFromInterpolatedParams(): void
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
                return 'Deploy: evil Bcc: attacker@evil.com' === $email->getSubject();
            }));

        $service = new LinkNotificationService(
            $configLoader,
            new MessageBuilder(),
            $this->createStub(ChatterInterface::class),
            $this->createStub(TexterInterface::class),
            $mailer,
            $this->createStub(HttpClientInterface::class),
            $this->createStub(LoggerInterface::class),
            'https://hooks.slack.com/services/test/test/test',
        );

        $service->send('test', ['app' => "evil\r\nBcc: attacker@evil.com"]);
    }

    #[Test]
    public function emailSubjectSanitizesLoneCrAndLf(): void
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
                return 'Deploy: line1 line2 line3' === $email->getSubject();
            }));

        $service = new LinkNotificationService(
            $configLoader,
            new MessageBuilder(),
            $this->createStub(ChatterInterface::class),
            $this->createStub(TexterInterface::class),
            $mailer,
            $this->createStub(HttpClientInterface::class),
            $this->createStub(LoggerInterface::class),
            'https://hooks.slack.com/services/test/test/test',
        );

        $service->send('test', ['app' => "line1\rline2\nline3"]);
    }

    #[Test]
    public function smsMessageTruncatedWhenExceedingLimit(): void
    {
        $longMessage = str_repeat('a', 1700);
        $link = new LinkDefinition(
            name: 'test',
            messageTemplate: '{msg}',
            parameters: [new ParameterDefinition('msg', true, 'string')],
            channels: [new ChannelDefinition('sms', ['to' => '+1234567890'])],
        );

        $configLoader = $this->createStub(LinkConfigLoader::class);
        $configLoader->method('getLink')->willReturn($link);

        $texter = $this->createMock(TexterInterface::class);
        $texter->expects($this->once())
            ->method('send')
            ->with($this->callback(static function (SmsMessage $msg) {
                return 1600 === mb_strlen($msg->getSubject())
                    && str_ends_with($msg->getSubject(), '...');
            }));

        $warningLogged = false;
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')
            ->willReturnCallback(function (string $message) use (&$warningLogged): void {
                if (str_contains($message, 'SMS message exceeds')) {
                    $warningLogged = true;
                }
            });

        $service = new LinkNotificationService(
            $configLoader,
            new MessageBuilder(),
            $this->createStub(ChatterInterface::class),
            $texter,
            $this->createStub(MailerInterface::class),
            $this->createStub(HttpClientInterface::class),
            $logger,
            'https://hooks.slack.com/services/test/test/test',
        );

        $service->send('test', ['msg' => $longMessage]);
        $this->assertTrue($warningLogged, 'Expected warning about SMS message length');
    }

    #[Test]
    public function discordMessageTruncatedWhenExceedingLimit(): void
    {
        $longMessage = str_repeat('b', 2500);
        $link = new LinkDefinition(
            name: 'test',
            messageTemplate: '{msg}',
            parameters: [new ParameterDefinition('msg', true, 'string')],
            channels: [new ChannelDefinition('discord')],
        );

        $configLoader = $this->createStub(LinkConfigLoader::class);
        $configLoader->method('getLink')->willReturn($link);

        $chatter = $this->createMock(ChatterInterface::class);
        $chatter->expects($this->once())
            ->method('send')
            ->with($this->callback(static function (ChatMessage $msg) {
                return 2000 === mb_strlen($msg->getSubject())
                    && str_ends_with($msg->getSubject(), '...');
            }));

        $warningLogged = false;
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')
            ->willReturnCallback(function (string $message) use (&$warningLogged): void {
                if (str_contains($message, 'Message exceeds')) {
                    $warningLogged = true;
                }
            });

        $service = new LinkNotificationService(
            $configLoader,
            new MessageBuilder(),
            $chatter,
            $this->createStub(TexterInterface::class),
            $this->createStub(MailerInterface::class),
            $this->createStub(HttpClientInterface::class),
            $logger,
            'https://hooks.slack.com/services/test/test/test',
        );

        $service->send('test', ['msg' => $longMessage]);
        $this->assertTrue($warningLogged, 'Expected warning about discord message length');
    }

    #[Test]
    public function shortMessageIsNotTruncated(): void
    {
        $link = new LinkDefinition(
            name: 'test',
            messageTemplate: '{msg}',
            parameters: [new ParameterDefinition('msg', true, 'string')],
            channels: [new ChannelDefinition('sms', ['to' => '+1234567890'])],
        );

        $configLoader = $this->createStub(LinkConfigLoader::class);
        $configLoader->method('getLink')->willReturn($link);

        $texter = $this->createMock(TexterInterface::class);
        $texter->expects($this->once())
            ->method('send')
            ->with($this->callback(static function (SmsMessage $msg) {
                return 'short message' === $msg->getSubject();
            }));

        $service = new LinkNotificationService(
            $configLoader,
            new MessageBuilder(),
            $this->createStub(ChatterInterface::class),
            $texter,
            $this->createStub(MailerInterface::class),
            $this->createStub(HttpClientInterface::class),
            $this->createStub(LoggerInterface::class),
            'https://hooks.slack.com/services/test/test/test',
        );

        $service->send('test', ['msg' => 'short message']);
    }

    #[Test]
    public function mixedTransportSuccessAndFailureAcrossDifferentTypes(): void
    {
        $link = new LinkDefinition(
            name: 'test',
            messageTemplate: '{msg}',
            parameters: [new ParameterDefinition('msg', true, 'string')],
            channels: [
                new ChannelDefinition('slack'),
                new ChannelDefinition('sms', ['to' => '+1234567890']),
                new ChannelDefinition('email', ['to' => 'a@b.com']),
            ],
        );

        $configLoader = $this->createStub(LinkConfigLoader::class);
        $configLoader->method('getLink')->willReturn($link);

        $chatter = $this->createStub(ChatterInterface::class);

        $texter = $this->createStub(TexterInterface::class);
        $texter->method('send')
            ->willThrowException(new \RuntimeException('Twilio error'));

        $mailer = $this->createStub(MailerInterface::class);

        $service = new LinkNotificationService(
            $configLoader,
            new MessageBuilder(),
            $chatter,
            $texter,
            $mailer,
            $this->createStub(HttpClientInterface::class),
            $this->createStub(LoggerInterface::class),
            'https://hooks.slack.com/services/test/test/test',
        );

        try {
            $service->send('test', ['msg' => 'hello']);
            $this->fail('Expected NotificationFailedException');
        } catch (NotificationFailedException $e) {
            $this->assertSame(['slack', 'email'], $e->getSucceededTransports());
            $this->assertSame(['sms' => 'Twilio error'], $e->getFailedTransports());
        }
    }
}
