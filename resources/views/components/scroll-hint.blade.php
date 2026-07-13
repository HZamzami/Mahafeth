{{-- Wraps a horizontally scrollable strip and shows a gradient + chevron on
     the trailing edge while content hides beyond it. The fade color must
     match the strip's backdrop: page background by default, "card" for
     strips inside cards. --}}
@props(['surface' => 'page'])

<div {{ $attributes->class('relative') }} x-data="scrollHint">
    {{ $slot }}

    <div x-show="more" x-cloak x-transition.opacity.duration.150ms aria-hidden="true"
        class="pointer-events-none absolute inset-y-0 end-0 z-10 flex w-12 items-center justify-end bg-gradient-to-l to-transparent rtl:bg-gradient-to-r {{ $surface === 'card'
            ? 'from-white via-white/60 dark:from-zinc-900 dark:via-zinc-900/60'
            : 'from-surface-50 via-surface-50/60 dark:from-charcoal-950 dark:via-charcoal-950/60' }}">
        <flux:icon.chevron-right class="size-4 text-zinc-400 rtl:rotate-180" />
    </div>
</div>
