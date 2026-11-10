const { Criteria } = Shopware.Data;

/**
 * Loads the role together with its sales channels (acl_role.mnSalesChannels),
 * so the general view can show them and the page's save writes them with the role.
 * The page loads the role with roleRepository.get(id) and no criteria, so the
 * repository is wrapped to add the association.
 */
Shopware.Component.override('sw-users-permissions-role-detail', {
    computed: {
        roleRepository() {
            const repository = this.$super('roleRepository');

            return new Proxy(repository, {
                get(target, property) {
                    if (property === 'get') {
                        return (id, context = Shopware.Context.api, criteria = null) => {
                            const roleCriteria = criteria ?? new Criteria(1, 1);
                            roleCriteria.addAssociation('mnSalesChannels');

                            return target.get(id, context, roleCriteria);
                        };
                    }

                    const value = Reflect.get(target, property);

                    return typeof value === 'function' ? value.bind(target) : value;
                },
            });
        },
    },
});
