import loadChannelScope from '../../service/channel-scope';

/**
 * Limited users don't get the Settings entry in the main menu. Shopware shows
 * it to every user (it has no privilege of its own); for a limited user it
 * would only lead to settings of all sales channels, such as the document
 * settings the order module needs read access to.
 *
 * The settings pages themselves stay protected by their own privileges.
 */
Shopware.Component.override('sw-admin-menu', {
    data() {
        return {
            mnScope: null,
        };
    },

    computed: {
        navigationEntries() {
            const entries = this.$super('navigationEntries');
            if (!this.mnScope?.restricted) {
                return entries;
            }

            return entries.filter((entry) => entry.id !== 'sw-settings' && entry.parent !== 'sw-settings' && entry.path !== 'sw.settings.index');
        },
    },

    created() {
        loadChannelScope().then((scope) => {
            this.mnScope = scope;
        });
    },
});
