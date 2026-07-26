import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                // Το bunny provider αγνοεί το `subsets` και κατεβάζει όλα τα subsets
                // (latin, latin-ext, greek, greek-ext, cyrillic, vietnamese). Τα ελληνικά
                // φορτώνονται lazy μέσω unicode-range, οπότε δεν κάνουμε preload τίποτα:
                // το preload είναι per-weight, όχι per-subset, και θα κατέβαζε eager
                // και τα κυριλλικά/βιετναμέζικα.
                bunny('Commissioner', { weights: [500, 700, 800], preload: false }),
                bunny('Inter', { weights: [400, 500, 600, 700], preload: false }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
