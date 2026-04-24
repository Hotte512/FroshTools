<?php

declare(strict_types=1);

namespace Frosh\Tools\Components;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class StorageStatisticsService
{
    private const CACHE_KEY = 'frosh_tools.storage_statistics';
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
        private readonly CacheInterface $cacheObject,
    ) {
    }

    /**
     * @return array{directories: list<array{name: string, path: string, size: int}>, totalSize: int, disk: array{free: int, total: int}, cachedAt: string}
     */
    public function getStorageStatistics(bool $fresh = false): array
    {
        if ($fresh) {
            $this->cacheObject->delete(self::CACHE_KEY);
        }

        return $this->cacheObject->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL);

            return $this->calculate();
        });
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
            $size = 0;

            if (is_dir($absolutePath)) {
                $size = $this->getDirectorySize($absolutePath);
            }

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

    private function getDirectorySize(string $dir): int
    {
        $process = new Process(['du', '-s', $dir]);
        $process->setTimeout(self::DIR_TIMEOUT);

        try {
            $process->run();
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException) {
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
