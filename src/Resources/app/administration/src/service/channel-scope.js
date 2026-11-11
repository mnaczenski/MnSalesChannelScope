import { apiGet } from './api.js';

/**
 * The current user's sales channel limit, loaded once per login:
 * { restricted, salesChannelIds, categoryEntryPoints: { own, foreign } }.
 * Only used to mark shared records as read-only; the server enforces the rules.
 */
const UNRESTRICTED = Object.freeze({ restricted: false, salesChannelIds: [], categoryEntryPoints: { own: [], foreign: [] } });

let pending = null;
let logoutListener = false;

export default function loadChannelScope() {
    if (pending === null) {
        pending = apiGet('_action/mn-channel-scope/me').catch(() => {
            pending = null;

            return UNRESTRICTED;
        });

        if (!logoutListener) {
            Shopware.Service('loginService').addOnLogoutListener?.(() => {
                pending = null;
            });
            logoutListener = true;
        }
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

/**
 * While a shared record is open, Shopware's acl service answers "no" for its
 * editor, creator and deleter roles. Shopware's fields disable themselves
 * through acl.can('…editor'), so the record looks exactly like it does for a
 * user who may only view it.
 *
 * (A page can't hand its children its own acl: Shopware builds overridden
 * components itself and doesn't pass an override's provide() on.)
 *
 * isReadOnly is called inside acl.can(), so components re-render when it
 * changes. Returns the function that removes the guard.
 */
const guards = new Set();
let installed = false;

export function registerReadOnlyGuard(isReadOnly, deniedPrivileges) {
    const acl = Shopware.Service('acl');

    if (!installed) {
        const can = acl.can.bind(acl);
        acl.can = (privilege) => {
            for (const guard of guards) {
                if (guard.deniedPrivileges.includes(privilege) && guard.isReadOnly()) {
                    return false;
                }
            }

            return can(privilege);
        };
        installed = true;
    }

    const guard = { isReadOnly, deniedPrivileges };
    guards.add(guard);

    return () => guards.delete(guard);
}

/**
 * Whether the current user may edit a Shopping Experience layout:
 * { restricted, editable, salesChannelIds }. Only drives the UI.
 */
export function loadCmsPageScope(pageId) {
    return apiGet(`_action/mn-channel-scope/cms-page/${pageId}`)
        .catch(() => ({ restricted: false, editable: true, salesChannelIds: [] }));
}
