<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ChannelDefinition;
use App\Dto\LinkDefinition;
use App\Dto\ParameterDefinition;
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

    /**
     * @param array<string, mixed> $data
     */
    private function parseLink(string $name, array $data): LinkDefinition
    {
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
        foreach ($data['channels'] ?? [] as $channelConfig) {
            $channels[] = new ChannelDefinition(
                transport: $channelConfig['transport'],
                options: $channelConfig['options'] ?? [],
            );
        }

        return new LinkDefinition(
            name: $name,
            messageTemplate: $data['message_template'] ?? '',
            parameters: $parameters,
            channels: $channels,
        );
    }
}
