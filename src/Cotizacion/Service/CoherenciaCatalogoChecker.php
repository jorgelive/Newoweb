<?php

declare(strict_types=1);

namespace App\Cotizacion\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Busca configuraciones A MEDIAS: las que nadie elige a propósito.
 *
 * ── Por qué hace falta ──────────────────────────────────────────────────────
 * En este dominio **«no aparece» es un estado legítimo**: un componente puede no tener proveedor,
 * ni habitación, ni foto, y la pantalla se ve igual de bien. Eso hace que un fallo real y un caso
 * vacío correcto sean indistinguibles a ojo, y por eso los cuatro que se encontraron el 27/08/2026
 * llevaban meses ahí:
 *
 * | Estaba bien | Faltaba | Se veía como |
 * |---|---|---|
 * | el nombre en el maestro | copiarlo al snapshot | otro nombre plausible |
 * | el `id` en la entidad | el grupo de serialización | una tarjeta que no sale |
 * | el nombre de la habitación | su título público | «no tiene habitación» |
 * | el id del servicio del prestador | su nombre congelado | «hotel 4 estrellas» a secas |
 *
 * Ninguno daba error. Lo que los delata no es un síntoma, es la **incoherencia interna**: un id
 * puesto y su nombre vacío es una combinación que ninguna acción del editor produce.
 *
 * ── La regla que separa reparar de avisar ───────────────────────────────────
 * **Se repara lo que tiene una sola respuesta posible**: si el id apunta a un maestro que existe y
 * el nombre está vacío, el nombre es el del maestro. No hay segunda lectura.
 *
 * **Se avisa de lo que es una decisión**: que un prestador esté oculto teniendo permiso el maestro
 * puede ser deliberado. Un script que «arregla» decisiones ajenas hace más daño que el hueco que
 * rellena.
 *
 * ⚠️ **Nunca toca documentos emitidos.** Las líneas de una Orden congelada se listan, no se
 * corrigen: dicen lo que se le mandó al proveedor.
 *
 * ⚠️ Todos los JOIN convierten los ids a mano: los `*_maestro_id` son `varchar(36)` **con guiones**
 * y los `id` de `travel_*` son `binary(16)`. Comparados en crudo dan **cero filas sin error**, y un
 * chequeo que nunca encuentra nada es peor que no tenerlo. Ver `UuidRelacionFilter`.
 */
