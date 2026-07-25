// ============================================================================
// Verificador POST-DEPLOY del PWA de Util.
// ----------------------------------------------------------------------------
// El service-worker.js sirve /app_util/shell.html como única respuesta de
// navegación para /chat y lo precachea en el install. Si en el servidor ese
// archivo (o el manifest de la raíz) responde 404, el SW nuevo NO se instala y
// los clientes quedan atrapados en un SW viejo con /chat obsoleto — el bug que
// "solo pasaba en el chat". Este script confirma, contra el dominio real, que
// todos los artefactos que el SW exige responden 200 antes de dar por bueno el
// deploy.
//
// Uso:
//   node scripts/pwa-verify-deploy.mjs                 # usa https://util.openperu.pe
//   node scripts/pwa-verify-deploy.mjs https://util.openperu.pe
//   VERIFY_BASE_URL=https://util.openperu.test:8890 node scripts/pwa-verify-deploy.mjs
// ============================================================================

const base = (process.argv[2] || process.env.VERIFY_BASE_URL || 'https://util.openperu.pe').replace(/\/+$/, '')

// Rutas de las que depende la instalación del SW.
const required = [
    '/service-worker.js',
    '/app_util/shell.html',
    '/app_util/push-sw.js',
    '/util-manifest.webmanifest',
]

const check = async (path) => {
    const url = `${base}${path}`
    try {
        const res = await fetch(url, { redirect: 'manual', cache: 'no-store' })
        const ct = res.headers.get('content-type') || ''
        // El shell y el SW deben ser el archivo real, NO el HTML de la SPA/login
        // (un 200 que en realidad es el index de Symfony también rompe el precache).
        const looksLikeSpaFallback = path === '/app_util/shell.html'
            ? false // el shell ES html; solo validamos el 200
            : (path.endsWith('.js') && ct.includes('text/html'))
        const ok = res.status === 200 && !looksLikeSpaFallback
        return { path, url, status: res.status, ct, ok }
    } catch (err) {
        return { path, url, status: 'ERR', ct: '', ok: false, err: err.message }
    }
}

const results = await Promise.all(required.map(check))

let failed = false
console.log(`\n🔎 Verificando artefactos PWA en ${base}\n`)
for (const r of results) {
    const mark = r.ok ? '✅' : '❌'
    console.log(`${mark} ${String(r.status).padEnd(4)} ${r.path}${r.err ? `  (${r.err})` : ''}`)
    if (!r.ok) failed = true
}

if (failed) {
    console.error('\n❌ Deploy PWA INCONSISTENTE: algún artefacto que el Service Worker precachea no responde 200.')
    console.error('   Con esto el SW nuevo no se instala y /chat queda servido por un SW viejo (código obsoleto).')
    console.error('   Asegúrate de correr `npm run build` completo y desplegar service-worker.js + public/app_util/** + util-manifest.webmanifest.\n')
    process.exit(1)
}

console.log('\n✅ Todos los artefactos del SW responden 200. El Service Worker podrá instalarse en los clientes.\n')
