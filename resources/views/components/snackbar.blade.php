{{-- Transient feedback chip; any Livewire component can pop it with
     $this->dispatch('toast', message: __('...')). Sits above the bottom nav. --}}
<div x-data="{
        message: null,
        timer: null,
        show(text) {
            this.message = text;
            window.haptic?.(10);
            clearTimeout(this.timer);
            this.timer = setTimeout(() => (this.message = null), 2500);
        },
    }"
    x-on:toast.window="show($event.detail.message)"
    x-show="message !== null"
    x-cloak
    x-transition.opacity.duration.150ms
    role="status"
    class="fixed inset-x-4 bottom-[calc(6rem+env(safe-area-inset-bottom))] z-50 mx-auto w-fit max-w-md rounded-full bg-zinc-900 px-4 py-2 text-sm font-medium text-white shadow-lg lg:bottom-6 dark:bg-zinc-100 dark:text-zinc-900">
    <span x-text="message"></span>
</div>
