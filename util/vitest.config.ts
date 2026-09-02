import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));

/**
 * Configuración de los tests, SEPARADA de `vite.config.ts` — misma razón que en `pax`.
 *
 * ⚠️ **Vitest carga la config de Vite con `command: 'serve'`**, y esa rama de `vite.config.ts`
 * exige los certificados de `util/certs/` y hace `process.exit(1)` si no están. Heredarla dejaría
 * `npm test` dependiendo de un certificado local: pasaría en la máquina de quien lo escribió y
 * **moriría en cualquier otra**, con un error que no habla de tests.
 *
 * ── Por qué aquí SÍ va el plugin de Vue y en `pax` no ───────────────────────
 * En `pax` sólo se testea `src/dominio/`, que es TypeScript puro. Aquí lo que interesa probar
 * —las reglas del editor— vive dentro de **stores de Pinia**, y esos módulos importan `.vue` por
 * la cadena de dependencias. Sin el plugin, el import falla antes de llegar a la primera
 * aserción.
 *
 * ⚠️ Que haga falta el plugin **es en sí un dato**: significa que la regla no está desacoplada
 * todavía. Cuando el cálculo salga a `dominio/`, sus tests no necesitarán nada de esto — y ese
 * será el momento de comprobar si esta config puede adelgazar.
 */
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': resolve(__dirname, 'src'),
        },
    },
    test: {
        include: ['src/**/*.test.ts'],

        /**
         * ⚠️ **`jsdom` y no `node`, y no es una preferencia: es una limitación medida.**
         * `src/services/apiClient.ts` lee `window.OPENPERU_CONFIG` **al importarse el módulo**, y
         * los stores lo importan. Sin un DOM, cargar cualquier store revienta con
         * `ReferenceError: window is not defined` antes de la primera aserción.
         *
         * Es la misma familia de problema que tenía la composición del itinerario metida en un
         * `.vue`: **lógica que sólo se puede ejecutar dentro de un navegador**. Aquí se paga con
         * una dependencia de test; el día que una regla salga a `dominio/`, sus tests no
         * necesitarán DOM ninguno — y ésa es justamente la diferencia que se busca.
         */
        environment: 'jsdom',
    },
});
