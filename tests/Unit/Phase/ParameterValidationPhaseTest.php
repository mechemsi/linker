<?php

declare(strict_types=1);

namespace App\Tests\Unit\Phase;

use App\Exception\InvalidParametersException;
use App\Service\LinkConfigLoader;
use App\Service\MessageBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ParameterValidationPhaseTest extends TestCase
{
    private static string $fixturesDir;

    public static function setUpBeforeClass(): void
    {
        self::$fixturesDir = __DIR__ . '/../../fixtures';
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtureProvider(): iterable
    {
        $dir = __DIR__ . '/../../fixtures/parameter-validation';
        $files = glob($dir . '/*.json');

        foreach ($files as $file) {
            yield basename($file, '.json') => [$file];
        }
    }

    #[Test]
    #[DataProvider('fixtureProvider')]
    public function itValidatesParametersMatchingFixtureExpectation(string $fixtureFile): void
    {
        $fixture = json_decode(file_get_contents($fixtureFile), true, 512, JSON_THROW_ON_ERROR);

        $configDir = self::$fixturesDir . '/' . \dirname($fixture['link_config']);
        $linkName = basename($fixture['link_config'], '.yaml');

        $loader = new LinkConfigLoader($configDir);
        $link = $loader->getLink($linkName);

        $builder = new MessageBuilder();

        if ($fixture['expected']['valid']) {
            $resolved = $builder->resolveParameters($link, $fixture['input']);
            $this->assertSame($fixture['expected']['resolved'], $resolved);
        } else {
            try {
                $builder->resolveParameters($link, $fixture['input']);
                $this->fail('Expected InvalidParametersException for: ' . $fixture['description']);
            } catch (InvalidParametersException $e) {
                $this->assertCount($fixture['expected']['error_count'], $e->getErrors());

                foreach ($fixture['expected']['error_contains'] as $needle) {
                    $found = false;
                    foreach ($e->getErrors() as $error) {
                        if (str_contains($error, $needle)) {
                            $found = true;
                            break;
                        }
                    }
                    $this->assertTrue($found, \sprintf('Expected error containing "%s"', $needle));
                }
            }
        }
    }
}
