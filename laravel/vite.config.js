import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/css/portal.css', 'resources/css/account.css', 'resources/js/app.js', 'resources/css/admin-shell.css', 'resources/js/language.js', 'resources/js/account-menu.js'],
            refresh: true,
        }),
    ],
});
