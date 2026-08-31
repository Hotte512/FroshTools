<?php

declare(strict_types=1);

namespace Frosh\Tools\Tests\Controller;

use Frosh\Tools\Controller\StatisticsController;
use Frosh\Tools\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(StatisticsController::class)]
class StatisticsControllerTest extends IntegrationTestCase
{
    private StatisticsController $controller;

    protected function setUp(): void
    {
        $this->controller = static::getContainer()->get(StatisticsController::class);
    }

    public function testDatabaseStatisticsReturnsServerTablesAndGlobalStatus(): void
    {
        $response = $this->controller->databaseStatistics();

        static::assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        static::assertIsArray($data);
        static::assertArrayHasKey('server', $data);
        static::assertArrayHasKey('tables', $data);
        static::assertArrayHasKey('globalStatus', $data);

        static::assertIsArray($data['server']);
        static::assertArrayHasKey('version', $data['server']);
        static::assertIsString($data['server']['version']);
        static::assertNotSame('', $data['server']['version']);

        static::assertIsArray($data['tables']);
        static::assertNotEmpty($data['tables']);

        $names = array_column($data['tables'], 'name');
        static::assertContains('product', $names);
    }

    public function testCacheStatisticsContainsAdapterKeys(): void
    {
        $response = $this->controller->cacheStatistics();

        static::assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        static::assertIsArray($data);
        foreach (['opcache', 'apcu', 'redis', 'fpm'] as $key) {
            static::assertArrayHasKey($key, $data);
        }
    }

    public function testStorageStatisticsReturnsDirectoriesAndDiskUsage(): void
    {
        $response = $this->controller->storageStatistics(new Request());

        static::assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        static::assertIsArray($data);
        foreach (['directories', 'totalSize', 'disk', 'cachedAt'] as $key) {
            static::assertArrayHasKey($key, $data);
        }

        static::assertIsArray($data['directories']);
        static::assertNotEmpty($data['directories']);

        foreach ($data['directories'] as $directory) {
            static::assertArrayHasKey('name', $directory);
            static::assertArrayHasKey('path', $directory);
            static::assertArrayHasKey('size', $directory);
        }

        static::assertIsArray($data['disk']);
        static::assertArrayHasKey('free', $data['disk']);
        static::assertArrayHasKey('total', $data['disk']);
    }
}
