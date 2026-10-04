import { createInertiaApp } from '@inertiajs/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

const queryClient = new QueryClient();

createInertiaApp({
    // Lazy, not eager: each page is its own chunk, fetched the first time it
    // is visited, so a storefront visitor never downloads checkout (Stripe),
    // the order pad or the warehouse screens. The name must match the file
    // path exactly, case included — config/inertia.php's ensure_pages_exist
    // checks every Inertia::render() name in the test suite.
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob<{ default: React.ComponentType }>('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        createRoot(el).render(
            <QueryClientProvider client={queryClient}>
                <App {...props} />
            </QueryClientProvider>,
        );
    },
});
