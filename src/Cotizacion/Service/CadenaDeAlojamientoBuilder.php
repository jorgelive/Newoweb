<?php

declare(strict_types=1);

namespace App\Cotizacion\Service;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Travel\Entity\TravelOrganizacion;
use App\Travel\Enum\ComponenteTipoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Monta la {@see CadenaDeAlojamiento} de una cotización.
 *
 * Separado del objeto puro a propósito: la regla de qué noche cubre qué estancia es lo que no
 * puede fallar, y así se prueba sin base de datos. Aquí sólo se junta el material.
 *
 * ## De dónde sale cada cosa
 *
 * Las estancias son los componentes de tipo `alojamiento` —{@see ComponenteTipoEnum::esAnclaDeUbicacion()}—
 * con su rango de fechas. El hotel es su **prestador**, y la dirección, la de la ficha de esa
 * organización. Si el componente no dice quién presta, la estancia no entra: media respuesta
 * —«duerme en algún sitio»— no le sirve a un conductor.
 *
 * ⚠️ **Se DEDUPLICAN.** Un mismo alojamiento aparece varias veces en la cotización —una fila por
 * habitación o por categoría de pasajero— y las dos dicen lo mismo. Sin deduplicar, la cadena
 * tendría estancias repetidas: no cambia el resultado, pero convierte cualquier informe de
 * cobertura en ruido.
 */
final readonly class CadenaDeAlojamientoBuilder
{
    public function __construct(private EntityManagerInterface $em) {}

    public function para(Cotizacion $cotizacion): CadenaDeAlojamiento
    {
        /** @var array<string, array{desde: \DateTimeImmutable, hasta: \DateTimeImmutable, hotel: string, maestroId: ?string}> $crudas */
        $crudas = [];

        foreach ($cotizacion->getCotservicios() as $servicio) {
            foreach ($servicio->getCotcomponentes() as $comp) {
                $estancia = $this->estanciaCruda($comp);

                if ($estancia === null) {
                    continue;
                }

                // La clave dedupe: mismo hotel y mismas fechas es la misma estancia, aunque sean
                // dos filas por dos habitaciones.
                $clave = $estancia['hotel'] . '|' . $estancia['desde']->format('Y-m-d') . '|' . $estancia['hasta']->format('Y-m-d');
                $crudas[$clave] = $estancia;
            }
        }

        if ($crudas === []) {
            return new CadenaDeAlojamiento([]);
        }

        $direcciones = $this->direccionesDe($crudas);
        $estancias = [];

        foreach ($crudas as $cruda) {
            $estancias[] = new Estancia(
                desde: $cruda['desde'],
                hasta: $cruda['hasta'],
                hotel: $cruda['hotel'],
                direccion: $cruda['maestroId'] === null ? null : ($direcciones[$cruda['maestroId']] ?? null),
            );
        }

        usort($estancias, static fn (Estancia $a, Estancia $b): int => $a->desde <=> $b->desde);

        return new CadenaDeAlojamiento($estancias);
    }

    /**
     * @return array{desde: \DateTimeImmutable, hasta: \DateTimeImmutable, hotel: string, maestroId: ?string}|null
     */
    private function estanciaCruda(CotizacionCotcomponente $comp): ?array
    {
        $tipo = ComponenteTipoEnum::tryFrom((string) $comp->getTipo());

        if ($tipo === null || !$tipo->esAnclaDeUbicacion()) {
            return null;
        }

        // ⚠️ Los alojamientos CANCELADOS o REEMPLAZADOS no cuentan, y es lo primero que hay que
        // preguntar aquí. Aquí no se borra nada: cambiar el hotel A por el B para las mismas
        // noches deja la fila de A viva y marcada, así que sin este filtro la cadena tendría LAS
        // DOS estancias sobre la misma noche y el desempate lo decidiría el orden de inserción.
        // La orden emitida saldría con el hotel viejo —nombre y dirección reales, fechas que
        // cuadran— y no habría forma de verlo leyéndola.
        //
        // ⚠️ `no_incluido` SÍ entra: es el hotel que el pasajero reservó por su cuenta. No se le
        // compra a nadie, pero es donde hay que recogerlo. Ver `CotizacionCotcomponente::estaVivo()`.
        if (!$comp->estaVivo()) {
            return null;
        }

        $desde = $comp->getFechaHoraInicio();
        $hasta = $comp->getFechaHoraFin();
        $hotel = trim((string) $comp->getPrestadorNombreSnapshot());

        // Sin fechas o sin hotel no hay estancia que declarar. Y `hasta <= desde` sería una
        // estancia de cero noches: no cubre ninguna, así que colarla sólo estorbaría.
        if ($desde === null || $hasta === null || $hotel === '' || $hasta <= $desde) {
            return null;
        }

        return [
            'desde' => $desde->setTime(0, 0),
            'hasta' => $hasta->setTime(0, 0),
            'hotel' => $hotel,
            'maestroId' => $comp->getPrestadorMaestroId(),
        ];
    }

    /**
     * Las direcciones de los hoteles, en UNA consulta.
     *
     * @param array<string, array{desde: \DateTimeImmutable, hasta: \DateTimeImmutable, hotel: string, maestroId: ?string}> $crudas
     *
     * @return array<string, ?string>
     */
    private function direccionesDe(array $crudas): array
    {
        $ids = [];

        foreach ($crudas as $cruda) {
            if ($cruda['maestroId'] !== null && Uuid::isValid($cruda['maestroId'])) {
                $ids[$cruda['maestroId']] = Uuid::fromString($cruda['maestroId']);
            }
        }

        if ($ids === []) {
            return [];
        }

        /** @var list<TravelOrganizacion> $organizaciones */
        $organizaciones = $this->em->getRepository(TravelOrganizacion::class)
            ->findBy(['id' => array_values($ids)]);

        $mapa = [];

        foreach ($organizaciones as $organizacion) {
            $mapa[(string) $organizacion->getId()] = $organizacion->getDireccion();
        }

        return $mapa;
    }
}
