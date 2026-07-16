<?php

use Livewire\Volt\Component;

new class extends Component {
    //
}; ?>

<div class="flex flex-col items-start">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Appearance')" :subheading="__('Update your account\'s appearance settings')">
        {{-- Full width on phones with icons hidden and smaller labels so the
             three segments (and their longer Arabic labels) never overflow the
             viewport. --}}
        <flux:radio.group x-data variant="segmented" x-model="$flux.appearance"
            class="w-full max-sm:text-xs max-sm:[&_svg]:hidden sm:w-auto">
            <flux:radio value="light" icon="sun">{{ __('Light') }}</flux:radio>
            <flux:radio value="dark" icon="moon">{{ __('Dark') }}</flux:radio>
            <flux:radio value="system" icon="computer-desktop">{{ __('System') }}</flux:radio>
        </flux:radio.group>
    </x-settings.layout>
</div>
