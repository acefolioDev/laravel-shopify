<?php

namespace LaravelShopify\Navigation;

use Illuminate\Support\Facades\Route;

/**
 * Navigation Bridge: syncs Laravel/Inertia routing with the Shopify Admin
 * address bar using the app: protocol.
 *
 * In App Bridge 4, navigation within the app is reflected in the Shopify
 * Admin URL bar via the app://path pattern. This helper generates the
 * correct navigation data for both Blade and Inertia/React setups.
 */
class NavigationBridge
{
    /**
     * Generate an App Bridge-compatible navigation path.
     *
     * Converts a Laravel route path like "/products/123" into the
     * format Shopify Admin expects for the address bar.
     *
     * @param string $path The app-relative path
     * @return string The app: protocol path
     */
    public static function appPath(string $path): string
    {
        $path = ltrim($path, '/');

        return "app://{$path}";
    }

    /**
     * Generate a navigation link array for App Bridge.
     *
     * @param string $label Display label for the nav item
     * @param string $routeName Laravel route name
     * @param array $params Route parameters
     * @param bool $isActive Whether this nav item is currently active
     * @return array
     */
    public static function navItem(
        string $label,
        string $routeName,
        array $params = [],
        bool $isActive = false
    ): array {
        $url = route($routeName, $params, false);

        return [
            'label' => $label,
            'destination' => $url,
            'appPath' => self::appPath($url),
            'active' => $isActive,
        ];
    }

    /**
     * Build a full navigation menu from an array of route definitions.
     *
     * @param array $items Array of ['label' => string, 'route' => string, 'params' => array]
     * @param string|null $currentRoute The current route name for active state
     * @return array
     */
    public static function buildMenu(array $items, ?string $currentRoute = null): array
    {
        return array_map(function ($item) use ($currentRoute) {
            return self::navItem(
                $item['label'],
                $item['route'],
                $item['params'] ?? [],
                $currentRoute === $item['route'],
            );
        }, $items);
    }

    /**
     * Generate JavaScript to sync navigation with the Shopify Admin address bar.
     *
     * This is designed to be included in a Blade view or rendered as a
     * script tag. It updates the admin URL when the user navigates within
     * the embedded app.
     *
     * @return string JavaScript code
     */
    public static function syncScript(): string
    {
        return <<<'JS'
<script>
(function() {
    if (typeof shopify === 'undefined') return;

    // Update the admin URL bar when navigating within the app
    function syncNavigation(path) {
        try {
            history.replaceState(null, '', path + window.location.search);
        } catch (e) {
            // Silently fail if not in embedded context
        }
    }

    // Intercept link clicks for SPA-like navigation
    document.addEventListener('click', function(e) {
        const link = e.target.closest('a[data-shopify-nav]');
        if (!link) return;

        e.preventDefault();
        const href = link.getAttribute('href');

        // Navigate within the app
        if (href && !href.startsWith('http')) {
            syncNavigation(href);
            // For Inertia.js / SPA frameworks
            if (typeof Inertia !== 'undefined') {
                Inertia.visit(href);
            } else {
                window.location.href = href;
            }
        }
    });

    // Expose for programmatic use
    window.shopifyNavigate = function(path) {
        syncNavigation(path);
    };
})();
</script>
JS;
    }

    /**
     * Generate an Inertia-compatible middleware response that includes
     * the navigation bridge data as shared props.
     *
     * @param array $menuItems Navigation menu definition
     * @param string|null $currentRoute Current route name
     * @return array Shared data for Inertia
     */
    public static function inertiaSharedData(array $menuItems = [], ?string $currentRoute = null): array
    {
        return [
            'shopify' => [
                'apiKey' => config('shopify-app.api_key'),
                'appUrl' => config('shopify-app.app_url'),
                'navigation' => self::buildMenu($menuItems, $currentRoute),
            ],
        ];
    }
}
