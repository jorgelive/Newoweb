<?php

declare(strict_types=1);

namespace App\Api\Provider\Cotizacion;

use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Metadata\Operation;
use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use App\Cotizacion\Entity\CotizacionFileGrupo;
use Doctrine\DBAL\ArrayParameterType;
use App\Cotizacion\Enum\PasajeroTipoEnum;
use App\Cotizacion\Service\Publico\IdentidadDelPasajero;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Provider público del expediente por localizador.
 *
 * - GET .../{localizador}            → PORTADA: File + resúmenes escalares de
 *                                      todas las propuestas públicas vigentes.
 * - GET .../{localizador}/{propuesta}  → DETALLE: lo anterior + la cotización
 *                                      completa de esa versión.
 *
 * Rendimiento: los resúmenes salen de UN query escalar (getArrayResult) y el
 * detalle de UN findOneBy. La colección $file->getCotizaciones() nunca se
 * hidrata, así el expediente puede tener 100+ versiones sin colapsar.
 *
 * @implements ProviderInterface<CotizacionFile>
 */
final class CotizacionFilePublicProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly IdentidadDelPasajero $identidad,
    )
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?CotizacionFile
    {
        $file = $this->em->getRepository(CotizacionFile::class)
            ->findOneBy(['localizador' => $uriVariables['localizador'] ?? null]);

        if (!$file) {
            return null; // 404 uniforme
        }

        $ahora = new \DateTimeImmutable();

        /**
         * ⚠️ **El operador ve lo no publicado; el cliente no.**
         *
         * Es lo que permite previsualizar la vista cliente sin tocar el estado —la queja que
         * originó separar `publicado`: «para verla antes de mandarla tengo que ponerle enviada»—.
         *
         * No hace falta enlace ni token especial: `util` y `pax` comparten dominio de cookie
         * (`FRAMEWORK_SESION_COOKIE_DOMAIN`) y el host de la API está bajo el firewall `main`, que
         * es stateful. La sesión del operador ya llega hasta aquí.
         *
         * ⚠️ La caducidad NO se salta: una propuesta expirada tampoco se previsualiza, porque
         * entonces el operador vería algo que el cliente no puede ver y creería que sí.
         */
        $previsualiza = $this->security->isGranted('ROLE_USER');

        // Se dice en pantalla. Ver {@see CotizacionFile::$vistaDeOperador}: saltarse tres puertas
        // en silencio hacía que el operador creyera que no existían.
        $file->setVistaDeOperador($previsualiza);

        // ── Lo que es TUYO: tu nombre y tus códigos ──────────────────────────
        //
        // Va antes de todo y para cualquier propuesta: si ya te identificaste, tu localizador de
        // vuelo es tuyo también mirando la portada. Sale **una** persona —la de la sesión—; la
        // relación entera nunca se expone, o sería el padrón otra vez.
        $this->ponerIdentidad($file);

        // ── 1. Resúmenes para la portada: un solo query escalar ──────────────
        $filas = $this->em->createQuery(<<<'DQL'
            SELECT c.propuesta, c.estado, c.numPax, c.titulo, c.resumen, c.idiomaCliente,
                   c.monedaGlobal, c.precioOculto, c.totalVenta, c.adelanto,
                   c.tipoCambio, c.fechaExpiracion, MIN(s.fechaInicioAbsoluta) AS fechaInicio,
                   o.totalVenta AS totalVentaOrigen
            FROM App\Cotizacion\Entity\Cotizacion c
            LEFT JOIN c.cotservicios s
            LEFT JOIN c.derivadaDe o
            WHERE c.file = :file
              AND (c.publicado = true OR :previsualiza = true)
              AND (c.fechaExpiracion IS NULL OR c.fechaExpiracion >= :ahora)
            GROUP BY c.id
            ORDER BY c.propuesta DESC
        DQL)
            ->setParameter('file', $file->getId(), UuidType::NAME)
            ->setParameter('previsualiza', $previsualiza)
            ->setParameter('ahora', $ahora)
            ->getArrayResult();

        // Sin ninguna propuesta pública vigente, el expediente no es visible
        if ($filas === []) {
            return null;
        }

        $file->setPropuestasParaCliente(array_values(array_map(static function (array $f): array {
            $oculto = (bool) $f['precioOculto'];
            $estado = $f['estado'] instanceof CotizacionEstadoEnum ? $f['estado']->value : $f['estado'];

            return [
                'propuesta'         => $f['propuesta'],
                'estado'          => $estado,
                'numPax'          => $f['numPax'],
                'titulo'          => $f['titulo'] ?? [],           // I18nContent[] (texto)
                'resumen'         => $f['resumen'] ?? [],          // I18nContent[] (HTML)
                'idiomaCliente'   => $f['idiomaCliente'],
                'monedaGlobal'    => $f['monedaGlobal'],
                'precioOculto'    => $oculto,
                'tipoCambio'      => (float) $f['tipoCambio'],
                // No filtrar montos cuando el precio está oculto
                // ⚠️ **El total de una OPERATIVA sale de la confirmada**, igual que en el
                // detalle (`Cotizacion::origenFinancieroParaCliente()`). Aquí hay que repetirlo
                // porque esta consulta lee columnas, no entidades: el getter que compone no llega
                // a ejecutarse nunca. Dos sitios que dicen lo mismo por dos caminos distintos, y
                // por eso el segundo lleva este aviso.
                //
                // ⚠️ La condición mira el ESTADO, no que `derivadaDe` esté puesto: un histórico
                // también lo tiene —apunta a la viva— y debe enseñar su propio dinero.
                'totalVenta'      => $oculto ? null : (
                    $estado === CotizacionEstadoEnum::OPERATIVA->value && $f['totalVentaOrigen'] !== null
                        ? $f['totalVentaOrigen']
                        : $f['totalVenta']
                ),
                'adelanto'        => $oculto ? null : $f['adelanto'],
                'fechaExpiracion' => $f['fechaExpiracion'] instanceof \DateTimeInterface
                    ? $f['fechaExpiracion']->format(DATE_ATOM) : null,
                'fechaInicio'     => $f['fechaInicio'] instanceof \DateTimeInterface
                    ? $f['fechaInicio']->format('Y-m-d')
                    : ($f['fechaInicio'] ? substr((string) $f['fechaInicio'], 0, 10) : null),
            ];
        }, $filas)));

        // ── 2. Detalle: cargar SOLO la versión solicitada ─────────────────────
        if (isset($uriVariables['propuesta'])) {
            // ⚠️ `publicado` va EN LA CONSULTA, no sólo en la comprobación de abajo.
            //
            // Una propuesta tiene varias filas —sus históricos, la aprobada, la operativa— y todas
            // comparten número. Con el `findOneBy` a secas MySQL podía entregar la que quisiera y
            // esto respondía 404 aunque hubiera una publicada perfectamente viva: un enlace que el
            // cliente ya tenía dejando de funcionar sin que cambiara nada suyo. Ya pasó una vez.
            //
            // Y con `publicado` como eje propio esto además es DETERMINISTA: la invariante dice
            // que hay como máximo una publicada por propuesta, así que no hay nada que desempatar.
            // 🔥 **Se precargan los `grupos` de los componentes en la misma consulta.**
            //
            // `esParaTodos()` pregunta `isEmpty()` sobre una `ManyToMany` perezosa, así que con un
            // `findOneBy` a secas era **una consulta por componente**: medido, 36 componentes → 36
            // consultas, y la operativa del colegio ronda los 150. Multiplicado por los 133 que
            // abren la app, eso es la diferencia entre una página y una caída.
            //
            // Antes no pasaba porque `$grupo` era una `ManyToOne`: un proxy no consulta hasta que
            // se le pide algo, y `=== null` no se lo pide. El plural cambió eso sin avisar.
            $dql = $this->em->createQueryBuilder()
                ->select('c', 'cs', 'cc', 'g')
                ->from(Cotizacion::class, 'c')
                ->leftJoin('c.cotservicios', 'cs')
                ->leftJoin('cs.cotcomponentes', 'cc')
                ->leftJoin('cc.grupos', 'g')
                ->where('c.file = :file')
                ->andWhere('c.propuesta = :propuesta')
                ->setParameter('file', $file->getId(), UuidType::NAME)
                ->setParameter('propuesta', (int) $uriVariables['propuesta']);

            if (!$previsualiza) {
                $dql->andWhere('c.publicado = true');
            }

            // 🔥 **`getResult()[0]` y no `getOneOrNullResult()`, y hace falta una REGLA.**
            //
            // Sin `publicado = true` —o sea, previsualizando— una propuesta tiene **varias filas**:
            // la confirmada y su operativa comparten número a propósito. El `findOneBy` de antes
            // elegía una **en silencio**, y con el orden que quisiera MySQL; al pasar a DQL, lo que
            // era una elección invisible se volvió un `NonUniqueResultException` en producción.
            //
            // El fallo no lo introdujo la consulta: lo destapó. Antes el operador previsualizaba
            // «una de las dos» sin saber cuál, que es peor que un error.
            //
            // La regla: **manda la publicada; si ninguna lo está, la OPERATIVA**, que es la fila
            // viva y lo que el operador está preparando cuando previsualiza. El resto, por número
            // descendente para que al menos sea estable.
            $dql->addOrderBy('c.publicado', 'DESC')
                ->addOrderBy('c.estado', 'ASC');

            /** @var list<Cotizacion> $encontradas */
            $encontradas = $dql->getQuery()->getResult();

            $cotizacion = null;

            foreach ($encontradas as $candidata) {
                // `estado ASC` no pone «operativa» primero por casualidad —es orden alfabético—,
                // así que la preferencia se dice aquí en vez de confiarla al alfabeto.
                if ($cotizacion === null
                    || ($candidata->isPublicado() && !$cotizacion->isPublicado())
                    || (!$cotizacion->isPublicado() && $candidata->getEstado() === CotizacionEstadoEnum::OPERATIVA)
                ) {
                    $cotizacion = $candidata;
                }
            }

            $esVisible = $cotizacion
                && ($previsualiza || $cotizacion->isPublicado())
                && ($cotizacion->getFechaExpiracion() === null || $cotizacion->getFechaExpiracion() >= $ahora);

            if (!$esVisible) {
                return null; // versión inexistente, no pública o expirada
            }

            // ── La única puerta cerrada del expediente ───────────────────────
            //
            // La OPERATIVA de un grupo lleva datos por persona —tu vuelo, tu código, tu horario— y
            // el enlace lo tienen 133 familias. Lo comercial (confirmadas, históricas) se queda
            // abierto: es el mismo documento para todos.
            //
            // ⚠️ **403 y no 404.** Un 404 diría «no existe» y `pax` no tendría cómo saber que debe
            // enseñar el formulario; además le mentiría al usuario sobre algo que sí está ahí. El
            // código viaja en el cuerpo para que el front no tenga que adivinar por el texto.
            //
            // ⚠️ El operador se lo salta —ya se identificó de otra forma, con su sesión—, igual
            // que se salta `publicado`. La caducidad no se salta ninguno de los dos.
            if ($cotizacion->getEstado() === CotizacionEstadoEnum::OPERATIVA
                && !$previsualiza
                && !$this->identidad->estaIdentificado($file)
            ) {
                throw new AccessDeniedHttpException('IDENTIFICACION_REQUERIDA');
            }

            // ── Cada uno ve LO SUYO ─────────────────────────────────────────
            //
            // Sólo en la operativa de un grupo: es la única que lleva componentes acotados a un
            // subgrupo. En lo comercial no hay nada que filtrar —es el mismo documento para
            // todos— y filtrarlo ahí sólo serviría para esconderle a alguien su propio viaje.
            //
            // ⚠️ Lista VACÍA si no pertenece a ningún subgrupo, nunca `null`: `null` significa
            // «no se filtró» y serviría el expediente entero. Es la diferencia entre ver lo
            // general y verlo todo.
            if ($cotizacion->getEstado() === CotizacionEstadoEnum::OPERATIVA && !$previsualiza) {
                $pasajero = $this->identidad->pasajeroIdentificado($file);

                if ($pasajero !== null) {
                    $suyos = [];

                    foreach ($pasajero->getPertenencias() as $pertenencia) {
                        $id = $pertenencia->getGrupo()?->getId()?->toRfc4122();

                        if ($id !== null) {
                            $suyos[] = $id;
                        }
                    }

                    $cotizacion->setFiltroSubgrupos($suyos);
                }
            }

            $file->setCotizacionParaCliente($cotizacion);
        }

        return $file;
    }

    /**
     * Copia al expediente lo que es del pasajero identificado: su nombre y sus códigos.
     *
     * ⚠️ **Se lee del `subeje` y la `clave` además del nombre.** Ninguno identifica solo: `clave`
     * es el valor crudo —`YMFLHB`, `HA13`— y sin el eje delante no dice nada; `subeje` es lo que
     * distingue «Nacional» de «Retorno», que pueden compartir clave porque las aerolíneas
     * reutilizan códigos entre tramos.
     *
     * ⚠️ Y `codigo` sale de la **pertenencia**, no del grupo: es el localizador de esa persona en
     * ese vuelo. El grupo dice en qué reserva va; el código, con qué número.
     */
    private function ponerIdentidad(CotizacionFile $file): void
    {
        $pasajero = $this->identidad->pasajeroIdentificado($file);

        if ($pasajero === null) {
            return;
        }

        $subgrupos = [];
        /** @var list<CotizacionFileGrupo> $gruposDelPasajero */
        $gruposDelPasajero = [];

        foreach ($pasajero->getPertenencias() as $pertenencia) {
            $grupo = $pertenencia->getGrupo();
            $eje = $grupo?->getTipo();

            // ⚠️ **El eje `servicio` se queda fuera.** Es binario —se va a Coco Bongo o no— y no
            // lleva valor: sus 10 filas llenarían la tarjeta de «Lo tuyo» con cosas que ya cuenta
            // el itinerario, y enterrarían las dos que de verdad son personales: el localizador de
            // vuelo y el número de habitación. Lo decide el enum, que ya sabe distinguirlo.
            if ($grupo === null || $eje === null || !$eje->esEjeConValor()) {
                continue;
            }

            $subgrupos[] = [
                'eje' => $eje->value,
                'ejeLabel' => $eje->label(),
                'subeje' => $grupo->getSubeje(),
                'clave' => (string) $grupo->getClave(),
                'nombre' => $grupo->getNombre(),
                'codigo' => $pertenencia->getCodigo(),
                'idGrupo' => $grupo->getId()?->toRfc4122(),
            ];
            $gruposDelPasajero[] = $grupo;
        }

        $companeros = $this->companerosDe($gruposDelPasajero);

        foreach ($subgrupos as $i => $sg) {
            $subgrupos[$i]['miembros'] = $companeros[$sg['idGrupo'] ?? ''] ?? [];
            unset($subgrupos[$i]['idGrupo']);
        }

        $file->setMiIdentidad([
            'nombre' => trim($pasajero->getNombre() . ' ' . $pasajero->getApellido()),
            'subgrupos' => $subgrupos,
        ]);
    }

    /**
     * Con quién comparte cada uno de SUS subgrupos: el compañero de habitación, los de su PNR.
     *
     * ── Por qué se enseña, si el padrón está cerrado ────────────────────────
     * Porque no es el padrón: es **su** habitación y **su** reserva de vuelo. Saber con quién
     * duermes y con quién embarcas es la primera pregunta que hace quien viaja en grupo, y
     * obligarle a escribir para preguntarlo es fricción sin nada al otro lado — a esa gente ya la
     * va a ver en el aeropuerto.
     *
     * ⚠️ **Acotado a los subgrupos de quien pregunta**, y a nada más. Los 133 nombres del
     * expediente siguen sin salir: ver {@see \App\Cotizacion\Entity\CotizacionFile::$miIdentidad}.
     *
     * ⚠️ **Sólo el nombre.** Ni documento, ni fecha de nacimiento, ni el `codigo` del vecino —el
     * localizador ajeno con un apellido abre la reserva de otro en la web de la aerolínea, que es
     * exactamente la fuga que se cerró al pasar `grupos` a plural—.
     *
     * ⚠️ **Los invitados no salen**, y no es un filtro por rol: {@see PasajeroTipoEnum::esExpuesto()}
     * dice que no existen en ninguna vista pública. Son gratuidades de la agencia y un hueco se
     * pregunta igual que un nombre.
     *
     * ⚠️ **UNA consulta para todos los grupos**, no una por grupo. Recorrer `$grupo->getMiembros()`
     * hidrata pertenencias y pasajeros de cada uno: con un vuelo de 44 son 45 consultas por
     * subgrupo, y es la misma trampa que ya costó cara en `OperacionServicio::getPasajeros()`.
     *
     * 🔥 **El id EN BINARIO y con `ArrayParameterType::BINARY`, o no casa nada.** La columna es
     * `BINARY(16)` y este `IN` devolvía **cero filas sin un solo error**: el panel salía vacío,
     * como si nadie compartiera habitación. Medido contra producción, sobre una habitación con dos
     * personas:
     *
     * ```
     * g = :g            (la ENTIDAD)              → 0 filas
     * g.id = :id        (Uuid suelto)             → 0
     * g.id = :id        (Uuid + UuidType::NAME)   → 2  ✅
     * g.id IN (:ids)    (Uuid[] suelto)           → 0
     * g.id IN (:ids)    (binario + BINARY)        → 2  ✅
     * g.clave = :c      (control, sin uuid)       → 2
     * ```
     *
     * ⚠️ **Pasar la entidad tampoco vale**, que es lo que uno probaría primero: Doctrine la
     * convierte al id y ahí se pierde igual. La nota del proyecto avisaba de los `Uuid`; esta fila
     * de la tabla es nueva.
     *
     * @param list<CotizacionFileGrupo> $grupos
     *
     * @return array<string, list<string>> id del grupo => nombres, el responsable primero
     */
    private function companerosDe(array $grupos): array
    {
        if ($grupos === []) {
            return [];
        }

        /** @var list<array{grupo: string, nombre: string|null, apellido: string|null, tipo: PasajeroTipoEnum|null}> $filas */
        $filas = $this->em->createQuery(
            <<<'DQL'
            SELECT g.id AS grupo, p.nombre AS nombre, p.apellido AS apellido, p.tipo AS tipo
              FROM App\Cotizacion\Entity\CotizacionPasajeroGrupo pg
              JOIN pg.grupo g
              JOIN pg.pasajero p
             WHERE g.id IN (:ids)
            DQL,
        )->setParameter(
            'ids',
            array_map(static fn (CotizacionFileGrupo $g): string => (string) $g->getId()?->toBinary(), $grupos),
            ArrayParameterType::BINARY,
        )->getArrayResult();

        /** @var array<string, list<array{orden: int, etiqueta: string, nombre: string}>> $porGrupo */
        $porGrupo = [];

        foreach ($filas as $f) {
            $tipo = $f['tipo'];

            // El invitado no existe aquí. Ver `esExpuesto()`.
            if ($tipo !== null && !$tipo->esExpuesto()) {
                continue;
            }

            $nombre = trim(($f['nombre'] ?? '') . ' ' . ($f['apellido'] ?? ''));

            if ($nombre === '') {
                continue;
            }

            $clave = (string) $f['grupo'];
            $porGrupo[$clave] ??= [];
            $porGrupo[$clave][] = [
                // Quien responde del grupo va primero: es a quien se busca cuando algo pasa.
                'orden' => match ($tipo) {
                    PasajeroTipoEnum::COORDINADOR => 0,
                    PasajeroTipoEnum::SUPERVISOR => 1,
                    default => 2,
                },
                // Se ordena por APELLIDO, que es como se lee una lista de pasajeros.
                'etiqueta' => mb_strtolower(trim(($f['apellido'] ?? '') . ' ' . ($f['nombre'] ?? ''))),
                'nombre' => $nombre,
            ];
        }

        $salida = [];

        foreach ($porGrupo as $clave => $gente) {
            usort($gente, static fn (array $a, array $b): int => $a['orden'] <=> $b['orden']
                ?: strcoll($a['etiqueta'], $b['etiqueta']));

            $salida[$clave] = array_map(static fn (array $x): string => $x['nombre'], $gente);
        }

        return $salida;
    }
}