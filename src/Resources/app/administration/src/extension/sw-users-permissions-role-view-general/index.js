import template from './sw-users-permissions-role-view-general.html.twig';
import { isPresetApplied, loadPresets, translated } from '../../service/presets';

/**
 * Role page: the role's sales channels (in the basic information) and the
 * permission presets card.
 */
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

    data() {
        return {
            mnPresets: [],
        };
    },

    computed: {
        mnSalesChannels() {
            return this.role?.extensions?.mnSalesChannels ?? null;
        },

        mnLocale() {
            return Shopware.Store.get('session')?.currentLocale ?? 'en-GB';
        },
    },

    created() {
        loadPresets().then((presets) => {
            this.mnPresets = presets;
        });
    },

    methods: {
        mnPresetLabel(preset) {
            return translated(preset.label, this.mnLocale);
        },

        mnPresetDescription(preset) {
            return translated(preset.description, this.mnLocale);
        },

        mnPresetApplied(preset) {
            return isPresetApplied(preset, this.role?.privileges, this.detailedPrivileges);
        },

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
                message: this.$t('mn-sales-channel-scope.preset.applied', { name: this.mnPresetLabel(preset) }),
            });
        },
    },
});
