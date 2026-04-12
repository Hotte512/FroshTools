import template from './template.twig';
import './style.scss';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('frosh-tools-tab-cache', {
    template,

    inject: ['froshToolsService', 'repositoryFactory', 'themeService'],
    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            cacheInfo: null,
            isLoading: true,
            numberFormater: null,
        };
    },

    created() {
        const language =
            Shopware.Application.getContainer(
                'factory'
            ).locale.getLastKnownLocale();
        this.numberFormater = new Intl.NumberFormat(language, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });

        this.createdComponent();
    },

    computed: {
        columns() {
            return [
                {
                    property: 'name',
                    label: 'frosh-tools.name',
                    rawData: true,
                },
                {
                    property: 'size',
                    label: 'frosh-tools.used',
                    rawData: true,
                    align: 'right',
                },
                {
                    property: 'freeSpace',
                    label: 'frosh-tools.free',
                    rawData: true,
                    align: 'right',
                },
            ];
        },
        cacheFolders() {
            if (this.cacheInfo === null) {
                return [];
            }

            return this.cacheInfo;
        },

        salesChannelRepository() {
            return this.repositoryFactory.create('sales_channel');
        },
    },

    methods: {
        async createdComponent() {
            this.isLoading = true;
            this.cacheInfo = await this.froshToolsService.getCacheInfo();
            this.isLoading = false;
        },

        formatSize(bytes) {
            const formatted = bytes / (1024 * 1024);

            return this.numberFormater.format(formatted) + ' MiB';
        },

        async clearCache(item) {
            this.isLoading = true;
            await this.froshToolsService.clearCache(item.name);
            await this.createdComponent();
        },

        async compileTheme() {
            const criteria = new Criteria();
            criteria.addAssociation('themes');
            this.isLoading = true;

            let salesChannels = await this.salesChannelRepository.search(
                criteria,
                Shopware.Context.api
            );

            for (let salesChannel of salesChannels) {
                const theme = salesChannel.extensions.themes.first();

                if (theme) {
                    await this.themeService.assignTheme(
                        theme.id,
                        salesChannel.id
                    );
                    this.createNotificationSuccess({
                        message: `${salesChannel.translated.name}: ${this.$t('frosh-tools.themeCompiled')}`,
                    });
                }
            }

            this.isLoading = false;
        },

        async clearOPcache() {
            this.isLoading = true;
            try {
                await this.froshToolsService.clearOPcache();
                this.createNotificationSuccess({
                    message: this.$t('frosh-tools.clearedOpcache'),
                });
            } catch (error) {
                this.createNotificationError({
                    message: this.$t('frosh-tools.cacheActionFailed', { message: error.message || 'Unknown error' }),
                });
            }
            await this.createdComponent();
        },

        async clearAllPools() {
            this.isLoading = true;
            try {
                const result = await this.froshToolsService.clearAllPools();
                if (result.success) {
                    this.createNotificationSuccess({
                        message: this.$t('frosh-tools.clearedAllPools'),
                    });
                } else {
                    this.createNotificationWarning({
                        message: this.$t('frosh-tools.cacheActionFailed', { message: result.message }),
                    });
                }
            } catch (error) {
                this.createNotificationError({
                    message: this.$t('frosh-tools.cacheActionFailed', { message: error.message || 'Unknown error' }),
                });
            }
            await this.createdComponent();
        },

        async clearAppCache() {
            this.isLoading = true;
            try {
                const result = await this.froshToolsService.clearAppCache();
                if (result.success) {
                    this.createNotificationSuccess({
                        message: this.$t('frosh-tools.clearedAppCache'),
                    });
                } else {
                    this.createNotificationWarning({
                        message: this.$t('frosh-tools.cacheActionFailed', { message: result.message }),
                    });
                }
            } catch (error) {
                this.createNotificationError({
                    message: this.$t('frosh-tools.cacheActionFailed', { message: error.message || 'Unknown error' }),
                });
            }
            await this.createdComponent();
        },

        async clearHttpCache() {
            this.isLoading = true;
            try {
                const result = await this.froshToolsService.clearHttpCache();
                if (result.success) {
                    this.createNotificationSuccess({
                        message: this.$t('frosh-tools.clearedHttpCache'),
                    });
                } else {
                    this.createNotificationWarning({
                        message: this.$t('frosh-tools.cacheActionFailed', { message: result.message }),
                    });
                }
            } catch (error) {
                this.createNotificationError({
                    message: this.$t('frosh-tools.cacheActionFailed', { message: error.message || 'Unknown error' }),
                });
            }
            await this.createdComponent();
        },

        async clearAllCaches() {
            this.isLoading = true;
            try {
                const result = await this.froshToolsService.clearAllCaches();
                if (result.results) {
                    for (const step of result.results) {
                        if (step.success) {
                            this.createNotificationSuccess({
                                message: step.message,
                            });
                        } else {
                            this.createNotificationWarning({
                                message: `${step.step}: ${step.message}`,
                            });
                        }
                    }
                }
                if (result.success) {
                    this.createNotificationSuccess({
                        message: this.$t('frosh-tools.clearedAllCaches'),
                    });
                }
            } catch (error) {
                this.createNotificationError({
                    message: this.$t('frosh-tools.cacheActionFailed', { message: error.message || 'Unknown error' }),
                });
            }
            await this.createdComponent();
        },
    },
});
