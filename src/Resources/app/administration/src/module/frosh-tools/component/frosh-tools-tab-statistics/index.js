import template from './template.twig';
import './style.scss';

const { Component } = Shopware;

Component.register('frosh-tools-tab-statistics', {
    template,

    inject: ['froshToolsService'],

    data() {
        return {
            cacheStats: null,
            dbStats: null,
            storageStats: null,
            isLoadingCache: true,
            isLoadingDb: true,
            isLoadingStorage: true,
            numberFormatter: null,
            percentFormatter: null,
        };
    },

    created() {
        const language =
            Shopware.Application.getContainer(
                'factory'
            ).locale.getLastKnownLocale();
        this.numberFormatter = new Intl.NumberFormat(language, {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0,
        });
        this.percentFormatter = new Intl.NumberFormat(language, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });

        this.loadData();
    },

    computed: {
        isLoading() {
            return this.isLoadingCache || this.isLoadingDb || this.isLoadingStorage;
        },

        diskUsedPercent() {
            if (!this.storageStats || !this.storageStats.disk || this.storageStats.disk.total === 0) {
                return 0;
            }
            const used = this.storageStats.disk.total - this.storageStats.disk.free;
            return (used / this.storageStats.disk.total) * 100;
        },

        storageColumns() {
            return [
                {
                    property: 'name',
                    label: this.$t('frosh-tools.tabs.statistics.directoryName'),
                    rawData: true,
                    allowResize: true,
                },
                {
                    property: 'path',
                    label: this.$t('frosh-tools.tabs.statistics.directoryPath'),
                    rawData: true,
                    allowResize: true,
                },
                {
                    property: 'size',
                    label: this.$t('frosh-tools.tabs.statistics.directorySize'),
                    rawData: true,
                    align: 'right',
                    allowResize: true,
                },
            ];
        },

        tableColumns() {
            return [
                {
                    property: 'name',
                    label: this.$t('frosh-tools.tabs.statistics.tableName'),
                    rawData: true,
                    allowResize: true,
                },
                {
                    property: 'engine',
                    label: this.$t('frosh-tools.tabs.statistics.engine'),
                    rawData: true,
                    allowResize: true,
                },
                {
                    property: 'rows',
                    label: this.$t('frosh-tools.tabs.statistics.rows'),
                    rawData: true,
                    align: 'right',
                    allowResize: true,
                },
                {
                    property: 'dataSize',
                    label: this.$t('frosh-tools.tabs.statistics.dataSize'),
                    rawData: true,
                    align: 'right',
                    allowResize: true,
                },
                {
                    property: 'indexSize',
                    label: this.$t('frosh-tools.tabs.statistics.indexSize'),
                    rawData: true,
                    align: 'right',
                    allowResize: true,
                },
                {
                    property: 'totalSize',
                    label: this.$t('frosh-tools.tabs.statistics.totalSize'),
                    rawData: true,
                    align: 'right',
                    allowResize: true,
                },
            ];
        },
    },

    methods: {
        loadData() {
            this.loadCacheStats();
            this.loadDbStats();
            this.loadStorageStats();
        },

        async loadCacheStats() {
            this.isLoadingCache = true;
            try {
                this.cacheStats = await this.froshToolsService.getCacheStatistics();
            } catch {
                this.cacheStats = null;
            }
            this.isLoadingCache = false;
        },

        async loadDbStats() {
            this.isLoadingDb = true;
            try {
                this.dbStats = await this.froshToolsService.getDatabaseStatistics();
            } catch {
                this.dbStats = null;
            }
            this.isLoadingDb = false;
        },

        async loadStorageStats() {
            this.isLoadingStorage = true;
            try {
                this.storageStats = await this.froshToolsService.getStorageStatistics();
            } catch {
                this.storageStats = null;
            }
            this.isLoadingStorage = false;
        },

        diskUsedVariant(percent) {
            if (percent >= 90) return 'danger';
            if (percent >= 75) return 'warning';
            return 'success';
        },

        formatSize(bytes) {
            if (bytes >= 1024 * 1024 * 1024) {
                return this.percentFormatter.format(bytes / (1024 * 1024 * 1024)) + ' GiB';
            }

            if (bytes >= 1024 * 1024) {
                return this.percentFormatter.format(bytes / (1024 * 1024)) + ' MiB';
            }

            if (bytes >= 1024) {
                return this.percentFormatter.format(bytes / 1024) + ' KiB';
            }

            return this.numberFormatter.format(bytes) + ' B';
        },

        formatNumber(number) {
            return this.numberFormatter.format(number);
        },

        formatPercent(value) {
            return this.percentFormatter.format(value) + ' %';
        },

        formatDecimal(value) {
            return this.percentFormatter.format(value);
        },

        formatUptime(seconds) {
            const days = Math.floor(seconds / 86400);
            const hours = Math.floor((seconds % 86400) / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);

            if (days > 0) {
                return `${days}d ${hours}h ${minutes}m`;
            }

            if (hours > 0) {
                return `${hours}h ${minutes}m`;
            }

            return `${minutes}m`;
        },

        hitRateVariant(rate) {
            if (rate >= 95) return 'success';
            if (rate >= 80) return 'warning';
            return 'danger';
        },
    },
});
