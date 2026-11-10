import template from './sw-product-detail.html.twig';
import loadChannelScope, { foreignChannelIds, registerReadOnlyGuard } from '../../service/channel-scope';

const { EntityCollection } = Shopware.Data;

/**
 * Products that are also sold in sales channels outside the user's scope are
 * read-only: a banner explains why, saving is disabled and every field is
 * disabled (while it is open, Shopware's acl denies the product editor,
 * creator and deleter roles, see registerReadOnlyGuard). The write guard refuses such changes
 * anyway.
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
        // Also covers the save shortcut, which doesn't go through the button.
        onSave() {
            if (this.mnReadOnly) {
                return Promise.resolve();
            }

            return this.$super('onSave');
        },

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

        /**
         * Shopware adds the default visibilities to the new product after the
         * sales channel select has rendered. The select copies its collection
         * once and only follows when the collection is replaced, so the
         * pre-selected channels were saved but not shown. Replacing the
         * collection after Shopware added them makes the select show them.
         */
        fetchSalesChannelByIds(ids) {
            return this.$super('fetchSalesChannelByIds', ids).then((salesChannels) => {
                if (this.product?.isNew?.()) {
                    // after Shopware's own callback has pushed the visibilities
                    setTimeout(() => {
                        const product = Shopware.Store.get('swProductDetail').product;
                        if (product?.visibilities) {
                            product.visibilities = EntityCollection.fromCollection(product.visibilities);
                        }
                    }, 0);
                }

                return salesChannels;
            });
        },
    },

    created() {
        this.mnRemoveReadOnlyGuard = registerReadOnlyGuard(() => this.mnReadOnly, ['product.editor', 'product.creator', 'product.deleter']);

        loadChannelScope().then((scope) => {
            this.mnScope = scope;
        });
    },

    beforeUnmount() {
        this.mnRemoveReadOnlyGuard?.();
    },
});
