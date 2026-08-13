<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillDominioInterface;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Pms\Service\Agent\PmsFrentes;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsReserva;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Las estancias (eventos de calendario) de una reserva ya identificada.
 *
 * 🔗 **Segundo eslabón de la cadena.** Recibe el `reserva_id` que devolvió
 * {@see BuscarReservaSkill} y baja un nivel: de «la reserva de Carlos» a «qué noches y en qué
 * casita». Una reserva puede tener varias estancias —cambio de casita a mitad, dos unidades a
 * la vez—, así que devolverlas todas es correcto; quien decide sobre cuál actuar es el
 * usuario, a través del modelo.
 *
 * Existe separada de la búsqueda por el mismo motivo que `BuscarReservaSkill` lo está de
 * `ConsultarMiReservaSkill`: cada eslabón hace UNA cosa y se reutiliza en cadenas distintas.
 * Meterlo todo en «modificar la salida de Carlos» daría una skill gigante que no sirve para
 * nada más.
 */
final readonly class BuscarEventoEstanciaSkill implements SkillInterface, SkillDominioInterface
{
    public function __construct(
        private EntityManagerInterface $em
    ) {}

    public function nombre(): string
    {
        return 'buscar_estancias_de_reserva';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Devuelve las estancias (noches en una casita concreta) de una '
                . 'reserva ya identificada, con sus fechas, horas de entrada y salida, y si '
                . 'tiene entrada temprana o salida tardía marcadas. Necesita el reserva_id '
                . 'que devuelve buscar_reserva: úsala DESPUÉS de identificar al huésped, '
                . 'nunca antes. Si devuelve varias, pregunta al usuario sobre cuál actuar.',
            parametros: [
                SkillParameter::texto('reserva_id', 'El identificador de la reserva, tal cual '
                    . 'lo devolvió buscar_reserva o consultar_mi_reserva.'),
            ],
        );
    }

    /**
     * Del negocio de ALOJAMIENTO: habla de reservas, estancias, casitas o su dinero.
     *
     * Recorta el catálogo, no los permisos — ver {@see SkillDominioInterface}.
     *
     * @return list<string>
     */
    public function dominios(): array
    {
        return [PmsFrentes::NEGOCIO];
    }

    public function rolesRequeridos(): array
    {
        return [Roles::RESERVAS_SHOW];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Lectura;
    }

    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        $reservaId = trim((string) ($entrada['reserva_id'] ?? ''));

        if (!Uuid::isValid($reservaId)) {
            return SkillResult::error(
                'El reserva_id no es válido. Identifica primero la reserva con buscar_reserva.'
            );
        }

        $reserva = $this->em->getRepository(PmsReserva::class)->find($reservaId);
        if ($reserva === null) {
            return SkillResult::error('No existe ninguna reserva con ese identificador.');
        }

        $estancias = [];

        foreach ($reserva->getEventosCalendario() as $evento) {
            /** @var PmsEventoCalendario $evento */

            // Las extensiones no son estancias: son la noche que bloquea un horario extra
            // (§7.1.b de PmsBeds24ReservasSync). Se ven en el flag, no como fila propia.
            if ($evento->getEventoOrigen() !== null) {
                continue;
            }

            if ($evento->getEstado()?->getId() === PmsEventoEstado::CODIGO_CANCELADA) {
                continue;
            }

            $estancias[] = [
                'evento_id' => (string) $evento->getId(),
                'casita' => $evento->getPmsUnidad()?->getNombre() ?? '—',
                'entrada' => $evento->getInicio()?->format('Y-m-d H:i'),
                'salida' => $evento->getFin()?->format('Y-m-d H:i'),
                'estado' => $evento->getEstado()?->getId(),
                'es_ota' => $evento->isOta(),
                'canal' => $evento->getReserva()?->getChannel()?->getNombre() ?? 'Directo',
                'entrada_temprana' => $evento->isEntradaTemprana(),
                'salida_tardia' => $evento->isSalidaTardia(),
            ];
        }

        return SkillResult::ok([
            'reserva_id' => $reservaId,
            'huesped' => trim($reserva->getNombreCliente() . ' ' . $reserva->getApellidoCliente()),
            'total' => count($estancias),
            'estancias' => $estancias,
        ]);
    }
}
