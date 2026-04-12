<?php

declare(strict_types=1);

namespace Frosh\Tools\Controller;

use Frosh\Tools\Components\CacheHelper;
use Frosh\Tools\Components\CacheRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/api/_action/frosh-tools', defaults: ['_routeScope' => ['api'], '_acl' => ['frosh_tools:read']])]
class CacheController extends AbstractController
{
    public function __construct(
        #[Autowire(param: 'kernel.cache_dir')]
        private readonly string $cacheDir,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
        private readonly CacheRegistry $cacheRegistry,
    ) {
    }

    #[Route(path: '/cache', name: 'api.frosh.tools.cache.get', methods: ['GET'])]
    public function cacheStatistics(): JsonResponse
    {
        $cacheFolder = \dirname($this->cacheDir);
        $folders = scandir($cacheFolder, \SCANDIR_SORT_ASCENDING) ?: [];

        $result = [];

        foreach ($folders as $folder) {
            if ($folder[0] === '.') {
                continue;
            }

            $cacheDir = $cacheFolder . '/' . $folder;
            $result[] = [
                'name' => $folder,
                'active' => $folder === basename($this->cacheDir),
                'size' => CacheHelper::getSize($cacheDir),
                'freeSpace' => disk_free_space($cacheDir),
                'type' => 'Filesystem',
            ];
        }

        foreach ($this->cacheRegistry->all() as $name => $adapter) {
            $result[] = [
                'name' => $name,
                'active' => true,
                'size' => $adapter->getSize(),
                'type' => $adapter->getType(),
                'freeSpace' => $adapter->getFreeSize(),
            ];
        }

        $activeColumns = array_column($result, 'active');
        $freeSpaceColumns = array_column($result, 'freeSpace');
        $sizeColumns = array_column($result, 'size');

        array_multisort(
            $activeColumns,
            \SORT_DESC,
            $freeSpaceColumns,
            \SORT_ASC,
            $sizeColumns,
            \SORT_DESC,
            $result,
        );

        return new JsonResponse($result);
    }

