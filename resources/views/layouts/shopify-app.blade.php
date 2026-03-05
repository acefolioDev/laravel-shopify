<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="shopify-api-key" content="{{ config('shopify-app.api_key') }}">

    <title>@yield('title', config('app.name', 'Shopify App'))</title>

    {{-- App Bridge 4 CDN — loaded before any app scripts --}}
    @if(config('shopify-app.app_bridge.enabled', true))
        <script src="{{ config('shopify-app.app_bridge.cdn_url', 'https://cdn.shopify.com/shopifycloud/app-bridge.js') }}"></script>
    @endif

    {{-- CSRF Token --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @stack('head')

    {{-- Vite assets --}}
    @if(file_exists(public_path('build/manifest.json')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif

    @stack('styles')
</head>
<body>
    {{--
        App Bridge 4 initialization script.
        No "host" or "shop" query parameter juggling needed — App Bridge 4
        reads the shopify-api-key meta tag and auto-initializes when loaded
        inside the Shopify Admin iframe.
    --}}
    <script>
        // Make shop and host available globally for convenience
        window.__SHOPIFY_APP__ = {
            apiKey: '{{ config('shopify-app.api_key') }}',
            host: new URLSearchParams(window.location.search).get('host') || '',
            shop: new URLSearchParams(window.location.search).get('shop') || '',
        };
    </script>

    {{-- Navigation Bridge: sync Laravel routes with Shopify Admin address bar --}}
    <script>
        (function() {
            if (typeof shopify === 'undefined') return;

            // Listen for navigation events from App Bridge
            document.addEventListener('DOMContentLoaded', function() {
                // Sync the current path with the Shopify Admin address bar
                const currentPath = window.location.pathname;
                if (currentPath && currentPath !== '/') {
                    try {
                        shopify.idToken().then(function(token) {
                            // Token is available — app is properly embedded
                            window.__SHOPIFY_SESSION_TOKEN__ = token;
                        });
                    } catch (e) {
                        // Not in an embedded context
                    }
                }
            });

            // Helper to get a fresh session token for API calls
            window.getSessionToken = async function() {
                if (typeof shopify !== 'undefined' && shopify.idToken) {
                    return await shopify.idToken();
                }
                return null;
            };

            // Helper to make authenticated fetch requests
            window.authenticatedFetch = async function(url, options = {}) {
                const token = await window.getSessionToken();
                if (!token) {
                    throw new Error('Unable to obtain session token');
                }

                options.headers = {
                    ...options.headers,
                    'Authorization': 'Bearer ' + token,
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                };

                const response = await fetch(url, options);

                // Handle App Bridge redirect headers (billing, reauth)
                const linkHeader = response.headers.get('Link');
                if (linkHeader && linkHeader.includes('rel="app-bridge-redirect-endpoint"')) {
                    const match = linkHeader.match(/<([^>]+)>/);
                    if (match && match[1]) {
                        open(match[1], '_top');
                        return response;
                    }
                }

                return response;
            };
        })();
    </script>

    <div id="app">
        @yield('content')
    </div>

    @stack('scripts')
</body>
</html>
