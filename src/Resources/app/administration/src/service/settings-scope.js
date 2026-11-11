/**
 * Settings pages a user limited to sales channels may open: the overview and
 * the pages with a sales channel switch whose values the server lets them
 * change for their own channels (ConfigRules::EDITABLE_DOMAINS, SEO URL
 * templates). They still need the role's privileges (preset "Sales channel
 * settings"). Each entry is a route name prefix: the page's own routes
 * ("…index", "…detail") and the overview tabs ("sw.settings.index.shop").
 */
const prefixes = [
    'sw.settings.index',
    'sw.settings.basic.information',
    'sw.settings.cart',
    'sw.settings.login.registration',
    'sw.settings.newsletter',
    'sw.settings.sitemap',
    'sw.settings.seo',
];

/**
 * Lets limited users open another settings page (route name prefix, e.g.
 * "sw.settings.acme"). The page's data still needs the role's privileges and
 * the server's rules.
 */
export function addSettingsRoutePrefix(prefix) {
    if (typeof prefix === 'string' && prefix !== '' && !prefixes.includes(prefix)) {
        prefixes.push(prefix);
    }
}

export function settingsRoutePrefixes() {
    return [...prefixes];
}

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

    return prefixes.some((prefix) => name === prefix || name.startsWith(`${prefix}.`));
}
