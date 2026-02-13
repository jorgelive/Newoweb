import fs from 'node:fs'
import path from 'node:path'

const root = process.cwd()

const publicDir = path.resolve(root, '../public')
const appPaxDir = path.resolve(publicDir, 'app_pax')

// 1) Copiar manifest PWA a raíz
const pwaManifestSrc = path.resolve(appPaxDir, 'manifest.webmanifest')
const pwaManifestDst = path.resolve(publicDir, 'manifest.webmanifest')

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
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#ffffff">
  <title>Pax</title>
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