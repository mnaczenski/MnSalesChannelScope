import template from './sw-category-detail.html.twig';
import loadChannelScope, { canEditCategory, registerReadOnlyGuard } from '../../service/channel-scope';

/**
 * Categories that are also used by other sales channels (or that are only
 * ancestors of the user's entry categories) are read-only: saving and every
 * field are disabled (while it is open, Shopware's acl denies the category editor,
 * creator and deleter roles, see registerReadOnlyGuard), sw-category-view shows the
 * banner. The write guard refuses such changes anyway.
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

    methods: {
        // Also covers the save shortcut, which doesn't go through the button.
        onSave() {
            if (this.mnCategoryReadOnly) {
                return Promise.resolve();
            }

            return this.$super('onSave');
        },
    },

    created() {
        this.mnRemoveReadOnlyGuard = registerReadOnlyGuard(() => this.mnCategoryReadOnly, ['category.editor', 'category.creator', 'category.deleter']);

        loadChannelScope().then((scope) => {
            this.mnScope = scope;
        });
    },

    beforeUnmount() {
        this.mnRemoveReadOnlyGuard?.();
    },
});
