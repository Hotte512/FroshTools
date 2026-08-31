import { describe, expect, it, vi } from 'vitest';
import {
    allowConsoleMessage,
    flushPromises,
} from '@friendsofshopware/vitest-shopware-admin-bridge/test-utils';
import { mountRegistered } from '../../../../../test/helpers';
import '../../../../mixin/sortable-table';
import './index';

const CACHE_STATS = {
    opcache: {
        hitRate: 97.5,
        hits: 1000,
        misses: 10,
        usedMemory: 50,
        wastedMemory: 5,
        wastedPercentage: 1.2,
        totalMemory: 128,
        cachedScripts: 10,
        maxCachedScripts: 20,
        internedStringsUsedMemory: 2,
        internedStringsFreeMemory: 4,
        lastRestart: null,
    },
    redis: [],
};

const STORAGE_STATS = {
    directories: [
        { name: 'Media', path: 'public/media', size: 2048 },
        { name: 'Log', path: 'var/log', size: -1 },
    ],
    totalSize: 2048,
    disk: { free: 40, total: 100 },
    cachedAt: '2024-01-01T00:00:00+00:00',
};

const DB_STATS = {
    server: {
        version: '8.0.36',
        uptime: 3600,
        queriesPerSecond: 1.2,
        threads: 4,
        slowQueries: 0,
        questions: 100,
    },
    tables: [
        {
            name: 'product',
            engine: 'InnoDB',
            rows: 10,
            dataSize: 80,
            indexSize: 20,
            totalSize: 100,
        },
        {
            name: 'order',
            engine: 'InnoDB',
            rows: 4,
            dataSize: 30,
            indexSize: 10,
            totalSize: 40,
        },
    ],
};

function createService({ fail = false } = {}) {
    return {
        getCacheStatistics: fail
            ? vi.fn().mockRejectedValue(new Error('cache fail'))
            : vi.fn().mockResolvedValue(CACHE_STATS),
        getDatabaseStatistics: fail
            ? vi.fn().mockRejectedValue(new Error('db fail'))
            : vi.fn().mockResolvedValue(DB_STATS),
        getStorageStatistics: fail
            ? vi.fn().mockRejectedValue(new Error('storage fail'))
            : vi.fn().mockResolvedValue(STORAGE_STATS),
    };
}

async function createWrapper({ fail = false, service = createService({ fail }) } = {}) {
    const wrapper = await mountRegistered('frosh-tools-tab-statistics', {
        provide: {
            froshToolsService: service,
        },
    });

    return { wrapper, service };
}

describe('frosh-tools-tab-statistics', () => {
    it('loads cache and database statistics', async () => {
        const { wrapper } = await createWrapper();
        await flushPromises();

        expect(wrapper.vm.cacheStats).toEqual(CACHE_STATS);
        expect(wrapper.vm.largestTableSize).toBe(100);
        expect(wrapper.vm.isLoading).toBe(false);
        expect(wrapper.vm.hitRateVariant(97)).toBe('success');
        expect(wrapper.vm.hitRateVariant(82)).toBe('warning');
        expect(wrapper.vm.fillVariant(91)).toBe('danger');
        expect(wrapper.vm.formatUptime(90000)).toBe('1d 1h 0m');
        expect(wrapper.vm.tableSizeWidth(50)).toBe(50);
    });

    it('loads storage statistics', async () => {
        const { wrapper } = await createWrapper();
        await flushPromises();

        expect(wrapper.vm.storageStats).toEqual(STORAGE_STATS);
        expect(wrapper.vm.diskUsedPercent).toBe(60);
        expect(wrapper.vm.isLoadingStorage).toBe(false);
    });

    it('re-fetches storage statistics with the refresh flag', async () => {
        const { wrapper, service } = await createWrapper();
        await flushPromises();

        await wrapper.vm.loadStorageStats(true);

        expect(service.getStorageStatistics).toHaveBeenLastCalledWith(true);
    });

    it('keeps panels empty when statistics fail to load', async () => {
        allowConsoleMessage('[frosh-tools] failed to load cache statistics');
        allowConsoleMessage('[frosh-tools] failed to load database statistics');
        allowConsoleMessage('[frosh-tools] failed to load storage statistics');

        const { wrapper } = await createWrapper({ fail: true });
        await flushPromises();

        expect(wrapper.vm.cacheStats).toBeNull();
        expect(wrapper.vm.dbStats).toBeNull();
        expect(wrapper.vm.storageStats).toBeNull();
        expect(wrapper.vm.largestTableSize).toBe(0);
        expect(wrapper.vm.diskUsedPercent).toBe(0);
    });
});
