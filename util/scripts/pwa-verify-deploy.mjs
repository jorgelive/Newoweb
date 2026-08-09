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
    '/util-service-worker.js',
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

// ----------------------------------------------------------------------------
// Un 200 no basta. El bug de agosto 2026 fue exactamente eso: /service-worker.js
// respondía 200 con el SW de PAX (mismo docroot, nombre de archivo compartido),
// sin importScripts de push-sw.js ni listener de `push`. Todo "verde" y las
// notificaciones mudas. Así que además del código HTTP validamos el CONTENIDO:
// que el SW desplegado sea el de util y traiga el handler de push.
// ----------------------------------------------------------------------------
if (!failed) {
    const swRes = await fetch(`${base}/util-service-worker.js`, { cache: 'no-store' })
    const swSrc = await swRes.text()

    if (swSrc.includes('/app_pax/')) {
        console.error('\n❌ /util-service-worker.js contiene assets de /app_pax/: se desplegó el SW equivocado.')
        failed = true
    }
    if (!swSrc.includes('/app_util/push-sw.js')) {
        console.error('\n❌ /util-service-worker.js NO importa /app_util/push-sw.js.')
        console.error('   La PWA se instalaría sin listener de `push`: el backend enviaría, el servidor')
        console.error('   push respondería 201 y el dispositivo no mostraría NADA. Rehaz el build de util.')
        failed = true
    } else {
        console.log('✅      contenido del SW: importa push-sw.js y no mezcla assets de pax')
    }
}

if (failed) {
    console.error('\n❌ Deploy PWA INCONSISTENTE: algún artefacto que el Service Worker precachea no responde 200.')
    console.error('   Con esto el SW nuevo no se instala y /chat queda servido por un SW viejo (código obsoleto).')
    console.error('   Asegúrate de correr `npm run build` completo y desplegar service-worker.js + public/app_util/** + util-manifest.webmanifest.\n')
    process.exit(1)
}

console.log('\n✅ Todos los artefactos del SW responden 200. El Service Worker podrá instalarse en los clientes.\n')
