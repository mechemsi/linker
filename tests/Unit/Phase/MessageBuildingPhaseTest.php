<?php

declare(strict_types=1);

namespace App\Tests\Unit\Phase;

use App\Dto\ChannelDefinition;
use App\Dto\LinkDefinition;
use App\Service\MessageBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MessageBuildingPhaseTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtureProvider(): iterable
    {
        $dir = __DIR__ . '/../../fixtures/message-building';
        $files = glob($dir . '/*.json');

        foreach ($files as $file) {
            yield basename($file, '.json') => [$file];
        }
    }

    #[Test]
    #[DataProvider('fixtureProvider')]
    public function itBuildsMessageMatchingFixtureExpectation(string $fixtureFile): void
    {
        $fixture = json_decode(file_get_contents($fixtureFile), true, 512, JSON_THROW_ON_ERROR);

        $link = new LinkDefinition(
            name: 'test',
            messageTemplate: $fixture['template'],
            parameters: [],
            channels: [new ChannelDefinition('slack')],
        );

        $builder = new MessageBuilder();
        $result = $builder->buildMessage($link, $fixture['parameters']);

        $this->assertSame(
            $fixture['expected_message'],
            $result,
            $fixture['description'],
        );
    }
}