final class CoherenciaCatalogoChecker
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param string|null $cotizacionId null = todo el catálogo; con id, sólo esa cotización
     *
     * @return list<array{clave: string, titulo: string, detalle: string, filas: int, reparable: bool}>
     */
    public function revisar(bool $reparar = false, ?string $cotizacionId = null): array
    {
        $bin = $this->aBinario($cotizacionId);

        // Un id ilegible NO se ignora: devolvería el informe de TODO el catálogo cuando quien
        // preguntó quería el de una cotización, y eso se lee como «tienes 40 problemas aquí».
        if ($cotizacionId !== null && $bin === null) {
            return [];
        }

        $hallazgos = [];

        foreach ([...$this->reparables(), ...$this->avisos()] as $clave => $chk) {
            $reparable = $chk['set'] !== null;
            $params    = $bin === null ? [] : ['cot' => $bin];
            $filtro    = $bin === null ? '' : ' AND ' . $chk['deCotizacion'];

            $filas = (int) $this->db->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s WHERE %s%s', $chk['desde'], $chk['donde'], $filtro),
                $params
            );

            if ($filas === 0) {
                continue;
            }

            if ($reparar && $reparable) {
                $filas = (int) $this->db->executeStatement(
                    sprintf('UPDATE %s SET %s WHERE %s%s', $chk['desde'], $chk['set'], $chk['donde'], $filtro),
                    $params
                );
            }

            $hallazgos[] = [
                'clave'     => $clave,
                'titulo'    => $chk['titulo'],
                'detalle'   => $chk['detalle'],
                'filas'     => $filas,
                'reparable' => $reparable,
            ];
        }

        return $hallazgos;
    }

    /**
     * Cada chequeo en tres piezas, para que la misma definición sirva para contar, reparar y
     * acotar a una cotización sin escribir el SQL tres veces.
     *
     * `deCotizacion` es la condición que lo ata a una cotización concreta. Se añade sólo cuando
     * hace falta, así que el chequeo global no paga el JOIN.
     *
     * @return array<string, array{titulo: string, detalle: string, desde: string, donde: string, set: string|null, deCotizacion: string}>
     */
    private function reparables(): array
    {
        $delComponente = 'k.cotservicio_id IN (SELECT sv.id FROM cotizacion_cotservicio sv WHERE sv.cotizacion_id = :cot)';

        return [
            'componente-nombre' => [
                'titulo'  => 'Componentes con maestro pero sin nombre operativo',
                'detalle' => 'La ficha de tráfico y la Orden caen al título público, que a veces es genérico.',
                'desde'   => 'cotizacion_cotcomponente k JOIN travel_componente m ON HEX(m.id) = UPPER(REPLACE(k.componente_maestro_id, "-", ""))',
                'donde'   => '(k.nombre_interno_snapshot IS NULL OR k.nombre_interno_snapshot = "") AND m.nombre_interno <> ""',
                'set'     => 'k.nombre_interno_snapshot = m.nombre_interno',
                'deCotizacion' => $delComponente,
            ],
            'prestador-nombre' => [
                'titulo'  => 'Prestador asignado sin su nombre congelado',
                'detalle' => 'La Orden se queda sin decir quién opera; `pax` no lo nota porque resuelve en vivo.',
                'desde'   => 'cotizacion_cotcomponente k JOIN travel_organizacion o ON HEX(o.id) = UPPER(REPLACE(k.prestador_maestro_id, "-", ""))',
                'donde'   => '(k.prestador_nombre_snapshot IS NULL OR k.prestador_nombre_snapshot = "") AND o.nombre_comercial <> ""',
                'set'     => 'k.prestador_nombre_snapshot = o.nombre_comercial',
                'deCotizacion' => $delComponente,
            ],
            'habitacion-nombre' => [
                'titulo'  => 'Habitación o clase asignada sin su nombre congelado',
                'detalle' => 'El huésped la ve —resuelve en vivo— y el proveedor recibe la categoría a secas.',
                'desde'   => 'cotizacion_cotcomponente k JOIN travel_organizacion_servicio s ON HEX(s.id) = UPPER(REPLACE(k.prestador_servicio_maestro_id, "-", ""))',
                'donde'   => '(k.prestador_servicio_nombre_snapshot IS NULL OR k.prestador_servicio_nombre_snapshot = "") AND s.nombre <> ""',
                'set'     => 'k.prestador_servicio_nombre_snapshot = s.nombre',
                'deCotizacion' => $delComponente,
            ],
            'segmento-nombre' => [
                'titulo'  => 'Segmentos con maestro pero sin nombre operativo',
                'detalle' => 'La línea pequeña de la ficha de tráfico se queda sin el tramo.',
                'desde'   => 'cotizacion_segmento g JOIN travel_segmento t ON HEX(t.id) = UPPER(REPLACE(g.segmento_maestro_id, "-", ""))',
                'donde'   => 'JSON_LENGTH(g.nombre_interno_snapshot) = 0 AND t.nombre_interno <> ""',
                'set'     => 'g.nombre_interno_snapshot = JSON_ARRAY(JSON_OBJECT("language", "es", "content", t.nombre_interno))',
                'deCotizacion' => 'g.cotservicio_id IN (SELECT sv.id FROM cotizacion_cotservicio sv WHERE sv.cotizacion_id = :cot)',
            ],
            // ⚠️ EL ÚLTIMO A PROPÓSITO: arrastra a La Biblia lo que los anteriores acaban de
            // arreglar. Y sincroniza sin pasar por la reconciliación: son campos VACÍOS que se
            // rellenan, no cambios que alguien deba aprobar.
            'biblia-desincronizada' => [
                'titulo'  => 'Filas del Centro de Operaciones sin datos que la cotización sí tiene',
                'detalle' => 'Se rellenan directamente: están vacíos, no hay nada que aprobar.',
                'desde'   => 'operacion_servicio o JOIN cotizacion_cotcomponente k ON k.id = o.cotizacion_componente_id',
                'donde'   => '((o.nombre_componente IS NULL AND k.nombre_interno_snapshot <> "")
                            OR (o.prestador_servicio_nombre IS NULL AND k.prestador_servicio_nombre_snapshot <> "")
                            OR (o.prestador_nombre IS NULL AND k.prestador_nombre_snapshot <> ""))',
                'set'     => 'o.nombre_componente = COALESCE(o.nombre_componente, NULLIF(k.nombre_interno_snapshot, "")),
                              o.prestador_servicio_nombre = COALESCE(o.prestador_servicio_nombre, NULLIF(k.prestador_servicio_nombre_snapshot, "")),
                              o.prestador_nombre = COALESCE(o.prestador_nombre, NULLIF(k.prestador_nombre_snapshot, ""))',
                'deCotizacion' => $delComponente,
            ],
        ];
    }

    /**
     * Lo que huele a medias pero admite más de una lectura. Se enseña y no se toca.
     *
     * @return array<string, array{titulo: string, detalle: string, desde: string, donde: string, set: null, deCotizacion: string}>
     */
    private function avisos(): array
    {
        return [
            'servicio-sin-titulo' => [
                'titulo'  => 'Servicios de organización sin título público',
                'detalle' => 'Invisibles para el huésped aunque estén asignados. Se rellenan en el catálogo.',
                'desde'   => 'travel_organizacion_servicio s',
                'donde'   => 'JSON_LENGTH(s.titulo) = 0 AND s.nombre <> ""',
                'set'     => null,
                'deCotizacion' => 'HEX(s.id) IN (SELECT UPPER(REPLACE(k.prestador_servicio_maestro_id, "-", "")) FROM cotizacion_cotcomponente k
                                                  JOIN cotizacion_cotservicio sv ON k.cotservicio_id = sv.id WHERE sv.cotizacion_id = :cot)',
            ],
            'visibilidad-desfasada' => [
                'titulo'  => 'Prestadores ocultos cuyo catálogo ya permite nombrarlos',
                'detalle' => 'Puede ser deliberado: la cotización manda. Se arrastra reasignando el prestador.',
                'desde'   => 'cotizacion_cotcomponente k JOIN travel_organizacion o ON HEX(o.id) = UPPER(REPLACE(k.prestador_maestro_id, "-", ""))',
                'donde'   => 'k.prestador_visible = 0 AND o.visible_para_cliente = 1',
                'set'     => null,
                'deCotizacion' => 'k.cotservicio_id IN (SELECT sv.id FROM cotizacion_cotservicio sv WHERE sv.cotizacion_id = :cot)',
            ],
            'orden-congelada-incompleta' => [
                'titulo'  => 'Líneas de Orden emitida sin datos que el Centro de Operaciones sí tiene',
                'detalle' => 'NO se reparan: dicen lo que se le mandó al proveedor. Se corrigen reemitiendo.',
                'desde'   => 'operacion_orden_servicio_item i JOIN operacion_servicio s ON s.id = UNHEX(REPLACE(i.operacion_servicio_id, "-", ""))',
                'donde'   => '((i.nombre_componente IS NULL AND s.nombre_componente IS NOT NULL)
                            OR (i.prestador_servicio_nombre IS NULL AND s.prestador_servicio_nombre IS NOT NULL))',
                'set'     => null,
                'deCotizacion' => 's.cotizacion_componente_id IN (SELECT k.id FROM cotizacion_cotcomponente k
                                                                    JOIN cotizacion_cotservicio sv ON k.cotservicio_id = sv.id WHERE sv.cotizacion_id = :cot)',
            ],
        ];
    }

    /** El uuid en los 16 bytes que guarda la columna, o null si no es legible. */
    private function aBinario(?string $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        $partes = explode('/', $id);
        $ultimo = (string) end($partes);

        return Uuid::isValid($ultimo) ? Uuid::fromString($ultimo)->toBinary() : null;
    }
}
