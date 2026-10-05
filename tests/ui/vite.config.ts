/**
 * 05.16 §6 screenshot harness: renders trade screens through Inertia with
 * fixed fixture props (fixtures.tsx) — no server, database or network —
 * so Playwright baselines at 390/1440 px are deterministic. Not an app
 * page: built only for `tests/ui/screenshots.mjs`, never deployed.
 *
 *   npx vite build --config tests/ui/vite.config.ts   (from the repo root)
 */
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';
import { defineConfig } from 'vite';

export default defineConfig({
    root: resolve(__dirname),
    base: '/',
    plugins: [react()],
    resolve: { alias: { '@': resolve(__dirname, '../../resources/js') } },
    css: { postcss: resolve(__dirname, '../..') },
    build: { outDir: process.env.UI_HARNESS_OUT ?? resolve(__dirname, '../../node_modules/.cache/ui-harness'), emptyOutDir: true },
});
