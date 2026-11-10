# Changelog

## 0.2.0

- Permission preset *Order management* (`src/Resources/config/presets/orders.json`): everything the order module needs, including documents (create, upload, download, send, delete), refunds, discounts and payment and delivery states.
- Role page: button *Add preset: Order management*; adds the preset to the role's permissions, the user saves as usual.
- Command `mn:channel-scope:preset <preset> <role>` adds a preset to a role from the CLI.
- `bin/expand-preset.js` regenerates the preset's expanded privileges after Shopware updates.

## 0.1.0

- First iteration (proof of concept): ACL roles can be limited to sales channels. Restricted users only see and change the orders and customers of their channels, and only see their own sales channels.
