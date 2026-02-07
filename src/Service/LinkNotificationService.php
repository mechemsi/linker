<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ChannelDefinition;
use App\Dto\LinkDefinition;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\TexterInterface;

class LinkNotificationService
{
    public function __construct(
        private readonly LinkConfigLoader $configLoader,
        private readonly MessageBuilder $messageBuilder,
        private readonly ChatterInterface $chatter,
        private readonly TexterInterface $texter,
        private readonly MailerInterface $mailer,
    ) {
    }

    /**
     * Loads config, builds message, dispatches to all configured channels.
     *
     * @param array<string, string> $queryParams
     *
     * @return string[] List of transport names that were notified
     */
    public function send(string $linkName, array $queryParams): array
    {
        $link = $this->configLoader->getLink($linkName);
        $resolved = $this->messageBuilder->resolveParameters($link, $queryParams);
        $message = $this->messageBuilder->buildMessage($link, $resolved);

        $notified = [];

        foreach ($link->channels as $channel) {
            $this->dispatch($channel, $message, $link, $resolved);
            $notified[] = $channel->transport;
        }

        return $notified;
    }

    /**
     * @param array<string, string> $resolvedParams
     */
    private function dispatch(
        ChannelDefinition $channel,
        string $message,
        LinkDefinition $link,
        array $resolvedParams,
    ): void {
        match ($channel->transport) {
            'slack', 'telegram', 'discord' => $this->sendChat($channel, $message),
            'sms' => $this->sendSms($channel, $message),
            'email' => $this->sendEmail($channel, $message, $link, $resolvedParams),
            default => throw new \RuntimeException(\sprintf('Unsupported transport "%s".', $channel->transport)),
        };
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
        LinkDefinition $link,
        array $resolvedParams,
    ): void {
        $to = $channel->options['to'] ?? throw new \RuntimeException('Email channel requires "to" option.');
        $subject = $channel->options['subject'] ?? $message;

        // Interpolate placeholders in subject
        foreach ($resolvedParams as $key => $value) {
            $subject = str_replace('{' . $key . '}', $value, $subject);
        }

        $email = (new Email())
            ->to($to)
            ->subject($subject)
            ->text($message);

        $this->mailer->send($email);
    }
}
