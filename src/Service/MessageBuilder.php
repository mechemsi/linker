<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\LinkDefinition;
use App\Exception\InvalidParametersException;

class MessageBuilder
{
    /**
     * Validates and resolves query parameters against the link definition.
     * Missing optional parameters get their default values.
     *
     * @param array<string, string> $queryParams
     *
     * @return array<string, string> Resolved parameters
     *
     * @throws InvalidParametersException
     */
    public function resolveParameters(LinkDefinition $link, array $queryParams): array
    {
        $resolved = [];
        $errors = [];

        foreach ($link->parameters as $param) {
            if (isset($queryParams[$param->name])) {
                $resolved[$param->name] = $queryParams[$param->name];
            } elseif (!$param->required && null !== $param->default) {
                $resolved[$param->name] = $param->default;
            } elseif ($param->required) {
                $errors[] = \sprintf('Missing required parameter "%s".', $param->name);
            }
        }

        if ([] !== $errors) {
            throw new InvalidParametersException($errors);
        }

        return $resolved;
    }

    /**
     * Interpolates {placeholder} tokens in the message template.
     *
     * @param array<string, string> $parameters
     */
    public function buildMessage(LinkDefinition $link, array $parameters): string
    {
        $message = $link->messageTemplate;

        foreach ($parameters as $key => $value) {
            $message = str_replace('{' . $key . '}', $value, $message);
        }

        return $message;
    }
}
