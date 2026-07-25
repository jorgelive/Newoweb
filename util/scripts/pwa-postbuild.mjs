import fs from 'node:fs'
import path from 'node:path'

const root = process.cwd()

const publicDir = path.resolve(root, '../public')
const appUtilDir = path.resolve(publicDir, 'app_util')

// 1) Copiar manifest PWA a raíz
const pwaManifestSrc = path.resolve(appUtilDir, 'util-manifest.webmanifest')
const pwaManifestDst = path.resolve(publicDir, 'util-manifest.webmanifest')

if (!fs.existsSync(pwaManifestSrc)) {
    console.error('❌ No se encontró:', pwaManifestSrc)
    process.exit(1)
}
fs.copyFileSync(pwaManifestSrc, pwaManifestDst)
console.log('✅ Manifest copiado a:', pwaManifestDst)

// 2) Leer manifest de Vite
let viteManifestPath = path.resolve(appUtilDir, '.vite/manifest.json')
if (!fs.existsSync(viteManifestPath)) {
    viteManifestPath = path.resolve(appUtilDir, 'manifest.json')
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
    .map((href) => `<link rel="stylesheet" href="/app_util/${href}">`)
    .join('\n')

const jsModule = `<script type="module" src="/app_util/${entry.file}"></script>`

// 3) Generar shell.html
const shellHtml = `<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover, interactive-widget=resizes-content">
  <meta name="theme-color" content="#ffffff">
  <title>OpenPeru Util</title>
  <link rel="icon" type="image/svg+xml" href="/app_util/favicon.svg">
  <script>
    window.OPENPERU_CONFIG = {
      apiUrl: "https://api.openperu.pe",
      panelUrl: "https://panel.openperu.pe"
    };
  <\/script>
  ${cssLinks}
</head>
<body class="bg-slate-50">
  <div id="app"></div>
  ${jsModule}
  <script>
    if ('serviceWorker' in navigator) {
      // updateViaCache 'none' fuerza la re-descarga de service-worker.js y de
      // push-sw.js (importScripts) en cada chequeo; sin esto un SW viejo con la
      // navegación corrupta (host + undefined) puede sobrevivir en el caché HTTP.
      navigator.serviceWorker.register('/service-worker.js', { scope: '/', updateViaCache: 'none' })
        .then(reg => reg.update());
    }
  <\/script>
</body>
</html>
`

const shellPath = path.resolve(appUtilDir, 'shell.html')
fs.writeFileSync(shellPath, shellHtml, 'utf8')
console.log('✅ Shell generado:', shellPath)

// ============================================================================
// 4) GATE DE AUTOCONSISTENCIA SW ↔ SHELL ↔ BUNDLE
// ----------------------------------------------------------------------------
// El service-worker.js (generado por VitePWA) PRECACHEA y sirve /app_util/shell.html
// como única respuesta de navegación para /chat. Si el shell no existe, o apunta a
// un bundle inexistente, el `install` del SW hace 404 y FALLA: el SW nuevo nunca se
// activa y el cliente queda atrapado en un SW viejo con /chat cacheado obsoleto.
// Ese fallo era invisible ("solo pasa en el chat"). Aquí lo convertimos en un error
// ruidoso del build en vez de una bomba de tiempo en producción.
// ============================================================================
const errors = []

// 4.1) El SW debe existir y referenciar el shell que acabamos de generar.
const swPath = path.resolve(publicDir, 'service-worker.js')
if (!fs.existsSync(swPath)) {
    errors.push(`No existe service-worker.js en ${swPath}. ¿Corrió 'vite build' antes del postbuild?`)
} else {
    const swSrc = fs.readFileSync(swPath, 'utf8')
    if (!swSrc.includes('/app_util/shell.html')) {
        errors.push('service-worker.js NO referencia /app_util/shell.html: el SW y el postbuild se desincronizaron (revisa vite.config: navigateFallback / additionalManifestEntries).')
    }

    // 4.2) Todo bundle que el SW precachea debe existir físicamente en disco,
    //      o el install del SW hará 404 y no se activará.
    const precachedAssets = [...swSrc.matchAll(/["']\/app_util\/(assets\/[^"']+?\.(?:js|css))["']/g)].map(m => m[1])
    for (const asset of new Set(precachedAssets)) {
        if (!fs.existsSync(path.resolve(appUtilDir, asset))) {
            errors.push(`El SW precachea /app_util/${asset} pero el archivo no existe en disco (install del SW haría 404).`)
        }
    }
}

// 4.3) El bundle que carga el shell debe existir en disco.
if (!fs.existsSync(path.resolve(appUtilDir, entry.file))) {
    errors.push(`shell.html carga /app_util/${entry.file} pero ese bundle no existe en disco.`)
}

// 4.4) El manifest PWA de la raíz (que enlaza el Twig como /util-manifest.webmanifest) debe existir.
if (!fs.existsSync(pwaManifestDst)) {
    errors.push(`Falta el manifest PWA en la raíz pública: ${pwaManifestDst}`)
}

if (errors.length > 0) {
    console.error('\n❌ BUILD PWA INCONSISTENTE — el Service Worker quedaría ininstalable:')
    for (const e of errors) console.error('   •', e)
    console.error('\n   Corre el build COMPLETO (`npm run build`, no solo `vite build`) y asegúrate de desplegar')
    console.error('   public/service-worker.js, public/app_util/** y public/util-manifest.webmanifest juntos.\n')
    process.exit(1)
}

console.log('✅ Consistencia SW ↔ shell ↔ bundles verificada. El Service Worker podrá instalarse.')