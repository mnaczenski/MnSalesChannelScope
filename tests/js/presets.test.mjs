import { test } from 'node:test';
import assert from 'node:assert/strict';
import { installShopware } from './shopware-stub.mjs';
import { isPresetApplied, loadPresets, translated } from '../../src/Resources/app/administration/src/service/presets.js';

test('texts in the locale, its language, English or whatever there is', () => {
    const texts = { 'en-GB': 'Orders', 'de-DE': 'Bestellungen' };

    assert.equal(translated(texts, 'de-DE'), 'Bestellungen');
    assert.equal(translated(texts, 'de-AT'), 'Bestellungen');
    assert.equal(translated(texts, 'fr-FR'), 'Orders');
    assert.equal(translated({ 'nl-NL': 'Bestellingen' }, 'fr-FR'), 'Bestellingen');
    assert.equal(translated(null, 'en-GB'), '');
});

test('a preset is applied when its roles and additional privileges are there', () => {
    const preset = { roles: ['order.viewer', 'order.editor'], additionalPrivileges: { 'document:delete': 'why' } };

    assert.equal(isPresetApplied(preset, ['order.viewer'], []), false);
    assert.equal(isPresetApplied(preset, ['order.viewer', 'order.editor'], []), false);
    assert.equal(isPresetApplied(preset, ['order.viewer', 'order.editor'], ['document:delete']), true);
    // without detailed privileges, the roles decide
    assert.equal(isPresetApplied(preset, ['order.viewer', 'order.editor'], null), true);
});

test('presets come from the API, once', async () => {
    const presets = [{ name: 'orders', roles: [], additionalPrivileges: {} }];
    const { requests } = installShopware({ responses: { '_action/mn-channel-scope/presets': presets } });

    assert.deepEqual(await loadPresets(), presets);
    await loadPresets();
    assert.equal(requests.length, 1);
});
