<?php

declare(strict_types=1);

namespace App\Tests\Unit\Phase;

use App\Dto\ChannelDefinition;
use App\Dto\LinkDefinition;
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
use Symfony\Component\Notifier\TexterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Verifies that the output of phase N is valid input for phase N+1,
 * catching interface mismatches between adjacent phases.
 *
 * Phase 1 (Config Loading) → Phase 2 (Parameter Validation)
 * Phase 2 (Parameter Validation) → Phase 3 (Message Building)
 * Phase 3 (Message Building) → Phase 4 (Dispatch)
 */
class PhaseTransitionTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/../../fixtures';
    private const WEBHOOK_URL = 'https://hooks.slack.com/services/test/test/test';

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function configToValidationProvider(): iterable
    {
        $fixtures = json_decode(
            file_get_contents(self::FIXTURES_DIR . '/phase-transition/config-to-validation.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ($fixtures as $fixture) {
            yield $fixture['description'] => [$fixture];
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function validationToMessageProvider(): iterable
    {
        $fixtures = json_decode(
            file_get_contents(self::FIXTURES_DIR . '/phase-transition/validation-to-message.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ($fixtures as $fixture) {
            yield $fixture['description'] => [$fixture];
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function messageToDispatchProvider(): iterable
    {
        $fixtures = json_decode(
            file_get_contents(self::FIXTURES_DIR . '/phase-transition/message-to-dispatch.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ($fixtures as $fixture) {
            yield $fixture['description'] => [$fixture];
        }
    }

    /**
     * Phase 1 → Phase 2: Config Loading output is valid input for Parameter Validation.
     *
     * Asserts that LinkDefinition returned by config loading can be consumed
     * by resolveParameters() without type errors or structural mismatches.
     *
     * @param array<string, mixed> $fixture
     */
    #[Test]
    #[DataProvider('configToValidationProvider')]
    public function configLoadingOutputIsValidInputForParameterValidation(array $fixture): void
    {
        $configDir = self::FIXTURES_DIR . '/' . $fixture['config_dir'];
        $loader = new LinkConfigLoader($configDir);
        $builder = new MessageBuilder();

        // Phase 1: Config Loading
        $link = $loader->getLink($fixture['link_name']);

        // Structural assertions: Phase 1 output meets Phase 2 contract
        $this->assertInstanceOf(LinkDefinition::class, $link);
        $this->assertIsString($link->name);
        $this->assertIsString($link->messageTemplate);
        $this->assertIsArray($link->parameters);
        $this->assertIsArray($link->channels);

        foreach ($link->parameters as $param) {
            $this->assertIsString($param->name);
            $this->assertIsBool($param->required);
            $this->assertIsString($param->type);
        }

        // Phase 2: Parameter Validation accepts Phase 1 output without error
        $resolved = $builder->resolveParameters($link, $fixture['query_params']);

        $this->assertIsArray($resolved);
        $this->assertSame($fixture['expected_resolved'], $resolved);

        // Verify every resolved value is a string (Phase 2 contract)
        foreach ($resolved as $key => $value) {
            $this->assertIsString($key, 'Resolved parameter key must be a string');
            $this->assertIsString($value, \sprintf('Resolved parameter "%s" value must be a string', $key));
        }
    }

    /**
     * Phase 2 → Phase 3: Parameter Validation output is valid input for Message Building.
     *
     * Asserts that resolved parameters from validation can be consumed
     * by buildMessage() and produce the expected interpolated string.
     *
     * @param array<string, mixed> $fixture
     */
    #[Test]
    #[DataProvider('validationToMessageProvider')]
    public function parameterValidationOutputIsValidInputForMessageBuilding(array $fixture): void
    {
        $configDir = self::FIXTURES_DIR . '/' . $fixture['config_dir'];
        $loader = new LinkConfigLoader($configDir);
        $builder = new MessageBuilder();

        // Phase 1: Config Loading (prerequisite)
        $link = $loader->getLink($fixture['link_name']);

        // Phase 2: Parameter Validation
        $resolved = $builder->resolveParameters($link, $fixture['query_params']);

        // Structural assertions: Phase 2 output meets Phase 3 contract
        $this->assertIsArray($resolved);

        foreach ($resolved as $key => $value) {
            $this->assertIsString($key);
            $this->assertIsString($value);
        }

        // Verify all template placeholders have corresponding resolved params
        preg_match_all('/\{(\w+)\}/', $link->messageTemplate, $matches);
        $placeholders = $matches[1];

        foreach ($placeholders as $placeholder) {
            $this->assertArrayHasKey(
                $placeholder,
                $resolved,
                \sprintf(
                    'Template placeholder "{%s}" has no corresponding resolved parameter — '
                    . 'Phase 2 output is incompatible with Phase 3 input',
                    $placeholder,
                ),
            );
        }

        // Phase 3: Message Building accepts Phase 2 output without error
        $message = $builder->buildMessage($link, $resolved);

        $this->assertIsString($message);
        $this->assertSame($fixture['expected_message'], $message);

        // Verify no unresolved placeholders remain
        $this->assertDoesNotMatchRegularExpression(
            '/\{\w+\}/',
            $message,
            'Message still contains unresolved placeholders — Phase 2→3 mismatch',
        );
    }

    /**
     * Phase 3 → Phase 4: Message Building output is valid input for Dispatch.
     *
     * Asserts that the built message string and link definition can be consumed
     * by the dispatch phase, producing the expected transport notifications.
     *
     * @param array<string, mixed> $fixture
     */
    #[Test]
    #[DataProvider('messageToDispatchProvider')]
    public function messageBuildingOutputIsValidInputForDispatch(array $fixture): void
    {
        $configDir = self::FIXTURES_DIR . '/' . $fixture['config_dir'];
        $loader = new LinkConfigLoader($configDir);
        $builder = new MessageBuilder();

        // Phase 1: Config Loading (prerequisite)
        $link = $loader->getLink($fixture['link_name']);

        // Phase 2: Parameter Validation (prerequisite)
        $resolved = $builder->resolveParameters($link, $fixture['query_params']);

        // Phase 3: Message Building
        $message = $builder->buildMessage($link, $resolved);

        // Structural assertions: Phase 3 output meets Phase 4 contract
        $this->assertIsString($message);
        $this->assertNotEmpty($message, 'Message must not be empty for dispatch');
        $this->assertSame($fixture['expected_message'], $message);

        // Verify link has channels (Phase 4 needs channels to dispatch to)
        $this->assertNotEmpty($link->channels, 'Link must have channels for dispatch');

        foreach ($link->channels as $channel) {
            $this->assertInstanceOf(ChannelDefinition::class, $channel);
            $this->assertIsString($channel->transport);
            $this->assertNotEmpty($channel->transport, 'Channel transport must not be empty');
            $this->assertIsArray($channel->options);
        }

        // Phase 4: Dispatch accepts Phase 3 output — mock transports and verify
        $chatter = $this->createMock(ChatterInterface::class);
        $texter = $this->createMock(TexterInterface::class);
        $mailer = $this->createMock(MailerInterface::class);
        $httpClient = $this->createMock(HttpClientInterface::class);

        $this->configureTransportExpectations(
            $fixture['expected_transports'],
            $fixture['expected_message'],
            $chatter,
            $mailer,
        );

        $service = new LinkNotificationService(
            $loader,
            $builder,
            $chatter,
            $texter,
            $mailer,
            $httpClient,
            self::WEBHOOK_URL,
        );

        $notified = $service->send($fixture['link_name'], $fixture['query_params']);

        $this->assertIsArray($notified);
        $this->assertSame($fixture['expected_transports'], $notified);

        // Verify every notified transport is a non-empty string
        foreach ($notified as $transport) {
            $this->assertIsString($transport);
            $this->assertNotEmpty($transport);
        }
    }

    private function configureTransportExpectations(
        array $expectedTransports,
        string $expectedMessage,
        ChatterInterface&\PHPUnit\Framework\MockObject\MockObject $chatter,
        MailerInterface&\PHPUnit\Framework\MockObject\MockObject $mailer,
    ): void {
        $chatCount = 0;
        $emailCount = 0;

        foreach ($expectedTransports as $transport) {
            match ($transport) {
                'slack', 'telegram', 'discord' => $chatCount++,
                'email' => $emailCount++,
                default => null,
            };
        }

        if ($chatCount > 0) {
            $chatter->expects($this->exactly($chatCount))
                ->method('send')
                ->with($this->callback(
                    static fn(ChatMessage $msg) => $msg->getSubject() === $expectedMessage,
                ));
        }

        if ($emailCount > 0) {
            $mailer->expects($this->exactly($emailCount))
                ->method('send')
                ->with($this->callback(
                    static fn(Email $email) => $email->getTextBody() === $expectedMessage,
                ));
        }
    }
}
