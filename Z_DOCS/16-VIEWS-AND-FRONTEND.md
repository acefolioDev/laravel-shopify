# 16 - Views & Frontend Integration (Blade, Vite Plugin, App Bridge 4)

The package provides Blade layouts, a Vite plugin, and JavaScript helpers for building the frontend of your Shopify app.

**Source files:**
- `resources/views/layouts/shopify-app.blade.php` — Blade layout
- `resources/views/layouts/shopify-react.blade.php` — React/Inertia layout
- `vite-plugin/index.js` — Vite plugin for App Bridge 4
- `vite-plugin/package.json` — Plugin package metadata

---

## App Bridge 4 — What It Is

App Bridge 4 is Shopify's JavaScript SDK that runs inside the Admin iframe. It:
- Auto-initializes when it detects the `shopify-api-key` meta tag
- Provides `shopify.idToken()` to get session tokens (JWTs)
- Handles navigation, modals, toasts, and other Admin UI features
- Does NOT require manual initialization code (unlike App Bridge 2/3)

**CDN URL:** `https://cdn.shopify.com/shopifycloud/app-bridge.js`

---

## 1. Blade Layout: `shopify-app.blade.php`

**File:** `resources/views/layouts/shopify-app.blade.php`
**Namespace:** `shopify-app::layouts.shopify-app`

This is the standard Blade layout for apps that use traditional Blade views (not React/Inertia).

### Usage

```blade
@extends('shopify-app::layouts.shopify-app')

@section('title', 'My Dashboard')

@section('content')
    <h1>Dashboard</h1>
    <div id="products"></div>

    <script>
        authenticatedFetch('/api/products')
            .then(res => res.json())
            .then(data => {
                document.getElementById('products').innerHTML =
                    JSON.stringify(data, null, 2);
            });
    </script>
@endsection
```

### What the Layout Includes

**`<head>` section:**

1. **`shopify-api-key` meta tag** — Required for App Bridge 4 auto-initialization:
   ```html
   <meta name="shopify-api-key" content="{{ config('shopify-app.api_key') }}">
   ```

2. **App Bridge 4 CDN script** — Loaded first, before any app scripts:
   ```html
   <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
   ```

3. **CSRF token meta tag** — For Laravel's CSRF protection

4. **Vite assets** — Conditionally loaded if `build/manifest.json` exists:
   ```blade
   @vite(['resources/css/app.css', 'resources/js/app.js'])
   ```

5. **Stack points:** `@stack('head')` and `@stack('styles')` for additional assets

**`<body>` section:**

1. **Global Shopify context:**
   ```javascript
   window.__SHOPIFY_APP__ = {
       apiKey: '{{ config("shopify-app.api_key") }}',
       host: new URLSearchParams(window.location.search).get('host') || '',
       shop: new URLSearchParams(window.location.search).get('shop') || '',
   };
   ```

2. **Navigation and authentication helpers:**
   - `window.getSessionToken()` — Gets a fresh JWT from App Bridge
   - `window.authenticatedFetch(url, options)` — Fetch wrapper that adds the Bearer token

3. **Content section:** `<div id="app">@yield('content')</div>`

4. **Stack point:** `@stack('scripts')`

### `getSessionToken()` Helper

```javascript
window.getSessionToken = async function() {
    if (typeof shopify !== 'undefined' && shopify.idToken) {
        return await shopify.idToken();
    }
    return null;
};
```

Calls App Bridge 4's `shopify.idToken()` to get a fresh session token JWT.

### `authenticatedFetch()` Helper

```javascript
window.authenticatedFetch = async function(url, options = {}) {
    const token = await window.getSessionToken();
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
        }
    }

    return response;
};
```

**Key behaviors:**
- Automatically adds `Authorization: Bearer <token>` header
- Automatically handles App Bridge redirect responses (billing checkout, reauth)
- Sets `Content-Type: application/json` by default

---

## 2. React/Inertia Layout: `shopify-react.blade.php`

**File:** `resources/views/layouts/shopify-react.blade.php`
**Namespace:** `shopify-app::layouts.shopify-react`

Designed for React apps using Inertia.js.

### Usage

```blade
@extends('shopify-app::layouts.shopify-react')
```

### Differences from Blade Layout

| Feature | `shopify-app` | `shopify-react` |
|---|---|---|
| App Bridge CDN | Conditional (`@if`) | Always loaded |
| Vite assets | `app.css` + `app.js` | `app.jsx` only |
| React Refresh | Not included | `@viteReactRefresh` |
| Inertia SSR | Not included | `@inertiaHead` + `@inertia` |
| Auth helpers | Inline JavaScript | Not included (use Vite plugin instead) |

### What It Includes

```html
<head>
    <meta name="shopify-api-key" content="...">
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    @inertiaHead
    @viteReactRefresh
    @vite(['resources/js/app.jsx'])
</head>
<body>
    <script>
        window.__SHOPIFY_APP__ = { apiKey, host, shop };
    </script>
    @inertia
</body>
```

The auth helpers (`getSessionToken`, `authenticatedFetch`) are NOT included in this layout — they come from the **Vite plugin** instead.

