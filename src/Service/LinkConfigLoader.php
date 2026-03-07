<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ChannelDefinition;
use App\Dto\LinkDefinition;
use App\Dto\ParameterDefinition;
use App\Exception\InvalidLinkConfigException;
use App\Exception\LinkNotFoundException;
use Symfony\Component\Yaml\Yaml;

class LinkConfigLoader
{
    /** @var array<string, LinkDefinition>|null */
    private ?array $links = null;

    private int $cachedMaxMtime = 0;

    private int $cachedFileCount = 0;

    public function __construct(
        private readonly string $linksDirectory,
    ) {
    }

    public function getLink(string $name): LinkDefinition
    {
        $links = $this->loadAll();

        if (!isset($links[$name])) {
            throw new LinkNotFoundException($name);
        }

        return $links[$name];
    }

    public function hasLink(string $name): bool
    {
        return isset($this->loadAll()[$name]);
    }

    /**
     * @return array<string, LinkDefinition>
     */
    public function getAllLinks(): array
    {
        return $this->loadAll();
    }

    /**
     * @return array<string, LinkDefinition>
     */
    private function loadAll(): array
    {
        if (null !== $this->links && !$this->hasConfigChanged()) {
            return $this->links;
        }

        $this->links = [];

        if (!is_dir($this->linksDirectory)) {
            return $this->links;
        }

        $files = glob($this->linksDirectory . '/*.yaml');

        if (false === $files) {
            return $this->links;
        }

        $maxMtime = 0;
        foreach ($files as $file) {
            $mtime = filemtime($file);
            if (false !== $mtime && $mtime > $maxMtime) {
                $maxMtime = $mtime;
            }

            $name = basename($file, '.yaml');
            $data = Yaml::parseFile($file);

            $this->links[$name] = $this->parseLink($name, $data);
        }

        $this->cachedMaxMtime = $maxMtime;
        $this->cachedFileCount = \count($files);

        return $this->links;
    }

    private function hasConfigChanged(): bool
    {
        if (!is_dir($this->linksDirectory)) {
            return $this->cachedFileCount > 0;
        }

        $files = glob($this->linksDirectory . '/*.yaml');

        if (false === $files) {
            return $this->cachedFileCount > 0;
        }

        if (\count($files) !== $this->cachedFileCount) {
            return true;
        }

        foreach ($files as $file) {
            $mtime = filemtime($file);
            if (false !== $mtime && $mtime > $this->cachedMaxMtime) {
                return true;
            }
        }

        return false;
    }

    private const array SUPPORTED_TRANSPORTS = [
        'slack',
        'telegram',
        'discord',
        'sms',
        'email',
        'slack-webhook',
    ];

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidLinkConfigException
     */
    private function parseLink(string $name, array $data): LinkDefinition
    {
        $this->validateStructure($name, $data);

        $parameters = [];
        foreach ($data['parameters'] ?? [] as $paramName => $paramConfig) {
            $parameters[] = new ParameterDefinition(
                name: $paramName,
                required: (bool) ($paramConfig['required'] ?? true),
                type: $paramConfig['type'] ?? 'string',
                default: $paramConfig['default'] ?? null,
            );
        }

        $channels = [];
        foreach ($data['channels'] as $channelConfig) {
            $channels[] = new ChannelDefinition(
                transport: $channelConfig['transport'],
                options: $channelConfig['options'] ?? [],
            );
        }

        return new LinkDefinition(
            name: $name,
            messageTemplate: $data['message_template'],
            parameters: $parameters,
            channels: $channels,
        );
    }

    /**
     * Validates transport-specific channel options (email recipients, phone numbers).
     *
     * @param array<string, mixed> $channelConfig
     * @param string[]             $errors
     */
    private function validateChannelOptions(int $index, array $channelConfig, array &$errors): void
    {
        $transport = $channelConfig['transport'];
        $options = $channelConfig['options'] ?? [];

        if ('email' === $transport) {
            if (!isset($options['to']) || !\is_string($options['to'])) {
                $errors[] = \sprintf('Channel at index %d (email) is missing required "to" option', $index);
            } elseif (false === filter_var($options['to'], \FILTER_VALIDATE_EMAIL)) {
                $errors[] = \sprintf(
                    'Channel at index %d (email) has invalid email address "%s"',
                    $index,
                    $options['to'],
                );
            }
        }

        if ('sms' === $transport) {
            if (!isset($options['to']) || !\is_string($options['to'])) {
                $errors[] = \sprintf('Channel at index %d (sms) is missing required "to" option', $index);
            } elseif (!preg_match('/^\+[1-9]\d{6,14}$/', $options['to'])) {
                $errors[] = \sprintf(
                    'Channel at index %d (sms) has invalid phone number "%s" (must be E.164 format, e.g. +1234567890)',
                    $index,
                    $options['to'],
                );
            }
        }
    }

    /**
     * Validates that all {placeholders} in the template have matching parameter definitions.
     *
     * @param array<string, mixed> $parameters
     * @param string[]             $errors
     */
    private function validatePlaceholders(string $template, array $parameters, array &$errors): void
    {
        if (preg_match_all('/\{(\w+)\}/', $template, $matches)) {
            $paramNames = array_keys($parameters);
            $undefinedPlaceholders = array_diff($matches[1], $paramNames);

            foreach ($undefinedPlaceholders as $placeholder) {
                $errors[] = \sprintf(
                    'Template placeholder "{%s}" has no matching parameter definition',
                    $placeholder,
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidLinkConfigException
     */
    private function validateStructure(string $name, array $data): void
    {
        $errors = [];

        if (!isset($data['message_template']) || !\is_string($data['message_template'])) {
            $errors[] = 'Missing or invalid "message_template" (must be a string)';
        }

        if (!isset($data['channels']) || !\is_array($data['channels'])) {
            $errors[] = 'Missing or invalid "channels" (must be an array)';
        } elseif ([] === $data['channels']) {
            $errors[] = '"channels" must not be empty';
        } else {
            foreach ($data['channels'] as $index => $channelConfig) {
                if (!\is_array($channelConfig)) {
                    $errors[] = \sprintf('Channel at index %d must be an array', $index);
                    continue;
                }

                if (!isset($channelConfig['transport']) || !\is_string($channelConfig['transport'])) {
                    $errors[] = \sprintf('Channel at index %d is missing required "transport" key', $index);
                } elseif (!\in_array($channelConfig['transport'], self::SUPPORTED_TRANSPORTS, true)) {
                    $errors[] = \sprintf(
                        'Channel at index %d has unsupported transport "%s" (supported: %s)',
                        $index,
                        $channelConfig['transport'],
                        implode(', ', self::SUPPORTED_TRANSPORTS),
                    );
                } else {
                    $this->validateChannelOptions($index, $channelConfig, $errors);
                }
            }
        }

        if (isset($data['parameters']) && !\is_array($data['parameters'])) {
            $errors[] = '"parameters" must be an array if provided';
        }

        if (
            isset($data['message_template']) && \is_string($data['message_template'])
            && isset($data['parameters']) && \is_array($data['parameters'])
        ) {
            $this->validatePlaceholders($data['message_template'], $data['parameters'], $errors);
        }

        if ([] !== $errors) {
            throw new InvalidLinkConfigException($name, $errors);
        }
    }
}
