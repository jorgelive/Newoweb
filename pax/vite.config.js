// pax/vite.config.ts
import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { resolve, dirname } from 'node:path'
import fs from 'node:fs'
import { VitePWA } from 'vite-plugin-pwa'
const buildTimestamp = Date.now().toString();

const __filename = fileURLToPath(import.meta.url)
const __dirname = dirname(__filename)

export default defineConfig(({ command }) => {
    const config = {
        plugins: [
            vue(),
            tailwindcss(),
            VitePWA({
                // ✅ En dev: PWA OFF (evita dev-sw y confusiones)
                devOptions: { enabled: false },

                // ✅ Symfony/Twig controla el HTML, no inyectar nada
                injectRegister: null,
                registerType: 'autoUpdate',

                // ✅ Un solo modo: generateSW (sin injectManifest)
                strategies: 'generateSW',

                // ✅ SW en la raíz pública (/public/service-worker.js)
                filename: '../service-worker.js',

                // ✅ Manifest PWA dentro de /public/app_pax/ (Vite lo deja ahí)
                manifestFilename: 'manifest.webmanifest',

                manifest: {
                    name: 'Pax App OpenPeru',
                    short_name: 'Pax',
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
                    cleanupOutdatedCaches: true,
                    clientsClaim: true,
                    skipWaiting: true,

                    // ✅ Importante: escanear SOLO desde public/app_pax
                    globDirectory: '../public/app_pax',

                    // ✅ No incluir HTML automáticamente (evita leer shell viejo)
                    globPatterns: ['**/*.{js,css,ico,png,svg,webmanifest,woff2,woff,ttf,eot}'],

                    globIgnores: ['**/shell.html', '**/*.map'],

                    // ✅ Los archivos encontrados se publican bajo /app_pax/ (sin duplicar)
                    modifyURLPrefix: { '': '/app_pax/' },

                    // ✅ Meter el shell manualmente SIN hash (evita checksum mismatch)
                    additionalManifestEntries: [
                        { url: '/app_pax/shell.html', revision: buildTimestamp },
                    ],

                    // ✅ Offline navigation => shell
                    navigateFallback: '/app_pax/shell.html',

                    // ✅ Solo interceptar rutas de la app (Symfony puede tener otras)
                    navigateFallbackAllowlist: [/^\/huesped(\/|$)/],
                },
            }),
        ],

        // Base de assets de Vite (coincide con tu carpeta final)
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

    // Configuración de DEV con HTTPS (MAMP/certs)
    if (command === 'serve') {
        const certPath = resolve(__dirname, 'certs/pax.openperu.test.crt')
        const keyPath = resolve(__dirname, 'certs/pax.openperu.test.key')

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
            },
        }
    }

    return config
})