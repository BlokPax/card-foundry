import './bootstrap';
import { cycleSelection, toggleRef } from './selection';
import { initGooglePicker } from './googlePicker';

initGooglePicker();

// Real filesystem paths of files/folders dropped from Finder. Electron ≥ 32
// removed File.path; webUtils.getPathForFile is its replacement and is
// reachable here because NativePHP runs the webview with node integration.
// In plain-browser dev this returns [] — drops are a desktop affordance.
window.droppedPaths = (event) => {
    const files = Array.from(event.dataTransfer?.files ?? []);

    return files
        .map((file) => {
            try {
                const webUtils = window.require?.('electron')?.webUtils;
                if (webUtils) {
                    return webUtils.getPathForFile(file);
                }
            } catch (e) {
                // fall through to the legacy property
            }

            return file.path ?? null;
        })
        .filter(Boolean);
};

// Bridge status indicator: any visual state (dot or spinner) holds for at
// least 1500ms before it may change again. A change requested during the
// hold replaces any earlier pending change (no stacking) and applies when
// the hold expires — if it still differs from what's shown.
//
// The spinner shows while ANY [data-bridge-sentinel] in this component is
// visible. The blade renders one per check that should be visible: the
// manual (click) sentinel always, the poll sentinel only while the bridge
// isn't healthy — so a green bridge re-checks silently. Because that poll
// sentinel comes and goes with Livewire re-renders, we observe the whole
// subtree rather than binding to fixed nodes.
document.addEventListener('alpine:init', () => {
    // Card selection lives here, not on the server, so every click paints
    // instantly. `selected` and `inverted` are entangled Livewire properties:
    // edits are deferred, ride along with any other request (so a Build sees
    // the fresh selection immediately), and a debounced commit syncs the
    // server copy — which re-renders the counts in the selection bar.
    // The acting set (all filtered refs, not just the rendered rows) arrives
    // via a wire:key'd sentinel whose x-init calls refreshActing().
    window.Alpine.data('cardSelection', ({ selected, inverted }) => ({
        selected,
        inverted,
        acting: [],

        refreshActing(el) {
            this.acting = JSON.parse(el.dataset.acting ?? '[]');
        },

        chosen(ref) {
            return this.selected.includes(ref);
        },

        get state() {
            const count = this.acting.filter((ref) => this.selected.includes(ref)).length;

            if (count === 0 || this.acting.length === 0) return 'none';

            return count === this.acting.length ? 'all' : 'partial';
        },

        get checkboxLabel() {
            if (this.state === 'none') return 'Select all';
            if (this.state === 'partial' && !this.inverted) return 'Invert the selection';

            return 'Unselect all';
        },

        toggle(ref) {
            this.selected = toggleRef(this.selected, ref);
            this.inverted = false;
            this.sync();
        },

        cycle() {
            const next = cycleSelection(this.selected, this.acting, this.inverted);
            this.selected = next.selected;
            this.inverted = next.inverted;
            this.sync();
        },

        clearAll() {
            this.selected = [];
            this.inverted = false;
            this.sync();
        },

        sync() {
            clearTimeout(this._syncTimer);
            this._syncTimer = setTimeout(() => this.$wire.$commit(), 400);
        },
    }));

    window.Alpine.data('bridgeIndicator', () => ({
        shown: 'dot', // 'dot' | 'spin'
        pending: null,
        timer: null,
        lockUntil: 0,

        init() {
            new MutationObserver(() => this.sync()).observe(this.$el, {
                subtree: true,
                childList: true,
                attributes: true,
                attributeFilter: ['style'],
            });

            this.sync();
        },

        sync() {
            const checking = Array.from(
                this.$el.querySelectorAll('[data-bridge-sentinel]'),
            ).some((el) => el.style.display !== 'none');

            this.want(checking ? 'spin' : 'dot');
        },

        want(next) {
            if (next === this.shown) {
                this.pending = null;
                return;
            }

            const now = Date.now();

            if (now >= this.lockUntil) {
                this.apply(next);
                return;
            }

            this.pending = next;
            clearTimeout(this.timer);
            this.timer = setTimeout(() => {
                if (this.pending && this.pending !== this.shown) {
                    this.apply(this.pending);
                }
                this.pending = null;
            }, this.lockUntil - now);
        },

        apply(next) {
            this.shown = next;
            this.lockUntil = Date.now() + 1500;
            this.pending = null;
        },
    }));
});
