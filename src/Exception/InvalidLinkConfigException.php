<?php

declare(strict_types=1);

namespace App\Exception;

class InvalidLinkConfigException extends \RuntimeException
{
    /**
     * @param string[] $errors
     */
    public function __construct(
        private readonly string $linkName,
        private readonly array $errors,
    ) {
        parent::__construct(\sprintf(
            'Invalid configuration for link "%s": %s',
            $linkName,
            implode('; ', $errors),
        ));
    }

    public function getLinkName(): string
    {
        return $this->linkName;
    }

    /**
     * @return string[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
