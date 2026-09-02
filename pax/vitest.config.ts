import { defineConfig } from 'vitest/config';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));

/**
 * Configuración de los tests, SEPARADA de `vite.config.ts` a propósito.
 *
 * ⚠️ **Vitest carga la config de Vite con `command: 'serve'`**, y esa rama de `vite.config.ts`
 * exige los certificados de `pax/certs/` y hace `process.exit(1)` si no están. Heredarla dejaría
 * `npm test` dependiendo de un certificado local: pasaría aquí y **moriría en cualquier máquina
 * que no sea ésta**, sin que el fallo tuviera nada que ver con los tests.
 *
 * Tampoco hace falta nada de allí: los módulos de `src/dominio/` son TypeScript puro —sin Vue,
 * sin PWA, sin Tailwind—, así que lo único que se replica es el alias.
 *
 * ⚠️ Si algún día se testea un componente `.vue`, hará falta el plugin de Vue **y** revisar esta
 * decisión. Mientras se testee sólo dominio, heredar sería traerse tres plugins para nada.
 */
export default defineConfig({
    resolve: {
        alias: {
            '@': resolve(__dirname, 'src'),
        },
    },
    test: {
        // Sólo dominio: lo que no toca el navegador. Ampliar esto es una decisión, no un descuido.
        include: ['src/dominio/**/*.test.ts'],
        environment: 'node',
    },
});
