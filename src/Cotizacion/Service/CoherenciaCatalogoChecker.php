<?php

declare(strict_types=1);

namespace App\Cotizacion\Service;

use Doctrine\DBAL\Connection;

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
 * | el id del servicio | su nombre congelado | «hotel 4 estrellas» a secas |
 *
 * Ninguno daba error. Lo que los delata no es un síntoma, es la **incoherencia interna**: un id
 * puesto y su nombre vacío es una combinación que ninguna acción del editor produce.
 *
 * ── La regla que separa reparar de avisar ───────────────────────────────────
 * **Se repara lo que tiene una sola respuesta posible**: si el id apunta a un maestro que existe y
 * el nombre está vacío, el nombre es el del maestro. No hay segunda lectura.
 *
 * **Se avisa de lo que es una decisión**: que un prestador esté oculto teniendo permiso el maestro
 * puede ser deliberado; que una tarifa no tenga clase asignada puede ser que aún no toque. Un
 * script que "arregla" decisiones ajenas hace más daño que el hueco que rellena.
 *
 * ⚠️ **Nunca toca documentos emitidos.** Las líneas de una Orden congelada se listan, no se
 * corrigen: dicen lo que se le mandó al proveedor.
 */
final class CoherenciaCatalogoChecker
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return list<array{clave: string, titulo: string, detalle: string, filas: int, reparable: bool}>
     */
    public function revisar(bool $reparar = false): array
    {
        $hallazgos = [];

        foreach ($this->reparables() as $clave => $r) {
            $filas = (int) $this->db->fetchOne($r['contar']);

            if ($filas === 0) {
                continue;
            }

            if ($reparar) {
                $filas = (int) $this->db->executeStatement($r['reparar']);
            }

            $hallazgos[] = [
                'clave'     => $clave,
                'titulo'    => $r['titulo'],
                'detalle'   => $r['detalle'],
                'filas'     => $filas,
                'reparable' => true,
            ];
        }

        foreach ($this->avisos() as $clave => $a) {
            $filas = (int) $this->db->fetchOne($a['contar']);

            if ($filas === 0) {
                continue;
            }

            $hallazgos[] = [
                'clave'     => $clave,
                'titulo'    => $a['titulo'],
                'detalle'   => $a['detalle'],
                'filas'     => $filas,
                'reparable' => false,
            ];
        }

        return $hallazgos;
    }

    /**
     * Un id apuntando a un maestro vivo y su nombre vacío. Una sola respuesta posible.
     *
     * ⚠️ Los JOIN convierten los ids a mano: los `*_maestro_id` son `varchar(36)` **con guiones** y
     * los `id` de `travel_*` son `binary(16)`. Comparados en crudo dan **cero filas sin error**, y
     * un chequeo que nunca encuentra nada es peor que no tenerlo. Ver `UuidRelacionFilter`.
     *
     * @return array<string, array{titulo: string, detalle: string, contar: string, reparar: string}>
     */
    private function reparables(): array
    {
        return [
            'componente-nombre' => [
                'titulo'  => 'Componentes con maestro pero sin nombre operativo',
                'detalle' => 'La ficha de tráfico y la Orden caen al título público, que a veces es genérico.',
                'contar'  => 'SELECT COUNT(*) FROM cotizacion_cotcomponente k
                                JOIN travel_componente m ON HEX(m.id) = UPPER(REPLACE(k.componente_maestro_id, "-", ""))
                               WHERE (k.nombre_interno_snapshot IS NULL OR k.nombre_interno_snapshot = "") AND m.nombre_interno <> ""',
                'reparar' => 'UPDATE cotizacion_cotcomponente k
                                JOIN travel_componente m ON HEX(m.id) = UPPER(REPLACE(k.componente_maestro_id, "-", ""))
                                 SET k.nombre_interno_snapshot = m.nombre_interno
                               WHERE (k.nombre_interno_snapshot IS NULL OR k.nombre_interno_snapshot = "") AND m.nombre_interno <> ""',
            ],
            'prestador-nombre' => [
                'titulo'  => 'Prestador asignado sin su nombre congelado',
                'detalle' => 'La Orden se queda sin decir quién opera; `pax` no lo nota porque resuelve en vivo.',
                'contar'  => 'SELECT COUNT(*) FROM cotizacion_cotcomponente k
                                JOIN travel_organizacion o ON HEX(o.id) = UPPER(REPLACE(k.prestador_maestro_id, "-", ""))
                               WHERE (k.prestador_nombre_snapshot IS NULL OR k.prestador_nombre_snapshot = "") AND o.nombre_comercial <> ""',
                'reparar' => 'UPDATE cotizacion_cotcomponente k
                                JOIN travel_organizacion o ON HEX(o.id) = UPPER(REPLACE(k.prestador_maestro_id, "-", ""))
                                 SET k.prestador_nombre_snapshot = o.nombre_comercial
                               WHERE (k.prestador_nombre_snapshot IS NULL OR k.prestador_nombre_snapshot = "") AND o.nombre_comercial <> ""',
            ],
            'habitacion-nombre' => [
                'titulo'  => 'Habitación o clase asignada sin su nombre congelado',
                'detalle' => 'Es el caso del Hotel Terra: el huésped veía la habitación y el proveedor no.',
                'contar'  => 'SELECT COUNT(*) FROM cotizacion_cotcomponente k
                                JOIN travel_organizacion_servicio s ON HEX(s.id) = UPPER(REPLACE(k.prestador_servicio_maestro_id, "-", ""))
                               WHERE (k.prestador_servicio_nombre_snapshot IS NULL OR k.prestador_servicio_nombre_snapshot = "") AND s.nombre <> ""',
                'reparar' => 'UPDATE cotizacion_cotcomponente k
                                JOIN travel_organizacion_servicio s ON HEX(s.id) = UPPER(REPLACE(k.prestador_servicio_maestro_id, "-", ""))
                                 SET k.prestador_servicio_nombre_snapshot = s.nombre
                               WHERE (k.prestador_servicio_nombre_snapshot IS NULL OR k.prestador_servicio_nombre_snapshot = "") AND s.nombre <> ""',
            ],
            'segmento-nombre' => [
                'titulo'  => 'Segmentos con maestro pero sin nombre operativo',
                'detalle' => 'La línea pequeña de la ficha se queda sin el tramo.',
                'contar'  => 'SELECT COUNT(*) FROM cotizacion_segmento g
                                JOIN travel_segmento t ON HEX(t.id) = UPPER(REPLACE(g.segmento_maestro_id, "-", ""))
                               WHERE JSON_LENGTH(g.nombre_interno_snapshot) = 0 AND t.nombre_interno <> ""',
                'reparar' => 'UPDATE cotizacion_segmento g
                                JOIN travel_segmento t ON HEX(t.id) = UPPER(REPLACE(g.segmento_maestro_id, "-", ""))
                                 SET g.nombre_interno_snapshot = JSON_ARRAY(JSON_OBJECT("language", "es", "content", t.nombre_interno))
                               WHERE JSON_LENGTH(g.nombre_interno_snapshot) = 0 AND t.nombre_interno <> ""',
            ],
            // Y el ÚLTIMO paso: lo que ya está bien en la cotización pero no bajó a La Biblia.
            // Va después a propósito, para que arrastre lo que los anteriores acaban de arreglar.
            'biblia-desincronizada' => [
                'titulo'  => 'Filas de La Biblia sin datos que la cotización sí tiene',
                'detalle' => 'Se sincroniza sin pasar por la reconciliación: son campos vacíos, no cambios que aprobar.',
                'contar'  => 'SELECT COUNT(*) FROM operacion_servicio o
                                JOIN cotizacion_cotcomponente k ON k.id = o.cotizacion_componente_id
                               WHERE (o.nombre_componente IS NULL AND k.nombre_interno_snapshot <> "")
                                  OR (o.prestador_servicio_nombre IS NULL AND k.prestador_servicio_nombre_snapshot <> "")
                                  OR (o.prestador_nombre IS NULL AND k.prestador_nombre_snapshot <> "")',
                'reparar' => 'UPDATE operacion_servicio o
                                JOIN cotizacion_cotcomponente k ON k.id = o.cotizacion_componente_id
                                 SET o.nombre_componente = COALESCE(o.nombre_componente, NULLIF(k.nombre_interno_snapshot, "")),
                                     o.prestador_servicio_nombre = COALESCE(o.prestador_servicio_nombre, NULLIF(k.prestador_servicio_nombre_snapshot, "")),
                                     o.prestador_nombre = COALESCE(o.prestador_nombre, NULLIF(k.prestador_nombre_snapshot, ""))
                               WHERE (o.nombre_componente IS NULL AND k.nombre_interno_snapshot <> "")
                                  OR (o.prestador_servicio_nombre IS NULL AND k.prestador_servicio_nombre_snapshot <> "")
                                  OR (o.prestador_nombre IS NULL AND k.prestador_nombre_snapshot <> "")',
            ],
        ];
    }

    /**
     * Lo que huele a medias pero admite más de una lectura. Se enseña y no se toca.
     *
     * @return array<string, array{titulo: string, detalle: string, contar: string}>
     */
    private function avisos(): array
    {
        return [
            'servicio-sin-titulo' => [
                'titulo'  => 'Servicios de organización sin título público',
                'detalle' => 'Invisibles para el huésped aunque estén asignados. `app:travel:completar-titulos-servicios`.',
                'contar'  => 'SELECT COUNT(*) FROM travel_organizacion_servicio WHERE JSON_LENGTH(titulo) = 0 AND nombre <> ""',
            ],
            'visibilidad-desfasada' => [
                'titulo'  => 'Prestadores ocultos cuyo maestro ya permite nombrarlos',
                'detalle' => 'Puede ser deliberado: la cotización manda sobre el catálogo. Se arrastra reasignando el prestador.',
                'contar'  => 'SELECT COUNT(*) FROM cotizacion_cotcomponente k
                                JOIN travel_organizacion o ON HEX(o.id) = UPPER(REPLACE(k.prestador_maestro_id, "-", ""))
                               WHERE k.prestador_visible = 0 AND o.visible_para_cliente = 1',
            ],
            'orden-congelada-incompleta' => [
                'titulo'  => 'Líneas de Orden emitida sin datos que La Biblia sí tiene',
                'detalle' => 'NO se reparan: dicen lo que se le mandó al proveedor. Se corrigen reemitiendo.',
                'contar'  => 'SELECT COUNT(*) FROM operacion_orden_servicio_item i
                                JOIN operacion_servicio s ON s.id = UNHEX(REPLACE(i.operacion_servicio_id, "-", ""))
                               WHERE (i.nombre_componente IS NULL AND s.nombre_componente IS NOT NULL)
                                  OR (i.prestador_servicio_nombre IS NULL AND s.prestador_servicio_nombre IS NOT NULL)',
            ],
        ];
    }
}
