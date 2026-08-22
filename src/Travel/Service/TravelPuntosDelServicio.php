<?php

declare(strict_types=1);

namespace App\Travel\Service;

use App\Travel\Entity\TravelItinerario;
use App\Travel\Entity\TravelItinerarioSegmentoRel;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Enum\PuntoModoEnum;
use App\Travel\Enum\PuntosDeServicio;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * ¿Dónde recojo y dónde dejo? — resuelto desde el catálogo, para un servicio concreto.
 *
 * Es la pregunta que hace todo proveedor al recibir una orden y que hasta ahora se contestaba a
 * mano. Aquí se contesta con lo que ya está modelado, sin campos nuevos por componente.
 *
 * ## Las dos reglas
 *
 * ```
 * servicio que ABARCA el día        inicio = 1.er segmento de (plantilla, día)
 * (horaServicioCompleto = true)     fin    = último segmento de (plantilla, día)
 *
 * cualquier otro                    inicio y fin = su propio segmento
 * ```
 *
 * La primera es la que resuelve el caso que parecía imposible: un pool del Valle Sagrado cuelga
 * —por comodidad de edición— del segmento de recojo, así que su segmento dice dónde empieza pero
 * no dónde acaba. Lo que sí sabe dónde acaba es el ÚLTIMO segmento de esa plantilla y día, y
 * `horaServicioCompleto` ya identifica, con unicidad garantizada por
 * {@see \App\Travel\EventListener\TravelSegmentoComponentePromocionUnicaListener}, cuál es el
 * servicio dueño del día. Se reutiliza esa clave en vez de inventar otra.
 *
 * ⚠️ **La variante de una plantilla no es una excepción, es otro segmento.** «Full Day Valle
 * Sagrado Tradicional» termina en «Retorno al centro de Cusco» y su versión VIP en «Descanso en
 * el Valle Sagrado»: difieren justo en el segmento 7, y por eso salen distintas sin que haya que
 * modelar overrides. Fue la razón de poner los puntos en el segmento y no en el componente.
 *
 * ⚠️ **Lo que este servicio NO hace es resolver el hotel.** Cuando un extremo es
 * {@see \App\Travel\Enum\PuntoModoEnum::ALOJAMIENTO}, aquí se devuelve el modo y nada más: cuál
 * hotel depende del pasajero y de la noche, y eso lo sabe la reserva, no el catálogo. Mezclarlo
 * obligaría a este servicio a conocer el expediente, que es justo lo que mantiene el catálogo
 * reutilizable.
 */
