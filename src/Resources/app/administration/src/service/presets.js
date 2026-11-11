/**
 * Permission presets for the role page, served by
 * GET /api/_action/mn-channel-scope/presets (every preset directory, ordered).
 */
import { apiGet } from './api.js';

let pending = null;

export function loadPresets() {
    pending ??= apiGet('_action/mn-channel-scope/presets').catch(() => {
        pending = null;

        return [];
    });

    return pending;
}

/**
 * The text of a { "en-GB": "...", "de-DE": "..." } object for a locale:
 * the locale, its language (de-AT → de-DE), English, or the first text.
 */
export function translated(texts, locale) {
    if (!texts || typeof texts !== 'object') {
        return '';
    }
    if (texts[locale]) {
        return texts[locale];
    }

    const language = String(locale ?? '').split('-')[0];
    const sameLanguage = Object.keys(texts).find((key) => key.split('-')[0] === language);

    return texts[sameLanguage] ?? texts['en-GB'] ?? Object.values(texts)[0] ?? '';
}

/**
 * Whether all of a preset's admin roles are on the role and its additional
 * privileges in the detailed privileges (when those are known).
 */
export function isPresetApplied(preset, privileges, detailedPrivileges) {
    const roles = privileges ?? [];
    const detailed = Array.isArray(detailedPrivileges) ? detailedPrivileges : null;

    return preset.roles.every((key) => roles.includes(key))
        && (detailed === null || Object.keys(preset.additionalPrivileges ?? {}).every((key) => detailed.includes(key)));
}
