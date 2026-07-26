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
});
