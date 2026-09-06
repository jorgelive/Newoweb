// ============================================================================
// Verificador POST-DEPLOY del PWA de Pax.
// ----------------------------------------------------------------------------
// Espejo de `util/scripts/pwa-verify-deploy.mjs`, con dos diferencias que salen
// del propio módulo: pax NO tiene push (no hay `push-sw.js` que comprobar) y su
// registro vive en `templates/pax/app.html.twig`, no en un shell generado — así
// que un cambio ahí necesita `cache:clear --env=prod` para llegar.
//
// Qué se comprueba y por qué: si alguno de los artefactos que el SW precachea
// responde 404, el SW nuevo NO se instala y los clientes se quedan atrapados en
// uno viejo, sirviendo código de hace semanas sin un solo error visible.
//
// Y un 200 no basta. En agosto de 2026 `/service-worker.js` respondía 200 con el
// SW equivocado —util y pax comparten docroot— y todo parecía verde. Por eso se
// mira también el CONTENIDO: que el SW desplegado sea el de pax y no traiga
// assets de util.
//
// Uso:
//   node scripts/pwa-verify-deploy.mjs                 # usa https://pax.openperu.pe
//   node scripts/pwa-verify-deploy.mjs https://pax.openperu.pe
//   VERIFY_BASE_URL=https://pax.openperu.test:8890 node scripts/pwa-verify-deploy.mjs
// ============================================================================

const base = (process.argv[2] || process.env.VERIFY_BASE_URL || 'https://pax.openperu.pe').replace(/\/+$/, '')

// Rutas de las que depende la instalación del SW.
const required = [
    '/pax-service-worker.js',
    '/app_pax/shell.html',
    '/pax-manifest.webmanifest',
]

const check = async (path) => {
    const url = `${base}${path}`
    try {
        const res = await fetch(url, { redirect: 'manual', cache: 'no-store' })
        const ct = res.headers.get('content-type') || ''
        // Un .js que responde `text/html` es el index de Symfony haciéndose pasar por
        // el artefacto: 200 y precache roto igual.
        const looksLikeSpaFallback = path.endsWith('.js') && ct.includes('text/html')
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

if (!failed) {
    const swRes = await fetch(`${base}/pax-service-worker.js`, { cache: 'no-store' })
    const swSrc = await swRes.text()

    if (swSrc.includes('/app_util/')) {
        console.error('\n❌ /pax-service-worker.js contiene assets de /app_util/: se desplegó el SW equivocado.')
        console.error('   util y pax comparten docroot; rehaz el build de pax.')
        failed = true
    }

    // El cartel de «nueva versión» de App.vue depende de esto: con `skipWaiting: false`
    // workbox inyecta el listener de SKIP_WAITING, y es lo que permite que el SW nuevo
    // espere al toque de la persona. Si el build saliera sin él, el cartel pediría una
    // actualización que nunca se aplicaría — y en silencio.
    if (!swSrc.includes('SKIP_WAITING')) {
        console.error('\n❌ /pax-service-worker.js NO trae el listener de SKIP_WAITING.')
        console.error('   El cartel de «nueva versión» quedaría muerto: pulsarlo no activaría nada.')
        console.error('   Revisa que `skipWaiting` siga en false en pax/vite.config.ts y rehaz el build.')
        failed = true
    } else {
        console.log('✅      contenido del SW: trae SKIP_WAITING y no mezcla assets de util')
    }
}

if (failed) {
    console.error('\n❌ Deploy PWA INCONSISTENTE: algún artefacto que el Service Worker precachea no responde 200.')
    console.error('   Con esto el SW nuevo no se instala y los clientes quedan servidos por uno viejo.')
    console.error('   Asegúrate de correr `npm run build` completo en pax/ y desplegar pax-service-worker.js +')
    console.error('   public/app_pax/** + pax-manifest.webmanifest.\n')
    process.exit(1)
}

console.log('\n✅ Todos los artefactos del SW responden 200. El Service Worker podrá instalarse en los clientes.\n')
