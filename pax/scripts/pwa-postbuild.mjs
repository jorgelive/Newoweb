// pax/scripts/pwa-postbuild.mjs
import fs from 'node:fs'
import path from 'node:path'

const projectRoot = process.cwd()

// VitePWA lo deja dentro del outDir (public/app_pax)
const source = path.resolve(projectRoot, '../public/app_pax/manifest.webmanifest')

// Lo copiamos a raíz (public/)
const destination = path.resolve(projectRoot, '../public/manifest.webmanifest')

if (!fs.existsSync(source)) {
    console.error('❌ No se encontró:', source)
    process.exit(1)
}

fs.copyFileSync(source, destination)
console.log('✅ Manifest copiado a:', destination)