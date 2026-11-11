import template from './sw-settings-seo.html.twig';
import loadChannelScope from '../../service/channel-scope';

/**
 * SEO settings for a user limited to sales channels: the URL templates of
 * their channels (seo_url_template, checked by the write guard). The
 * "redirect to canonical URL" card has no sales channel switch and always
 * saves the value for all channels, so it is hidden for them (the page only
 * saves it when the card is rendered).
 */
Shopware.Component.override('sw-settings-seo', {
    template,

    data() {
        return { mnRestricted: false };
    },

    created() {
        loadChannelScope().then((scope) => {
            this.mnRestricted = !!scope?.restricted;
            if (this.mnRestricted) {
                // The card may have started loading before it was hidden; it
                // can no longer report the end of loading.
                this.isLoading = false;
            }
        });
    },
});
