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
