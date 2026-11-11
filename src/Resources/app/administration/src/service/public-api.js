import loadChannelScope, { canEditCategory, foreignChannelIds, loadCmsPageScope, registerReadOnlyGuard } from './channel-scope.js';
import { addSettingsRoutePrefix, isAllowedSettingsRoute, isSettingsRoute, settingsRoutePrefixes } from './settings-scope.js';
import { loadPresets } from './presets.js';

/**
 * The plugin's administration API for other plugins:
 *
 *     const scope = Shopware.Service('mnChannelScope');
 *     const { restricted, salesChannelIds } = await scope.load();
 *
 * See docs/extending.md.
 */
export default function createPublicApi() {
    return Object.freeze({
        /** The current user's sales channel limit (cached per login). */
        load: loadChannelScope,
        /** { restricted, editable, salesChannelIds } of a Shopping Experience layout. */
        loadCmsPage: loadCmsPageScope,
        /** The permission presets of the role page. */
        loadPresets,
        /** While isReadOnly() is true, acl.can() answers false for the given privileges. Returns the remover. */
        registerReadOnlyGuard,
        canEditCategory,
        foreignChannelIds,
        /** Settings pages limited users may open (route name prefixes). */
        addSettingsRoutePrefix,
        settingsRoutePrefixes,
        isSettingsRoute,
        isAllowedSettingsRoute,
    });
}
