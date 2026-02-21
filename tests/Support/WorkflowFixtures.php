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
     * Happy-path inputs for the "hotfix" workflow fixture.
     *
     * @return array<string, string>
     */
    public static function hotfixWorkflowInput(): array
    {
        return [
            'service' => 'payment-api',
            'version' => '3.1.1',
            'issue' => 'JIRA-4521',
            'environment' => 'production',
            'author' => 'jane.doe',
        ];
    }

    /**
     * Inputs for "hotfix" workflow relying on parameter defaults.
     *
     * @return array<string, string>
     */
    public static function hotfixWorkflowInputWithDefaults(): array
    {
        return [
            'service' => 'auth-service',
            'version' => '1.0.3',
            'issue' => 'HOTFIX-99',
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
}
