<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Pms\Entity\PmsEventoCalendario;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Marca la salida tardía o la entrada temprana de una estancia. **Escribe.**
 *
 * 🔗 Último eslabón de la cadena, y el primero que toca datos. Deliberadamente hace UNA cosa:
 * marcar el flag. No cobra nada — cobrar es {@see RegistrarCargoSkill}, y el modelo encadena
 * las dos si el operador lo pide. Meter el cobro aquí ataría dos decisiones que el negocio
 * toma por separado: hay late check-outs de cortesía.
 *
 * ### Por qué marca un flag y no mueve `fin`
 *
 * `PmsExtensionEstanciaService` reacciona al flag creando un evento de extensión que bloquea
 * la noche y viaja al canal como `black`. Mover `fin` fue el primer intento del proyecto y se
 * descartó por dos motivos (§7.1.b de PmsBeds24ReservasSync): no servía para las OTA —cuyas
 * fechas nunca se envían— y no protegía del propio PMS, que seguía vendiendo la noche.
 *
 * Por eso esto SÍ funciona en reservas de OTA, mientras que mover fechas está prohibido.
 *
 * ### La confirmación no la garantiza el prompt
 *
 * `confirmado` es un parámetro obligatorio. Sin él la skill devuelve la previsualización y
 * **no escribe**. Confiar sólo en que el system prompt diga «pregunta antes de aplicar» deja
 * la puerta a que el modelo se salte el paso; así el freno está en el código.
 */
final readonly class AplicarCambioHorarioSkill implements SkillInterface
{
    public function __construct(
        private EntityManagerInterface $em
    ) {}

    public function nombre(): string
    {
        return 'aplicar_cambio_horario';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Marca la salida tardía o la entrada temprana de una estancia. '
                . 'MODIFICA DATOS: antes de llamarla con confirmado=true, dile al usuario '
                . 'exactamente qué vas a hacer (huésped, casita, qué se marca) y espera su '
                . 'sí. Si la llamas con confirmado=false te devuelve la previsualización sin '
                . 'tocar nada. Necesita el evento_id de buscar_estancias_de_reserva, y '
                . 'conviene comprobar antes con evaluar_cambio_horario que está permitido.',
            parametros: [
                SkillParameter::texto('evento_id', 'Identificador de la estancia.'),
                SkillParameter::texto('cambio', '"salida_tardia" o "entrada_temprana".'),
                SkillParameter::booleano('confirmado', 'true SÓLO después de que el usuario '
                    . 'haya confirmado explícitamente. false para previsualizar.'),
            ],
        );
    }

    public function rolesRequeridos(): array
    {
        return [Roles::RESERVAS_WRITE];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Escritura;
    }

    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        $eventoId = trim((string) ($entrada['evento_id'] ?? ''));
        $cambio = strtolower(trim((string) ($entrada['cambio'] ?? '')));
        $confirmado = filter_var($entrada['confirmado'] ?? false, FILTER_VALIDATE_BOOL);

        if (!Uuid::isValid($eventoId)) {
            return SkillResult::error('El evento_id no es válido.');
        }

        if (!in_array($cambio, ['salida_tardia', 'entrada_temprana'], true)) {
            return SkillResult::error('El cambio debe ser "salida_tardia" o "entrada_temprana".');
        }

        $evento = $this->em->getRepository(PmsEventoCalendario::class)->find($eventoId);
        if ($evento === null) {
            return SkillResult::error('No existe ninguna estancia con ese identificador.');
        }

        $esSalida = $cambio === 'salida_tardia';
        $yaMarcado = $esSalida ? $evento->isSalidaTardia() : $evento->isEntradaTemprana();

        $resumen = [
            'evento_id' => $eventoId,
            'huesped' => trim(
                ($evento->getReserva()?->getNombreCliente() ?? '') . ' ' .
                ($evento->getReserva()?->getApellidoCliente() ?? '')
            ),
            'casita' => $evento->getPmsUnidad()?->getNombre() ?? '—',
            'cambio' => $cambio,
        ];

        if ($yaMarcado) {
            return SkillResult::ok($resumen + [
                'aplicado' => false,
                'motivo' => 'ya_estaba_marcado',
                'mensaje' => 'Esta estancia ya tenía ese horario marcado. No se ha cambiado nada.',
            ]);
        }

        if (!$confirmado) {
            return SkillResult::ok($resumen + [
                'aplicado' => false,
                'motivo' => 'falta_confirmacion',
                'previsualizacion' => sprintf(
                    'Se marcará la %s de %s en %s, lo que bloqueará esa noche también en los '
                    . 'canales de venta. Confírmalo para aplicarlo.',
                    $esSalida ? 'salida tardía' : 'entrada temprana',
                    $resumen['huesped'] !== '' ? $resumen['huesped'] : 'el huésped',
                    $resumen['casita']
                ),
            ]);
        }

        // Al hacer flush, PmsExtensionEstanciaService crea el evento de extensión y el push
        // lo manda al canal. Esta skill no orquesta nada de eso: sólo marca.
        if ($esSalida) {
            $evento->setSalidaTardia(true);
        } else {
            $evento->setEntradaTemprana(true);
        }

        $this->em->flush();

        return SkillResult::ok($resumen + [
            'aplicado' => true,
            'mensaje' => sprintf(
                'Marcada la %s. Se ha creado la extensión que bloquea esa noche.',
                $esSalida ? 'salida tardía' : 'entrada temprana'
            ),
            'siguiente_paso_sugerido' => 'Si corresponde cobrarlo, usa registrar_cargo.',
        ]);
    }
}
