<?php

declare(strict_types=1);

namespace Frosh\Tools\Components;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class StorageStatisticsService
{
    private const CACHE_KEY = 'frosh-tools-storage-statistics';
    private const CACHE_TTL = 300;
    private const DIR_TIMEOUT = 10;

    private const DIRECTORIES = [
        'Media' => 'public/media',
        'Thumbnails' => 'public/thumbnail',
        'Sitemap' => 'public/sitemap',
        'Cache' => 'var/cache',
        'Log' => 'var/log',
        'Private Files' => 'files',
    ];

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
        #[Autowire(service: 'cache.object')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @return array{directories: list<array{name: string, path: string, size: int}>, totalSize: int, disk: array{free: int, total: int}, cachedAt: string}
     */
    public function getStorageStatistics(bool $fresh = false): array
    {
        $item = $this->cache->getItem(self::CACHE_KEY);

        if (!$fresh && $item->isHit()) {
            /** @var array{directories: list<array{name: string, path: string, size: int}>, totalSize: int, disk: array{free: int, total: int}, cachedAt: string} $cached */
            $cached = $item->get();

            return $cached;
        }

        $result = $this->calculate();

        $item->set($result);
        $item->expiresAfter(self::CACHE_TTL);
        $this->cache->save($item);

        return $result;
    }

    /**
     * @return array{directories: list<array{name: string, path: string, size: int}>, totalSize: int, disk: array{free: int, total: int}, cachedAt: string}
     */
    private function calculate(): array
    {
        $directories = [];
        $totalSize = 0;

        foreach (self::DIRECTORIES as $name => $relativePath) {
            $absolutePath = $this->projectDir . '/' . $relativePath;
            $size = is_dir($absolutePath) ? $this->getDirectorySize($absolutePath) : 0;

            $directories[] = [
                'name' => $name,
                'path' => $relativePath,
                'size' => $size,
            ];

            if ($size > 0) {
                $totalSize += $size;
            }
        }

        return [
            'directories' => $directories,
            'totalSize' => $totalSize,
            'disk' => [
                'free' => (int) @disk_free_space($this->projectDir),
                'total' => (int) @disk_total_space($this->projectDir),
            ],
            'cachedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Shells out to `du` instead of recursing in PHP: media/thumbnail directories can hold
     * hundreds of thousands of files, which would make a PHP-side walk far slower than a
     * single native call. A hard timeout keeps one huge directory from stalling the tab;
     * timed-out or failed directories report -1 so the admin can show them as "unknown".
     */
    private function getDirectorySize(string $dir): int
    {
        $process = new Process(['du', '-s', $dir]);
        $process->setTimeout(self::DIR_TIMEOUT);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return -1;
        }

        if (!$process->isSuccessful()) {
            return -1;
        }

        if (preg_match('/\d+/', $process->getOutput(), $match)) {
            return (int) $match[0] * 1024;
        }

        return -1;
    }
}
