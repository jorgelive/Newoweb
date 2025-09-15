<?php

namespace App\Service;

use Symfony\Contracts\Translation\TranslatorInterface;
use App\Entity\CotizacionCotizacion;
use App\Entity\CotizacionCotservicio;
use App\Entity\CotizacionCotcomponente;

/**
 * Genera la agenda (lista de componentes agendables) para una cotización.
 */
class CotizacionAgenda
{
    private TranslatorInterface $translator;
    private CotizacionItinerario $cotizacionItinerario;

    public function __construct(TranslatorInterface $translator, CotizacionItinerario $cotizacionItinerario)
    {
        $this->translator = $translator;
        $this->cotizacionItinerario = $cotizacionItinerario;
    }

    /**
     * Devuelve la agenda con la misma estructura de claves que usaba la versión original.
     *
     * Estructura devuelta:
     * [
     *   'componentes' => [
     *       [
     *           'tituloItinerario' => string,
     *           'nombre' => string,
     *           'tipoComponente' => string,
     *           'fechahorainicio' => \DateTime,
     *           'fechahorafin' => \DateTime,
     *           'titulo' => string (solo si existen items)
     *       ],
     *       ...
     *   ]
     * ]
     *
     * @param CotizacionCotizacion $cotizacion
     * @return array<string, mixed>
     */
    public function getAgenda(CotizacionCotizacion $cotizacion): array
    {
        // inicializo explícitamente la clave que usa la vista
        $datos = ['componentes' => []];

        if ($cotizacion->getCotservicios()->count() === 0) {
            return $datos;
        }

        /** @var CotizacionCotservicio $servicio */
        foreach ($cotizacion->getCotservicios() as $servicio) {
            // protejo por si la colección contuviera elementos inesperados
            if (! $servicio instanceof CotizacionCotservicio) {
                continue;
            }

            /** @var CotizacionCotcomponente $componente */
            foreach ($servicio->getCotcomponentes() as $componente) {
                if (! $componente instanceof CotizacionCotcomponente) {
                    continue;
                }

                // solo componentes agendables
                $tipo = $componente->getComponente()->getTipocomponente();
                if ($tipo->isAgendable() !== true) {
                    continue;
                }

                $tempArrayComponente = [
                    'tituloItinerario' => $this->cotizacionItinerario->getTituloItinerario(
                        $componente->getFechahorainicio(),
                        $servicio
                    ),
                    'nombre' => $componente->getComponente()->getNombre(),
                    'tipoComponente' => $tipo->getNombre(),
                    'fechahorainicio' => $componente->getFechahorainicio(),
                    'fechahorafin' => $componente->getFechahorafin(),
                ];

                // Recojo títulos de items (si existen) y los guardo exactamente bajo 'titulo'
                $titulos = [];
                if ($componente->getComponente()->getComponenteitems()->count() > 0) {
                    foreach ($componente->getComponente()->getComponenteitems() as $item) {
                        // asumimos que el item tiene getTitulo()
                        if (is_object($item) && method_exists($item, 'getTitulo')) {
                            $titulos[] = $item->getTitulo();
                        }
                    }
                }

                if (! empty($titulos)) {
                    $tempArrayComponente['titulo'] = implode(', ', $titulos);
                }

                // solo agrego el componente si tiene 'titulo' (compatibilidad con la lógica original)
                if (isset($tempArrayComponente['titulo'])) {
                    $datos['componentes'][] = $tempArrayComponente;
                }
            }
        }

        return $datos;
    }
}
