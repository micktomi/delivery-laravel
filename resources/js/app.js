/**
 * Storefront menu controller.
 *
 * Livewire 3 ships Alpine, so this registers against the instance Livewire
 * exposes rather than importing and starting its own. Everything with an
 * operator or a branch lives here: markup attributes stay bare method calls,
 * which keeps expression errors inside a file that can be read and diffed.
 */
document.addEventListener('livewire:init', () => {
    window.Alpine.data('menu', (initialSlug = '') => ({
        activeSlug: initialSlug,
        cartOpen: false,
        scrolled: false,
        addedProductId: null,
        addedTimer: null,

        /* ── "added to cart" confirmation ──
           Driven by the server's cart-updated event, so it confirms a real
           addition rather than the intent to add. Reset on a timer, not on
           animationend: under prefers-reduced-motion the animation is ~0ms
           and the confirmation would vanish before it could be read. */

        isAdded(id) {
            return this.addedProductId === id;
        },

        notAdded(id) {
            return this.addedProductId !== id;
        },

        /* Returned as a class string rather than bound with a ternary, so the
           markup attribute stays a bare call. Both branches are literal here
           so Tailwind still sees the utilities when it scans this file. */
        addButtonClass(id) {
            if (this.isAdded(id)) {
                return 'bg-emerald-600 text-white';
            }

            return 'bg-[var(--accent)] text-white group-hover:bg-[var(--accent-hover)]';
        },

        flagAdded(id) {
            clearTimeout(this.addedTimer);
            this.addedProductId = id;

            if (id === null) {
                return;
            }

            this.addedTimer = setTimeout(() => {
                this.addedProductId = null;
            }, 1600);
        },

        /* ── cart ──
           Livewire renders the cart rows from the same array the server owns;
           nothing here mirrors them. Alpine only reacts to the event: flag the
           product that was just added, and reveal the sheet. */

        syncCart(detail) {
            this.flagAdded(detail.productId ?? null);
            this.cartOpen = true;
        },

        openCart() {
            this.cartOpen = true;
        },

        closeCart() {
            this.cartOpen = false;
        },

        /* ── chrome ── */

        onScroll() {
            this.scrolled = window.scrollY > 60;
        },

        isActiveCategory(slug) {
            return this.activeSlug === slug;
        },

        stickyHeaderClass() {
            return this.scrolled ? 'shadow-sm' : '';
        },

        pillClass(slug) {
            if (this.isActiveCategory(slug)) {
                return 'bg-[var(--accent)] text-white';
            }

            return 'text-[var(--ink-soft)] hover:bg-[var(--accent-light)] hover:text-[var(--accent-text)]';
        },

        scrollToCategory(slug) {
            const section = document.getElementById('cat-' + slug);

            if (! section) {
                return;
            }

            section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        },

        /* scrollIntoView() walked up to the window on the vertical axis and
           snapped the page back toward the header, because the hero means the
           pill nav is not already at y=0. Scroll the nav itself instead. */
        initScrollSpy() {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (! entry.isIntersecting) {
                        return;
                    }

                    this.activeSlug = entry.target.dataset.slug;

                    const pill = document.querySelector(`[data-pill='${this.activeSlug}']`);

                    if (! pill) {
                        return;
                    }

                    const nav = pill.parentElement;

                    nav.scrollTo({
                        left: pill.offsetLeft - (nav.clientWidth - pill.clientWidth) / 2,
                        behavior: 'smooth',
                    });
                });
            }, { rootMargin: '-96px 0px -55% 0px', threshold: 0 });

            document.querySelectorAll('[data-slug]').forEach((element) => observer.observe(element));
        },
    }));

    window.Alpine.data('driverOrderNotifications', (driverId) => ({
        soundEnabled: false,
        soundUnavailable: false,
        audioContext: null,
        baselineEstablished: false,
        notifiedOrderIds: [],
        pendingOrderIds: [],
        highlightedOrderIds: [],
        highlightTimers: {},
        storageKey: `delivery:driver:${driverId}:notified-order-ids`,

        init() {
            this.notifiedOrderIds = this.loadNotifiedOrderIds();
        },

        syncOrders(orderIds) {
            const currentOrderIds = [...new Set(
                orderIds
                    .map((orderId) => Number(orderId))
                    .filter((orderId) => Number.isInteger(orderId) && orderId > 0),
            )];

            if (! this.baselineEstablished) {
                this.baselineEstablished = true;
                this.pendingOrderIds = [];
                this.rememberNotified(currentOrderIds);

                return;
            }

            this.pendingOrderIds = this.pendingOrderIds.filter((orderId) =>
                currentOrderIds.includes(orderId),
            );

            const newOrderIds = currentOrderIds.filter((orderId) =>
                ! this.notifiedOrderIds.includes(orderId)
                && ! this.pendingOrderIds.includes(orderId),
            );

            if (newOrderIds.length === 0) {
                return;
            }

            newOrderIds.forEach((orderId) => this.highlightOrder(orderId));
            this.pendingOrderIds.push(...newOrderIds);

            if (this.soundEnabled) {
                this.flushPendingSounds();
            }
        },

        isHighlighted(orderId) {
            return this.highlightedOrderIds.includes(Number(orderId));
        },

        orderHighlightClass(orderId) {
            return this.isHighlighted(orderId)
                ? 'ring-4 ring-amber-300 border-amber-400 bg-amber-50 shadow-lg'
                : '';
        },

        soundControlClass() {
            if (this.soundUnavailable) {
                return 'bg-gray-200 text-gray-500 cursor-not-allowed';
            }

            return this.soundEnabled
                ? 'bg-emerald-100 text-emerald-800 cursor-default'
                : 'bg-amber-400 text-amber-950 hover:bg-amber-300 animate-pulse';
        },

        soundStatusLabel() {
            if (this.soundUnavailable) return 'Ήχος μη διαθέσιμος';

            return this.soundEnabled ? '🔊 Ήχος ενεργός' : '🔔 Ενεργοποίηση ήχου';
        },

        async enableSound() {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;

            if (! AudioContextClass) {
                this.soundUnavailable = true;

                return;
            }

            try {
                this.audioContext ??= new AudioContextClass();

                if (this.audioContext.state === 'suspended') {
                    await this.audioContext.resume();
                }

                if (this.audioContext.state !== 'running') {
                    throw new Error('AudioContext did not enter the running state.');
                }

                if (! this.playNotificationSound()) {
                    throw new Error('Audio test sound could not be scheduled.');
                }

                this.soundEnabled = true;
                await this.flushPendingSounds();
            } catch {
                this.soundEnabled = false;
                this.soundUnavailable = true;
                this.audioContext?.close();
                this.audioContext = null;
            }
        },

        async flushPendingSounds() {
            if (! this.soundEnabled || ! this.audioContext || this.pendingOrderIds.length === 0) {
                return;
            }

            if (this.audioContext.state === 'suspended') {
                try {
                    await this.audioContext.resume();
                } catch {
                    this.soundEnabled = false;
                    this.soundUnavailable = true;

                    return;
                }
            }

            if (this.audioContext.state !== 'running') {
                this.soundEnabled = false;
                this.soundUnavailable = true;

                return;
            }

            const orderIds = [...this.pendingOrderIds];
            this.pendingOrderIds = [];

            orderIds.forEach((orderId, index) => {
                this.playNotificationSound(index * 0.28);
            });
            this.rememberNotified(orderIds);
        },

        playNotificationSound(delay = 0) {
            if (! this.audioContext || this.audioContext.state !== 'running') {
                return false;
            }

            const startsAt = this.audioContext.currentTime + delay;
            const oscillator = this.audioContext.createOscillator();
            const gain = this.audioContext.createGain();

            oscillator.type = 'sine';
            oscillator.frequency.setValueAtTime(880, startsAt);
            gain.gain.setValueAtTime(0.0001, startsAt);
            gain.gain.exponentialRampToValueAtTime(0.45, startsAt + 0.01);
            gain.gain.exponentialRampToValueAtTime(0.0001, startsAt + 0.2);
            oscillator.connect(gain);
            gain.connect(this.audioContext.destination);
            oscillator.start(startsAt);
            oscillator.stop(startsAt + 0.22);

            return true;
        },

        highlightOrder(orderId) {
            if (! this.highlightedOrderIds.includes(orderId)) {
                this.highlightedOrderIds.push(orderId);
            }

            window.clearTimeout(this.highlightTimers[orderId]);
            this.highlightTimers[orderId] = window.setTimeout(() => {
                this.highlightedOrderIds = this.highlightedOrderIds.filter(
                    (highlightedOrderId) => highlightedOrderId !== orderId,
                );
                delete this.highlightTimers[orderId];
            }, 12000);
        },

        loadNotifiedOrderIds() {
            try {
                const storedOrderIds = JSON.parse(sessionStorage.getItem(this.storageKey) ?? '[]');

                if (! Array.isArray(storedOrderIds)) {
                    return [];
                }

                return [...new Set(
                    storedOrderIds
                        .map((orderId) => Number(orderId))
                        .filter((orderId) => Number.isInteger(orderId) && orderId > 0),
                )];
            } catch {
                return [];
            }
        },

        rememberNotified(orderIds) {
            this.notifiedOrderIds = [...new Set([...this.notifiedOrderIds, ...orderIds])];

            try {
                sessionStorage.setItem(this.storageKey, JSON.stringify(this.notifiedOrderIds));
            } catch {
                // In-memory deduplication still prevents replay during this page session.
            }
        },

        destroy() {
            Object.values(this.highlightTimers).forEach((timer) => window.clearTimeout(timer));
            this.audioContext?.close();
        },
    }));
});

/* PWA install/standalone-launch support. Registration only — the worker
   itself (public/service-worker.js) sticks to whitelisted static assets. */
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/service-worker.js');
    });
}
