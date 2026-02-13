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
                // ✅ En dev: NO PWA (evita cache/HMR locos)
                devOptions: { enabled: false },

                // Symfony controla el HTML
                injectRegister: null,
                registerType: 'autoUpdate',

                // ✅ ENTERPRISE: SW propio (control total)
                strategies: 'injectManifest',
                srcDir: 'src',
                injectManifest: {
                    swSrc: 'sw.ts',
                },

                // ✅ SW en raíz (public/service-worker.js)
                filename: '../service-worker.js',

                // ✅ Manifest PWA se genera dentro de app_pax y luego lo copiamos a raíz con postbuild
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

                // ✅ Qué se precachea (incluye shell.html)
                workbox: {
                    globDirectory: '../public',
                    globPatterns: [
                        'app_pax/**/*.{js,css,ico,png,svg,webmanifest,html}',
                    ],
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