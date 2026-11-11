import loadChannelScope from '../../service/channel-scope';

/**
 * Shopware's form for a new promotion has no sales channels (they are set
 * later under Conditions), but a limited user's new promotion needs one of
 * their channels, or they could not see it after saving (the write guard
 * refuses it). The new promotion starts with all of the user's channels;
 * they can be narrowed down under Conditions.
 */
Shopware.Component.override('sw-promotion-v2-detail', {
    inject: ['repositoryFactory'],

    methods: {
        async savePromotion() {
            const scope = await loadChannelScope();
            const assignments = this.promotion?.salesChannels;

            if (scope?.restricted && this.promotion?.isNew?.() && assignments && assignments.length === 0) {
                const repository = this.repositoryFactory.create('promotion_sales_channel');
                scope.salesChannelIds.forEach((salesChannelId) => {
                    const assignment = repository.create();
                    assignment.promotionId = this.promotion.id;
                    assignment.salesChannelId = salesChannelId;
                    assignment.priority = 1;
                    assignments.add(assignment);
                });
            }

            return this.$super('savePromotion');
        },
    },
});
