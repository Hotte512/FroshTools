import './frosh-tools-flyout.scss';

const { Component, Mixin } = Shopware;

Component.override('sw-admin-menu-item', {
    inject: {
        froshToolsService: { from: 'froshToolsService', default: null },
    },
    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            froshFlyoutVisible: false,
            froshFlyoutTimer: null,
            froshFlyoutHovered: false,
        };
    },

    computed: {
        isFroshToolsEntry() {
            if (!this.entry) {
                return false;
            }

            const id = this.entry.id || this.entry.name || '';
            return id === 'frosh-tools' || this.entry.to === 'frosh.tools.index.cache';
        },

        isFlyoutEnabled() {
            try {
                return !!Shopware.Context.app.config.settings.froshTools.quickActionsFlyoutEnabled;
            } catch {
                return false;
            }
        },
    },

    mounted() {
        if (this.isFroshToolsEntry && this.isFlyoutEnabled && this.froshToolsService) {
            this.$el.classList.add('frosh-tools-flyout-anchor');
            this.$el.addEventListener('mouseenter', this.onFroshMouseEnter);
            this.$el.addEventListener('mouseleave', this.onFroshMouseLeave);
        }
    },

    beforeDestroy() {
        this.cleanupFroshFlyout();
    },

    beforeUnmount() {
        this.cleanupFroshFlyout();
    },

    methods: {
        cleanupFroshFlyout() {
            if (!this.isFroshToolsEntry) {
                return;
            }
            clearTimeout(this.froshFlyoutTimer);
            this.removeFroshFlyout();
            if (this.$el) {
                this.$el.removeEventListener('mouseenter', this.onFroshMouseEnter);
                this.$el.removeEventListener('mouseleave', this.onFroshMouseLeave);
            }
        },

        onFroshMouseEnter() {
            this.froshFlyoutTimer = setTimeout(() => {
                this.froshFlyoutVisible = true;
                this.renderFroshFlyout();
            }, 400);
        },

        onFroshMouseLeave() {
            clearTimeout(this.froshFlyoutTimer);

            setTimeout(() => {
                if (!this.froshFlyoutHovered) {
                    this.froshFlyoutVisible = false;
                    this.removeFroshFlyout();
                }
            }, 150);
        },

        renderFroshFlyout() {
            this.removeFroshFlyout();

            const flyout = document.createElement('div');
            flyout.className = 'frosh-tools-quick-flyout';

            flyout.addEventListener('mouseenter', () => {
                this.froshFlyoutHovered = true;
            });
            flyout.addEventListener('mouseleave', () => {
                this.froshFlyoutHovered = false;
                this.froshFlyoutVisible = false;
                this.removeFroshFlyout();
            });

            const title = document.createElement('div');
            title.className = 'frosh-tools-quick-flyout__title';
            title.textContent = this.$t('frosh-tools.flyout.title');
            flyout.appendChild(title);

            const actions = [
                {
                    label: this.$t('frosh-tools.flyout.clearAll'),
                    method: 'executeClearAll',
                    danger: true,
                },
                {
                    label: this.$t('frosh-tools.flyout.clearAllPools'),
                    method: 'executeClearAllPools',
                    danger: false,
                },
                {
                    label: this.$t('frosh-tools.flyout.clearOpCache'),
                    method: 'executeClearOpCache',
                    danger: false,
                },
                {
                    label: this.$t('frosh-tools.flyout.compileTheme'),
                    method: 'executeCompileTheme',
                    danger: false,
                },
            ];

            for (const action of actions) {
                const btn = document.createElement('button');
                btn.className = 'frosh-tools-quick-flyout__btn';
                if (action.danger) {
                    btn.classList.add('frosh-tools-quick-flyout__btn--danger');
                }
                btn.textContent = action.label;
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    e.preventDefault();
                    this[action.method]();
                });
                flyout.appendChild(btn);
            }

            this.$el.appendChild(flyout);
            this._froshFlyoutEl = flyout;
        },

        removeFroshFlyout() {
            if (this._froshFlyoutEl) {
                this._froshFlyoutEl.remove();
                this._froshFlyoutEl = null;
            }
        },

        async executeClearAll() {
            this.removeFroshFlyout();
            try {
                const result = await this.froshToolsService.clearAllCaches();
                if (result.success) {
                    this.createNotificationSuccess({
                        message: this.$t('frosh-tools.clearedAllCaches'),
                    });
                } else {
                    this.createNotificationWarning({
                        message: this.$t('frosh-tools.cacheActionFailed', {
                            message: result.results?.map(r => r.message).join('; ') || 'Unknown error',
                        }),
                    });
                }
            } catch (error) {
                this.createNotificationError({
                    message: this.$t('frosh-tools.cacheActionFailed', { message: error.message || 'Unknown error' }),
                });
            }
        },

        async executeClearAllPools() {
            this.removeFroshFlyout();
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
        },

        async executeClearOpCache() {
            this.removeFroshFlyout();
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
        },

        async executeCompileTheme() {
            this.removeFroshFlyout();
            try {
                const result = await this.froshToolsService.compileThemeBackend();
                if (result.success) {
                    this.createNotificationSuccess({
                        message: this.$t('frosh-tools.themeCompiled'),
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
        },
    },
});
