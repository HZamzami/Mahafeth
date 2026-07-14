<div
    x-data="{
        installPrompt: null,
        iosHint: false,
        dismissed: localStorage.getItem('pwaInstallDismissed') === '1',
        standalone: window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true,
        init() {
            window.addEventListener('beforeinstallprompt', (event) => {
                event.preventDefault();
                this.installPrompt = event;
            });
            this.iosHint = /iphone|ipad|ipod/i.test(window.navigator.userAgent) && ! this.standalone;
        },
        install() {
            this.installPrompt.prompt();
            this.installPrompt.userChoice.then(() => { this.installPrompt = null; });
        },
        dismiss() {
            this.dismissed = true;
            localStorage.setItem('pwaInstallDismissed', '1');
        },
    }"
    x-cloak
    x-show="! dismissed && ! standalone && (installPrompt || iosHint)"
    class="fixed inset-x-4 bottom-[calc(6rem+env(safe-area-inset-bottom))] z-50 mx-auto flex max-w-md items-center gap-3 rounded-xl border border-zinc-200 bg-white p-3 shadow-lg lg:bottom-4 dark:border-zinc-700 dark:bg-zinc-800"
>
    <img src="/icons/icon-192.png" alt="" class="size-11 shrink-0 rounded-lg" />

    <div class="min-w-0 flex-1">
        <p class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('Install Mahafeth') }}</p>
        <p x-show="installPrompt" class="text-xs text-zinc-500 dark:text-zinc-400">
            {{ __('Add the app to your home screen for quick access.') }}
        </p>
        <p x-show="! installPrompt && iosHint" class="text-xs text-zinc-500 dark:text-zinc-400">
            {{ __('Tap the Share icon, then choose "Add to Home Screen".') }}
        </p>
    </div>

    <flux:button x-show="installPrompt" x-on:click="install" variant="primary" size="sm">
        {{ __('Install') }}
    </flux:button>

    <flux:button x-on:click="dismiss" variant="ghost" size="sm" icon="x-mark" :aria-label="__('Dismiss')" />
</div>
