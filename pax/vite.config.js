import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { resolve, dirname } from 'node:path'
import fs from 'node:fs'
import { VitePWA } from 'vite-plugin-pwa'

const __filename = fileURLToPath(import.meta.url)
const __dirname = dirname(__filename)

export default defineConfig(({ command }) => {

    const config = {
        plugins: [
            vue(),
            tailwindcss(),
            VitePWA({
                // ✅ en dev: sin PWA
                devOptions: { enabled: false },

                // Symfony controla el HTML
                injectRegister: null,
                registerType: 'autoUpdate',

                // ✅ UN SOLO SISTEMA: generateSW
                strategies: 'generateSW',

                // ✅ SW en raíz de Symfony
                filename: '../service-worker.js',

                // ✅ manifest PWA dentro de app_pax
                manifestFilename: 'manifest.webmanifest',

                manifest: {
                    name: 'Pax App OpenPeru',
                    short_name: 'PaxApp',
                    description: 'Aplicación de Gestión de Huéspedes',
                    theme_color: '#ffffff',
                    background_color: '#ffffff',
                    display: 'standalone',
                    start_url: '/',
                    scope: '/',
                    icons: [
                        { src: '/app_pax/pwa-192x192.png', sizes: '192x192', type: 'image/png' },
                        { src: '/app_pax/pwa-512x512.png', sizes: '512x512', type: 'image/png' },
                    ],
                },

                workbox: {
                    clientsClaim: true,
                    skipWaiting: true,
                    // ✅ precache desde /public
                    globDirectory: '../public',
                    globPatterns: ['app_pax/**/*.{js,css,ico,png,svg,webmanifest,html}'],

                    // ✅ navegación offline: SIEMPRE tu shell físico
                    navigateFallback: '/app_pax/shell.html',

                    navigateFallbackAllowlist: [/^\/huesped(\/|$)/],
                },
            }),
        ],

        base: '/app_pax/',

        resolve: {
            alias: {
                '@': fileURLToPath(new URL('./src', import.meta.url)),
            },
        },

        build: {
            manifest: true,
            emptyOutDir: true,
            outDir: '../public/app_pax',
            rollupOptions: {
                input: resolve(__dirname, 'src/main.ts'),
            },
        },
    }

    if (command === 'serve') {
        const certPath = resolve(__dirname, 'certs/pax.openperu.test.crt')
        const keyPath  = resolve(__dirname, 'certs/pax.openperu.test.key')

        if (!fs.existsSync(certPath) || !fs.existsSync(keyPath)) {
            console.error('❌ ERROR: No encuentro certificados en pax/certs/')
            process.exit(1)
        }

        return {
            ...config,
            server: {
                host: true,
                port: 5173,
                strictPort: true,
                https: {
                    key: fs.readFileSync(keyPath),
                    cert: fs.readFileSync(certPath),
                },
                cors: true,
                origin: 'https://pax.openperu.test:5173',
                hmr: {
                    host: 'pax.openperu.test',
                    port: 5173,
                    protocol: 'wss',
                },
            },
        }
    }

    return config
})