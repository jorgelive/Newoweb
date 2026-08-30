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
            // ⚠️ Un alojamiento con hora deja de ser «estadía» en la guía del huésped: no se
            // repite cada noche —sale sólo el primer día— y se coloca a media tarde en vez de
            // cerrar el día. Un hotel de tres noches se ve como una actividad suelta.
            //
            // Se repara marcando `sin_horario`, que es lo que mira `compConHora()` antes que la
            // hora: no hay más que una respuesta posible, porque un alojamiento nunca se vende
            // por hora. La hora de llegada al hotel es otro campo —`horaRecojo` de La Biblia—
            // y no se toca aquí. Ver docs/Cotizaciones.md §6.u.
            'alojamiento-con-hora' => [
                'titulo'  => 'Alojamientos con hora, que romperían la estadía en la guía',
                'detalle' => 'Se marcan como sin horario: un hotel no se vende por hora.',
                'desde'   => 'cotizacion_cotcomponente k JOIN travel_componente m ON HEX(m.id) = UPPER(REPLACE(k.componente_maestro_id, "-", ""))',
                'donde'   => 'm.tipo = "alojamiento" AND k.sin_horario = 0
                            AND k.fecha_hora_inicio IS NOT NULL AND TIME(k.fecha_hora_inicio) <> "00:00:00"',
                'set'     => 'k.sin_horario = 1',
                'deCotizacion' => $delComponente,
            ],
            // ⚠️ Un componente bidireccional que no avisa de que lo es. El proveedor lee el
            // nombre en grande y el del segmento debajo; si sólo lee el de arriba, «Transporte
            // Cusco ↔ Ollanta» no le dice cuál de los dos extremos es el destino de hoy — y una
            // flecha de dos puntas invita a suponer, que es peor que no decir nada: se puede
            // presentar en el extremo equivocado.
            //
            // Se REPARA porque no hay segunda lectura: añadir «(ida o vuelta)» no cambia lo que
            // el componente es, sólo lo dice. Y va sólo en el nombre operativo — el `titulo` es
            // prosa de cliente y ahí el paréntesis no significa nada para quien viaja.
            'bidireccional-sin-marca' => [
                'titulo'  => 'Transportes con «↔» que no avisan de que sirven en los dos sentidos',
                'detalle' => 'Se les añade «(ida o vuelta)»: la flecha sola invita a suponer el destino.',
                'desde'   => 'travel_componente c',
                'donde'   => 'c.tipo = "transporte" AND c.nombre_interno LIKE "%↔%"
                            AND c.nombre_interno NOT LIKE "%(ida o vuelta)%"',
                'set'     => 'c.nombre_interno = CONCAT(TRIM(c.nombre_interno), " (ida o vuelta)")',
                'deCotizacion' => 'HEX(c.id) IN (SELECT UPPER(REPLACE(k.componente_maestro_id, "-", "")) FROM cotizacion_cotcomponente k
                                                   JOIN cotizacion_cotservicio sv ON k.cotservicio_id = sv.id WHERE sv.cotizacion_id = :cot)',
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
            // ── Lo que sostiene el ordenamiento del día ─────────────────────────────────
            //
            // Un servicio se coloca en su día por la hora más temprana de sus componentes; los que
            // no tienen hora desempatan por el `orden` de sus segmentos. Dos supuestos que nadie
            // vigilaba, y de los que depende que el itinerario salga en el orden correcto.
            'orden-empatado' => [
                'titulo'  => 'Párrafos hermanos con el mismo orden en el mismo día',
                'detalle' => 'Cuál sale antes lo decide el azar del guardado. Se arregla renumerando en el constructor.',
                'desde'   => 'cotizacion_segmento g',
                'donde'   => 'EXISTS (SELECT 1 FROM cotizacion_segmento d
                                       WHERE d.cotservicio_id = g.cotservicio_id
                                         AND d.dia = g.dia AND d.orden = g.orden AND d.id > g.id)',
                'set'     => null,
                'deCotizacion' => 'g.cotservicio_id IN (SELECT sv.id FROM cotizacion_cotservicio sv WHERE sv.cotizacion_id = :cot)',
            ],
            // ⚠️ El precio de haber hecho el `orden` manual PISABLE. Se aceptó a sabiendas: sin
            // él no hay control, y la alternativa —que la posición rara sea la única señal— es un
            // aviso accidental, de los que este código ya ha pagado caros. Éste es explícito.
            //
            // Salta cuando un servicio colocado a mano queda ANTES que otro del mismo día que
            // empieza más tarde. No es necesariamente un error —el operador puede tener un motivo
            // que el reloj no sabe— pero es una decisión que merece verse.
            'orden-contradice-hora' => [
                'titulo'  => 'Servicios movidos a mano que contradicen su hora',
                'detalle' => 'Puede ser deliberado. Se revisa, o se devuelve el día a «Automático».',
                'desde'   => 'cotizacion_cotservicio a JOIN cotizacion_cotservicio b
                                ON b.cotizacion_id = a.cotizacion_id AND b.id <> a.id',
                // ⚠️ **Del mismo DÍA**, no sólo de la misma cotización. Sin esta condición el
                // chequeo comparaba un servicio del día 3 con otro del día 4 y gritaba con
                // cualquier itinerario ordenado a mano. Lo destapó la prueba: los dos servicios
                // que eligió al azar eran del 22 y del 23 de julio.
                'donde'   => 'a.orden > 0 AND b.orden > 0 AND a.orden < b.orden
                            AND DATE(a.fecha_inicio_absoluta) = DATE(b.fecha_inicio_absoluta)
                            AND (SELECT MIN(k.fecha_hora_inicio) FROM cotizacion_cotcomponente k
                                  WHERE k.cotservicio_id = a.id AND k.sin_horario = 0 AND k.fecha_hora_inicio IS NOT NULL)
                              > (SELECT MIN(k2.fecha_hora_inicio) FROM cotizacion_cotcomponente k2
                                  WHERE k2.cotservicio_id = b.id AND k2.sin_horario = 0 AND k2.fecha_hora_inicio IS NOT NULL)',
                'set'     => null,
                'deCotizacion' => 'a.cotizacion_id = :cot',
            ],
            // ⚠️ El supuesto es «lo que dura varios días es porque incluye una noche», y NO es
            // «sólo los hoteles duran varios días»: hay siete servicios de dos días —«Two Day
            // Camino inca», «Skylodge con actividades»— y **todos llevan alojamiento dentro**. Es
            // la noche la que los hace durar.
            //
            // Si aparece uno que abarca días SIN noche, o las fechas de sus componentes están mal
            // —lo más probable— o es una forma nueva que el ordenamiento no sabe colocar: un
            // servicio aparece en tantos grupos de día como fechas tengan sus componentes, y el
            // desempate que comparten es uno solo.
            'multidia-sin-noche' => [
                'titulo'  => 'Servicios que abarcan varios días sin incluir alojamiento',
                'detalle' => 'O las fechas están mal, o es una forma que el ordenamiento del día no contempla.',
                'desde'   => 'cotizacion_cotservicio cs',
                'donde'   => '(SELECT COUNT(DISTINCT DATE(k.fecha_hora_inicio)) FROM cotizacion_cotcomponente k
                                WHERE k.cotservicio_id = cs.id AND k.fecha_hora_inicio IS NOT NULL) > 1
                            AND NOT EXISTS (SELECT 1 FROM cotizacion_cotcomponente k2
                                             WHERE k2.cotservicio_id = cs.id AND k2.tipo = "alojamiento")',
                'set'     => null,
                'deCotizacion' => 'cs.cotizacion_id = :cot',
            ],
            // ── La duplicación del catálogo, que vuelve sola ────────────────────────────
            //
            // El 29/08/2026 el transporte tenía 94 componentes y 336 tarifas para 39 líneas de
            // cotización reales, con divergencias que nadie había visto en meses: el mismo «Auto»
            // con capacidad 3 en un sentido y 4 en el otro, las Van del aeropuerto y del terminal
            // intercambiadas, siete «Pool Bickmar» idénticas. Se dejó en 239 tarifas.
            //
            // ⚠️ **Nada impide que vuelva a crecer igual**, y crecerá: cada una de esas copias se
            // creó por una razón razonable en su momento. Lo que faltaba era algo que lo dijera
            // mientras son dos y no veinte. Ninguno se REPARA solo salvo el último: borrar o
            // fundir tarifas toca dinero, y eso no lo decide un chequeo.
            'tarifas-repetidas' => [
                'titulo'  => 'Tarifas repetidas exactas dentro del mismo componente',
                'detalle' => 'Mismo nombre, importe y moneda. Se limpian con `app:travel:limpiar-tarifas-repetidas`.',
                'desde'   => 'travel_tarifa t',
                'donde'   => 'EXISTS (SELECT 1 FROM travel_tarifa d WHERE d.componente_id = t.componente_id
                                        AND d.id <> t.id
                                        AND d.nombre_interno = t.nombre_interno
                                        AND d.monto = t.monto
                                        AND d.moneda_id <=> t.moneda_id
                                        AND d.id > t.id)',
                'set'     => null,
                'deCotizacion' => 'HEX(t.componente_id) IN (SELECT UPPER(REPLACE(k.componente_maestro_id, "-", "")) FROM cotizacion_cotcomponente k
                                                              JOIN cotizacion_cotservicio sv ON k.cotservicio_id = sv.id WHERE sv.cotizacion_id = :cot)',
            ],
            // Un componente por SENTIDO en vez de por ruta. El origen y el destino los guarda el
            // segmento —`travel_componente` no tiene esas columnas—, así que el par sólo duplica
            // el precio, y al duplicarlo lo deja divergir.
            'transporte-direccional' => [
                'titulo'  => 'Transportes con su gemelo en sentido contrario',
                'detalle' => 'Un componente por RUTA, no por sentido: `app:travel:fusionar-transportes-bidireccionales`.',
                'desde'   => 'travel_componente a',
                'donde'   => 'a.tipo = "transporte" AND a.nombre_interno LIKE "Transporte % - %"
                            AND EXISTS (SELECT 1 FROM travel_componente b
                                         WHERE b.tipo = "transporte" AND b.id <> a.id
                                           AND b.nombre_interno = CONCAT("Transporte ",
                                                 TRIM(SUBSTRING_INDEX(SUBSTRING(a.nombre_interno, 12), " - ", -1)), " - ",
                                                 TRIM(SUBSTRING_INDEX(SUBSTRING(a.nombre_interno, 12), " - ", 1))))',
                'set'     => null,
                'deCotizacion' => 'HEX(a.id) IN (SELECT UPPER(REPLACE(k.componente_maestro_id, "-", "")) FROM cotizacion_cotcomponente k
                                                   JOIN cotizacion_cotservicio sv ON k.cotservicio_id = sv.id WHERE sv.cotizacion_id = :cot)',
            ],
            // El sentido metido DENTRO del nombre de la tarifa, en un componente que ya es
            // bidireccional: «Master hasta sector Aranwa» y «Master desde sector Aranwa».
            // ⚠️ Lo que NO se toca es el resto del nombre: en ese mismo componente el SECTOR sí
            // distingue —Urubamba 55, Aranwa 60, Pisac 120— porque es distancia real.
            'tarifas-por-sentido' => [
                'titulo'  => 'Tarifas que sólo se diferencian en «desde» / «hasta»',
                'detalle' => 'En un componente bidireccional no distinguen nada: `app:travel:fusionar-tarifas-por-sentido`.',
                'desde'   => 'travel_tarifa t JOIN travel_componente c ON c.id = t.componente_id',
                'donde'   => 'c.nombre_interno LIKE "%↔%"
                            AND EXISTS (SELECT 1 FROM travel_tarifa d
                                         WHERE d.componente_id = t.componente_id AND d.id <> t.id
                                           AND SUBSTRING_INDEX(REPLACE(REPLACE(d.nombre_interno, " desde ", " "), " hasta ", " "), " · ", 1)
                                             = SUBSTRING_INDEX(REPLACE(REPLACE(t.nombre_interno, " desde ", " "), " hasta ", " "), " · ", 1))',
                'set'     => null,
                'deCotizacion' => 'HEX(t.componente_id) IN (SELECT UPPER(REPLACE(k.componente_maestro_id, "-", "")) FROM cotizacion_cotcomponente k
                                                              JOIN cotizacion_cotservicio sv ON k.cotservicio_id = sv.id WHERE sv.cotizacion_id = :cot)',
            ],
            // ⚠️ Sólo órdenes VIVAS. Una completada o cancelada no se puede reemitir, así que
            // avisar de ella es pedir algo imposible — y un aviso sobre el que no se puede actuar
            // entrena a saltarse el bloque entero, que es como se pierden los que sí importan.
            //
            // La frontera no es nueva: `OperacionOrdenServicio::getEdicionPermitida()` ya llama
            // «cerrada» a completada o cancelada, con el motivo escrito —«a toro pasado no
            // completa nada, sólo reescribe historia»—. Aquí se reusa esa misma línea.
            //
            // Las dos órdenes de prueba del 22/08, canceladas, salían en cada inspección desde
            // que `nombre_componente` nació el 27/08: cuatro líneas de ruido permanente.
            'orden-congelada-incompleta' => [
                'titulo'  => 'Líneas de Orden emitida sin datos que el Centro de Operaciones sí tiene',
                'detalle' => 'NO se reparan: dicen lo que se le mandó al proveedor. Se corrigen reemitiendo.',
                'desde'   => 'operacion_orden_servicio_item i JOIN operacion_servicio s ON s.id = UNHEX(REPLACE(i.operacion_servicio_id, "-", "")) JOIN operacion_orden_servicio os ON os.id = i.orden_id',
                'donde'   => 'os.estado_os NOT IN ("completada", "cancelada")
                            AND ((i.nombre_componente IS NULL AND s.nombre_componente IS NOT NULL)
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
