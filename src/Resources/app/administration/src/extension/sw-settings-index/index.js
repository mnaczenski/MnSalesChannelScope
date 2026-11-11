import loadChannelScope from '../../service/channel-scope';
import { isAllowedSettingsRoute } from '../../service/settings-scope';

/**
 * The settings overview shows a user limited to sales channels only the
 * sales channel specific pages (service/settings-scope.js).
 */
Shopware.Component.override('sw-settings-index', {
    data() {
        return { mnScope: null };
    },

    computed: {
        settingsGroups() {
            const groups = this.$super('settingsGroups');
            if (!this.mnScope?.restricted) {
                return groups;
            }

            return Object.fromEntries(
                Object.entries(groups)
                    .map(([name, settings]) => [name, settings.filter((setting) => isAllowedSettingsRoute(setting.to))])
                    .filter(([, settings]) => settings.length > 0),
            );
        },
    },

    created() {
        loadChannelScope().then((scope) => {
            this.mnScope = scope;
        });
    },
});
