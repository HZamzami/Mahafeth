{{-- A free TradingView embed widget. Every widget in their family loads from
     the same iframe URL: /embed-widget/{name}/ with a JSON config in the
     fragment. Defaults to the dark theme; Alpine swaps to light when the
     page isn't dark. --}}
@props(['name', 'config' => []])

@php
$settings = array_merge([
    'colorTheme' => 'dark',
    'isTransparent' => true,
    'width' => '100%',
    'height' => '100%',
    'locale' => app()->getLocale() === 'ar' ? 'ar_AE' : 'en',
], $config);
@endphp

<div {{ $attributes->class('overflow-hidden rounded-lg') }} wire:ignore>
    <iframe
        src="https://www.tradingview-widget.com/embed-widget/{{ $name }}/?locale={{ $settings['locale'] }}#{{ rawurlencode(json_encode($settings)) }}"
        class="h-full w-full border-0" loading="lazy" title="TradingView" x-data
        x-init="if (! document.documentElement.classList.contains('dark')) $el.src = $el.src.replace('%22dark%22', '%22light%22')"></iframe>
</div>
