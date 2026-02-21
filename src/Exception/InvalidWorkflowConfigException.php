<?php

declare(strict_types=1);

namespace App\Exception;

class InvalidWorkflowConfigException extends \InvalidArgumentException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
