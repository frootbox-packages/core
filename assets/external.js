/*
 * SPDX-License-Identifier: MIT
 */

// Content and scripts of other providers load only after consent
// (decisions 0224, 0264). The choice stays in localStorage, never in a
// cookie: it does not reach the server, so cached pages stay the same
// for everyone. A consent tool takes over with frootboxConsent.use().

const KEY = 'frootbox:consent';
const OLD = 'frootbox:external';
const CHANGED = 'frootbox:consent';
const PROOF = '/_consent/proof';

function read() {
    try {
        const stored = JSON.parse(localStorage.getItem(KEY) ?? 'null');

        if (stored && typeof stored === 'object' && stored.services && typeof stored.services === 'object') {
            return stored;
        }

        // The list of "always load" from before (decision 0224).
        const old = JSON.parse(localStorage.getItem(OLD) ?? '[]');
        const services = {};

        for (const name of Array.isArray(old) ? old : []) {
            if (typeof name === 'string') {
                services[name] = true;
            }
        }

        return { id: null, version: null, at: null, services };
    } catch {
        return { id: null, version: null, at: null, services: {} };
    }
}

let state = read();
let tool = null;
const waitsForTool = document.querySelector('fb-consent-banner[tool]') !== null;

function version() {
    return document.querySelector('[data-consent-version]')?.dataset.consentVersion ?? null;
}

function keepsProof() {
    return document.querySelector('[data-consent-proof]') !== null;
}

function newId() {
    return crypto.randomUUID?.() ?? Array.from(crypto.getRandomValues(new Uint8Array(16)), (byte) => byte.toString(16).padStart(2, '0')).join('');
}

function store(choices) {
    state = { id: state.id ?? newId(), version: version(), at: new Date().toISOString(), services: { ...state.services, ...choices } };

    try {
        localStorage.setItem(KEY, JSON.stringify(state));
        localStorage.removeItem(OLD);
    } catch {
        // Private windows may refuse; the choice then holds for this page only.
    }

    if (keepsProof()) {
        fetch(PROOF, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: state.id, version: state.version, services: state.services }),
            keepalive: true,
        }).catch(() => {});
    }
}

const consent = {
    /** true, false or null when the visitor has not decided yet */
    allowed(name) {
        if (tool) {
            const answer = tool.allowed(name);

            return typeof answer === 'boolean' ? answer : null;
        }

        return typeof state.services[name] === 'boolean' ? state.services[name] : null;
    },

    /** Choices by name of the service, e.g. { 'acme/stats/matomo': true } */
    set(choices) {
        if (tool) {
            tool.set?.(choices);
        } else {
            store(choices);
        }

        consent.changed();
    },

    open() {
        if (tool?.open) {
            tool.open();
        } else {
            document.querySelector('fb-privacy-settings')?.open();
        }
    },

    /** Lets a consent tool decide from now on (decision 0264). */
    use(next) {
        tool = next;
        consent.changed();
    },

    /** Whether a tool decides; then the banner of the core stays away. */
    get tool() {
        return tool;
    },

    /** The random ID of the choice, once there is one */
    get id() {
        return tool ? null : state.id;
    },

    /** Tells everyone that a choice changed, also called by a tool. */
    changed() {
        activate();
        document.dispatchEvent(new CustomEvent(CHANGED));
    },
};

function ready() {
    return !waitsForTool || tool !== null;
}

/** The services on this page that load on every page, by their templates. */
function deferred() {
    return [...document.querySelectorAll('template[data-fb-consent]')];
}

function pending() {
    return [...new Set(deferred().map((template) => template.dataset.fbConsent))].filter((name) => consent.allowed(name) === null);
}

/** Whether the banner of the core asks right now. */
function bannerOpen() {
    return !waitsForTool && tool === null && document.querySelector('fb-consent-banner') !== null && pending().length > 0;
}