    #[Route(path: '/cache/{folder}', name: 'api.frosh.tools.cache.clear', methods: ['DELETE'])]
    public function clearCache(string $folder): JsonResponse
    {
        if ($this->cacheRegistry->has($folder)) {
            $this->cacheRegistry->get($folder)->clear();
        } else {
            CacheHelper::removeDir(\dirname($this->cacheDir) . '/' . basename($folder));
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route(path: '/cache_clear_opcache', name: 'api.frosh.tools.cache.clear_opcache', methods: ['DELETE'])]
    public function clearOpCache(): JsonResponse
    {
        if (\function_exists('opcache_reset')) {
            opcache_reset();
        }

        if (\function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route(path: '/cache_clear_pools', name: 'api.frosh.tools.cache.clear_pools', methods: ['DELETE'])]
    public function clearAllPools(): JsonResponse
    {
        $details = [];
        $allSuccess = true;

        foreach ($this->cacheRegistry->all() as $name => $adapter) {
            try {
                $adapter->clear();
                $details[] = ['pool' => $name, 'success' => true];
            } catch (\Throwable $e) {
                $details[] = ['pool' => $name, 'success' => false, 'error' => $e->getMessage()];
                $allSuccess = false;
            }
        }

        return new JsonResponse([
            'success' => $allSuccess,
            'message' => $allSuccess
                ? 'All cache pools have been cleared'
                : 'Some cache pools could not be cleared',
            'details' => $details,
        ]);
    }

    #[Route(path: '/cache_clear_app', name: 'api.frosh.tools.cache.clear_app', methods: ['DELETE'])]
    public function clearAppCache(): JsonResponse
    {
        try {
            $phpBinary = $this->findPhpBinary();
            $consolePath = $this->projectDir . '/bin/console';

            $process = new Process([$phpBinary, $consolePath, 'cache:clear', '--no-warmup']);
            $process->setTimeout(120);
            $process->run();

            if (!$process->isSuccessful()) {
                return new JsonResponse([
                    'success' => false,
                    'message' => trim($process->getErrorOutput() ?: $process->getOutput()),
                ]);
            }

            return new JsonResponse([
                'success' => true,
                'message' => 'Application cache has been cleared',
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    #[Route(path: '/cache_clear_http', name: 'api.frosh.tools.cache.clear_http', methods: ['DELETE'])]
    public function clearHttpCache(): JsonResponse
    {
        $messages = [];
        $success = true;

        if ($this->cacheRegistry->has('cache.http')) {
            try {
                $this->cacheRegistry->get('cache.http')->clear();
                $messages[] = 'HTTP cache pool cleared';
            } catch (\Throwable $e) {
                $messages[] = 'HTTP cache pool: ' . $e->getMessage();
                $success = false;
            }
        }

        $httpCacheDir = $this->cacheDir . '/http_cache';
        if (is_dir($httpCacheDir)) {
            try {
                CacheHelper::removeDir($httpCacheDir);
                $messages[] = 'HTTP filesystem cache cleared';
            } catch (\Throwable $e) {
                $messages[] = 'HTTP filesystem cache: ' . $e->getMessage();
                $success = false;
            }
        }

        if (empty($messages)) {
            $messages[] = 'No HTTP cache found to clear';
        }

        return new JsonResponse([
            'success' => $success,
            'message' => implode('. ', $messages),
        ]);
    }

    #[Route(path: '/cache_clear_all', name: 'api.frosh.tools.cache.clear_all', methods: ['DELETE'])]
    public function clearAllCaches(): JsonResponse
    {
        $results = [];

        // 1. Clear all cache pools
        $poolNames = [];
        $poolSuccess = true;
        foreach ($this->cacheRegistry->all() as $name => $adapter) {
            try {
                $adapter->clear();
                $poolNames[] = $name;
            } catch (\Throwable) {
                $poolSuccess = false;
            }
        }
        $results[] = [
            'step' => 'pools',
            'success' => $poolSuccess,
            'message' => $poolSuccess
                ? 'All cache pools cleared (' . implode(', ', $poolNames) . ')'
                : 'Some cache pools could not be cleared',
        ];

        // 2. Clear application cache
        try {
            $phpBinary = $this->findPhpBinary();
            $consolePath = $this->projectDir . '/bin/console';
            $process = new Process([$phpBinary, $consolePath, 'cache:clear', '--no-warmup']);
            $process->setTimeout(120);
            $process->run();
            $results[] = [
                'step' => 'app',
                'success' => $process->isSuccessful(),
                'message' => $process->isSuccessful()
                    ? 'Application cache cleared'
                    : trim($process->getErrorOutput() ?: $process->getOutput()),
            ];
        } catch (\Throwable $e) {
            $results[] = ['step' => 'app', 'success' => false, 'message' => $e->getMessage()];
        }

        // 3. Clear HTTP cache (pool + filesystem)
        try {
            if ($this->cacheRegistry->has('cache.http')) {
                $this->cacheRegistry->get('cache.http')->clear();
            }
            $httpCacheDir = $this->cacheDir . '/http_cache';
            if (is_dir($httpCacheDir)) {
                CacheHelper::removeDir($httpCacheDir);
            }
            $results[] = ['step' => 'http', 'success' => true, 'message' => 'HTTP cache cleared'];
        } catch (\Throwable $e) {
            $results[] = ['step' => 'http', 'success' => false, 'message' => $e->getMessage()];
        }

        // 4. Clear OPcache / APCu
        try {
            if (\function_exists('opcache_reset')) {
                opcache_reset();
            }
            if (\function_exists('apcu_clear_cache')) {
                apcu_clear_cache();
            }
            $results[] = ['step' => 'opcache', 'success' => true, 'message' => 'OPcache cleared'];
        } catch (\Throwable $e) {
            $results[] = ['step' => 'opcache', 'success' => false, 'message' => $e->getMessage()];
        }

        // 5. Compile theme
        try {
            $phpBinary = $this->findPhpBinary();
            $consolePath = $this->projectDir . '/bin/console';
            $process = new Process([$phpBinary, $consolePath, 'theme:compile']);
            $process->setTimeout(300);
            $process->run();
            $results[] = [
                'step' => 'theme',
                'success' => $process->isSuccessful(),
                'message' => $process->isSuccessful()
                    ? 'Theme compiled'
                    : trim($process->getErrorOutput() ?: $process->getOutput()),
            ];
        } catch (\Throwable $e) {
            $results[] = ['step' => 'theme', 'success' => false, 'message' => $e->getMessage()];
        }

        $allSuccess = empty(array_filter($results, static fn (array $r): bool => !$r['success']));

        return new JsonResponse([
            'success' => $allSuccess,
            'results' => $results,
        ]);
    }

    #[Route(path: '/cache_compile_theme', name: 'api.frosh.tools.cache.compile_theme', methods: ['POST'])]
    public function compileTheme(): JsonResponse
    {
        try {
            $phpBinary = $this->findPhpBinary();
            $consolePath = $this->projectDir . '/bin/console';

            $process = new Process([$phpBinary, $consolePath, 'theme:compile']);
            $process->setTimeout(300);
            $process->run();

            if (!$process->isSuccessful()) {
                return new JsonResponse([
                    'success' => false,
                    'message' => trim($process->getErrorOutput() ?: $process->getOutput()),
                ]);
            }

            return new JsonResponse([
                'success' => true,
                'message' => 'Theme compiled successfully',
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function findPhpBinary(): string
    {
        $finder = new ExecutableFinder();
        $php = $finder->find('php');

        if ($php === null) {
            throw new \RuntimeException('Could not find PHP binary');
        }

        return $php;
    }
}
