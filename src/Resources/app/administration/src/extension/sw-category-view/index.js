import template from './sw-category-view.html.twig';
import loadChannelScope, { canEditCategory } from '../../service/channel-scope';

/**
 * The read-only banner for shared categories, in the card column above the
 * tabs like Shopware's own category hints.
 */
Shopware.Component.override('sw-category-view', {
    template,

    data() {
        return {
            mnScope: null,
        };
    },

    computed: {
        mnCategoryReadOnly() {
            const category = Shopware.Store.get('swCategoryDetail')?.category;
            if (!this.mnScope?.restricted || !category || category.isNew?.()) {
                return false;
            }

            return !canEditCategory(this.mnScope, category);
        },
    },

    created() {
        loadChannelScope().then((scope) => {
            this.mnScope = scope;
        });
    },
});
