{{-- Company profile: the business summary (AI-translated for Arabic by the
     caller) plus the facts a reader scans for — sector, size, home base. --}}
@props(['profile', 'summary'])

<div class="card p-5">
    <div class="flex items-center justify-between">
        <flux:heading class="uppercase tracking-widest !text-neutral-500 dark:!text-neutral-400" size="sm">
            {{ __('About the Company') }}</flux:heading>
        <flux:text class="text-xs !text-neutral-400">{{ __('Data by Yahoo Finance') }}</flux:text>
    </div>

    {{-- dir=auto keeps the paragraph readable when the Arabic translation
         falls back to the English source text. --}}
    <flux:text class="mt-4 text-sm leading-relaxed" dir="auto">{{ $summary }}</flux:text>

    <div class="mt-5 grid grid-cols-2 gap-x-6 gap-y-3 border-t border-neutral-100 pt-4 dark:border-zinc-800">
        @if ($profile['sector'] !== null)
            <div>
                <flux:text class="text-xs">{{ __('Sector') }}</flux:text>
                <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white">{{ __($profile['sector']) }}</flux:text>
            </div>
        @endif
        @if ($profile['industry'] !== null)
            <div>
                <flux:text class="text-xs">{{ __('Industry') }}</flux:text>
                <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white">{{ $profile['industry'] }}</flux:text>
            </div>
        @endif
        @if ($profile['employees'] !== null)
            <div>
                <flux:text class="text-xs">{{ __('Employees') }}</flux:text>
                <flux:text class="text-sm font-medium tabular-nums !text-zinc-800 dark:!text-white" dir="ltr">
                    {{ number_format($profile['employees']) }}</flux:text>
            </div>
        @endif
        @if ($profile['city'] !== null || $profile['country'] !== null)
            <div>
                <flux:text class="text-xs">{{ __('Headquarters') }}</flux:text>
                <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white">
                    {{ implode(app()->getLocale() === 'ar' ? '، ' : ', ', array_filter([$profile['city'], $profile['country']])) }}</flux:text>
            </div>
        @endif
        @if ($profile['website'] !== null)
            <div>
                <flux:text class="text-xs">{{ __('Website') }}</flux:text>
                <a class="text-sm font-medium text-teal-700 hover:underline dark:text-teal-400" dir="ltr"
                    href="{{ $profile['website'] }}" target="_blank" rel="noopener noreferrer">
                    {{ str_replace(['https://', 'http://', 'www.'], '', rtrim($profile['website'], '/')) }}</a>
            </div>
        @endif
    </div>
</div>
