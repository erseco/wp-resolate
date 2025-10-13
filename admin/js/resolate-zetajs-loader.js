/**
 * Lightweight loader to expose ZetaJS helpers from the official CDN.
 */
const config = window.resolateZetaLoaderConfig || {};
const ensureTrailingSlash = (url) => (url.endsWith('/') ? url : `${url}/`);
const baseUrl = config.baseUrl ? ensureTrailingSlash(config.baseUrl) : 'https://cdn.zetaoffice.net/zetaoffice_latest/';
const assets = Array.isArray(config.assets) ? config.assets : [];

const resolvedAssets = assets
    .map((asset) => {
        if (typeof asset === 'string') {
            return { href: asset, as: 'fetch' };
        }
        if (!asset || typeof asset.href !== 'string') {
            return null;
        }
        return {
            href: asset.href,
            as: asset.as || 'fetch',
        };
    })
    .filter(Boolean)
    .map((asset) => {
        const href = /^https?:/i.test(asset.href) ? asset.href : baseUrl + asset.href.replace(/^\//, '');
        return {
            href,
            as: asset.as,
        };
    });

const head = document.head || document.getElementsByTagName('head')[0];
const ensurePreload = ({ href, as }) => {
    if (!head || !href) {
        return;
    }
    const selector = `link[data-resolate-preload="${href}"]`;
    if (document.querySelector(selector)) {
        return;
    }
    const link = document.createElement('link');
    link.rel = 'preload';
    link.as = as || 'fetch';
    link.href = href;
    link.crossOrigin = 'anonymous';
    link.dataset.resolatePreload = href;
    head.appendChild(link);
};

resolvedAssets.forEach(ensurePreload);

const store = window.resolateZeta || {};
store.baseUrl = baseUrl;

if (!store.loadHelper) {
    store.loadHelper = async () => {
        if (store.helper) {
            return store.helper;
        }
        const module = await import(`${baseUrl}zetaHelper.js`);
        store.helper = module;
        return module;
    };
}

window.resolateZeta = store;

store.loadHelper().catch((error) => {
    console.error('Resolate ZetaJS', error);
});
