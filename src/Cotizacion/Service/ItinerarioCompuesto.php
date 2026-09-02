<?php

declare(strict_types=1);

namespace App\Cotizacion\Service;

use App\Cotizacion\Dominio\ComponerItinerario;
use App\Cotizacion\Entity\Cotizacion;
use App\Dominio\EjecutorDeDominio;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * El itinerario de una cotización, compuesto por la MISMA regla que ejecuta el navegador.
 *
 * ── Por qué existe esta clase y no una llamada suelta ───────────────────────
 * Es el sitio donde se decide **qué se le manda** al cálculo, y eso es conocimiento de dominio:
 * el ejecutor no sabe qué es una cotización, y el módulo no sabe de dónde salen los datos. Aquí
 * viven las dos mitades de esa frontera —`PHP reúne los hechos, el módulo decide` — y por eso
 * esta clase está en `src/Cotizacion/` y no en `src/Dominio/`.
 *
 * ⚠️ **Se serializa con los grupos PÚBLICOS** (`pax_cotizacion:read`), no con los del operador.
 * El módulo declara los doce campos que lee y la forma pública los tiene todos; mandar la del
 * operador sería darle campos que no necesita —incluidos los que la API decide no enseñar al
 * cliente— sin ninguna ventaja.
 *
 * ── Lo que este servicio DEMUESTRA ──────────────────────────────────────────
 * El servicio en PHP que se borró el 02/09/2026 daba **11 días donde hay 16**: se había traído a
 * mano tres reglas del itinerario y falló en la de las estadías, que reparte un alojamiento por
 * cada noche. Este no puede fallar en eso, porque no reimplementa nada.
 */
final readonly class ItinerarioCompuesto
{
    public function __construct(
        private EjecutorDeDominio $ejecutor,
        private ComponerItinerario $operacion,
        private NormalizerInterface $normalizer,
    ) {
    }

    /**
     * Los días de una cotización, ya ordenados.
     *
     * @return list<array{fecha: string, numeroDia: int, bloques: list<array<string, mixed>>}>
     *
     * @throws \App\Dominio\Excepcion\DominioNoDisponible
     */
    public function dias(Cotizacion $cotizacion): array
    {
        /** @var array<string, mixed> $plano */
        $plano = $this->normalizer->normalize($cotizacion, null, ['groups' => ['pax_cotizacion:read']]);

        /** @var list<array{fecha: string, numeroDia: int, bloques: list<array<string, mixed>>}> $dias */
        $dias = $this->ejecutor->ejecutarUna($this->operacion, $plano);

        return $dias;
    }

    /**
     * Lo mismo para varias cotizaciones, en **una sola invocación**.
     *
     * ⚠️ No es una optimización prematura: existe para que quien tenga que componer N itinerarios
     * —un envío por lotes, un runner— no escriba un bucle de invocaciones. Medido: una cotización
     * tarda 121 ms y tres tardan 122. El coste es el arranque de Node, no el cálculo.
     *
     * @param list<Cotizacion> $cotizaciones
     *
     * @return list<list<array<string, mixed>>> Una lista de días por cotización, en el mismo orden.
     */
    public function diasDeVarias(array $cotizaciones): array
    {
        $planos = [];

        foreach ($cotizaciones as $cotizacion) {
            $planos[] = $this->normalizer->normalize($cotizacion, null, ['groups' => ['pax_cotizacion:read']]);
        }

        /** @var list<list<array<string, mixed>>> $salida */
        $salida = $this->ejecutor->ejecutar($this->operacion, $planos);

        return $salida;
    }
}
