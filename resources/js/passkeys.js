import { browserSupportsWebAuthn, startAuthentication, startRegistration } from '@simplewebauthn/browser';

// WebAuthn ceremonies for passkey sign-in and enrollment. The browser and
// OS render the actual Face ID / fingerprint prompt; these components only
// shuttle the challenge and response between server and authenticator.
document.addEventListener('alpine:init', () => {
    // Login page: an explicit button plus conditional-UI autofill, where
    // focusing the email field offers a saved passkey directly.
    window.Alpine.data('passkeyLogin', (optionsUrl, authenticateUrl, csrfToken) => ({
        supported: browserSupportsWebAuthn(),
        busy: false,

        async init() {
            if (!this.supported || !window.PublicKeyCredential?.isConditionalMediationAvailable) {
                return;
            }

            if (await window.PublicKeyCredential.isConditionalMediationAvailable()) {
                this.authenticate(true);
            }
        },

        async authenticate(conditional = false) {
            if (this.busy && !conditional) {
                return;
            }

            try {
                const optionsJSON = await (await fetch(optionsUrl, { credentials: 'same-origin' })).json();

                if (!conditional) {
                    this.busy = true;
                }

                const response = await startAuthentication({ optionsJSON, useBrowserAutofill: conditional });

                this.submit(authenticateUrl, {
                    _token: csrfToken,
                    remember: '1',
                    start_authentication_response: JSON.stringify(response),
                });
            } catch (error) {
                // The user dismissing the prompt (or a page navigation
                // aborting conditional mediation) is not an error state.
                this.busy = false;

                if (!['AbortError', 'NotAllowedError'].includes(error.name)) {
                    console.error(error);
                }
            }
        },

        // A real form POST keeps the controller's session handling and
        // full-page redirect to the dashboard intact.
        submit(action, fields) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = action;

            for (const [name, value] of Object.entries(fields)) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.appendChild(input);
            }

            document.body.appendChild(form);
            form.submit();
        },
    }));

    // Settings page: enrollment against the Livewire component, which
    // generates the options and stores the attestation response.
    window.Alpine.data('passkeyCreate', () => ({
        supported: browserSupportsWebAuthn(),
        busy: false,

        async create() {
            if (this.busy) {
                return;
            }

            this.busy = true;

            try {
                const optionsJSON = JSON.parse(await this.$wire.getRegisterOptions());
                const response = await startRegistration({ optionsJSON });

                await this.$wire.storePasskey(JSON.stringify(response));
            } catch (error) {
                if (!['AbortError', 'NotAllowedError'].includes(error.name)) {
                    this.$wire.reportEnrollmentFailure();
                }
            } finally {
                this.busy = false;
            }
        },
    }));
});
