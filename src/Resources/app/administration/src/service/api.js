/**
 * GET request against the Admin API with the current user's token.
 */
export function apiGet(path) {
    const httpClient = Shopware.Application.getContainer('init').httpClient;
    const loginService = Shopware.Service('loginService');

    return httpClient
        .get(path, {
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${loginService.getToken()}`,
            },
        })
        .then((response) => response.data);
}
