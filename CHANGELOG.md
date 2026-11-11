# Changelog

## 0.9.0

- The *Product management* preset also adds Shopware's review roles (viewer, editor, deleter): *Catalogue → Reviews* with the reviews of the role's sales channels; approving needs `product_review:update`, which the product roles don't grant.
- Commercial's review summaries (`product_review_summary`, one per product and sales channel) and their translations: limited users see and change only those of their channels.

## 0.8.0

- The *Sales channel settings* preset also gives the role its own sales channels (*Sales Channels* in the main menu, Shopware's sales channel viewer and editor roles). Creating and deleting sales channels stays with unrestricted users.
- What a sales channel offers (countries, currencies, languages, payment and shipping methods, translations, theme assignment), its analytics, product comparison exports and files: limited users see and change only those of their channels.
- Themes: assigning a theme only to their channels (`POST /api/_action/theme/{id}/assign/{salesChannelId}` marks the pending theme with plain SQL and may switch after an asynchronous compilation, so it is checked on the route); changing or resetting a theme's configuration only when it is used by their channels only (`ThemeGuardSubscriber`; Shopware checks no privilege on these routes).
- Configuration values: limited users may also read the storefront's theme bookkeeping (`storefront.*`: pending theme, theme seeds), which the sales channel's theme tab shows; changing it stays with Shopware's theme assignment.

## 0.7.0

- Shopping Experiences: a layout belongs to the sales channels of the categories, products and landing pages that use it, of the default layout settings that name it and, while nothing uses it, of the limited user who created it (new table `mn_cms_page_sales_channel`). Limited users see the layouts of their channels and Shopware's locked layouts; they edit layouts that belong to their channels only. Shared layouts look like locked ones in the editor (overlay, hint, no saving); copies of them belong to the user's channels. Sections, blocks, slots and translations follow their layout. Categories, products and landing pages can only get layouts the user sees.
- Landing pages: visible when shown in one of the user's channels, editable when shown only there; new landing pages only for the user's channels.
- New permission preset *Shopping Experiences*.
- Clone (`POST /api/_action/clone/{entity}/{id}`): Shopware reads the source without the read filter's search events and writes the copy in the system scope, so limited users could clone records of other channels. Now the source must be visible to them, products only when sold in their channels only (the copy keeps the visibilities); copies of layouts belong to their channels (`CloneGuardSubscriber`).
- `GET /api/_action/mn-channel-scope/cms-page/{id}` returns `{ restricted, editable, salesChannelIds }` for the editor.

## 0.6.0

Hardening: paths around the read filter.

- *Log in as customer* (`POST /api/_proxy/generate-imitate-customer-token`): Shopware checks only that the customer and the sales channel exist, without the search events the read filter hooks into, so a limited user could get a storefront login for customers of other channels. Now the channel must be one of theirs and the customer registered in or bound to one of their channels (a customer who only ordered there would show orders of other channels in the storefront account).
- Sales channel settings: configuration values (`system_config`, where payment credentials can live), SEO URLs and domains of other channels are hidden. Limited users only change those of their own channels, none that apply to all channels.
- Settings pages opened by URL redirect limited users to the dashboard.
- Configuration values: limited users only read Shopware's own settings (`core.*`), without credentials and installation-wide domains (mailer, store and license, apps, services, updates, usage data, webhooks). Before, every value for all channels was readable with `system_config:read`, which the order preset includes.
- New permission preset *Sales channel settings*: the settings pages with a sales channel switch (basic information, cart, login & registration, newsletter, sitemap) for the role's own channels, plus the SEO URL templates of those channels. The settings API (`/api/_action/system-config`, plain SQL, not covered by the write guard) is guarded by `SystemConfigGuardSubscriber`; the overview only shows these pages, and the channel switch has no *All Sales Channels* option. Product sortings (part of Shopware's *Settings* role) can't be changed by limited users.

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
