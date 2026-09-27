<?php

declare(strict_types=1);

namespace App\Pms\Service\Agent;

use App\Agent\Access\RestriccionCanal;
use App\Pms\Entity\PmsUnidad;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Qué enlace se le da a alguien para ver una casita, según por dónde escribe.
 *
 * | Escribe desde | Enlace |
 * |---|---|
 * | una reserva o consulta de **Airbnb**, y la casita tiene anuncio | el **anuncio de Airbnb** de esa casita |
 * | una consulta de OTA sin confirmar, sin anuncio de esa plataforma | **ninguno** |
 * | cualquier otro caso (directo, prospecto, reserva confirmada) | la **página pública** de la casita |
 *
 * ⚠️ **La segunda fila es la que importa.** En una consulta de OTA sin confirmar, un enlace a
 * nuestra web es una invitación a cerrar el trato por fuera, que es lo que la plataforma prohíbe
 * y lo que {@see RestriccionCanal} existe para impedir. Sin anuncio de su plataforma no hay
 * enlace posible: la casita se nombra y el cliente la busca allí. Booking siempre cae aquí, porque
 * allí no hay anuncio por casita.
 *
 * La página pública es la del catálogo, `/{establecimiento}/{unidad}`: la de la casita, NO la
 * guía de una reserva, que va por localizador y enseña lo de esa estancia.
 */
final readonly class EnlaceDeCasita
{
    private const string PLATAFORMA_AIRBNB = 'airbnb';

    public function __construct(
        #[Autowire('%pax_host_url%')]
        private string $hostPax,
    ) {}

    /**
     * @param string|null $plataforma El canal de la reserva desde la que se escribe
     *                                (`airbnb`, `booking`, `directo`…), o null sin reserva.
     */
    public function para(PmsUnidad $unidad, ?string $plataforma, RestriccionCanal $restriccion): ?string
    {
        if ($plataforma === self::PLATAFORMA_AIRBNB && $unidad->getUrlAnuncioAirbnb() !== null) {
            return $unidad->getUrlAnuncioAirbnb();
        }

        if ($restriccion->restringe()) {
            return null;
        }

        return $this->paginaPublica($unidad);
    }

    private function paginaPublica(PmsUnidad $unidad): ?string
    {
        $establecimiento = $unidad->getEstablecimiento()?->getSlug();
        $casita = $unidad->getSlug();

        if ($this->hostPax === '' || $establecimiento === null || $establecimiento === '' || $casita === null || $casita === '') {
            return null;
        }

        return sprintf('%s/%s/%s', rtrim($this->hostPax, '/'), $establecimiento, $casita);
    }
}
