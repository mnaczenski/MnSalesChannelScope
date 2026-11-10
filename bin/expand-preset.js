/*
 * Prints the entity privileges behind a preset's admin roles, as the
 * administration expands them on save. Run it against a shop when Shopware
 * is updated and copy "privileges" into the preset's "expandedPrivileges":
 *
 *   node bin/expand-preset.js src/Resources/config/presets/orders.json /path/to/shop
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const [presetFile, shop] = process.argv.slice(2);
const preset = JSON.parse(fs.readFileSync(presetFile, 'utf8'));
const src = path.join(shop, 'vendor/shopware/administration/Resources/app/administration/src/module');
const files = ['sw-order/acl/index.js', 'sw-settings-document/acl/index.js'];

const mappings = [];
const service = { addPrivilegeMappingEntry(entry) { mappings.push(entry); return service; }, getPrivileges() { return []; } };
for (const file of files) {
    const code = fs.readFileSync(path.join(src, file), 'utf8').replace(/^\s*(import|export)[^\n]*$/gm, '');
    vm.runInNewContext(code, { Shopware: { Service: () => service } });
}

const privileges = new Set();
const seen = new Set();
const add = (key) => {
    if (seen.has(key)) return;
    seen.add(key);
    const [name, role] = key.split('.');
    const mapping = mappings.find((entry) => entry.key === name && entry.roles[role]);
    if (!mapping) throw new Error(`Unknown admin role ${key}`);
    mapping.roles[role].privileges.flat().forEach((privilege) => privileges.add(privilege));
    mapping.roles[role].dependencies.forEach(add);
};
preset.roles.forEach(add);

console.log(JSON.stringify({ roles: [...seen].sort(), privileges: [...privileges].sort() }, null, 4));
