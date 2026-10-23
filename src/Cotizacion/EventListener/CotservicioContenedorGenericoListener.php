<?php

declare(strict_types=1);

namespace App\Cotizacion\EventListener;

use App\Cotizacion\Entity\CotizacionCotservicio;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Events;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Le dice a cada servicio de una cotización si su maestro es un contenedor genérico.
 *
 * La marca vive en el catálogo (`TravelServicio::$contenedorGenerico`) y el servicio de la
 * cotización sólo guarda el soft-link `servicioMaestroId`. Se resuelve AQUÍ, al cargar, y no en un
 * normalizador, porque la cotización se serializa por tres caminos —el editor, la guía del cliente
 * y la que PHP manda al cálculo compartido en Node (`ItinerarioCompuesto`)— y los tres tienen que
 * ver lo mismo. Un normalizador cubría sólo el primero que se le enganchara.
 *
 * Una consulta por petición: los servicios marcados son unos pocos de unas decenas, y se guardan
 * en memoria. `reset()` la vacía entre mensajes de un worker, que vive días.
 *
 * `postPersist` cubre el servicio recién creado: lo que devuelve la API tras guardarlo no pasó por
 * `postLoad`.
 */
#[AsEntityListener(event: Events::postLoad, method: 'marcar', entity: CotizacionCotservicio::class)]
#[AsEntityListener(event: Events::postPersist, method: 'marcar', entity: CotizacionCotservicio::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'marcar', entity: CotizacionCotservicio::class)]
final class CotservicioContenedorGenericoListener implements ResetInterface
{
    /** @var array<string, true>|null id del servicio maestro (RFC 4122, minúsculas) → marcado */
    private ?array $marcados = null;

    public function __construct(private readonly Connection $conexion)
    {
    }

    public function marcar(CotizacionCotservicio $servicio): void
    {
        $id = $servicio->getServicioMaestroId();
        $servicio->setContenedorGenerico($id !== null && isset($this->marcados()[strtolower($id)]));
    }

    public function reset(): void
    {
        $this->marcados = null;
    }

    /** @return array<string, true> */
    private function marcados(): array
    {
        if ($this->marcados === null) {
            $this->marcados = [];
            foreach ($this->conexion->fetchFirstColumn('SELECT BIN_TO_UUID(id) FROM travel_servicio WHERE contenedor_generico = 1') as $id) {
                if (\is_string($id)) {
                    $this->marcados[strtolower($id)] = true;
                }
            }
        }

        return $this->marcados;
    }
}
