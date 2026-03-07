<?php

declare(strict_types=1);

namespace App\Exception;

class NotificationFailedException extends \RuntimeException
{
    /**
     * @param string[]     $succeededTransports
     * @param array<string, string> $failedTransports transport => error message
     */
    public function __construct(
        private readonly string $linkName,
        private readonly array $succeededTransports,
        private readonly array $failedTransports,
    ) {
        $failed = implode(', ', array_keys($failedTransports));
        parent::__construct(\sprintf(
            'Notification for link "%s" failed on transports: %s',
            $linkName,
            $failed,
        ));
    }

    public function getLinkName(): string
    {
        return $this->linkName;
    }

    /**
     * @return string[]
     */
    public function getSucceededTransports(): array
    {
        return $this->succeededTransports;
    }

    /**
     * @return array<string, string>
     */
    public function getFailedTransports(): array
    {
        return $this->failedTransports;
    }
}
