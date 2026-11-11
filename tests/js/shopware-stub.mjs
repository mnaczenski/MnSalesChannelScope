/**
 * The few parts of the global Shopware object the services use, with a
 * fake Admin API: responses by path.
 */
export function installShopware({ responses = {}, can = () => true } = {}) {
    const requests = [];
    const logoutListeners = [];
    const acl = { can };

    globalThis.Shopware = {
        Application: {
            getContainer: () => ({
                httpClient: {
                    get(path, options) {
                        requests.push({ path, options });
                        if (!(path in responses)) {
                            return Promise.reject(new Error(`no response for ${path}`));
                        }

                        return Promise.resolve({ data: responses[path] });
                    },
                },
            }),
        },
        Service(name) {
            if (name === 'acl') {
                return acl;
            }
            if (name === 'loginService') {
                return { getToken: () => 'token', addOnLogoutListener: (listener) => logoutListeners.push(listener) };
            }

            throw new Error(`unknown service ${name}`);
        },
    };

    return { requests, acl, logout: () => logoutListeners.forEach((listener) => listener()) };
}