final readonly class TravelPuntosDelServicio
{
    public function __construct(private EntityManagerInterface $em) {}

    public function para(TravelSegmentoComponente $sc): PuntosResueltos
    {
        $tipo = $sc->getComponente()?->getTipo();

        if ($tipo === null) {
            return PuntosResueltos::noAplica();
        }

        $puntos = $tipo->puntosDeServicio();

        if ($puntos === PuntosDeServicio::NINGUNO) {
            return PuntosResueltos::noAplica();
        }

        $propio = $sc->getSegmento();

        // ── ¿Abarca el día? Entonces sus extremos son los del día, no los de su segmento ──
        $abarca = $sc->isHoraServicioCompleto() && $sc->getItinerarioContexto() !== null;

        $segmentoInicio = $propio;
        $segmentoFin = $propio;

        if ($abarca) {
            /** @var TravelItinerario $itinerario */
            $itinerario = $sc->getItinerarioContexto();
            $extremos = $this->extremosDelDia($itinerario, $this->diaDe($sc, $itinerario));

            // ⚠️ **Es un OVERRIDE, no un aplastamiento.** El extremo del día manda **si declara
            // algo**; si no, se cae al segmento del que cuelga el componente.
            //
            // La primera versión cogía el primero y el último del día sin mirar, y eso borraba
            // información: un pool colgado de un segmento que SÍ dice dónde recoge, dentro de un
            // día cuyo primer segmento no dice nada, se quedaba sin punto de recojo. No se nota
            // mientras todos los abarcadores cuelguen del primer segmento de su día —los dos
            // caminos coinciden—, y deja de ser cierto en cuanto se cuelgue uno de un segmento
            // intermedio, que es lo que el modelo permite.
            //
            // Y en este orden y no al revés: si el día declara un extremo, ése es el bueno. Es lo
            // que hace que «Retorno al centro de Cusco» mande sobre el segmento de recojo.
            //
            // Sin relaciones cargadas —una plantilla a medio montar— `$extremos` viene a nulos y
            // se cae también al propio: peor información, pero información.
            $segmentoInicio = $extremos[0]?->getInicioModo()->esDeclarado() === true ? $extremos[0] : $propio;
            $segmentoFin = $extremos[1]?->getFinModo()->esDeclarado() === true ? $extremos[1] : $propio;
        }

        return new PuntosResueltos(
            inicioModo: $segmentoInicio?->getInicioModo() ?? PuntoModoEnum::SIN_DEFINIR,
            inicioPunto: $segmentoInicio?->getInicioPunto(),
            finModo: $segmentoFin?->getFinModo() ?? PuntoModoEnum::SIN_DEFINIR,
            finPunto: $segmentoFin?->getFinPunto(),
            aplica: true,
            tieneFin: $puntos->programaFin(),
        );
    }


    /**
     * Qué día del itinerario abarca este componente.
     *
     * `$sc->getDia()` es lo que dice el pivote, y `null` ahí significa «todos los días». Para un
     * full-day da igual, pero en una plantilla de varios días **no hay un día que abarcar**: se
     * usa entonces el del segmento del que cuelga, que es la respuesta que da la copia de
     * Cotización — allí no existe el pivote y el día sale siempre del segmento.
     *
     * ⚠️ Antes esta rama tomaba los extremos de la PLANTILLA ENTERA —primer segmento del día 1,
     * último del día N—, y la hermana tomaba los del día del segmento. Dos respuestas distintas
     * para la misma pregunta, cada una defendible por su lado y ninguna comparable con la otra.
     * Es la clase de divergencia que justifica el precio de tener la regla escrita dos veces sólo
     * si alguien las compara; ésta no la comparaba nadie.
     */
    private function diaDe(TravelSegmentoComponente $sc, TravelItinerario $itinerario): ?int
    {
        if ($sc->getDia() !== null) {
            return $sc->getDia();
        }

        $propio = $sc->getSegmento();

        if ($propio === null) {
            return null;
        }

        $rel = $this->em->getRepository(TravelItinerarioSegmentoRel::class)
            ->createQueryBuilder('r')
            ->andWhere('r.itinerario = :i')->setParameter('i', $itinerario->getId(), UuidType::NAME)
            ->andWhere('r.segmento = :s')->setParameter('s', $propio->getId(), UuidType::NAME)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $rel?->getDia();
    }

    /**
     * El primer y el último segmento de una plantilla en un día.
     *
     * `$dia` ya viene resuelto por {@see self::diaDe()}: `null` sólo si no se pudo averiguar, y
     * entonces se toman los extremos de la plantilla entera como último recurso.
     *
     * @return array{0: ?TravelSegmento, 1: ?TravelSegmento}
     */
    private function extremosDelDia(TravelItinerario $itinerario, ?int $dia): array
    {
        // ⚠️ El UUID va con su TIPO explícito, y `getId()` en vez de la entidad.
        //
        // Sin el tercer argumento, Doctrine liga el valor como cadena contra una columna
        // `BINARY(16)` y la consulta devuelve **cero filas sin error**: la misma búsqueda por
        // `findBy()` devolvía siete. Un servicio que dice «no hay segmentos» cuando los hay se
        // cae en silencio a un peor dato —el segmento propio— y la orden sale con el sitio
        // equivocado, que es exactamente el fallo plausible que esto viene a evitar.
        $qb = $this->em->getRepository(TravelItinerarioSegmentoRel::class)
            ->createQueryBuilder('r')
            ->andWhere('r.itinerario = :itinerario')
            ->setParameter('itinerario', $itinerario->getId(), UuidType::NAME)
            ->addOrderBy('r.dia', 'ASC')
            ->addOrderBy('r.orden', 'ASC');

        if ($dia !== null) {
            $qb->andWhere('r.dia = :dia')->setParameter('dia', $dia);
        }

        /** @var list<TravelItinerarioSegmentoRel> $rels */
        $rels = $qb->getQuery()->getResult();

        if ($rels === []) {
            return [null, null];
        }

        return [
            $rels[0]->getSegmento(),
            $rels[count($rels) - 1]->getSegmento(),
        ];
    }
}
