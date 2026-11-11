/**
 * Settings pages a user limited to sales channels may open: the overview and
 * the pages with a sales channel switch whose values the server lets them
 * change for their own channels (ConfigRules::EDITABLE_DOMAINS, SEO URL
 * templates). They still need the role's privileges (preset "Sales channel
 * settings"). Each entry is a route name prefix: the page's own routes
 * ("…index", "…detail") and the overview tabs ("sw.settings.index.shop").
 */
export const SETTINGS_ROUTE_PREFIXES = [
    'sw.settings.index',
    'sw.settings.basic.information',
    'sw.settings.cart',
    'sw.settings.login.registration',
    'sw.settings.newsletter',
    'sw.settings.sitemap',
    'sw.settings.seo',
];

const routeName = (target) => (typeof target === 'string' ? target : target?.name);

export function isSettingsRoute(target) {
    const name = routeName(target);

    return typeof name === 'string' && name.startsWith('sw.settings');
}

export function isAllowedSettingsRoute(target) {
    const name = routeName(target);
    if (typeof name !== 'string') {
        return false;
    }

    return SETTINGS_ROUTE_PREFIXES.some((prefix) => name === prefix || name.startsWith(`${prefix}.`));
}
