import template from './sw-product-detail.html.twig';
import loadChannelScope, { foreignChannelIds } from '../../service/channel-scope';

/**
 * Products that are also sold in sales channels outside the user's scope are
 * read-only: a banner explains why and saving is disabled. The write guard
 * refuses such changes anyway.
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

    created() {
        loadChannelScope().then((scope) => {
            this.mnScope = scope;
        });
    },
});
