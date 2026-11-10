/*
 * Prints the entity privileges behind a preset's admin roles, the way the
 * administration expands them when a role is saved. Run it against a shop
 * after Shopware, Commercial or plugin updates and copy "privileges" into the
 * preset's "expandedPrivileges":
 *
 *   node bin/expand-preset.js src/Resources/config/presets/orders.json /path/to/shop
 *
 * It reads every administration ACL file (acl/index.js|ts) of the core
 * administration, Shopware Commercial and the plugins in custom/plugins, merges
 * entries that extend the same key (Commercial adds bundles and review
 * summaries to "product", for example) and resolves getPrivileges('x.role')
 * references (the product roles include the media and Shopping Experience
 * viewer privileges that way).
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const [presetFile, shop] = process.argv.slice(2);
if (!presetFile || !shop) {
    console.error('Usage: node bin/expand-preset.js <preset.json> <shop root>');
    process.exit(1);
}
const preset = JSON.parse(fs.readFileSync(presetFile, 'utf8'));

const roots = [
    'vendor/shopware/administration/Resources/app/administration/src',
    'vendor/shopware/commercial/src',
    'custom/plugins',
].map((dir) => path.join(shop, dir));

function aclFiles(dir, found = []) {
    if (!fs.existsSync(dir)) return found;
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        if (entry.name === 'node_modules' || entry.name === 'public' || entry.name.startsWith('.')) continue;
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) aclFiles(full, found);
        else if (/^index\.(js|ts)$/.test(entry.name) && path.basename(dir) === 'acl') found.push(full);
    }
    return found;
}

// getPrivileges('x.role') is resolved after every file is loaded.
const ref = (key) => ({ __ref: key });
const entries = [];
const service = {
    addPrivilegeMappingEntry(entry) { entries.push(entry); return service; },
    addPrivilegeMappingEntries(list) { list.forEach((entry) => entries.push(entry)); return service; },
    getPrivileges: ref,
};

for (const file of roots.flatMap((root) => aclFiles(root))) {
    const code = fs.readFileSync(file, 'utf8')
        .replace(/^\s*import[^\n]*$/gm, '')
        .replace(/export\s+(const|let)\s+\w+(\s*:\s*[^=]+)?\s*=/g, '__exported[__exported.length] =')
        .replace(/export\s+default\s+/g, '__exported[__exported.length] = ')
        .replace(/\s+as\s+[A-Za-z_<>[\]|' ]+(?=[,;)\]\n])/g, '');
    try {
        // A fresh context per file: the files declare the same top-level constants.
        const sandbox = { Shopware: { Service: () => service }, __exported: [], console };
        vm.runInNewContext(code, sandbox, { filename: file });
        // Commercial modules export the entry and register it from their module index.
        sandbox.__exported.filter((value) => value && value.key && value.roles).forEach((entry) => entries.push(entry));
    } catch (error) {
        console.error(`skipped ${path.relative(shop, file)}: ${error.message}`);
    }
}

// Entries with the same key are merged, as the privileges service does.
const roles = {};
for (const entry of entries) {
    for (const [role, definition] of Object.entries(entry.roles || {})) {
        const key = `${entry.key}.${role}`;
        roles[key] ??= { privileges: [], dependencies: [] };
        roles[key].privileges.push(...(definition.privileges || []).flat());
        roles[key].dependencies.push(...(definition.dependencies || []));
    }
}

const privileges = new Set();
const done = new Set();
// A role key (e.g. "order.viewer") is stored for the roles the preset ticks
// and their dependencies; roles pulled in through getPrivileges() only add
// their entity privileges, not their key.
function expand(key, withKey) {
    const role = roles[key];
    if (!role) throw new Error(`Unknown admin role ${key}`);
    if (withKey) privileges.add(key);
    const mark = `${key}|${withKey}`;
    if (done.has(mark)) return;
    done.add(mark);
    for (const privilege of role.privileges) {
        if (privilege && privilege.__ref) expand(privilege.__ref, false);
        else if (typeof privilege === 'string') privileges.add(privilege);
    }
    role.dependencies.forEach((dependency) => expand(dependency, withKey));
}
preset.roles.forEach((key) => expand(key, true));

const keys = [...privileges].filter((privilege) => roles[privilege]).sort();
const entity = [...privileges].filter((privilege) => !roles[privilege]).sort();
console.log(JSON.stringify({ roles: keys, privileges: entity }, null, 4));
