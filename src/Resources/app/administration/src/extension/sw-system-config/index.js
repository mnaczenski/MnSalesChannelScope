/**
 * The fields show the values "for all sales channels" as inherited values
 * (actualConfigData.null). Shopware loads them first because the switch
 * starts on "All Sales Channels"; for users limited to sales channels the
 * switch starts on their own channel (sw-sales-channel-switch), possibly
 * before those values are loaded. Load them before any channel's values.
 */
Shopware.Component.override('sw-system-config', {
    methods: {
        async readAll() {
            if (this.currentSalesChannelId !== null && !Object.prototype.hasOwnProperty.call(this.actualConfigData, 'null')) {
                this.mnInherited ??= this.systemConfigApiService.getValues(this.domain, null).then((values) => {
                    this.actualConfigData.null = values;
                    this.initialConfigData.null = Shopware.Utils.object.deepCopyObject(values);
                });
                await this.mnInherited;
            }

            return this.$super('readAll');
        },
    },
});
