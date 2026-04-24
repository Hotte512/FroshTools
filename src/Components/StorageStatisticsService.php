<?php

declare(strict_types=1);

namespace Frosh\Tools\Components;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

class StorageStatisticsService
{
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
    ) {
    }

    /**
     * @return array{directories: list<array{name: string, path: string, size: int}>, totalSize: int, disk: array{free: int, total: int}}
     */
    public function getStorageStatistics(): array
    {
        $directories = [];
        $totalSize = 0;

        foreach (self::DIRECTORIES as $name => $relativePath) {
            $absolutePath = $this->projectDir . '/' . $relativePath;
            $size = 0;

            if (is_dir($absolutePath)) {
                $size = CacheHelper::getSize($absolutePath);
            }

            $directories[] = [
                'name' => $name,
                'path' => $relativePath,
                'size' => $size,
            ];

            $totalSize += $size;
        }

        $diskFree = (int) @disk_free_space($this->projectDir);
        $diskTotal = (int) @disk_total_space($this->projectDir);

        return [
            'directories' => $directories,
            'totalSize' => $totalSize,
            'disk' => [
                'free' => $diskFree,
                'total' => $diskTotal,
            ],
        ];
    }
}
