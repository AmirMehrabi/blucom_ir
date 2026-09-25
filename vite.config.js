import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        host: '127.0.0.1',
        cors: {
            origin: [
                'http://admin.blucom.local:8000',
                'http://hub.blucom.local:8000',
            ],
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
