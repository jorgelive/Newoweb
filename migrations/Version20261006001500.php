<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

/**
 * `esGrupal: bool` → `calculo: string` dentro de los documentos financieros ya guardados.
 *
 * Cierra el último resto de la fase 6b (`docs/PlanModalidadDeTarifa.md`). La columna `es_grupal`
 * de los snapshots de tarifa se borró el 05/10/2026 y `calculo_snapshot` quedó como único campo
 * —233 individual + 67 grupal, sin nulos—, pero los dos JSON derivados de `cotizacion_cotizacion`
 * seguían con la clave vieja porque se escribieron antes:
 *
 * ```
 * clasificacion_financiera          581 ocurrencias (134 true + 447 false)
 * clasificacion_financiera_cliente  581 ocurrencias (134 true + 447 false)
 * ya con "calculo"                    0
 * ```
 *
 * ## Por qué se arregla, si nadie la leía
 *
 * No la lee nadie —`util` sólo **escribe** ese JSON y recalcula desde el árbol; `pax` no lee ni
 * `esGrupal` ni `calculo` de ahí; las menciones que quedan en PHP son comentarios— y se rellenaba
 * sola al siguiente guardado de cada cotización. Pero quedaban **7 documentos publicados**
 * describiendo una forma que el código ya no escribe, y eso es una trampa con fecha: el día que
 * alguien lea `d.calculo` sobre un documento viejo, obtiene `undefined` y el respaldo lo da por
 * `individual`. Una grupal mostrada como unitaria, sin un solo error.
 *
 * El tipo de `pax` ya fuerza a tratar la ausencia (`calculo?: string` con `esGrupal?: boolean`
 * deprecado al lado), así que el riesgo estaba contenido. Esto lo elimina: con los documentos
 * normalizados, el campo deprecado puede morir.
 *
 * ## ⚠️ Por qué recorriendo el JSON y no con `REPLACE` de texto
 *
 * `REPLACE(col, '"esGrupal": true', '"calculo": "grupal"')` habría funcionado hoy —se midió que
 * las dos únicas formas textuales son ésas, con el espacio tras los dos puntos que pone MySQL al
 * convertir el JSON binario a texto— y es exactamente por eso que no se usa: **depende de cómo
 * MySQL renderice el JSON**, que no es parte de ningún contrato. Un cambio de versión, una
 * ordenación distinta de claves o un `null` inesperado y el reemplazo pasa de largo dejando la
 * clave vieja, en silencio y a medias.
 *
 * Decodificando, el recorrido no puede dejarse una: encuentra la clave a cualquier profundidad,
 * dentro de `tarifas[]`, de `clasesPasajeros[].detalle[]` o de donde aparezca, y el `json_encode`
 * no puede producir JSON inválido. Y al final **verifica y lanza** si quedó alguna, que es la
 * diferencia entre una migración que funcionó y una que dijo `[OK]`.
 *
 * ## ⚠️ Doctrine va a decir que esta migración no hizo nada
 *
 * ```
 * [warning] Migration …Version20261006001500 was executed but did not result in any SQL statements.
 * ```
 *
 * Es mentira y es esperable: el aviso lo suelta Doctrine cuando `addSql()` no se usó, y aquí el
 * trabajo va por `$this->connection` porque hace falta decodificar el JSON. **El aviso dice que
 * no se planificó SQL, no que no se escribiera nada.** La señal de verdad son las dos cosas que
 * sí pone esta clase: la línea «esGrupal → calculo: N claves renombradas» y la verificación final,
 * que **lanza** si queda una sola ocurrencia. Medido en local antes de desplegar: 992 claves
 * renombradas, 0 restantes, y el `down()` las devuelve las 992.
 *
 * ## El mapeo, y lo que el booleano no sabía decir
 *
 * ```
 * esGrupal: true   →  calculo: 'grupal'
 * esGrupal: false  →  calculo: 'individual'
 * ```
 *
 * No hay tercer caso que recuperar: `operativa` no se podía publicar antes de la fase 5 —el
 * «⚠️ CONFLICTO» lo bloqueaba— y el booleano no tenía forma de expresarla. Por eso el `down()`
 * sí es honesto aquí, y por eso se niega si encuentra una `operativa`: ésa la escribió un guardado
 * POSTERIOR, y degradarla a `false` le quitaría su carácter oculto.
 */
final class Version20261006001500 extends AbstractMigration
{
    /** Las dos columnas JSON con documentos derivados. @var list<string> */
    private const COLUMNAS = ['clasificacion_financiera', 'clasificacion_financiera_cliente'];

    public function getDescription(): string
    {
        return 'esGrupal → calculo dentro de los JSON financieros ya guardados (recorriendo el árbol, no por texto)';
    }

