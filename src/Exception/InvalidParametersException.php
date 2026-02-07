<?php

declare(strict_types=1);

namespace App\Exception;

class InvalidParametersException extends \InvalidArgumentException
{
    /**
     * @param string[] $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct(\sprintf('Invalid parameters: %s', implode(', ', $errors)));
    }

    /**
     * @return string[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
