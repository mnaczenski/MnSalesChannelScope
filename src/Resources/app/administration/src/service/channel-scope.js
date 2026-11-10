/**
 * The current user's sales channel limit, loaded once per login:
 * { restricted: boolean, salesChannelIds: string[] }.
 * Only used to mark shared records as read-only; the server enforces the rules.
 */
let pending = null;

export default function loadChannelScope() {
    if (pending === null) {
        const httpClient = Shopware.Application.getContainer('init').httpClient;
        const loginService = Shopware.Service('loginService');

        pending = httpClient
            .get('_action/mn-channel-scope/me', {
                headers: {
                    Accept: 'application/json',
                    Authorization: `Bearer ${loginService.getToken()}`,
                },
            })
            .then((response) => response.data)
            .catch(() => {
                pending = null;

                return { restricted: false, salesChannelIds: [] };
            });

        loginService.addOnLogoutListener?.(() => {
            pending = null;
        });
    }

    return pending;
}

/**
 * Sales channel ids of a product's visibilities (a variant without its own
 * uses its parent's) that are not the user's.
 */
export function foreignChannelIds(scope, product, parentProduct) {
    if (!scope?.restricted || !product) {
        return [];
    }

    const own = product.visibilities?.length ? product.visibilities : (parentProduct?.visibilities ?? []);
    const ids = Array.from(own ?? []).map((visibility) => visibility.salesChannelId);

    return ids.filter((id) => !scope.salesChannelIds.includes(id));
}

/**
 * Whether a limited user may edit a category: it lies in a tree of their
 * channels (an own entry category is the category or one of its ancestors)
 * and in no tree of another channel. Same rule as the server's CategoryTrees.
 */
export function canEditCategory(scope, category) {
    if (!scope?.restricted || !category?.id) {
        return true;
    }

    const lineage = [...String(category.path ?? '').split('|').filter(Boolean), category.id];
    const entries = scope.categoryEntryPoints ?? { own: [], foreign: [] };

    return lineage.some((id) => entries.own.includes(id)) && !lineage.some((id) => entries.foreign.includes(id));
}
