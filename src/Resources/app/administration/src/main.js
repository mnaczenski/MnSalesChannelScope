import './extension/mn-sales-channel-scope.scss';
import createPublicApi from './service/public-api';
import './extension/sw-users-permissions-role-detail';
import './extension/sw-users-permissions-role-view-general';
import './extension/sw-product-detail';
import './extension/sw-category-detail';
import './extension/sw-category-view';
import './extension/sw-admin-menu';
import './extension/sw-settings-index';
import './extension/sw-sales-channel-switch';
import './extension/sw-system-config';
import './extension/sw-settings-seo';
import './extension/sw-seo-url-template-card';
import './extension/sw-cms-detail';
import './extension/sw-promotion-v2-detail';

// Public API for other plugins: Shopware.Service('mnChannelScope'), see docs/extending.md.
Shopware.Service().register('mnChannelScope', createPublicApi);
