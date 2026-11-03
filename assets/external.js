/*
 * SPDX-License-Identifier: MIT
 */

// Content of other providers loads only after consent (decision 0224).
// The choice "always load" stays in localStorage, never in a cookie: it
// does not reach the server, so cached pages stay the same for everyone.

const KEY = 'frootbox:external';
const CHANGED = 'frootbox:external';

function allowed() {
    try {
        const list = JSON.parse(localStorage.getItem(KEY) ?? '[]');

        return Array.isArray(list) ? list.filter((name) => typeof name === 'string') : [];
    } catch {
        return [];
    }
}

function allow(name, always) {
    const list = new Set(allowed());
    always ? list.add(name) : list.delete(name);

    try {
        localStorage.setItem(KEY, JSON.stringify([...list]));
    } catch {
        // Private windows may refuse; the choice then holds for this page only.
    }

    document.dispatchEvent(new CustomEvent(CHANGED));
}

class External extends HTMLElement {
    connectedCallback() {
        this.sync = () => allowed().includes(this.getAttribute('service')) && this.load(false);
        this.querySelector('.external__actions')?.removeAttribute('hidden');
        this.querySelector('[data-external-load]')?.addEventListener('click', () => {
            const always = this.querySelector('[data-external-always]')?.checked;

            // First this one with the focus, then the others of the service.
            this.load(true);

            if (always) {
                allow(this.getAttribute('service'), true);
            }
        });
        document.addEventListener(CHANGED, this.sync);
        this.sync();
    }

    disconnectedCallback() {
        document.removeEventListener(CHANGED, this.sync);
    }

    load(focus) {
        const template = this.querySelector(':scope > template');

        if (!template) {
            return;
        }

        this.replaceChildren(template.content.cloneNode(true));
        this.setAttribute('loaded', '');

        if (focus) {
            // The button is gone, so the focus moves to what replaced it.
            this.tabIndex = -1;
            this.focus();
        }
    }
}

class PrivacySettings extends HTMLElement {
    connectedCallback() {
        const button = this.querySelector('.privacy-settings__button');
        const dialog = this.querySelector('dialog');

        if (!button || !dialog) {
            return;
        }

        // Only what the visitor allowed can be taken back, so the button
        // shows once a service loads by itself (decision 0225).
        const show = () => {
            if (!dialog.open) {
                button.hidden = allowed().length === 0;
            }
        };

        button.addEventListener('click', () => {
            const list = allowed();

            for (const box of dialog.querySelectorAll('input[type="checkbox"]')) {
                box.checked = list.includes(box.value);
                box.closest('li').hidden = !box.checked;
            }

            dialog.showModal();
        });
        dialog.addEventListener('close', show);
        document.addEventListener(CHANGED, show);
        show();
        dialog.addEventListener('change', (event) => {
            if (event.target instanceof HTMLInputElement && event.target.type === 'checkbox') {
                allow(event.target.value, event.target.checked);
            }
        });
    }
}

if (!customElements.get('fb-external')) {
    customElements.define('fb-external', External);
}

if (!customElements.get('fb-privacy-settings')) {
    customElements.define('fb-privacy-settings', PrivacySettings);
}
