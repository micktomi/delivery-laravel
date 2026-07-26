/**
 * Storefront menu controller.
 *
 * Livewire 3 ships Alpine, so this registers against the instance Livewire
 * exposes rather than importing and starting its own. Everything with an
 * operator or a branch lives here: markup attributes stay bare method calls,
 * which keeps expression errors inside a file that can be read and diffed.
 */
document.addEventListener('livewire:init', () => {
    window.Alpine.data('menu', (initialCart = [], initialSlug = '') => ({
        cart: initialCart,
        activeSlug: initialSlug,
        cartOpen: false,
        scrolled: false,
        addedProductId: null,
        addedTimer: null,

        get cartCount() {
            return this.cart.reduce((total, item) => total + item.quantity, 0);
        },

        get subtotal() {
            return this.cart.reduce((total, item) => total + parseFloat(item.line_total), 0);
        },

        get isEmpty() {
            return this.cart.length === 0;
        },

        /* ── formatting ── */

        eur(value) {
            return value.toFixed(2).replace('.', ',') + ' €';
        },

        lineTotal(item) {
            return this.eur(parseFloat(item.line_total));
        },

        itemCountLabel() {
            return this.cartCount + ' ' + (this.cartCount === 1 ? 'προϊόν' : 'προϊόντα');
        },

        optionSummary(item) {
            return item.selected_options.map((option) => option.value).join(' · ');
        },

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
           The server owns the cart; this mirror exists so a tap feels
           instant. Every mutation still goes through the Livewire action,
           and syncCart overwrites the mirror with whatever came back. */

        syncCart(detail) {
            this.cart = detail.cart || [];
            this.flagAdded(detail.productId ?? null);
            this.cartOpen = true;
        },

        increment(index) {
            this.cart[index].quantity++;
            this.$wire.updateQty(index, this.cart[index].quantity);
        },

        decrement(index) {
            if (this.cart[index].quantity > 1) {
                this.cart[index].quantity--;
                this.$wire.updateQty(index, this.cart[index].quantity);

                return;
            }

            this.removeLine(index);
        },

        removeLine(index) {
            this.cart.splice(index, 1);
            this.$wire.removeFromCart(index);
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
