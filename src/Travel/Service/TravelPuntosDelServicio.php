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
            $extremos = $this->extremosDelDia($itinerario, $sc->getDia());

            // Sin relaciones cargadas —una plantilla a medio montar— se cae al segmento propio.
            // Es peor información, pero es información: devolver nada dejaría la orden en blanco
            // justo en el servicio más visible del día.
        // ⚠️ **Es un OVERRIDE, no un aplastamiento.** El extremo del día manda **si declara
        // algo**; si no, se cae al segmento del que cuelga el componente.
        //
        // La primera versión cogía el primero y el último del día sin mirar, y eso borraba
        // información: un pool colgado de un segmento que SÍ dice dónde recoge, dentro de un día
        // cuyo primer segmento no dice nada, se quedaba sin punto de recojo. Hoy no se nota
        // porque todos los abarcadores cuelgan del primer segmento de su día —y entonces los dos
        // caminos coinciden—, pero deja de ser cierto en cuanto se cuelgue uno de un segmento
        // intermedio, que es justo lo que permite el modelo.
        //
        // En este orden y no al revés: si el día declara un extremo, ése es el bueno — es lo que
        // hace que «Retorno al centro de Cusco» mande sobre el segmento de recojo.
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
     * El primer y el último segmento de una plantilla en un día.
     *
     * `$dia === null` significa «aplica a todos los días» —así lo trata el listener de
     * unicidad—, y en la práctica son plantillas de un solo día. Se toman entonces los extremos
     * de la plantilla entera, que para un Full Day es lo mismo y para una de varios días es lo
     * único defendible sin inventarse a cuál se refería.
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
