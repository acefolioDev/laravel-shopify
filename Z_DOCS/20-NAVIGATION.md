# 20 - Navigation Bridge

The Navigation Bridge syncs your Laravel/Inertia routes with the Shopify Admin address bar, so navigation within your embedded app is reflected in the Admin URL.

**Source file:** `src/Navigation/NavigationBridge.php`

---

## Why This Exists

When your app runs inside the Shopify Admin iframe, the Admin URL bar shows something like:

```
https://admin.shopify.com/store/my-store/apps/your-api-key
```

When the user navigates within your app (e.g., to a settings page), the Admin URL bar should update to reflect the new path:

```
https://admin.shopify.com/store/my-store/apps/your-api-key/settings
```

The Navigation Bridge provides helpers to generate the correct navigation data for this synchronization.

---

## `NavigationBridge` Class

**File:** `src/Navigation/NavigationBridge.php`

All methods are **static**.

---

### `appPath(string $path): string`

Converts a Laravel route path to an App Bridge `app://` protocol path.

```php
NavigationBridge::appPath('/products');
// → "app://products"

NavigationBridge::appPath('/products/123');
// → "app://products/123"

NavigationBridge::appPath('settings');
// → "app://settings"
```

Strips leading slash before prepending `app://`.

---

### `navItem(string $label, string $routeName, array $params = [], bool $isActive = false): array`

Generates a single navigation link array.

```php
NavigationBridge::navItem('Dashboard', 'dashboard', [], true);
// Returns:
// [
//     'label' => 'Dashboard',
//     'destination' => '/dashboard',     ← Laravel route URL
//     'appPath' => 'app://dashboard',    ← App Bridge path
//     'active' => true,
// ]

NavigationBridge::navItem('Product', 'products.show', ['id' => 123]);
// Returns:
// [
//     'label' => 'Product',
//     'destination' => '/products/123',
//     'appPath' => 'app://products/123',
//     'active' => false,
// ]
```

Uses `route($routeName, $params, false)` to generate the relative URL (the `false` parameter means no domain prefix).

---

### `buildMenu(array $items, ?string $currentRoute = null): array`

Builds a full navigation menu from an array of route definitions.

```php
$menu = NavigationBridge::buildMenu([
    ['label' => 'Dashboard', 'route' => 'dashboard'],
    ['label' => 'Products', 'route' => 'products.index'],
    ['label' => 'Settings', 'route' => 'settings'],
], Route::currentRouteName());

// Returns:
// [
//     ['label' => 'Dashboard', 'destination' => '/dashboard', 'appPath' => 'app://dashboard', 'active' => true],
//     ['label' => 'Products', 'destination' => '/products', 'appPath' => 'app://products', 'active' => false],
//     ['label' => 'Settings', 'destination' => '/settings', 'appPath' => 'app://settings', 'active' => false],
// ]
```

Each item in the input array needs:
- **`label`** — Display text
- **`route`** — Laravel route name
- **`params`** (optional) — Route parameters

The `active` flag is set automatically based on `$currentRoute`.

---

### `syncScript(): string`

Generates a JavaScript `<script>` tag that synchronizes navigation with the Shopify Admin address bar.

```php
{!! NavigationBridge::syncScript() !!}
```

**What the script does:**

1. **Intercepts link clicks** — Listens for clicks on `<a data-shopify-nav>` elements
2. **Updates browser history** — Calls `history.replaceState()` to update the URL
3. **Supports Inertia.js** — If Inertia is available, uses `Inertia.visit()` for SPA navigation; otherwise falls back to `window.location.href`
4. **Exposes `window.shopifyNavigate(path)`** — For programmatic navigation

**Usage in Blade:**

```blade
@extends('shopify-app::layouts.shopify-app')

@section('content')
    <nav>
        <a href="/dashboard" data-shopify-nav>Dashboard</a>
        <a href="/products" data-shopify-nav>Products</a>
        <a href="/settings" data-shopify-nav>Settings</a>
    </nav>

    @yield('page-content')
@endsection

@push('scripts')
    {!! \LaravelShopify\Navigation\NavigationBridge::syncScript() !!}
@endpush
```

The `data-shopify-nav` attribute is what the script listens for. Regular links without this attribute behave normally.

---

### `inertiaSharedData(array $menuItems = [], ?string $currentRoute = null): array`

Generates shared data for Inertia.js pages.

```php
$sharedData = NavigationBridge::inertiaSharedData([
    ['label' => 'Dashboard', 'route' => 'dashboard'],
    ['label' => 'Products', 'route' => 'products.index'],
], Route::currentRouteName());

// Returns:
// [
//     'shopify' => [
//         'apiKey' => 'your-api-key',
//         'appUrl' => 'https://your-app.com',
//         'navigation' => [
//             ['label' => 'Dashboard', 'destination' => '/dashboard', 'appPath' => 'app://dashboard', 'active' => true],
//             ['label' => 'Products', 'destination' => '/products', 'appPath' => 'app://products', 'active' => false],
//         ],
//     ],
// ]
```

### Using with Inertia Middleware

You can use this in a custom middleware or in the `ShareShopifyInertiaData` middleware:

```php
// In a middleware or service provider
Inertia::share(NavigationBridge::inertiaSharedData($menuItems, $currentRoute));
```

Then in your React/Vue components:

```jsx
function Layout({ children }) {
    const { shopify } = usePage().props;

    return (
        <div>
            <nav>
                {shopify.navigation.map(item => (
                    <Link
                        key={item.destination}
                        href={item.destination}
                        className={item.active ? 'active' : ''}
                    >
                        {item.label}
                    </Link>
                ))}
            </nav>
            {children}
        </div>
    );
}
```

---

## Programmatic Navigation

The `syncScript()` exposes `window.shopifyNavigate()`:

```javascript
// Navigate programmatically
window.shopifyNavigate('/products/123');

// This updates the Admin URL bar to:
// https://admin.shopify.com/store/my-store/apps/your-api-key/products/123
```

---

## Complete Example: Blade App with Navigation

```php
// routes/web.php
Route::middleware('verify.shopify')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
});
```

```php
// DashboardController.php
use LaravelShopify\Navigation\NavigationBridge;

class DashboardController extends Controller
{
    public function index()
    {
        $menu = NavigationBridge::buildMenu([
            ['label' => 'Dashboard', 'route' => 'dashboard'],
            ['label' => 'Products', 'route' => 'products.index'],
            ['label' => 'Settings', 'route' => 'settings'],
        ], Route::currentRouteName());

        return view('dashboard', ['menu' => $menu]);
    }
}
```

```blade
{{-- resources/views/dashboard.blade.php --}}
@extends('shopify-app::layouts.shopify-app')

@section('content')
    <nav>
        @foreach($menu as $item)
            <a href="{{ $item['destination'] }}"
               data-shopify-nav
               class="{{ $item['active'] ? 'active' : '' }}">
                {{ $item['label'] }}
            </a>
        @endforeach
    </nav>

    <h1>Dashboard</h1>
@endsection

@push('scripts')
    {!! \LaravelShopify\Navigation\NavigationBridge::syncScript() !!}
@endpush
```
