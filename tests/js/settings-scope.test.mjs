import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    addSettingsRoutePrefix,
    isAllowedSettingsRoute,
    isSettingsRoute,
    settingsRoutePrefixes,
} from '../../src/Resources/app/administration/src/service/settings-scope.js';

test('settings routes are recognised by name or route object', () => {
    assert.equal(isSettingsRoute('sw.settings.cart.index'), true);
    assert.equal(isSettingsRoute({ name: 'sw.settings.mailer.index' }), true);
    assert.equal(isSettingsRoute({ name: 'sw.product.index' }), false);
    assert.equal(isSettingsRoute(undefined), false);
});

test('only the preset pages and the overview are allowed', () => {
    assert.equal(isAllowedSettingsRoute('sw.settings.index'), true);
    assert.equal(isAllowedSettingsRoute('sw.settings.index.shop'), true);
    assert.equal(isAllowedSettingsRoute('sw.settings.cart.index'), true);
    assert.equal(isAllowedSettingsRoute('sw.settings.seo.index'), true);
    assert.equal(isAllowedSettingsRoute('sw.settings.listing.index'), false);
    assert.equal(isAllowedSettingsRoute('sw.settings.mailer.index'), false);
    // a prefix matches whole segments only
    assert.equal(isAllowedSettingsRoute('sw.settings.cartx.index'), false);
});

test('plugins can allow their own settings pages', () => {
    assert.equal(isAllowedSettingsRoute('sw.settings.acme.index'), false);
    addSettingsRoutePrefix('sw.settings.acme');
    addSettingsRoutePrefix('sw.settings.acme');
    addSettingsRoutePrefix('');

    assert.equal(isAllowedSettingsRoute('sw.settings.acme.index'), true);
    assert.equal(settingsRoutePrefixes().filter((prefix) => prefix === 'sw.settings.acme').length, 1);
    assert.equal(settingsRoutePrefixes().includes(''), false);
});
