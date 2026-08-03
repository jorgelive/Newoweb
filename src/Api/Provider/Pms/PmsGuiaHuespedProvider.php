<?php

declare(strict_types=1);

namespace App\Api\Provider\Pms;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsGuia;
use App\Pms\Entity\PmsReserva;
use App\Pms\Guia\PmsGuiaAcceso;
use App\Pms\Guia\PmsGuiaArbolFiltro;
use App\Pms\Guia\PmsGuiaContexto;
use App\Pms\Guia\PmsGuiaThrottle;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Guía del huésped, resuelta por LOCALIZADOR de reserva.
 *
 * - GET .../{localizador}          → la única estancia visible de la reserva.
 * - GET .../{localizador}/{unidad} → una estancia concreta, por slug de unidad.
 *
 * Mismo patrón que CotizacionFilePublicProvider: una entrada pública por
 * localizador, `null` como 404 uniforme, y todo el trabajo de recorte hecho
 * aquí para que la entidad no calcule nada.
 *
 * Qué cambia respecto al circuito anterior:
 *
 *  - El identificador que ve el cliente es el localizador que ya recibe por
 *    correo, no el UUID interno del evento de calendario.
 *  - Es UNA petición. Antes el front pedía el contexto a
 *    /pax/client/guiahelper/{uuidEvento}, sacaba de la respuesta el
 *    `unit_uuid` y con eso lanzaba una segunda petición al CMS de la guía.
 *  - El árbol viene podado y con los `{{ placeholders }}` ya resueltos. Lo que
 *    el huésped no puede ver no viaja, ni siquiera enmascarado.
 */
final class PmsGuiaHuespedProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PmsGuiaArbolFiltro $filtro,
        private readonly PmsGuiaThrottle $throttle,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?PmsGuia
    {
        // El localizador ES la credencial, y son 6 caracteres: sin freno, el
        // espacio de búsqueda útil se recorre en minutos. Ver PmsGuiaThrottle.
        $this->throttle->asegurarPresupuesto();

        $reserva = $this->em->getRepository(PmsReserva::class)
            ->findOneBy(['localizador' => $uriVariables['localizador'] ?? null]);

        if (!$reserva) {
            return $this->rechazar();
        }

        // getEventosActivosGuia() ya filtra por estado y por guiaDisabled, y
        // los devuelve en orden cronológico. Pasar por él —en vez de por un
        // find() directo, como hacía GuiaHelperClientController— es lo que
        // impide servir estancias canceladas o excluidas a mano de la guía.
        $eventos = $reserva->getEventosActivosGuia();

        if ([] === $eventos) {
            return $this->rechazar();
        }

        $evento = $this->elegirEvento($eventos, $uriVariables['unidad'] ?? null);

        if (null === $evento) {
            return $this->rechazar(); // slug de unidad que no pertenece a esta reserva
        }

        $unidad = $evento->getPmsUnidad();
        $guia = $unidad?->getGuia();

        if (!$unidad || !$guia || !$guia->isActivo()) {
            return $this->rechazar();
        }

        $acceso = PmsGuiaAcceso::paraEvento($evento);
        $contexto = PmsGuiaContexto::construir($unidad, $evento);

        return $guia
            ->setSeccionesParaCliente($this->filtro->podar($guia, $acceso, $contexto))
            ->setContextoParaCliente($contexto->valores)
            // Las credenciales del WiFi solo salen del servidor con la ventana
            // abierta. Cerrada, el array va vacío: no hay contraseña que
            // enmascarar porque no se envía.
            ->setRedesWifiParaCliente($acceso->estaAbierto() ? $contexto->redesWifi : [])
            ->setAccesoParaCliente($acceso);
    }

    /**
     * 404 uniforme + consumo de presupuesto.
     *
     * TODOS los caminos de rechazo pasan por aquí, no solo "la reserva no
     * existe". Si un localizador válido pero sin estancias visibles no
     * consumiera presupuesto, esa diferencia de comportamiento sería un
     * oráculo: el atacante distinguiría los localizadores reales de los
     * inventados por el mero hecho de que unos le cuestan cupo y otros no.
     */
    private function rechazar(): null
    {
        $this->throttle->registrarFallo();

        return null;
    }

    /**
     * Elige la estancia de la reserva sobre la que se abre la guía.
     *
     * Sin slug en la URL se toma la primera —cronológicamente, que es la que
     * getEventosActivosGuia() deja delante—, de forma que el enlace corto
     * `/g/XJ922M` siempre lleve a algún sitio útil. El front decide si además
     * ofrece el selector: `PmsReserva` sigue listando todas las estancias.
     *
     * @param array<int, PmsEventoCalendario> $eventos
     */
    private function elegirEvento(array $eventos, ?string $slugUnidad): ?PmsEventoCalendario
    {
        if (null === $slugUnidad) {
            return $eventos[0];
        }

        foreach ($eventos as $evento) {
            if ($evento->getPmsUnidad()?->getSlug() === $slugUnidad) {
                return $evento;
            }
        }

        return null;
    }
}
