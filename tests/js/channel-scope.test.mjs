import { test } from 'node:test';
import assert from 'node:assert/strict';
import { installShopware } from './shopware-stub.mjs';
import loadChannelScope, {
    canEditCategory,
    foreignChannelIds,
    loadCmsPageScope,
    registerReadOnlyGuard,
} from '../../src/Resources/app/administration/src/service/channel-scope.js';

const DE = 'de';
const EN = 'en';
const scope = { restricted: true, salesChannelIds: [DE], categoryEntryPoints: { own: ['nav-de', 'shared'], foreign: ['nav-en', 'shared'] } };

test('foreign channels of a product, or of its parent for a variant', () => {
    const shared = { visibilities: [{ salesChannelId: DE }, { salesChannelId: EN }] };

    assert.deepEqual(foreignChannelIds(scope, shared), [EN]);
    assert.deepEqual(foreignChannelIds(scope, { visibilities: [] }, shared), [EN]);
    assert.deepEqual(foreignChannelIds(scope, { visibilities: [{ salesChannelId: DE }] }), []);
    assert.deepEqual(foreignChannelIds({ restricted: false }, shared), []);
});

test('a category is editable in an own tree and in no foreign one', () => {
    assert.equal(canEditCategory(scope, { id: 'shoes', path: '|root|nav-de|' }), true);
    assert.equal(canEditCategory(scope, { id: 'nav-de', path: '|root|' }), true);
    assert.equal(canEditCategory(scope, { id: 'sale', path: '|root|shared|' }), false, 'shared tree');
    assert.equal(canEditCategory(scope, { id: 'root', path: null }), false, 'ancestor of an entry');
    assert.equal(canEditCategory({ restricted: false }, { id: 'root' }), true);
});

test('read-only guards deny their privileges while active', () => {
    const { acl } = installShopware({ can: () => true });
    let readOnly = true;
    const remove = registerReadOnlyGuard(() => readOnly, ['product.editor']);

    assert.equal(acl.can('product.editor'), false);
    assert.equal(acl.can('product.viewer'), true);
    readOnly = false;
    assert.equal(acl.can('product.editor'), true);
    readOnly = true;
    remove();
    assert.equal(acl.can('product.editor'), true);
});

test('the scope is loaded once and reset on logout', async () => {
    const me = { restricted: true, salesChannelIds: [DE], categoryEntryPoints: { own: [], foreign: [] } };
    const { requests, logout } = installShopware({ responses: { '_action/mn-channel-scope/me': me } });

    assert.deepEqual(await loadChannelScope(), me);
    await loadChannelScope();
    assert.equal(requests.length, 1);
    assert.equal(requests[0].options.headers.Authorization, 'Bearer token');

    logout();
    await loadChannelScope();
    assert.equal(requests.length, 2);
});

test('failures count as unrestricted, for the UI only', async () => {
    installShopware({ responses: {} });

    assert.deepEqual(await loadCmsPageScope('page'), { restricted: false, editable: true, salesChannelIds: [] });
});
