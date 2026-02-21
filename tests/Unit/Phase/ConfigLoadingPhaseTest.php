<?php

declare(strict_types=1);

namespace App\Tests\Unit\Phase;

use App\Service\LinkConfigLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ConfigLoadingPhaseTest extends TestCase
{
    private static string $fixturesDir;

    public static function setUpBeforeClass(): void
    {
        self::$fixturesDir = __DIR__ . '/../../fixtures/config-loading';
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function fixtureProvider(): iterable
    {
        $expectedDir = __DIR__ . '/../../fixtures/config-loading/expected';
        $files = glob($expectedDir . '/*.json');

        foreach ($files as $file) {
            $name = basename($file, '.json');
            yield $name => [$name . '.yaml', $file];
        }
    }

    #[Test]
    #[DataProvider('fixtureProvider')]
    public function itLoadsConfigMatchingExpectedOutput(string $yamlFile, string $expectedJsonFile): void
    {
        $loader = new LinkConfigLoader(self::$fixturesDir);
        $linkName = basename($yamlFile, '.yaml');

        $link = $loader->getLink($linkName);

        $expected = json_decode(file_get_contents($expectedJsonFile), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($expected['name'], $link->name);
        $this->assertSame($expected['messageTemplate'], $link->messageTemplate);
        $this->assertCount(\count($expected['parameters']), $link->parameters);

        foreach ($expected['parameters'] as $i => $expectedParam) {
            $this->assertSame($expectedParam['name'], $link->parameters[$i]->name);
            $this->assertSame($expectedParam['required'], $link->parameters[$i]->required);
            $this->assertSame($expectedParam['type'], $link->parameters[$i]->type);
            $this->assertSame($expectedParam['default'], $link->parameters[$i]->default);
        }

        $this->assertCount(\count($expected['channels']), $link->channels);

        foreach ($expected['channels'] as $i => $expectedChannel) {
            $this->assertSame($expectedChannel['transport'], $link->channels[$i]->transport);
            $expectedOptions = $expectedChannel['options'];
            if (\is_array($expectedOptions) && [] === $expectedOptions) {
                $this->assertSame([], $link->channels[$i]->options);
            } else {
                $this->assertSame($expectedOptions, $link->channels[$i]->options);
            }
        }
    }
}
