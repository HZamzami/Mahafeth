{{-- A free TradingView embed widget. Every widget in their family loads from
     the same iframe URL: /embed-widget/{name}/ with a JSON config in the
     fragment. The theme placeholder is resolved by an Alpine effect on the
     reactive $flux.dark, so widgets reload into the matching theme whenever
     the user toggles appearance — not just on page load. --}}
@props(['name', 'config' => []])

@php
$settings = array_merge([
    'colorTheme' => '__THEME__',
    'isTransparent' => true,
    'width' => '100%',
    'height' => '100%',
    'locale' => app()->getLocale() === 'ar' ? 'ar_AE' : 'en',
], $config);
@endphp

<div {{ $attributes->class('overflow-hidden rounded-lg') }} wire:ignore>
    <iframe
        data-src="https://www.tradingview-widget.com/embed-widget/{{ $name }}/?locale={{ $settings['locale'] }}#{{ rawurlencode(json_encode($settings)) }}"
        class="h-full w-full border-0" loading="lazy" title="TradingView" x-data
        x-effect="$el.src = $el.dataset.src.replace('__THEME__', $flux.dark ? 'dark' : 'light')"></iframe>
</div>
