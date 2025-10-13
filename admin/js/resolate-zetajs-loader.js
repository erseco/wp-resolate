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

const readyEventName = config.readyEvent || 'resolateZeta:ready';
const errorEventName = config.errorEvent || 'resolateZeta:error';
const pendingSelector = config.pendingSelector || '[data-zetajs-disabled]';
const loadingText = config.loadingText || '';
const errorText = config.errorText || '';

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

const disablePendingButtons = () => {
    const apply = () => {
        const elements = document.querySelectorAll(pendingSelector);
        elements.forEach((element) => {
            if (element.dataset.zetajsProcessed === '1') {
                return;
            }
            const targetHref = element.dataset.zetajsHref || element.getAttribute('href');
            if (targetHref && !element.dataset.zetajsHref) {
                element.dataset.zetajsHref = targetHref;
            }
            element.dataset.zetajsProcessed = '1';
            element.dataset.zetajsDisabled = '1';
            element.classList.add('disabled');
            element.setAttribute('aria-disabled', 'true');
            element.removeAttribute('href');
            if (!element.dataset.zetajsOriginalHtml) {
                element.dataset.zetajsOriginalHtml = element.innerHTML;
            }
            if (loadingText) {
                element.innerHTML = loadingText;
            }
            element.addEventListener('click', (event) => {
                if (element.dataset.zetajsDisabled === '1') {
                    event.preventDefault();
                    event.stopPropagation();
                }
            });
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', apply, { once: true });
    } else {
        apply();
    }
};

const enablePendingButtons = () => {
    const elements = document.querySelectorAll(pendingSelector);
    elements.forEach((element) => {
        if (element.dataset.zetajsDisabled !== '1') {
            return;
        }
        const targetHref = element.dataset.zetajsHref;
        if (targetHref) {
            element.setAttribute('href', targetHref);
        }
        element.classList.remove('disabled');
        element.removeAttribute('aria-disabled');
        element.dataset.zetajsDisabled = '0';
        element.removeAttribute('data-zetajs-disabled');
        if (element.dataset.zetajsOriginalHtml && loadingText && element.innerHTML === loadingText) {
            element.innerHTML = element.dataset.zetajsOriginalHtml;
        }
        element.dataset.zetajsReady = '1';
    });
};

const markErrorState = (error) => {
    if (errorText) {
        const elements = document.querySelectorAll(pendingSelector);
        elements.forEach((element) => {
            element.classList.add('disabled');
            element.setAttribute('aria-disabled', 'true');
            element.dataset.zetajsDisabled = '1';
            element.dataset.zetajsReady = '0';
            element.innerHTML = errorText;
        });
    }
    const detail = { error };
    window.dispatchEvent(new CustomEvent(errorEventName, { detail }));
};

window.addEventListener(readyEventName, enablePendingButtons);

disablePendingButtons();

const signalReady = () => {
    window.dispatchEvent(new CustomEvent(readyEventName));
};

const bootstrap = () => {
    if (store.ready || store.helper) {
        store.ready = true;
        signalReady();
        return;
    }
    store.loadHelper()
        .then((module) => {
            store.ready = true;
            store.helper = module;
            signalReady();
        })
        .catch((error) => {
            console.error('Resolate ZetaJS', error);
            markErrorState(error);
        });
};

bootstrap();