/** Puts the scripts of allowed services in place, so they run. */
function activate() {
    if (!ready()) {
        return;
    }

    for (const template of deferred()) {
        if (consent.allowed(template.dataset.fbConsent) === true) {
            template.replaceWith(template.content.cloneNode(true));
        }
    }
}

class External extends HTMLElement {
    connectedCallback() {
        this.sync = () => ready() && consent.allowed(this.getAttribute('service')) === true && this.load(false);
        this.querySelector('.external__actions')?.removeAttribute('hidden');
        this.querySelector('[data-external-load]')?.addEventListener('click', () => {
            const always = this.querySelector('[data-external-always]')?.checked;

            // First this one with the focus, then the others of the service.
            this.load(true);

            if (always) {
                consent.set({ [this.getAttribute('service')]: true });
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
        this.button = this.querySelector('.privacy-settings__button');
        this.dialog = this.querySelector('dialog');

        if (!this.button || !this.dialog) {
            return;
        }

        this.show = () => this.update();
        this.button.addEventListener('click', () => consent.open());
        this.dialog.addEventListener('close', this.show);
        this.dialog.addEventListener('change', (event) => {
            if (event.target instanceof HTMLInputElement && event.target.type === 'checkbox') {
                consent.set({ [event.target.value]: event.target.checked });
            }
        });
        document.addEventListener(CHANGED, this.show);
        this.update();
    }

    disconnectedCallback() {
        document.removeEventListener(CHANGED, this.show);
    }

    /** The first one on the page is the one to open, the others only open it. */
    open() {
        if (!this.dialog) {
            return;
        }

        for (const box of this.dialog.querySelectorAll('input[type="checkbox"]')) {
            box.checked = consent.allowed(box.value) === true;
            // Content services show once allowed: only that can be taken back (decision 0225).
            box.closest('li').hidden = box.closest('li').dataset.consentReach !== 'site' && !box.checked;
        }

        for (const group of this.dialog.querySelectorAll('[data-consent-group]')) {
            group.hidden = group.querySelector('li:not([hidden])') === null;
        }

        const id = this.dialog.querySelector('[data-consent-id]');

        if (id) {
            id.hidden = !consent.id;
            id.querySelector('code').textContent = consent.id ?? '';
        }

        this.dialog.showModal();
    }

    update() {
        if (!this.button || this.dialog.open) {
            return;
        }

        // A floating one only stands in for a theme without a button.
        const floating = this.classList.contains('privacy-settings--floating');
        // Only what the visitor allowed can be taken back (decision 0225).
        const decided = tool !== null || Object.values(state.services).includes(true);
        const asked = deferred().length > 0;
        this.button.hidden = !(decided || asked) || (floating && bannerOpen());
    }
}

class ConsentBanner extends HTMLElement {
    connectedCallback() {
        this.querySelector('[data-consent-accept]')?.addEventListener('click', () => this.choose(true));
        this.querySelector('[data-consent-reject]')?.addEventListener('click', () => this.choose(false));
        this.querySelector('[data-consent-settings]')?.addEventListener('click', () => consent.open());
        this.update = () => this.sync();
        document.addEventListener(CHANGED, this.update);
        this.sync();
    }

    disconnectedCallback() {
        document.removeEventListener(CHANGED, this.update);
    }

    choose(agree) {
        consent.set(Object.fromEntries(pending().map((name) => [name, agree])));
    }

    sync() {
        const open = bannerOpen() ? pending() : [];

        for (const item of this.querySelectorAll('[data-consent-service]')) {
            item.hidden = !open.includes(item.dataset.consentService);
        }

        this.hidden = open.length === 0;
    }
}

// A tool that loaded before the core leaves itself here.
window.frootboxConsent = consent;

if (window.frootboxConsentTool) {
    tool = window.frootboxConsentTool;
}

for (const [name, element] of [['fb-external', External], ['fb-privacy-settings', PrivacySettings], ['fb-consent-banner', ConsentBanner]]) {
    if (!customElements.get(name)) {
        customElements.define(name, element);
    }
}

activate();
document.dispatchEvent(new CustomEvent(CHANGED));
