import template from './sw-category-detail.html.twig';
import loadChannelScope, { canEditCategory } from '../../service/channel-scope';

/**
 * Categories that are also used by other sales channels (or that are only
 * ancestors of the user's entry categories) are read-only: a banner explains
 * why and saving is disabled. The write guard refuses such changes anyway.
 */
Shopware.Component.override('sw-category-detail', {
    template,

    data() {
        return {
            mnScope: null,
        };
    },

    computed: {
        mnCategoryReadOnly() {
            if (!this.mnScope?.restricted || !this.category || this.category.isNew?.()) {
                return false;
            }

            return !canEditCategory(this.mnScope, this.category);
        },
    },

    created() {
        loadChannelScope().then((scope) => {
            this.mnScope = scope;
        });
    },
});
