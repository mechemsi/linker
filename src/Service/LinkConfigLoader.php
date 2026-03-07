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
        if (null !== $this->links) {
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

        foreach ($files as $file) {
            $name = basename($file, '.yaml');
            $data = Yaml::parseFile($file);

            $this->links[$name] = $this->parseLink($name, $data);
        }

        return $this->links;
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
                }
            }
        }

        if (isset($data['parameters']) && !\is_array($data['parameters'])) {
            $errors[] = '"parameters" must be an array if provided';
        }

        if ([] !== $errors) {
            throw new InvalidLinkConfigException($name, $errors);
        }
    }
}
