<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Provides sample workflow input data for tests.
 */
final class WorkflowFixtures
{
    public static function fixturesPath(): string
    {
        return __DIR__ . '/Data/workflows';
    }

    /**
     * Happy-path inputs for the "complete" workflow fixture.
     *
     * @return array<string, string>
     */
    public static function completeWorkflowInput(): array
    {
        return [
            'server' => 'web-01.prod',
            'status' => 'critical',
            'message' => 'CPU usage above 95%',
        ];
    }

    /**
     * Input using default value for the optional "message" parameter.
     *
     * @return array<string, string>
     */
    public static function completeWorkflowInputWithDefaults(): array
    {
        return [
            'server' => 'db-02.staging',
            'status' => 'warning',
        ];
    }

    /**
     * Happy-path inputs for the "multi-step" workflow fixture.
     *
     * @return array<string, string>
     */
    public static function multiStepWorkflowInput(): array
    {
        return [
            'app' => 'linker-api',
            'version' => '2.4.0',
            'environment' => 'production',
            'deployer' => 'ci-bot',
        ];
    }

    /**
     * Inputs for "multi-step" workflow relying on parameter defaults.
     *
     * @return array<string, string>
     */
    public static function multiStepWorkflowInputWithDefaults(): array
    {
        return [
            'app' => 'linker-api',
            'version' => '2.4.0',
            'environment' => 'staging',
        ];
    }

    /**
     * Input for the "single-step" workflow fixture.
     *
     * @return array<string, string>
     */
    public static function singleStepWorkflowInput(): array
    {
        return [
            'message' => 'Deployment completed successfully',
        ];
    }

    /**
     * Edge case: empty string values.
     *
     * @return array<string, string>
     */
    public static function emptyStringInput(): array
    {
        return [
            'server' => '',
            'status' => '',
            'message' => '',
        ];
    }

    /**
     * Edge case: values containing special characters.
     *
     * @return array<string, string>
     */
    public static function specialCharactersInput(): array
    {
        return [
            'server' => 'web-01.prod (primary)',
            'status' => 'error & critical',
            'message' => 'Disk usage: 99% — /var/log full <alert>',
        ];
    }

    /**
     * Edge case: values containing placeholder-like braces.
     *
     * @return array<string, string>
     */
    public static function bracesInValuesInput(): array
    {
        return [
            'server' => 'host-{unknown}',
            'status' => '{status}',
            'message' => 'Literal {braces} in message',
        ];
    }

    /**
     * Edge case: whitespace-only parameter values.
     *
     * @return array<string, string>
     */
    public static function whitespaceOnlyInput(): array
    {
        return [
            'server' => '   ',
            'status' => "\t",
            'message' => " \n ",
        ];
    }

    /**
     * Edge case: very long parameter values.
     *
     * @return array<string, string>
     */
    public static function veryLongValueInput(): array
    {
        return [
            'server' => str_repeat('a', 10000),
            'status' => str_repeat('x', 5000),
            'message' => str_repeat('long message ', 1000),
        ];
    }

    /**
     * Edge case: unicode and multibyte characters in parameter values.
     *
     * @return array<string, string>
     */
    public static function unicodeInput(): array
    {
        return [
            'server' => 'サーバー-01',
            'status' => 'критический',
            'message' => '磁盘使用率: 99% /var/log 已满',
        ];
    }

    /**
     * Edge case: null-like string values.
     *
     * @return array<string, string>
     */
    public static function nullLikeStringInput(): array
    {
        return [
            'server' => 'null',
            'status' => 'undefined',
            'message' => 'false',
        ];
    }

    /**
     * Edge case: extra parameters not defined in the workflow.
     *
     * @return array<string, string>
     */
    public static function extraParametersInput(): array
    {
        return [
            'server' => 'web-01',
            'status' => 'ok',
            'message' => 'All good',
            'extra_param' => 'should be ignored',
            'another_extra' => 'also ignored',
        ];
    }

    /**
     * Edge case: newlines and control characters in values.
     *
     * @return array<string, string>
     */
    public static function newlinesInValuesInput(): array
    {
        return [
            'server' => "web-01\nweb-02",
            'status' => "line1\r\nline2",
            'message' => "tab\there",
        ];
    }
}
