<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class MockResponseFactory
{
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        return new MockResponse('ok', ['http_code' => 200]);
    }
}
