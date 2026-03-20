// util/vite.config.ts
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
                // ✅ En dev: PWA OFF
                devOptions: { enabled: false },

                // ✅ Symfony/Twig controla el HTML, no inyectar nada
                injectRegister: null,
                registerType: 'autoUpdate',

                // ✅ Un solo modo: generateSW
                strategies: 'generateSW',

                // ✅ SW en la raíz pública (/public/service-worker.js)
                filename: '../service-worker.js',

                // ✅ Manifest PWA dentro de /public/app_util/
                manifestFilename: 'util-manifest.webmanifest',

                manifest: {
                    name: 'OpenPeru Utilidades',
                    short_name: 'Util',
                    description: 'Aplicación Interna de Gestión y Chat',
                    theme_color: '#ffffff',
                    background_color: '#ffffff',
                    display: 'standalone',
                    start_url: '/',
                    scope: '/',
                    icons: [
                        { src: '/app_util/pwa-192x192.png', sizes: '192x192', type: 'image/png' },
                        { src: '/app_util/pwa-512x512.png', sizes: '512x512', type: 'image/png' },
                    ],
                },

                workbox: {
                    cleanupOutdatedCaches: true,
                    clientsClaim: true,
                    skipWaiting: true,

                    // ✅ Importante: escanear SOLO desde public/app_util
                    globDirectory: '../public/app_util',

                    // ✅ No incluir HTML automáticamente
                    globPatterns: ['**/*.{js,css,ico,png,svg,webmanifest,woff2,woff,ttf,eot}'],

                    globIgnores: ['**/shell.html', '**/*.map'],

                    // ✅ Publicar bajo /app_util/
                    modifyURLPrefix: { '': '/app_util/' },

                    // ✅ Meter el shell manualmente SIN hash
                    additionalManifestEntries: [
                        { url: '/app_util/shell.html', revision: buildTimestamp },
                    ],

                    // ✅ Offline navigation => shell
                    navigateFallback: '/app_util/shell.html',

                    // ✅ Solo interceptar rutas de la app
                    navigateFallbackAllowlist: [/^\/chat(\/|$)/],
                },
            }),
        ],

        base: '/app_util/',

        resolve: {
            alias: {
                '@': fileURLToPath(new URL('./src', import.meta.url)),
            },
        },

        build: {
            manifest: true,
            emptyOutDir: true,
            outDir: '../public/app_util',
            rollupOptions: {
                input: resolve(__dirname, 'src/main.ts'),
            },
        },
    }

    if (command === 'serve') {
        // 🔥 Ajustado a la carpeta util/certs
        const certPath = resolve(__dirname, 'certs/util.openperu.test.crt')
        const keyPath = resolve(__dirname, 'certs/util.openperu.test.key')

        if (!fs.existsSync(certPath) || !fs.existsSync(keyPath)) {
            console.error('❌ ERROR CRÍTICO: No encuentro los certificados en util/certs/')
            process.exit(1)
        }

        return {
            ...config,
            server: {
                host: true,
                port: 5174,
                strictPort: true,
                https: {
                    key: fs.readFileSync(keyPath),
                    cert: fs.readFileSync(certPath),
                },
                cors: true,
                origin: 'https://util.openperu.test:5174',
                hmr: {
                    host: 'util.openperu.test',
                    port: 5174,
                    protocol: 'wss',
                },
            },
        }
    }

    return config
})