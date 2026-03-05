<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="shopify-api-key" content="{{ config('shopify-app.api_key') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', config('app.name', 'Shopify App'))</title>

    {{-- App Bridge 4 CDN — MUST load before React app --}}
    <script src="{{ config('shopify-app.app_bridge.cdn_url', 'https://cdn.shopify.com/shopifycloud/app-bridge.js') }}"></script>

    @stack('head')

    {{-- Inertia SSR head --}}
    @if(isset($page))
        @inertiaHead
    @endif

    @viteReactRefresh
    @vite(['resources/js/app.jsx'])
</head>
<body>
    {{-- Global Shopify context for React --}}
    <script>
        window.__SHOPIFY_APP__ = {
            apiKey: '{{ config('shopify-app.api_key') }}',
            host: new URLSearchParams(window.location.search).get('host') || '',
            shop: new URLSearchParams(window.location.search).get('shop') || '',
        };
    </script>

    @if(isset($page))
        @inertia
    @else
        <div id="app">@yield('content')</div>
    @endif
</body>
</html>
