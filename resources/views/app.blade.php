<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- 05.11 §4.1: a storefront page's head tags are in the initial HTML for crawlers; React re-renders them with the same keys (SeoHead). --}}
    <title inertia>{{ isset($seo) ? $seo->title : config('app.name', 'Laravel') }}</title>
    @isset($seo)
    {!! $seo->html() !!}
    @endisset
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
