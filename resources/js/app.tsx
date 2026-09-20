import { createInertiaApp } from '@inertiajs/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createRoot } from 'react-dom/client';

const queryClient = new QueryClient();

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./pages/**/*.tsx', { eager: true });

        return pages[`./pages/${name}.tsx`] as { default: React.ComponentType };
    },
    setup({ el, App, props }) {
        createRoot(el).render(
            <QueryClientProvider client={queryClient}>
                <App {...props} />
            </QueryClientProvider>,
        );
    },
});
