import { defineConfig } from 'vitest/config';

/**
 * Los tests del dominio no necesitan nada: ni plugin de Vue, ni DOM, ni alias.
 *
 * Que esta configuración quepa en diez líneas **es la medida de que el módulo está sano**. La de
 * `util` necesita `jsdom` porque allí las reglas viven dentro de stores que importan un cliente
 * HTTP que lee `window` al cargarse; la de `pax` necesita un alias. Aquí no hace falta nada
 * porque no hay nada que no sea la regla.
 *
 * ⚠️ Si algún día este archivo crece, es señal de que algo se coló en `dominio/`.
 */
export default defineConfig({
    test: {
        include: ['**/*.test.ts'],
        environment: 'node',
    },
});
