<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\LinkConfigLoader;
use App\Service\LinkNotificationService;
use App\Service\MessageBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\TexterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * End-to-end integration test that verifies the full pipeline:
 *   Phase 1 (Config Loading) → Phase 2 (Parameter Validation)
 *   → Phase 3 (Message Building) → Phase 4 (Dispatch)
 *
 * Uses a real LinkConfigLoader and MessageBuilder with YAML fixtures,
 * mocking only the external transport interfaces.
 */
class FullPipelineTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/../fixtures/integration';
    private const WEBHOOK_URL = 'https://hooks.slack.com/services/test/test/test';

    #[Test]
    public function itExecutesAllPhasesWithAllParamsProvided(): void
    {
        // Phase 1: Real config loading from YAML fixture
        $configLoader = new LinkConfigLoader(self::FIXTURE_DIR);
        $messageBuilder = new MessageBuilder();

        // Verify Phase 1 output: config is loaded correctly
        $link = $configLoader->getLink('full-pipeline');
        $this->assertSame('full-pipeline', $link->name);
        $this->assertSame('[{status}] Server {server}: {message}', $link->messageTemplate);
        $this->assertCount(3, $link->parameters);
        $this->assertCount(2, $link->channels);

        // Phase 2: Real parameter validation
        $queryParams = ['server' => 'web-01', 'status' => 'DOWN', 'message' => 'Disk full'];
        $resolved = $messageBuilder->resolveParameters($link, $queryParams);
        $this->assertSame('web-01', $resolved['server']);
        $this->assertSame('DOWN', $resolved['status']);
        $this->assertSame('Disk full', $resolved['message']);

        // Phase 3: Real message building
        $message = $messageBuilder->buildMessage($link, $resolved);
        $this->assertSame('[DOWN] Server web-01: Disk full', $message);

        // Phase 4: Dispatch — mock transports and verify correct calls
        $chatter = $this->createMock(ChatterInterface::class);
        $chatter->expects($this->once())
            ->method('send')
            ->with($this->callback(
                static fn (ChatMessage $msg) => $msg->getSubject() === '[DOWN] Server web-01: Disk full'
                    && $msg->getTransport() === 'slack',
            ));

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())
            ->method('send')
            ->with($this->callback(
                static fn (Email $email) => $email->getTextBody() === '[DOWN] Server web-01: Disk full'
                    && $email->getSubject() === 'Alert: web-01 is DOWN'
                    && $email->getTo()[0]->getAddress() === 'ops@example.com',
            ));

        $texter = $this->createStub(TexterInterface::class);
        $httpClient = $this->createStub(HttpClientInterface::class);

        $service = new LinkNotificationService(
            $configLoader,
            $messageBuilder,
            $chatter,
            $texter,
            $mailer,
            $httpClient,
            self::WEBHOOK_URL,
        );

        $notified = $service->send('full-pipeline', $queryParams);

        $this->assertSame(['slack', 'email'], $notified);
    }

    #[Test]
    public function itExecutesAllPhasesWithOptionalParamDefaults(): void
    {
        // Phase 1: Real config loading
        $configLoader = new LinkConfigLoader(self::FIXTURE_DIR);
        $messageBuilder = new MessageBuilder();

        // Phase 2: Parameter validation — omit optional 'message' param
        $link = $configLoader->getLink('full-pipeline-with-defaults');
        $queryParams = ['server' => 'db-03', 'status' => 'WARN'];
        $resolved = $messageBuilder->resolveParameters($link, $queryParams);

        // Verify default was applied
        $this->assertSame('No details provided', $resolved['message']);

        // Phase 3: Message building with default value interpolated
        $message = $messageBuilder->buildMessage($link, $resolved);
        $this->assertSame('[WARN] Server db-03: No details provided', $message);

        // Phase 4: Dispatch verification
        $chatter = $this->createMock(ChatterInterface::class);
        $chatter->expects($this->once())
            ->method('send')
            ->with($this->callback(
                static fn (ChatMessage $msg) => $msg->getSubject() === '[WARN] Server db-03: No details provided',
            ));

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())
            ->method('send')
            ->with($this->callback(
                static fn (Email $email) => $email->getTextBody() === '[WARN] Server db-03: No details provided'
                    && $email->getSubject() === 'Alert: db-03 is WARN',
            ));

        $texter = $this->createStub(TexterInterface::class);
        $httpClient = $this->createStub(HttpClientInterface::class);

        $service = new LinkNotificationService(
            $configLoader,
            $messageBuilder,
            $chatter,
            $texter,
            $mailer,
            $httpClient,
            self::WEBHOOK_URL,
        );

        $notified = $service->send('full-pipeline-with-defaults', $queryParams);

        $this->assertSame(['slack', 'email'], $notified);
    }

    #[Test]
    public function itRejectsInvalidParametersBeforeDispatch(): void
    {
        // Phase 1: Real config loading
        $configLoader = new LinkConfigLoader(self::FIXTURE_DIR);
        $messageBuilder = new MessageBuilder();

        // Phase 2: Missing required params should throw before reaching dispatch
        $chatter = $this->createMock(ChatterInterface::class);
        $chatter->expects($this->never())->method('send');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $texter = $this->createStub(TexterInterface::class);
        $httpClient = $this->createStub(HttpClientInterface::class);

        $service = new LinkNotificationService(
            $configLoader,
            $messageBuilder,
            $chatter,
            $texter,
            $mailer,
            $httpClient,
            self::WEBHOOK_URL,
        );

        $this->expectException(\App\Exception\InvalidParametersException::class);

        // Omit all required params — pipeline should fail at Phase 2
        $service->send('full-pipeline', []);
    }

    #[Test]
    public function itRejectsUnknownLinkAtConfigPhase(): void
    {
        // Phase 1: Unknown link should throw before any other phase
        $configLoader = new LinkConfigLoader(self::FIXTURE_DIR);
        $messageBuilder = new MessageBuilder();

        $chatter = $this->createMock(ChatterInterface::class);
        $chatter->expects($this->never())->method('send');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $texter = $this->createStub(TexterInterface::class);
        $httpClient = $this->createStub(HttpClientInterface::class);

        $service = new LinkNotificationService(
            $configLoader,
            $messageBuilder,
            $chatter,
            $texter,
            $mailer,
            $httpClient,
            self::WEBHOOK_URL,
        );

        $this->expectException(\App\Exception\LinkNotFoundException::class);

        $service->send('nonexistent-link', ['server' => 'x', 'status' => 'y']);
    }
}
