# Permission presets

Shopware's permissions don't map one to one to its modules. The order module, for example, also needs document, mail and payment state permissions, which Shopware lists under *Settings* or in no role at all. A preset adds everything a module needs in one step. It only adds; nothing is removed.

## Applying a preset

- **Role page:** *Settings → Users & permissions → Roles → (role)*, card *Permission presets*, **Add**, then save. The button shows **Added** once the preset's roles are on the role. Saving lets the administration expand the roles into privileges, as for every role.
- **Command line:** `bin/console mn:channel-scope:preset <preset> "<role name or id>"` writes the role's privileges directly (from the preset's `expandedPrivileges`).

A preset works with and without sales channels on the role; together with sales channels, the plugin limits what the granted permissions reach.

## The presets

| Preset (`name`) | Admin roles | Additional privileges |
|---|---|---|
| Order management (`orders`) | order viewer, editor, creator, deleter; refund viewer, editor, creator, deleter; discounts; document viewer and deleter | mail sending, document files, document deletion, payment state changes, refund positions, downloads |
| Product management (`products`) | product viewer, editor, creator, deleter; review viewer, editor, deleter | – |
| Category management (`categories`) | category viewer, editor, creator, deleter | – |
| Marketing (`marketing`) | promotion viewer, editor, creator, deleter; layout viewer, editor, creator, deleter | – |
| Shopping Experiences (`cms`) | layout viewer, editor, creator, deleter | – |
| Sales channel settings (`settings`) | settings (`system.system_config`); sales channel viewer and editor | – |

### Order management

| What you do in the order module | What it needs | Where Shopware puts it |
|---|---|---|
| See, create, send documents | `document.viewer` | *Settings → Documents* |
| Delete a document | `document.deleter` (UI check) and `document:delete` | `document.deleter` only grants `document_base_config:delete`; `document:delete` is in no role |
| Send a document or order mail | `api_send_email` | only with *Mail templates → edit* |
| Change the payment status | `order_transaction:update` | not part of *Orders → edit* |
| Refund | capture and refund position privileges | partly missing |
| Document files (document generation v2) | `document_file:*` | in no role |

`document.deleter` also allows deleting document settings in *Settings → Documents*.

### Product management

The product roles pull in media, Shopping Experience viewers and, with Shopware Commercial, bundles and review summaries. The review roles add *Catalogue → Reviews*; approving a review needs `product_review:update`, which the product roles don't grant.

### Marketing and Shopping Experiences

Shopware's layout editor role also grants `category:update` and `landing_page:update` (for assigning layouts); the plugin limits both to the user's channels. The promotion editor role also grants changing rules, payment and shipping methods (for the rule builder in the promotion conditions); limited users may create rules, not change existing rules, payment or shipping methods.

### Sales channel settings

The role's own sales channels appear under *Sales Channels* in the main menu. The settings pages with a sales channel switch become available for the role's channels:

| Page | What a limited user changes |
|---|---|
| Basic information | `core.basicInformation` |
| Cart | `core.cart` |
| Login & registration | `core.loginRegistration` |
| Newsletter | `core.newsletter` |
| Sitemap | `core.sitemap` |
| SEO | URL templates of their channels; the "redirect to canonical URL" card applies to all channels and is hidden |

Settings for all channels, pages that aren't per channel, plugin settings, and creating or deleting sales channels stay with unrestricted users. Other plugins can open their own per-channel settings pages, see [extending](extending.md#settings-pages).

## Preset files

Presets are JSON files in `src/Resources/config/presets/`. Other plugins can add a directory of their own ([extending](extending.md#presets)).

```json
{
    "name": "orders",
    "position": 10,
    "label": { "en-GB": "Order management", "de-DE": "Bestellverwaltung" },
    "description": { "en-GB": "Orders with documents, …", "de-DE": "Bestellungen mit Dokumenten, …" },
    "roles": ["order.viewer", "order.editor", "…"],
    "additionalPrivileges": { "document:delete": "Deleting documents (no role grants it)" },
    "expandedPrivileges": ["order:read", "order:update", "…"]
}
```

| Key | Meaning |
|---|---|
| `name` | the file name without `.json`; letters, digits, `-` and `_` |
| `position` | order on the role page (default 1000) |
| `label`, `description` | texts per locale; the role page uses the user's locale, then its language, then `en-GB` |
| `roles` | admin role keys to tick in the permission grid |
| `additionalPrivileges` | privileges no role grants, each with the reason it is needed |
| `expandedPrivileges` | the entity privileges the roles expand to, as the administration expands them when a role is saved; the command needs them to write a complete role |

Invalid files are reported when a preset is loaded (`UnexpectedValueException` naming the file and the problem).

## Keeping presets up to date

After a Shopware, Commercial or plugin update, the roles may expand to different privileges. Recompute them in a shop:

```bash
node bin/expand-preset.js src/Resources/config/presets/orders.json /path/to/shop
```

and copy `privileges` into `expandedPrivileges`. The script reads every administration ACL file of the core administration, Shopware Commercial and the plugins, merges entries that extend the same role and resolves `getPrivileges()` references, so the result matches what the administration stores when a role is saved there.
