<?php

declare(strict_types=1);

namespace App\Exception;

class WorkflowNotFoundException extends \RuntimeException
{
    public function __construct(string $workflowName)
    {
        parent::__construct(\sprintf('Workflow "%s" not found.', $workflowName));
    }
}
