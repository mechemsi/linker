<?php

declare(strict_types=1);

namespace App\Exception;

class LinkNotFoundException extends \RuntimeException
{
    public function __construct(string $linkName)
    {
        parent::__construct(\sprintf('Link "%s" not found.', $linkName));
    }
}
