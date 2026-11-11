import loadChannelScope from '../../service/channel-scope.js';

/**
 * With the administration's OpenSearch search (SHOPWARE_ADMIN_ES_ENABLED),
 * the global search asks the search index for the best matches of all sales
 * channels; for a limited user, matches of other channels would be dropped
 * afterwards and the counts would include them. Limited users search the
 * database instead, which is filtered like every list (the server refuses
 * them the OpenSearch route, see AdminSearchGuardSubscriber).
 */
Shopware.Component.override('sw-search-bar', {
    data() {
        return {
            mnRestricted: false,
        };
    },

    computed: {
        adminEsEnable() {
            return !this.mnRestricted && this.$super('adminEsEnable');
        },
    },

    created() {
        loadChannelScope().then((scope) => {
            if (!scope?.restricted) {
                return;
            }

            this.mnRestricted = true;
            // also read directly, e.g. by sw-highlight-text
            Shopware.Context.app.adminEsEnable = false;
        });
    },
});
