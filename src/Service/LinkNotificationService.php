<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ChannelDefinition;
use App\Exception\NotificationFailedException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\TexterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class LinkNotificationService
{
    private const float WEBHOOK_TIMEOUT = 10.0;

    public function __construct(
        private readonly LinkConfigLoader $configLoader,
        private readonly MessageBuilder $messageBuilder,
        private readonly ChatterInterface $chatter,
        private readonly TexterInterface $texter,
        private readonly MailerInterface $mailer,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $slackWebhookUrl,
    ) {
    }

    /**
     * Loads config, builds message, dispatches to all configured channels.
     * Continues dispatching even if individual transports fail.
     *
     * @param array<string, string> $queryParams
     *
     * @return string[] List of transport names that were notified
     *
     * @throws NotificationFailedException When one or more transports fail
     */
    public function send(string $linkName, array $queryParams): array
    {
        $link = $this->configLoader->getLink($linkName);
        $resolved = $this->messageBuilder->resolveParameters($link, $queryParams);
        $message = $this->messageBuilder->buildMessage($link, $resolved);

        $this->logger->info('Dispatching notification for link "{link}"', [
            'link' => $linkName,
            'transports' => array_map(
                static fn (ChannelDefinition $ch) => $ch->transport,
                $link->channels,
            ),
        ]);

        $notified = [];
        /** @var array<string, string> $failures */
        $failures = [];

        foreach ($link->channels as $channel) {
            try {
                $this->dispatch($channel, $message, $resolved);
                $notified[] = $channel->transport;
                $this->logger->info('Successfully dispatched to "{transport}" for link "{link}"', [
                    'transport' => $channel->transport,
                    'link' => $linkName,
                ]);
            } catch (\Throwable $e) {
                $failures[$channel->transport] = $e->getMessage();
                $this->logger->error('Failed to dispatch to "{transport}" for link "{link}": {error}', [
                    'transport' => $channel->transport,
                    'link' => $linkName,
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        if ([] !== $failures) {
            throw new NotificationFailedException($linkName, $notified, $failures);
        }

        return $notified;
    }

    /**
     * @param array<string, string> $resolvedParams
     */
    private function dispatch(
        ChannelDefinition $channel,
        string $message,
        array $resolvedParams,
    ): void {
        match ($channel->transport) {
            'slack-webhook' => $this->sendSlackWebhook($message),
            'slack', 'telegram', 'discord' => $this->sendChat($channel, $message),
            'sms' => $this->sendSms($channel, $message),
            'email' => $this->sendEmail($channel, $message, $resolvedParams),
            default => throw new \RuntimeException(\sprintf('Unsupported transport "%s".', $channel->transport)),
        };
    }

    private function sendSlackWebhook(string $message): void
    {
        $response = $this->httpClient->request('POST', $this->slackWebhookUrl, [
            'json' => ['text' => $message],
            'timeout' => self::WEBHOOK_TIMEOUT,
        ]);

        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException(\sprintf(
                'Slack webhook failed with status %d: %s',
                $response->getStatusCode(),
                $response->getContent(false),
            ));
        }
    }

    private function sendChat(ChannelDefinition $channel, string $message): void
    {
        $chatMessage = new ChatMessage($message);
        $chatMessage->transport($channel->transport);
        $this->chatter->send($chatMessage);
    }

    private function sendSms(ChannelDefinition $channel, string $message): void
    {
        $to = $channel->options['to'] ?? throw new \RuntimeException('SMS channel requires "to" option.');
        $smsMessage = new SmsMessage($to, $message);
        $smsMessage->transport('twilio');
        $this->texter->send($smsMessage);
    }

    /**
     * @param array<string, string> $resolvedParams
     */
    private function sendEmail(
        ChannelDefinition $channel,
        string $message,
        array $resolvedParams,
    ): void {
        $to = $channel->options['to'] ?? throw new \RuntimeException('Email channel requires "to" option.');
        $subject = $channel->options['subject'] ?? $message;
        $subject = $this->messageBuilder->interpolate($subject, $resolvedParams);
        $subject = $this->sanitizeHeaderValue($subject);

        $email = (new Email())
            ->to($to)
            ->subject($subject)
            ->text($message);

        $this->mailer->send($email);
    }

    /**
     * Strips CR/LF characters to prevent email header injection.
     */
    private function sanitizeHeaderValue(string $value): string
    {
        return str_replace(["\r\n", "\r", "\n"], ' ', $value);
    }
}
