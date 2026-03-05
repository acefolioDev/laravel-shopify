/**
 * Vite Plugin: Shopify App Bridge 4
 *
 * Automatically injects the App Bridge 4 CDN script into HTML pages,
 * configures HMR for embedded Shopify apps, and provides helpers
 * for session token authentication.
 *
 * Usage in vite.config.js:
 *
 *   import shopifyAppBridge from './vite-plugin-shopify/index.js';
 *   // or if published: import shopifyAppBridge from 'vite-plugin-shopify-app-bridge';
 *
 *   export default defineConfig({
 *     plugins: [
 *       shopifyAppBridge({
 *         apiKey: process.env.SHOPIFY_API_KEY,
 *       }),
 *     ],
 *   });
 */

const DEFAULT_CDN_URL = 'https://cdn.shopify.com/shopifycloud/app-bridge.js';

/**
 * @param {object} options
 * @param {string} [options.apiKey] - Shopify App API Key
 * @param {string} [options.cdnUrl] - Custom App Bridge CDN URL
 * @param {boolean} [options.forceRedirect] - Force redirect if not in iframe (default: false)
 * @returns {import('vite').Plugin}
 */
export default function shopifyAppBridge(options = {}) {
    const {
        apiKey = process.env.SHOPIFY_API_KEY || '',
        cdnUrl = DEFAULT_CDN_URL,
        forceRedirect = false,
    } = options;

    return {
        name: 'vite-plugin-shopify-app-bridge',
        enforce: 'pre',

        config(config) {
            // Ensure the dev server works inside Shopify's iframe
            return {
                server: {
                    ...config.server,
                    hmr: {
                        protocol: 'wss',
                        ...(config.server?.hmr || {}),
                    },
                    headers: {
                        // Allow embedding in Shopify Admin iframe
                        'Content-Security-Policy': `frame-ancestors https://*.myshopify.com https://admin.shopify.com`,
                        ...(config.server?.headers || {}),
                    },
                },
                // Ensure build outputs work in embedded context
                build: {
                    ...config.build,
                    rollupOptions: {
                        ...config.build?.rollupOptions,
                    },
                },
            };
        },

        transformIndexHtml(html) {
            const tags = [];

            // Inject the API key meta tag
            if (apiKey) {
                tags.push({
                    tag: 'meta',
                    attrs: {
                        name: 'shopify-api-key',
                        content: apiKey,
                    },
                    injectTo: 'head-prepend',
                });
            }

            // Inject the App Bridge CDN script
            tags.push({
                tag: 'script',
                attrs: {
                    src: cdnUrl,
                },
                injectTo: 'head-prepend',
            });

            // Inject the session token helper and authenticated fetch
            tags.push({
                tag: 'script',
                children: getHelperScript(forceRedirect),
                injectTo: 'head',
            });

            return tags;
        },

        // Handle HMR in the embedded iframe context
        handleHotUpdate({ file, server }) {
            // Force full reload for Blade/PHP template changes
            if (file.endsWith('.blade.php') || file.endsWith('.php')) {
                server.ws.send({ type: 'full-reload' });
                return [];
            }
        },
    };
}

/**
 * Generate the inline helper script for session token management.
 */
function getHelperScript(forceRedirect) {
    return `
    (function() {
        // Redirect to Shopify Admin if not in iframe (optional)
        ${forceRedirect ? `
        if (window.top === window.self) {
            var shop = new URLSearchParams(window.location.search).get('shop');
            if (shop) {
                var apiKey = document.querySelector('meta[name="shopify-api-key"]')?.content;
                if (apiKey) {
                    window.location.href = 'https://' + shop + '/admin/apps/' + apiKey;
                    return;
                }
            }
        }
        ` : ''}

        // Session token cache
        var _cachedToken = null;
        var _tokenExpiry = 0;

        /**
         * Get a fresh session token from App Bridge.
         * Caches the token and refreshes ~60s before expiry.
         */
        window.getSessionToken = async function() {
            var now = Date.now();
            if (_cachedToken && _tokenExpiry > now + 60000) {
                return _cachedToken;
            }

            if (typeof shopify !== 'undefined' && shopify.idToken) {
                _cachedToken = await shopify.idToken();
                // Session tokens are valid for ~60s, cache for 50s
                _tokenExpiry = now + 50000;
                return _cachedToken;
            }

            throw new Error('App Bridge not available. Ensure the app is embedded in Shopify Admin.');
        };

        /**
         * Make an authenticated fetch request with the session token.
         * Automatically handles App Bridge redirect responses.
         */
        window.authenticatedFetch = async function(url, options) {
            options = options || {};
            var token = await window.getSessionToken();

            options.headers = Object.assign({
                'Authorization': 'Bearer ' + token,
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            }, options.headers || {});

            var response = await fetch(url, options);

            // Handle 401 — token may have expired, retry once
            if (response.status === 401) {
                _cachedToken = null;
                _tokenExpiry = 0;
                token = await window.getSessionToken();
                options.headers['Authorization'] = 'Bearer ' + token;
                response = await fetch(url, options);
            }

            // Handle App Bridge redirect headers (billing, reauth)
            var linkHeader = response.headers.get('Link');
            if (linkHeader && linkHeader.indexOf('rel="app-bridge-redirect-endpoint"') !== -1) {
                var match = linkHeader.match(/<([^>]+)>/);
                if (match && match[1]) {
                    open(match[1], '_top');
                }
            }

            return response;
        };
    })();
    `;
}
