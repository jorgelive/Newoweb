import fs from 'node:fs'
import path from 'node:path'

const root = process.cwd()

const publicDir = path.resolve(root, '../public')
const appPaxDir = path.resolve(publicDir, 'app_pax')

// 1) Copiar manifest PWA a raíz
const pwaManifestSrc = path.resolve(appPaxDir, 'pax-manifest.webmanifest')
const pwaManifestDst = path.resolve(publicDir, 'pax-manifest.webmanifest')

if (!fs.existsSync(pwaManifestSrc)) {
    console.error('❌ No se encontró:', pwaManifestSrc)
    process.exit(1)
}
fs.copyFileSync(pwaManifestSrc, pwaManifestDst)
console.log('✅ Manifest copiado a:', pwaManifestDst)

// 2) Leer manifest de Vite (para obtener hashes reales)
let viteManifestPath = path.resolve(appPaxDir, '.vite/manifest.json')
if (!fs.existsSync(viteManifestPath)) {
    viteManifestPath = path.resolve(appPaxDir, 'manifest.json')
}
if (!fs.existsSync(viteManifestPath)) {
    console.error('❌ No se encontró manifest.json de Vite en:', viteManifestPath)
    process.exit(1)
}

const viteManifest = JSON.parse(fs.readFileSync(viteManifestPath, 'utf8'))
const entry = viteManifest['src/main.ts'] || viteManifest['src/main.js']

if (!entry?.file) {
    console.error('❌ No encontré entry src/main.ts o src/main.js en manifest de Vite')
    process.exit(1)
}

const cssLinks = (entry.css || [])
    .map((href) => `<link rel="stylesheet" href="/app_pax/${href}">`)
    .join('\n')

const jsModule = `<script type="module" src="/app_pax/${entry.file}"></script>`

// 3) Generar shell.html físico (app shell)
const shellHtml = `<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover, interactive-widget=resizes-content">
  <meta name="theme-color" content="#ffffff">
  <title>Pax Experince</title>
  <link rel="icon" type="image/svg+xml" href="/app_pax/favicon.svg">
  ${cssLinks}
</head>
<body>
  <div id="app"></div>
  ${jsModule}
</body>
</html>
`

const shellPath = path.resolve(appPaxDir, 'shell.html')
fs.writeFileSync(shellPath, shellHtml, 'utf8')
console.log('✅ Shell generado:', shellPath)

// ============================================================================
// 4) GUARDA ANTI-COLISIÓN CON LA PWA DE UTIL
// ----------------------------------------------------------------------------
// util y pax se sirven desde el MISMO docroot (un solo vhost nginx con varios
// server_name). Cuando ambos vite.config emitían '../service-worker.js', el
// último build en correr sobrescribía el SW del otro. En producción ganó pax, y
// la PWA de util quedó ejecutando este SW —que no importa push-sw.js ni tiene
// listener de `push`—: el backend enviaba, FCM devolvía 201 y el teléfono no
// mostraba nada. Sin síntoma en el build y sin error en los logs del servidor.
// Ver docs/PwaNotificaciones.md.
// ============================================================================
const errors = []

// 4.1) Este build debe emitir su SW con nombre propio.
const paxSwPath = path.resolve(publicDir, 'pax-service-worker.js')
if (!fs.existsSync(paxSwPath)) {
    errors.push(`No existe pax-service-worker.js en ${paxSwPath}. ¿Corrió 'vite build' antes del postbuild?`)
}

// 4.2) Y NO debe haber tocado el de util.
const utilSwPath = path.resolve(publicDir, 'util-service-worker.js')
if (fs.existsSync(utilSwPath)) {
    const utilSw = fs.readFileSync(utilSwPath, 'utf8')
    if (utilSw.includes('/app_pax/')) {
        errors.push('util-service-worker.js contiene assets de /app_pax/: el build de pax pisó el SW de util. Revisa `filename` en pax/vite.config.ts.')
    }
}

// 4.3) El nombre genérico no debe volver a aparecer: si existe, es un artefacto
//      viejo que los clientes todavía podrían registrar.
const legacySwPath = path.resolve(publicDir, 'service-worker.js')
if (fs.existsSync(legacySwPath)) {
    console.warn('⚠️  Existe public/service-worker.js (nombre genérico obsoleto).')
    console.warn('   Bórralo del repo y del servidor: ya nadie debería registrarlo.')
}

if (errors.length > 0) {
    console.error('\n❌ BUILD PWA PAX INCONSISTENTE:')
    for (const e of errors) console.error('   •', e)
    process.exit(1)
}

console.log('✅ SW de pax emitido sin colisionar con el de util.')