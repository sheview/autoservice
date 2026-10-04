import vue from '@vitejs/plugin-vue';
import autoprefixer from 'autoprefixer';
import laravel from 'laravel-vite-plugin';
import path from 'path';
import tailwindcss from 'tailwindcss';
import { defineConfig, loadEnv } from 'vite';

// .env is not loaded into process.env for this file: read what it needs.
const env = loadEnv(process.env.NODE_ENV ?? 'development', process.cwd(), '');

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.ts'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    // Testing on a phone over the LAN: set VITE_HMR_HOST in .env to this computer's IP (and APP_URL to
    // http://<that IP>:8000); without it the dev server stays on localhost as before.
    server: env.VITE_HMR_HOST
        ? { host: '0.0.0.0', hmr: { host: env.VITE_HMR_HOST }, cors: true }
        : undefined,
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
        },
    },
    css: {
        postcss: {
            plugins: [tailwindcss, autoprefixer],
        },
    },
});
