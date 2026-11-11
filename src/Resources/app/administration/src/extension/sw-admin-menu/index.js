import loadChannelScope from '../../service/channel-scope';
import { isAllowedSettingsRoute, isSettingsRoute } from '../../service/settings-scope';

/**
 * Users limited to sales channels only get the sales channel specific
 * settings pages (service/settings-scope.js), and only with the role's
 * Settings permission (preset "Sales channel settings"). Without it, the
 * Settings entry is hidden; other settings pages opened by URL redirect to
 * the dashboard. The API checks every request on its own.
 */
Shopware.Component.override('sw-admin-menu', {
    inject: ['acl'],

    data() {
        return { mnScope: null };
    },

    computed: {
        navigationEntries() {
            const entries = this.$super('navigationEntries');
            if (!this.mnScope?.restricted || this.mnCanUseSettings) {
                return entries;
            }

            return entries.filter((entry) => entry.id !== 'sw-settings'
                && entry.parent !== 'sw-settings'
                && entry.path !== 'sw.settings.index');
        },

        mnCanUseSettings() {
            return this.acl.can('system.system_config');
        },
    },

    created() {
        loadChannelScope().then((scope) => {
            this.mnScope = scope;
            if (!scope?.restricted) {
                return;
            }

            const blocked = (route) => isSettingsRoute(route) && !(this.mnCanUseSettings && isAllowedSettingsRoute(route));
            this.$router.beforeEach((to) => (blocked(to) ? { name: 'sw.dashboard.index' } : true));
            if (blocked(this.$route)) {
                this.$router.replace({ name: 'sw.dashboard.index' });
            }
        });
    },
});