    public function up(Schema $schema): void
    {
        $renombradas = $this->recorrerDocumentos(
            static function (mixed $valor): string {
                if (!is_bool($valor)) {
                    throw new RuntimeException(sprintf(
                        'esGrupal traía un %s, no un booleano: se para antes de inventar un cálculo.',
                        get_debug_type($valor)
                    ));
                }

                return $valor ? 'grupal' : 'individual';
            },
            'esGrupal',
            'calculo'
        );

        $this->write(sprintf('    <info>esGrupal → calculo:</info> %d claves renombradas.', $renombradas));

        $quedan = $this->contarOcurrencias('esGrupal');
        if ($quedan !== 0) {
            throw new RuntimeException(sprintf('Quedaron %d ocurrencias de esGrupal: el recorrido no las vio.', $quedan));
        }
    }

    public function down(Schema $schema): void
    {
        $renombradas = $this->recorrerDocumentos(
            static function (mixed $valor): bool {
                if ($valor === 'operativa') {
                    // Un booleano no puede decir «operativa». Volver atrás la convertiría en una
                    // línea visible para el cliente, que es justo lo contrario de lo que es.
                    throw new RuntimeException(
                        'Hay una tarifa `operativa` en un documento guardado: el booleano no sabe '
                        . 'expresarla y revertir la haría visible al cliente. No se revierte.'
                    );
                }

                return $valor === 'grupal';
            },
            'calculo',
            'esGrupal'
        );

        $this->write(sprintf('    <info>calculo → esGrupal:</info> %d claves revertidas.', $renombradas));
    }

    /**
     * Recorre los dos JSON de las 15 cotizaciones y renombra la clave a cualquier profundidad.
     *
     * @param callable(mixed): (string|bool) $traducirValor
     */
    private function recorrerDocumentos(callable $traducirValor, string $claveVieja, string $claveNueva): int
    {
        $total = 0;

        foreach (self::COLUMNAS as $columna) {
            /** @var list<array{id: string, doc: string|null}> $filas */
            $filas = $this->connection->fetchAllAssociative(sprintf(
                'SELECT LOWER(HEX(id)) AS id, `%s` AS doc FROM cotizacion_cotizacion WHERE `%s` IS NOT NULL',
                $columna,
                $columna
            ));

            foreach ($filas as $fila) {
                if (!is_string($fila['doc']) || $fila['doc'] === '') {
                    continue;
                }

                $arbol = json_decode($fila['doc'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($arbol)) {
                    continue;
                }

                $tocadas = 0;
                $arbol = $this->renombrarEnArbol($arbol, $claveVieja, $claveNueva, $traducirValor, $tocadas);

                if ($tocadas === 0) {
                    continue;
                }

                // UNHEX para comparar contra el binary(16) del id: un UUID como texto no casa.
                $this->connection->executeStatement(
                    sprintf('UPDATE cotizacion_cotizacion SET `%s` = :doc WHERE id = UNHEX(:id)', $columna),
                    ['doc' => json_encode($arbol, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'id' => $fila['id']]
                );

                $total += $tocadas;
            }
        }

        return $total;
    }

    /**
     * @param array<array-key, mixed> $nodo
     * @param callable(mixed): (string|bool) $traducirValor
     *
     * @return array<array-key, mixed>
     */
    private function renombrarEnArbol(array $nodo, string $vieja, string $nueva, callable $traducirValor, int &$tocadas): array
    {
        $salida = [];

        foreach ($nodo as $clave => $valor) {
            if ($clave === $vieja) {
                $salida[$nueva] = $traducirValor($valor);
                ++$tocadas;

                continue;
            }

            $salida[$clave] = is_array($valor)
                ? $this->renombrarEnArbol($valor, $vieja, $nueva, $traducirValor, $tocadas)
                : $valor;
        }

        return $salida;
    }

    private function contarOcurrencias(string $clave): int
    {
        $suma = 0;

        foreach (self::COLUMNAS as $columna) {
            // `fetchOne()` devuelve `mixed` y un `(int)` encima sería taparlo: si el driver no
            // devuelve un número, lo que falla es la comprobación, y entonces hay que verlo.
            $cuenta = $this->connection->fetchOne(
                sprintf('SELECT COUNT(*) FROM cotizacion_cotizacion WHERE `%s` LIKE :aguja', $columna),
                ['aguja' => '%' . $clave . '%']
            );

            if (!is_numeric($cuenta)) {
                throw new RuntimeException(sprintf(
                    'El COUNT(*) de %s devolvió un %s: sin número no se puede verificar nada.',
                    $columna,
                    get_debug_type($cuenta)
                ));
            }

            $suma += (int) $cuenta;
        }

        return $suma;
    }
}
