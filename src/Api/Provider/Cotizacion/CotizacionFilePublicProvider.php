<?php

declare(strict_types=1);

namespace App\Api\Provider\Cotizacion;

use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Metadata\Operation;
use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use App\Cotizacion\Entity\CotizacionFileGrupo;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use Doctrine\DBAL\ArrayParameterType;
use App\Cotizacion\Enum\PasajeroTipoEnum;
use App\Cotizacion\Service\Publico\IdentidadDelPasajero;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
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
        // vuelo es tuyo también mirando la portada.
        //
        // ⚠️ **Actualizado el 05/09/2026.** Aquí decía «sale UNA persona; la relación entera nunca
        // se expone». Lo segundo sigue siendo verdad —el padrón no sale— pero lo primero ya no:
        // desde `companerosDe()` cada subgrupo trae los NOMBRES de quienes están en él. Son los
        // subgrupos de quien pregunta y sólo el nombre; el límite se movió, no desapareció.
        $this->ponerIdentidad($file);

        // ── 1. Resúmenes para la portada: un solo query escalar ──────────────
        $filas = $this->em->createQuery(<<<'DQL'
            SELECT c.propuesta, c.estado, c.publicado, c.numPax, c.titulo, c.resumen, c.idiomaCliente,
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

        // En la portada la única puerta que hay que saltarse es `publicado`: aquí no se identifica
        // a nadie ni se filtra nada. Ver `CotizacionFile::$saltosDeOperador`.
        if ($previsualiza) {
            $hayBorradores = array_filter($filas, static fn (array $f): bool => ($f['publicado'] ?? true) !== true);
            $file->setSaltosDeOperador($hayBorradores !== [] ? ['sin_publicar'] : []);
        }

        $file->setPropuestasParaCliente(array_values(array_map(static function (array $f) use ($previsualiza): array {
            $oculto = (bool) $f['precioOculto'];
            $estado = $f['estado'] instanceof CotizacionEstadoEnum ? $f['estado']->value : $f['estado'];

            return [
                'propuesta'         => $f['propuesta'],
                // ⚠️ **Cuál de ellas está sin publicar, no sólo que hay alguna.** El cartel de
                // arriba avisa del salto; con varias propuestas en la lista, no decir cuál obliga
                // a abrirlas una a una para averiguarlo. Sólo viaja en previsualización: para el
                // cliente esta clave no existe, porque las no publicadas ni siquiera se consultan.
                'sinPublicar'     => $previsualiza ? ($f['publicado'] ?? true) !== true : null,
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
                // ⚠️ Como DÍA (`Y-m-d`), no como instante con desplazamiento.
                //
                // Salía en `DATE_ATOM` y el cliente sólo la enseña como «Válida hasta el 15 de
                // septiembre»: una fecha de pared. Mandar un instante obligaba a `pax` a quedarse
                // con los diez primeros caracteres, y eso acertaba únicamente mientras el
                // desplazamiento que viajara fuese el de casa — con la cotización expirando a las
                // 22:00 y un desplazamiento distinto, el día enseñado sería otro. Ver
                // `dominio/fecha/naive.ts`: un hecho de pared y un instante no se mezclan.
                'fechaExpiracion' => $f['fechaExpiracion'] instanceof \DateTimeInterface
                    ? $f['fechaExpiracion']->format('Y-m-d') : null,
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

            // ⚠️ **Lo que se salta, dicho aquí y no deducido en `pax`.** Son las mismas tres
            // condiciones que se evalúan justo debajo; que las vuelva a calcular la vista sería un
            // segundo juez capaz de discrepar del primero, y discreparía en silencio.
            if ($previsualiza) {
                $saltos = [];

                if (!$cotizacion->isPublicado()) {
                    $saltos[] = 'sin_publicar';
                }

                // La puerta del documento y el filtrado por persona son de la OPERATIVA de un
                // grupo, y de nadie más: en una confirmada o una enviada no existen, y anunciarlas
                // ahí era el ruido que enseñaba a no leer el cartel.
                if ($cotizacion->getEstado() === CotizacionEstadoEnum::OPERATIVA
                    && $file->isExigeIdentificacion()
                ) {
                    $saltos[] = 'sin_documento';
                    $saltos[] = 'sin_filtrar';
                }

                $file->setSaltosDeOperador($saltos);
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
                'vuelos' => $this->tramosDe($grupo),
                'idGrupo' => $grupo->getId()?->toRfc4122(),
            ];
            $gruposDelPasajero[] = $grupo;
        }

        $companeros = $this->companerosDe($gruposDelPasajero, $pasajero);

        foreach ($subgrupos as $i => $sg) {
            $subgrupos[$i]['miembros'] = $companeros[$sg['idGrupo'] ?? ''] ?? [];
            unset($subgrupos[$i]['idGrupo']);
        }

        $file->setMiIdentidad([
            'nombre' => trim($pasajero->getNombre() . ' ' . $pasajero->getApellido()),
            'subgrupos' => $subgrupos,
            'documentos' => $this->documentosDe($file, $pasajero),
            'documentosEnviados' => $this->tiposYaEnviados($file, $pasajero),
        ]);
    }

    /**
     * Los TRAMOS de un subgrupo aéreo: número, ruta y horas.
     *
     * 🔥 **El operador ve esto en el manifiesto y el pasajero no lo veía.** «Copa Airlines ·
     * BNZXNE · 8 personas» dice con quién vuela y con qué localizador, pero no **cuándo ni desde
     * dónde** — que es lo que se busca la noche antes. Y el itinerario del viaje tampoco vale
     * aquí: el vuelo es de SU subgrupo, no del grupo entero, y por eso está en «Lo tuyo».
     *
     * ⚠️ Fechas y horas **tal cual**, sin formatear: la app habla siete idiomas y quien sabe en
     * cuál se está leyendo es el front.
     *
     * ⚠️ Sólo tiene sentido en el eje de reserva aérea; en los demás sale vacío y el front no
     * pinta nada. No se pregunta por el eje aquí porque la relación ya lo dice: un subgrupo de
     * habitación no tiene vuelos.
     *
     * @return list<array{numero: string|null, origen: string|null, destino: string|null, aerolinea: string|null, salida: string|null, llegada: string|null}>
     */
    private function tramosDe(CotizacionFileGrupo $grupo): array
    {
        $tramos = [];

        foreach ($grupo->getVuelos() as $vuelo) {
            $tramos[] = [
                'numero' => $vuelo->getNumero(),
                'origen' => $vuelo->getOrigen(),
                'destino' => $vuelo->getDestino(),
                'aerolinea' => $vuelo->getAerolinea(),
                'salida' => $vuelo->getSalida()?->format('c'),
                'llegada' => $vuelo->getLlegada()?->format('c'),
            ];
        }

        return $tramos;
    }

    /**
     * Los adjuntos que son **suyos**: su tarjeta de embarque de cada tramo.
     *
     * ── Por qué no salen por la lista de la portada ─────────────────────────
     * 🔥 `getDocumentosParaCliente()` filtra por tipo, **no por persona**. Con ocho tramos y 133
     * pasajeros eso son ~542 entradas en la portada, y cualquiera que abra el expediente con el
     * localizador las vería todas. El fichero en sí está protegido —{@see ArchivoPrivadoController}
     * comprueba de quién es y devuelve 404—, pero la LISTA seguiría contando quién vuela qué.
     *
     * Aquí van sólo los suyos, y sólo cuando se ha identificado con documento y fecha de
     * nacimiento.
     *
     * ⚠️ **Con el vuelo delante, no con el nombre del fichero.** El pasajero tiene ocho tarjetas y
     * todas se llaman igual; lo que necesita en la puerta de embarque es «CUZ → LIM, 17 sep», no
     * «boleto». La fecha la formatea el front, que sabe en qué idioma se está leyendo.
     *
     * 🔥 **Y viaja como `Y-m-d`, no como ISO completo.** `salida` es hora LOCAL de Lima; mandada
     * con `format('c')` sale con `-05:00`, y el front la pinta en UTC para que una fecha sin hora
     * no se corra. Las dos cosas juntas empujan un vuelo de las 23:50 al día siguiente. El resto
     * de fechas de esa vista ya recortaban a diez caracteres por lo mismo; ésta lo hace aquí, que
     * es donde se sabe que es una fecha y no un instante.
     *
     * ⚠️ **Ordenados por fecha de vuelo.** Es el orden en que los va a usar, y el que hace que el
     * de mañana esté arriba.
     *
     * @return list<array{id: string, nombre: array<int, array<string, string|null>>|null, tipo: ?string, numero: ?string, origen: ?string, destino: ?string, fecha: ?string}>
     */
    private function documentosDe(CotizacionFile $file, CotizacionFilepasajero $pasajero): array
    {
        $suyos = [];

        foreach ($file->getFilearchivos() as $archivo) {
            $mio = $archivo->getPasajero()?->getId()?->equals($pasajero->getId() ?? $archivo->getId()) === true;

            // ⚠️ `esDevolvibleAlPasajero()` también aquí: el escaneo de su propio pasaporte es
            // suyo y aun así no se le devuelve. Si sólo lo comprobara el controlador del fichero,
            // esta lista lo anunciaría y el enlace daría 404 — peor que no enseñarlo.
            if (!$mio || !$archivo->esDevolvibleAlPasajero() || ($archivo->getImageName() ?? '') === '') {
                continue;
            }

            $vuelo = $archivo->getVuelo();
            $fecha = $vuelo?->getSalida() ?? $vuelo?->getFecha();

            $suyos[] = [
                'id' => (string) $archivo->getId(),
                'nombre' => $archivo->getNombre(),
                'tipo' => $archivo->getTipoArchivo()?->value,
                'numero' => $vuelo?->getNumero(),
                'origen' => $vuelo?->getOrigen(),
                'destino' => $vuelo?->getDestino(),
                'fecha' => $fecha?->format('Y-m-d'),
            ];
        }

        usort($suyos, static fn (array $a, array $b): int => ($a['fecha'] ?? '') <=> ($b['fecha'] ?? ''));

        return $suyos;
    }

    /**
     * Qué escaneos de identidad **ya mandó**, sólo el tipo.
     *
     * 🔥 **Sin esto la pantalla se olvida.** El pasajero sube su pasaporte, cierra, vuelve al día
     * siguiente y los tres botones dicen «SUBIR» otra vez: parece que no llegó. O lo manda de
     * nuevo —trabajo repetido para él y para el operador— o da por hecho que el sistema no
     * funciona y deja de intentarlo. Lo segundo no se descubre nunca, porque nadie escribe para
     * decir que se rindió.
     *
     * ⚠️ **Sólo el tipo: ni id, ni url, ni nombre de fichero.** Un escaneo de identidad no se le
     * devuelve ni a su dueño ({@see ArchivoTipoEnum::esDevolvibleAlPasajero()}); esto contesta
     * «recibido» sin darle nada con lo que abrirlo. Por eso no cabe en `documentos`, que sí lleva
     * enlaces.
     *
     * @return list<string>
     */
    private function tiposYaEnviados(CotizacionFile $file, CotizacionFilepasajero $pasajero): array
    {
        $tipos = [];

        foreach ($file->getFilearchivos() as $archivo) {
            $tipo = $archivo->getTipoArchivo();
            $mio = $archivo->getPasajero()?->getId()?->equals($pasajero->getId() ?? $archivo->getId()) === true;

            if (!$mio || $tipo === null || !$tipo->loSubeElPasajero()) {
                continue;
            }

            $tipos[$tipo->value] = true;
        }

        return array_keys($tipos);
    }

    /** Minúsculas y sin tildes: la clave con la que se ORDENA, nunca la que se enseña. */
    private static function aplanar(string $texto): string
    {
        return mb_strtolower(strtr(trim($texto), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
        ]));
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
     * ⚠️ **Quien pregunta NUNCA se filtra a sí mismo.** Un invitado —gratuidad de la agencia— no
     * sale en las listas de los demás, y eso está bien; pero si es él quien mira, verse a sí mismo
     * fuera de su propia habitación es justo lo que la regla quería evitar: «una lista de la que
     * faltas invita a preguntar». Se le esconde de los otros, no de sí mismo.
     *
     * ⚠️ **`NO_PARTICIPA` tampoco es compañero de nadie.** `esExpuesto()` lo da por visible —existe
     * en el manifiesto, hay que poder verlo— pero no viaja: enseñarlo como tu compañero de
     * habitación es decirte que duermes con alguien que no va. Si el padrón le dejó la celda
     * puesta al caerse, aquí no cuenta.
     *
     * @param list<CotizacionFileGrupo> $grupos
     *
     * @return array<string, list<array{nombre: string, rol: string|null}>> id del grupo =>
     *         su gente con el rol que se enseña, el responsable primero
     */
    private function companerosDe(array $grupos, CotizacionFilepasajero $quienPregunta): array
    {
        if ($grupos === []) {
            return [];
        }

        /** @var list<array{grupo: string, pasajero: string, nombre: string|null, apellido: string|null, tipo: PasajeroTipoEnum|null}> $filas */
        $filas = $this->em->createQuery(
            <<<'DQL'
            SELECT g.id AS grupo, p.id AS pasajero, p.nombre AS nombre, p.apellido AS apellido, p.tipo AS tipo
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

        /** @var array<string, list<array{orden: int, etiqueta: string, nombre: string, rol: string|null}>> $porGrupo */
        $porGrupo = [];

        $suyo = $quienPregunta->getId()?->toRfc4122();

        foreach ($filas as $f) {
            $tipo = $f['tipo'];
            $esElMismo = $suyo !== null && (string) $f['pasajero'] === $suyo;

            // El invitado no existe aquí —ver `esExpuesto()`— y el que no viaja tampoco es
            // compañero de nadie. Salvo que sea quien pregunta: a uno mismo no se le esconde.
            //
            // ⚠️ **Sin rol NO es lo mismo que excluido, y esto los excluía.** `desdeTexto()`
            // devuelve `null` cuando la hoja trae un rol que no está en la plantilla, así que un
            // `tipo` vacío es un fallo de clasificación, no una decisión: son pasajeros del grupo
            // con nombre y apellido —hoy 2 de 135 en producción— que desaparecían de «mis grupos»
            // sin que nadie pudiera notarlo, porque no se echa de menos a quien no sabías que
            // estaba. Vale la regla de todo el proyecto: sin clasificar es **sin acotar**, y de
            // más se ve; de menos, nunca.
            if (!$esElMismo && $tipo !== null
                && (!$tipo->esExpuesto() || $tipo === PasajeroTipoEnum::NO_PARTICIPA)) {
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
                // Y además VIAJA, desde el 06/09/2026. El rol ya decidía el orden pero se perdía
                // al serializar, así que en la lista el coordinador salía primero y nada lo decía:
                // el que la lee no sabe que el primero es el primero por algo. Es el mismo dato,
                // enseñado en vez de sólo usado.
                //
                // ⚠️ Sólo estos dos. `participante` y `acompanante` no son un rol que le importe a
                // nadie del grupo, y pintarlos convertiría la lista en un organigrama.
                'rol' => match ($tipo) {
                    PasajeroTipoEnum::COORDINADOR => 'coordinador',
                    PasajeroTipoEnum::SUPERVISOR => 'supervisor',
                    default => null,
                },
                // Se ordena por APELLIDO, que es como se lee una lista de pasajeros.
                //
                // ⚠️ **Sin tildes ni eñes en la CLAVE.** `strcoll()` usa el locale del proceso, y
                // el de php-fpm es `C`: con él «Ñuñez» y «Ávila» se van al final de la lista, detrás
                // de la Z. Se compara una versión plana; lo que se enseña sigue llevando su tilde.
                'etiqueta' => self::aplanar(($f['apellido'] ?? '') . ' ' . ($f['nombre'] ?? '')),
                'nombre' => $nombre,
            ];
        }

        $salida = [];

        foreach ($porGrupo as $clave => $gente) {
            usort($gente, static fn (array $a, array $b): int => $a['orden'] <=> $b['orden']
                ?: strcmp($a['etiqueta'], $b['etiqueta']));

            $salida[$clave] = array_map(
                static fn (array $x): array => ['nombre' => $x['nombre'], 'rol' => $x['rol']],
                $gente,
            );
        }

        return $salida;
    }
}