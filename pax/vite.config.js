import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { resolve, dirname } from 'node:path'
import fs from 'node:fs'
import { VitePWA } from 'vite-plugin-pwa'

const __filename = fileURLToPath(import.meta.url)
const __dirname = dirname(__filename)

export default defineConfig(({ command, mode }) => {

    const config = {
        plugins: [
            vue(),
            tailwindcss(),
            VitePWA({
                // ✅ en dev mejor OFF para evitar dev-sw.js y confusiones
                devOptions: { enabled: false },

                registerType: 'autoUpdate',
                injectRegister: null, // Symfony controla el HTML

                strategies: 'generateSW',

                // ✅ sacar ambos a /public (raíz), usando el truco ../
                filename: '../service-worker.js',
                manifestFilename: 'manifest.webmanifest',

                manifest: {
                    name: 'Pax App OpenPeru',
                    short_name: 'PaxApp',
                    description: 'Aplicación de Gestión de Huéspedes',
                    theme_color: '#ffffff',
                    background_color: '#ffffff',
                    display: 'standalone',

                    // ✅ tu app real vive en /
                    start_url: '/',
                    scope: '/',

                    // ✅ rutas físicas de iconos (viven en /app_pax/)
                    icons: [
                        { src: '/app_pax/pwa-192x192.png', sizes: '192x192', type: 'image/png' },
                        { src: '/app_pax/pwa-512x512.png', sizes: '512x512', type: 'image/png' },
                    ],
                },

                workbox: {
                    // ✅ fallback a tu shell Symfony
                    navigateFallback: '/',

                    // ✅ precache solo assets (no necesitas html)
                    globPatterns: ['**/*.{js,css,ico,png,svg,webmanifest}'],

                    runtimeCaching: [
                        {
                            urlPattern: ({ request }) => request.destination === 'image',
                            handler: 'CacheFirst',
                            options: {
                                cacheName: 'pax-images-cache',
                                expiration: { maxEntries: 50, maxAgeSeconds: 60 * 60 * 24 * 30 },
                            },
                        },
                    ],
                },
            })
        ],
        // Base de los assets de Vite
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
            console.error('❌ ERROR CRÍTICO: No encuentro los certificados en pax/certs/')
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
            }
        }
    }

    return config
})