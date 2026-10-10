/**
 * Mounts one fixture (?fixture=name) as Inertia would: the real page
 * component, real shell and shared props, fixed data and a fixed clock.
 */
import '../../resources/css/app.css';

import { App } from '@inertiajs/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createRoot } from 'react-dom/client';
import type { ComponentType } from 'react';

import { useToastStore } from '@/stores/toastStore';

import { FIXTURES, NOW, sharedProps } from './fixtures';

// A fixed clock: "hours left" and dates render identically on every run.
const fixed = new Date(NOW).getTime();
const RealDate = Date;
class FixedDate extends RealDate {
    constructor(...args: ConstructorParameters<typeof Date>) {
        super(...((args.length === 0 ? [fixed] : args) as [number]));
    }
    static now() {
        return fixed;
    }
}
globalThis.Date = FixedDate as DateConstructor;

const pages = import.meta.glob<{ default: ComponentType }>('../../resources/js/pages/**/*.tsx');
const name = new URLSearchParams(window.location.search).get('fixture') ?? '';
const fixture = FIXTURES[name];

async function mount() {
    const el = document.getElementById('app');
    if (!el || !fixture) {
        document.body.textContent = `Unknown fixture: ${name}`;

        return;
    }
    const resolve = async (component: string) => {
        if (fixture.render) {
            return fixture.render;
        }
        const load = pages[`../../resources/js/pages/${component}.tsx`];

        return (await load()).default;
    };
    const initialComponent = await resolve(fixture.component);
    fixture.toasts?.forEach((t) => useToastStore.getState().push(t));

    createRoot(el).render(
        <QueryClientProvider client={new QueryClient()}>
            <App
                initialPage={{ component: fixture.component, props: { ...sharedProps, errors: {}, ...fixture.props }, url: `${fixture.url}?fixture=${name}`, version: null, clearHistory: false, encryptHistory: false, flash: {}, rememberedState: {} }}
                initialComponent={initialComponent}
                resolveComponent={resolve}
            />
        </QueryClientProvider>,
    );
    document.body.dataset.ready = 'true';
}

void mount();
