<?php

declare(strict_types=1);

namespace App\Tests\Unit\Phase;

use App\Dto\ChannelDefinition;
use App\Dto\LinkDefinition;
use App\Dto\ParameterDefinition;
use App\Service\LinkConfigLoader;
use App\Service\LinkNotificationService;
use App\Service\MessageBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
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

class DispatchPhaseTest extends TestCase
{
    private const WEBHOOK_URL = 'https://hooks.slack.com/services/test/test/test';

    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtureProvider(): iterable
    {
        $dir = __DIR__ . '/../../fixtures/dispatch';
        $files = glob($dir . '/*.json');

        foreach ($files as $file) {
            yield basename($file, '.json') => [$file];
        }
    }

    #[Test]
    #[DataProvider('fixtureProvider')]
    public function itDispatchesMatchingFixtureExpectation(string $fixtureFile): void
    {
        $fixture = json_decode(file_get_contents($fixtureFile), true, 512, JSON_THROW_ON_ERROR);

        $link = $this->buildLinkFromFixture($fixture['link']);

        $configLoader = $this->createStub(LinkConfigLoader::class);
        $configLoader->method('getLink')->willReturn($link);

        $chatter = $this->createMock(ChatterInterface::class);
        $texter = $this->createMock(TexterInterface::class);
        $mailer = $this->createMock(MailerInterface::class);
        $httpClient = $this->createMock(HttpClientInterface::class);

        $this->configureExpectations(
            $fixture,
            $chatter,
            $texter,
            $mailer,
            $httpClient,
        );

        $service = new LinkNotificationService(
            $configLoader,
            new MessageBuilder(),
            $chatter,
            $texter,
            $mailer,
            $httpClient,
            self::WEBHOOK_URL,
        );

        $result = $service->send($link->name, $fixture['input']);

        $this->assertSame($fixture['expected_transports'], $result);
    }

    private function buildLinkFromFixture(array $linkData): LinkDefinition
    {
        $parameters = array_map(
            static fn (array $p) => new ParameterDefinition(
                name: $p['name'],
                required: $p['required'],
                type: $p['type'],
                default: $p['default'],
            ),
            $linkData['parameters'],
        );

        $channels = array_map(
            static fn (array $c) => new ChannelDefinition(
                transport: $c['transport'],
                options: \is_array($c['options']) ? $c['options'] : [],
            ),
            $linkData['channels'],
        );

        return new LinkDefinition(
            name: $linkData['name'],
            messageTemplate: $linkData['messageTemplate'],
            parameters: $parameters,
            channels: $channels,
        );
    }

    private function configureExpectations(
        array $fixture,
        ChatterInterface&\PHPUnit\Framework\MockObject\MockObject $chatter,
        TexterInterface&\PHPUnit\Framework\MockObject\MockObject $texter,
        MailerInterface&\PHPUnit\Framework\MockObject\MockObject $mailer,
        HttpClientInterface&\PHPUnit\Framework\MockObject\MockObject $httpClient,
    ): void {
        $chatCount = 0;
        $smsCount = 0;
        $emailCount = 0;
        $webhookCount = 0;

        foreach ($fixture['expected_transports'] as $transport) {
            match ($transport) {
                'slack', 'telegram', 'discord' => $chatCount++,
                'sms' => $smsCount++,
                'email' => $emailCount++,
                'slack-webhook' => $webhookCount++,
            };
        }

        if ($chatCount > 0) {
            $chatter->expects($this->exactly($chatCount))
                ->method('send')
                ->with($this->callback(
                    static fn (ChatMessage $msg) => $msg->getSubject() === $fixture['expected_message'],
                ));
        }

        if ($smsCount > 0) {
            $texter->expects($this->once())
                ->method('send')
                ->with($this->callback(
                    static fn (SmsMessage $msg) => $msg->getSubject() === $fixture['expected_message'],
                ));
        }

        if ($emailCount > 0) {
            $mailer->expects($this->once())
                ->method('send')
                ->with($this->callback(
                    static function (Email $email) use ($fixture) {
                        $bodyMatch = $email->getTextBody() === $fixture['expected_message'];
                        if (isset($fixture['expected_subject'])) {
                            return $bodyMatch && $email->getSubject() === $fixture['expected_subject'];
                        }

                        return $bodyMatch;
                    },
                ));
        }

        if ($webhookCount > 0) {
            $response = $this->createStub(ResponseInterface::class);
            $response->method('getStatusCode')->willReturn(200);

            $httpClient->expects($this->once())
                ->method('request')
                ->with(
                    'POST',
                    self::WEBHOOK_URL,
                    $this->callback(
                        static fn (array $opts) => ($opts['json']['text'] ?? null) === $fixture['expected_message'],
                    ),
                )
                ->willReturn($response);
        }
    }
}
