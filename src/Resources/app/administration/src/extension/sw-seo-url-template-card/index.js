const { Criteria } = Shopware.Data;

/**
 * A sales channel's URL template fields are created from the default
 * templates ("All Sales Channels"). Shopware loads those first because the
 * switch starts on "All Sales Channels"; for users limited to sales channels
 * the switch starts on their own channel (sw-sales-channel-switch), possibly
 * before the defaults are loaded. Load them before a channel's templates.
 */
Shopware.Component.override('sw-seo-url-template-card', {
    methods: {
        fetchSeoUrlTemplates(salesChannelId = null) {
            if (!salesChannelId || this.defaultSeoUrlTemplates.length > 0) {
                return this.$super('fetchSeoUrlTemplates', salesChannelId);
            }

            if (!this.mnDefaults) {
                const criteria = new Criteria(1, 25);
                criteria.addFilter(Criteria.equals('salesChannelId', null));
                this.mnDefaults = this.seoUrlTemplateRepository.search(criteria).then((response) => {
                    response.forEach((entity) => {
                        if (!this.defaultSeoUrlTemplates.has(entity.id)) {
                            this.defaultSeoUrlTemplates.add(entity);
                        }
                    });
                });
            }

            return this.mnDefaults.then(() => this.$super('fetchSeoUrlTemplates', salesChannelId));
        },
    },
});
