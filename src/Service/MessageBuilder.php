<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\LinkDefinition;
use App\Exception\InvalidParametersException;

class MessageBuilder
{
    private const array SUPPORTED_TYPES = ['string', 'integer', 'number', 'boolean'];

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
                $value = $queryParams[$param->name];
                $typeError = $this->validateType($param->name, $value, $param->type);

                if (null !== $typeError) {
                    $errors[] = $typeError;
                } else {
                    $resolved[$param->name] = $value;
                }
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

    private function validateType(string $name, string $value, string $type): ?string
    {
        return match ($type) {
            'string' => null,
            'integer' => filter_var($value, \FILTER_VALIDATE_INT) !== false
                ? null
                : \sprintf('Parameter "%s" must be an integer, got "%s".', $name, $value),
            'number' => is_numeric($value)
                ? null
                : \sprintf('Parameter "%s" must be a number, got "%s".', $name, $value),
            'boolean' => \in_array(strtolower($value), ['true', 'false', '1', '0'], true)
                ? null
                : \sprintf('Parameter "%s" must be a boolean (true/false/1/0), got "%s".', $name, $value),
            default => \sprintf(
                'Parameter "%s" has unsupported type "%s" (supported: %s).',
                $name,
                $type,
                implode(', ', self::SUPPORTED_TYPES),
            ),
        };
    }

    /**
     * Interpolates {placeholder} tokens in the message template.
     *
     * @param array<string, string> $parameters
     */
    public function buildMessage(LinkDefinition $link, array $parameters): string
    {
        return $this->interpolate($link->messageTemplate, $parameters);
    }

    /**
     * Interpolates {placeholder} tokens in an arbitrary template string.
     *
     * @param array<string, string> $parameters
     */
    public function interpolate(string $template, array $parameters): string
    {
        foreach ($parameters as $key => $value) {
            $template = str_replace('{' . $key . '}', $value, $template);
        }

        return $template;
    }
}
