import template from './sw-cms-detail.html.twig';
import { loadCmsPageScope, registerReadOnlyGuard } from '../../service/channel-scope';

/**
 * Layouts that are also used by other sales channels look and behave like
 * Shopware's locked layouts for a limited user: overlay on the stage, info
 * banner, no saving, and Shopware's acl denies the layout editor, creator
 * and deleter roles while the layout is open. The write guard refuses such
 * changes anyway. New layouts and copies belong to the user's channels.
 */
Shopware.Component.override('sw-cms-detail', {
    template,

    data() {
        return {
            mnCmsScope: null,
        };
    },

    computed: {
        mnLayoutReadOnly() {
            return !!this.mnCmsScope?.restricted && !this.mnCmsScope.editable && !this.page?.locked;
        },

        canSave() {
            return !this.mnLayoutReadOnly && this.$super('canSave');
        },
    },

    watch: {
        pageId: {
            immediate: true,
            handler(pageId) {
                this.mnCmsScope = null;
                if (!pageId) {
                    return;
                }
                loadCmsPageScope(pageId).then((scope) => {
                    if (this.pageId === pageId) {
                        this.mnCmsScope = scope;
                    }
                });
            },
        },
    },

    methods: {
        // Also covers the save shortcut, which doesn't go through the button.
        onSave() {
            if (this.mnLayoutReadOnly) {
                return Promise.resolve();
            }

            return this.$super('onSave');
        },
    },

    created() {
        this.mnRemoveReadOnlyGuard = registerReadOnlyGuard(() => this.mnLayoutReadOnly, ['cms.editor', 'cms.creator', 'cms.deleter']);
    },

    beforeUnmount() {
        this.mnRemoveReadOnlyGuard?.();
    },
});
