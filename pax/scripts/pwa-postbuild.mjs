import fs from 'node:fs';
import path from 'node:path';

const projectRoot = process.cwd();

// Ruta donde Vite genera el manifest
const source = path.resolve(projectRoot, '../public/app_pax/manifest.webmanifest');

// Ruta final en raíz pública
const destination = path.resolve(projectRoot, '../public/manifest.webmanifest');

if (!fs.existsSync(source)) {
    console.error('❌ No se encontró manifest.webmanifest en app_pax');
    process.exit(1);
}

fs.copyFileSync(source, destination);

console.log('✅ Manifest copiado a public/manifest.webmanifest');