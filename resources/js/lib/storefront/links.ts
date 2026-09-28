/**
 * Where storefront links point (05.15 §5.1). One place, so a route change
 * is one edit.
 */
export const storefrontLinks = {
    home: () => '/',
    search: (q: string) => `/search?q=${encodeURIComponent(q)}`,
    category: (slug: string) => `/c/${encodeURIComponent(slug)}`,
    product: (card: { slug: string }) => `/p/${encodeURIComponent(card.slug)}`,
    cart: () => '/cart',
    /**
     * "Continue shopping": a trade buyer goes back to the order pad, their
     * working tool; everyone else to the storefront.
     */
    continueShopping: (isTrade: boolean) => (isTrade ? '/order-pad' : '/'),
};
