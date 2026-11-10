# Changelog

## 0.5.2

- Limited users don't see the *Settings* entry in the main menu. Shopware shows it to every user; for a limited user it only led to settings of all sales channels (such as the document settings the order preset grants read access to). The settings pages stay protected by their own privileges.

## 0.5.1

- Shared products and categories now look like they do for a view-only user: every field is disabled (while a shared record is open, Shopware's acl denies its editor, creator and deleter roles), and the save shortcut does nothing.
- The read-only hints sit in the card column above the tabs, with the same width and spacing as Shopware's own page hints.

## 0.5.0

- Categories: limited users see the category trees of their sales channels (below their entry categories: navigation, footer, service) and the ancestors of those entries; trees no channel of theirs uses, and trees outside every channel, are hidden. Categories that belong only to their channels are editable; shared trees and ancestors are read-only (banner and disabled save on the category page, refused by the write guard). New categories need such a parent; deleting or moving a subtree that holds another channel's entry category is refused. Products can only be assigned to categories the user sees; main categories only of the user's channels.
- Sales channel entry categories can only be set to categories the user may edit.
- Fixed: translations of shared products (name, description) could be changed; `product_translation` is now checked like the product.
- New permission preset *Category management*.
- `GET /api/_action/mn-channel-scope/me` also returns the entry categories (`categoryEntryPoints.own`, `.foreign`).

## 0.4.0

- Totals Shopware stores across all sales channels are counted in the limited user's channels when they are loaded: customer order count, order total, last order date and review count; product rating and sales. Same rules as Shopware (completed orders, approved reviews, orders that are not cancelled). Before, a customer who also bought in another channel revealed that turnover. Filtering and sorting by these fields still use the stored totals.

## 0.3.3

- Fixed: pre-selected sales channels of a new product were stored but not shown in the *Sales Channels* field. Shopware adds the default visibilities after the field has rendered, and the field only follows a replaced collection; the plugin now replaces it once the defaults are added. This also affects Shopware's own default sales channels for unrestricted users.

## 0.3.2

- New products of a limited user start with their own sales channels selected: the default sales channels (*Settings → Products*) among the user's channels, or all of the user's channels when none of the defaults is theirs. Before, such products could only be saved after adding a visibility by hand.

## 0.3.1

- New permission preset *Product management*.
- `bin/expand-preset.js` now expands roles the way the administration does: it reads the ACL files of the core administration, Shopware Commercial and plugins, merges entries that extend the same role and resolves `getPrivileges()` references. The stored privileges of the presets were incomplete before: the product roles' media and Shopping Experience permissions and Commercial's additions were missing, and the order preset lacked `b2b_order_employee:*` from B2B.

## 0.3.0

- Products (iteration 2): limited users see only products sold in their sales channels (variants through their parent) and their prices, media, visibilities, configurator settings, cross-sellings, downloads and search keywords, and only product reviews of their channels.
- Shared products (also sold in other channels) are read-only: the product page shows a banner and disables saving, and the write guard refuses changes. Products sold only in the user's channels stay editable.
- New products need a visibility in one of the user's channels; new variants need a parent the user owns. Visibilities and main categories of the user's own channels can be changed on every product they see.
- `GET /api/_action/mn-channel-scope/me` tells the administration whether the user is limited and to which channels.

## 0.2.0

- Permission preset *Order management* (`src/Resources/config/presets/orders.json`): everything the order module needs, including documents (create, upload, download, send, delete), refunds, discounts and payment and delivery states.
- Role page: button *Add preset: Order management*; adds the preset to the role's permissions, the user saves as usual.
- Command `mn:channel-scope:preset <preset> <role>` adds a preset to a role from the CLI.
- `bin/expand-preset.js` regenerates the preset's expanded privileges after Shopware updates.

## 0.1.0

- First iteration (proof of concept): ACL roles can be limited to sales channels. Restricted users only see and change the orders and customers of their channels, and only see their own sales channels.
