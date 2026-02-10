import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { resolve, dirname } from 'node:path'
import fs from 'node:fs'

// Definimos __dirname para ES Modules
const __filename = fileURLToPath(import.meta.url)
const __dirname = dirname(__filename)

// ----------------------------------------------------------------------
// CONFIGURACIÓN DE CERTIFICADOS
// ----------------------------------------------------------------------
const certPath = resolve(__dirname, 'certs/pax.openperu.test.crt')
const keyPath  = resolve(__dirname, 'certs/pax.openperu.test.key')

if (!fs.existsSync(certPath) || !fs.existsSync(keyPath)) {
    console.error('❌ ERROR CRÍTICO: No encuentro los certificados en pax/certs/')
    console.error('   Por favor copia los archivos .crt y .key de MAMP a esa carpeta.')
    process.exit(1)
}

export default defineConfig({
    plugins: [
        vue(),
        tailwindcss(), // ✅ AÑADIR
    ],

    base: '/app_pax/',

    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./src', import.meta.url)),
        },
    },

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

    build: {
        manifest: true,
        emptyOutDir: true,
        outDir: '../public/app_pax',
        rollupOptions: {
            input: resolve(__dirname, 'src/main.ts'),
        },
    },
})