---

## 3. Vite Plugin: `vite-plugin-shopify-app-bridge`

**File:** `vite-plugin/index.js`

A Vite plugin that automatically configures your development environment for Shopify's embedded iframe context.

### Installation

```bash
php artisan vendor:publish --tag=shopify-vite-plugin
```

This copies the plugin to `vite-plugin-shopify/` in your project root.

### Configuration

```javascript
// vite.config.js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import shopifyAppBridge from './vite-plugin-shopify/index.js';

export default defineConfig({
    plugins: [
        shopifyAppBridge({
            apiKey: process.env.SHOPIFY_API_KEY,  // Optional, also reads from env
            cdnUrl: 'https://cdn.shopify.com/...',  // Optional, defaults to App Bridge 4
            forceRedirect: false,  // Optional, redirect if not in iframe
        }),
        laravel({
            input: ['resources/js/app.jsx'],
            refresh: true,
        }),
    ],
});
```

### What the Plugin Does

**1. Configures Dev Server for iframe context:**

```javascript
server: {
    hmr: { protocol: 'wss' },  // WebSocket Secure for iframe
    headers: {
        'Content-Security-Policy': 'frame-ancestors https://*.myshopify.com https://admin.shopify.com',
    },
}
```

- **`wss` protocol** — HMR (Hot Module Replacement) must use secure WebSockets inside the Shopify Admin iframe
- **CSP header** — Allows your app to be embedded in Shopify's iframe

**2. Injects HTML tags via `transformIndexHtml`:**

- **API key meta tag:** `<meta name="shopify-api-key" content="...">`
- **App Bridge CDN script:** `<script src="https://cdn.shopify.com/...">`
- **Helper script:** Inline JavaScript with `getSessionToken()` and `authenticatedFetch()`

**3. Handles HMR for PHP files:**

```javascript
handleHotUpdate({ file, server }) {
    if (file.endsWith('.blade.php') || file.endsWith('.php')) {
        server.ws.send({ type: 'full-reload' });
        return [];
    }
}
```

When a `.blade.php` or `.php` file changes, the plugin triggers a full page reload instead of HMR (since PHP isn't a JS module).

### `getSessionToken()` in the Vite Plugin

The Vite plugin's version includes **token caching**:

```javascript
var _cachedToken = null;
var _tokenExpiry = 0;

window.getSessionToken = async function() {
    var now = Date.now();
    if (_cachedToken && _tokenExpiry > now + 60000) {
        return _cachedToken;
    }
    _cachedToken = await shopify.idToken();
    _tokenExpiry = now + 50000;  // Cache for 50 seconds
    return _cachedToken;
};
```

Session tokens are valid for ~60 seconds. The plugin caches them for 50 seconds and refreshes 10 seconds before expiry.

### `authenticatedFetch()` in the Vite Plugin

The Vite plugin's version includes **automatic retry on 401**:

```javascript
window.authenticatedFetch = async function(url, options) {
    var token = await window.getSessionToken();
    options.headers['Authorization'] = 'Bearer ' + token;

    var response = await fetch(url, options);

    // If 401, clear cache and retry once with fresh token
    if (response.status === 401) {
        _cachedToken = null;
        _tokenExpiry = 0;
        token = await window.getSessionToken();
        options.headers['Authorization'] = 'Bearer ' + token;
        response = await fetch(url, options);
    }

    // Handle App Bridge redirect headers
    var linkHeader = response.headers.get('Link');
    if (linkHeader && linkHeader.indexOf('rel="app-bridge-redirect-endpoint"') !== -1) {
        var match = linkHeader.match(/<([^>]+)>/);
        if (match && match[1]) {
            open(match[1], '_top');
        }
    }

    return response;
};
```

### `forceRedirect` Option

If `forceRedirect: true`, the plugin injects code that checks if the app is running outside an iframe:

```javascript
if (window.top === window.self) {
    var shop = new URLSearchParams(window.location.search).get('shop');
    if (shop) {
        window.location.href = 'https://' + shop + '/admin/apps/' + apiKey;
    }
}
```

This redirects users who try to access the app directly (not through Shopify Admin) to the embedded version.

---

## Customizing Views

Publish and edit:

```bash
php artisan vendor:publish --tag=shopify-views
```

Published views go to `resources/views/vendor/shopify-app/`. Laravel will use these instead of the package originals.

---

## Using with React (Without Inertia)

If you're using React without Inertia:

```blade
@extends('shopify-app::layouts.shopify-app')

@section('content')
    <div id="root"></div>
@endsection

@push('scripts')
    @viteReactRefresh
    @vite(['resources/js/app.jsx'])
@endpush
```

In your React app, use the global `authenticatedFetch`:

```jsx
function App() {
    const [products, setProducts] = useState([]);

    useEffect(() => {
        window.authenticatedFetch('/api/products')
            .then(res => res.json())
            .then(data => setProducts(data.products));
    }, []);

    return <div>{products.map(p => <p key={p.id}>{p.title}</p>)}</div>;
}
```
