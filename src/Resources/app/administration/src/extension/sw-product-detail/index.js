import template from './sw-product-detail.html.twig';
import loadChannelScope, { foreignChannelIds } from '../../service/channel-scope';

/**
 * Products that are also sold in sales channels outside the user's scope are
 * read-only: a banner explains why and saving is disabled. The write guard
 * refuses such changes anyway.
 *
 * New products of a limited user start with their own sales channels selected
 * (a new product needs a visibility in one of them).
 */
Shopware.Component.override('sw-product-detail', {
    template,

    data() {
        return {
            mnScope: null,
        };
    },

    computed: {
        mnReadOnly() {
            if (!this.mnScope?.restricted || !this.product || !this.productId) {
                return false;
            }

            return foreignChannelIds(this.mnScope, this.product, this.parentProduct).length > 0;
        },
    },

    methods: {
        /**
         * Shopware pre-selects the channels of "Settings > Products > Default
         * sales channels" for new products. For a limited user only their own
         * channels count: the defaults among them, or all of their channels
         * when none of the defaults is theirs.
         */
        async getDefaultSalesChannels() {
            const [defaults, scope] = await Promise.all([
                this.$super('getDefaultSalesChannels'),
                loadChannelScope(),
            ]);

            if (!scope?.restricted || !scope.salesChannelIds.length) {
                return defaults;
            }

            const own = (defaults?.defaultSalesChannelIds ?? []).filter((id) => scope.salesChannelIds.includes(id));

            return {
                defaultVisibilities: defaults?.defaultVisibilities ?? {},
                defaultActive: defaults?.defaultActive ?? true,
                ...defaults,
                defaultSalesChannelIds: own.length ? own : [...scope.salesChannelIds],
            };
        },
    },

    created() {
        loadChannelScope().then((scope) => {
            this.mnScope = scope;
        });
    },
});
