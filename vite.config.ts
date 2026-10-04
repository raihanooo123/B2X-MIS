import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

/**
 * Vendor chunks. Pages are lazy (resources/js/app.tsx), so each page is its
 * own chunk; these groups pull the libraries every page shares out of them,
 * so they are downloaded once and cached across deploys that only touch app
 * code. Stripe gets its own chunk, imported only by the checkout page, so no
 * other page loads it. Anything not listed stays with the page that uses it.
 */
const vendorChunks: Record<string, RegExp> = {
    react: /[\\/]node_modules[\\/](react|react-dom|scheduler)[\\/]/,
    // Inertia and its runtime (HTTP client, query strings, lodash-es); Rollup brings their own dependencies along.
    inertia: /[\\/]node_modules[\\/](@inertiajs|axios|qs|lodash-es|laravel-precognition)[\\/]/,
    query: /[\\/]node_modules[\\/]@tanstack[\\/]/,
    stripe: /[\\/]node_modules[\\/]@stripe[\\/]/,
};

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        react(),
    ],
    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },
    build: {
        rollupOptions: {
            output: {
                manualChunks(id) {
                    for (const [name, pattern] of Object.entries(vendorChunks)) {
                        if (pattern.test(id)) {
                            return name;
                        }
                    }
                    return undefined;
                },
            },
        },
    },
});
