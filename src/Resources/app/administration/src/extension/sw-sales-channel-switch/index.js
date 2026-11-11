import template from './sw-sales-channel-switch.html.twig';
import loadChannelScope from '../../service/channel-scope';

/**
 * Settings for all sales channels are reserved for unrestricted users: a
 * user limited to sales channels gets no "All Sales Channels" option, and the
 * switch starts with their first channel (the list only holds their own).
 */
Shopware.Component.override('sw-sales-channel-switch', {
    template,

    data() {
        return { mnRestricted: false };
    },

    created() {
        loadChannelScope().then((scope) => {
            if (!scope?.restricted) {
                return;
            }
            this.mnRestricted = true;

            const first = [...(scope.salesChannelIds ?? [])].sort()[0];
            if (!this.salesChannelId && first) {
                this.$nextTick(() => this.onChange(first));
            }
        });
    },
});
