import {
    defineConfig
} from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: [`resources/views/**/*`],
        }),
        tailwindcss(),
    ],
    server: {
        // Required for DDEV: listen on all interfaces inside the container and
        // emit asset/HMR URLs pointing at the router-exposed https port.
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        origin: process.env.DDEV_PRIMARY_URL
            ? `${process.env.DDEV_PRIMARY_URL.replace(/:\d+$/, '')}:5173`
            : undefined,
        cors: true,
    },
});