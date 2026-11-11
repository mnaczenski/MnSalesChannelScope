import template from './sw-users-permissions-role-view-general.html.twig';
import ordersPreset from '../../../../../config/presets/orders.json';
import productsPreset from '../../../../../config/presets/products.json';
import categoriesPreset from '../../../../../config/presets/categories.json';
import settingsPreset from '../../../../../config/presets/settings.json';
import cmsPreset from '../../../../../config/presets/cms.json';
import marketingPreset from '../../../../../config/presets/marketing.json';

const PRESETS = [ordersPreset, productsPreset, categoriesPreset, marketingPreset, cmsPreset, settingsPreset];

Shopware.Component.override('sw-users-permissions-role-view-general', {
    template,

    mixins: [Shopware.Mixin.getByName('notification')],

    props: {
        // Passed by sw-users-permissions-role-detail to every role view; the page saves them with the role.
        detailedPrivileges: {
            type: Array,
            required: false,
            default: null,
        },
    },

    computed: {
        mnSalesChannels() {
            return this.role?.extensions?.mnSalesChannels ?? null;
        },

        mnPresets() {
            return PRESETS;
        },
    },

    methods: {
        onMnSalesChannelsChange(collection) {
            this.role.extensions.mnSalesChannels = collection;
        },

        /**
         * Adds the preset's admin roles to the permission grid and its
         * additional privileges to the detailed privileges. Nothing is
         * removed; the role is stored when the user saves.
         */
        onMnApplyPreset(preset) {
            preset.roles.forEach((key) => {
                if (!this.role.privileges.includes(key)) {
                    this.role.privileges.push(key);
                }
            });

            if (Array.isArray(this.detailedPrivileges)) {
                Object.keys(preset.additionalPrivileges).forEach((privilege) => {
                    if (!this.detailedPrivileges.includes(privilege)) {
                        this.detailedPrivileges.push(privilege);
                    }
                });
            }

            this.createNotificationSuccess({
                message: this.$t('mn-sales-channel-scope.preset.applied', {
                    name: this.$t(`mn-sales-channel-scope.preset.names.${preset.name}`),
                }),
            });
        },
    },
});
