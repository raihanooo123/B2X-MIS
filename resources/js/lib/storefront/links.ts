/**
 * Where storefront links point (05.15 §5.1). Category, search and product
 * pages arrive in slice S3. Until then they open the catalogue (the order
 * pad) filtered the same way, so every link works today and S3 changes only
 * this file.
 */
export const storefrontLinks = {
    home: () => '/',
    search: (q: string) => `/order-pad?q=${encodeURIComponent(q)}`,
    category: (slug: string) => `/order-pad?category=${encodeURIComponent(slug)}`,
    product: (card: { name: string }) => `/order-pad?q=${encodeURIComponent(card.name)}`,
    cart: () => '/cart',
};
