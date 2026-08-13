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
use App\Pms\Service\Reserva\PmsDisponibilidadService;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * ¿Se puede cambiar el horario de esta estancia, y qué pasaría?
 *
 * 🔗 **Tercer eslabón**, y el que guarda las reglas del dominio. **NO escribe**: evalúa y
 * responde. La escritura irá en una skill aparte con `NivelRiesgo::Escritura`, que exigirá
 * confirmación — y que podrá apoyarse en esta evaluación en vez de repetirla.
 *
 * Separar evaluar de aplicar no es ceremonia: permite que el operador pregunte «¿puedo mover
 * la salida de Carlos?» y reciba un no razonado **sin haber tocado nada**, que es el 80% de
 * las veces que se hace esa pregunta.
 *
 * ### Las tres reglas que codifica
 *
 * 1. **Una reserva de OTA no cambia de fechas desde el PMS.** Beds24 es la fuente de verdad
 *    para fechas en OTA y el push ni siquiera las envía (guard `!$isOta || $isMirror`, §9.4 de
 *    PmsBeds24ReservasSync). Cambiarlas aquí crearía una divergencia silenciosa con el canal.
 * 2. **Retrasar la salida NO es mover `fin`.** Es marcar `salidaTardia`, y entonces
 *    `PmsExtensionEstanciaService` crea un evento de extensión que bloquea esa noche y viaja
 *    al canal como `black`. Mover `fin` fue el primer intento del proyecto y se descartó por
 *    dos motivos documentados (§7.1.b).
 * 3. **Alargar ocupa una noche más**, así que hay que comprobar que la casita esté libre —
 *    o se crea un doble booking.
 */
final readonly class EvaluarCambioHorarioSkill implements SkillInterface, SkillDominioInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private PmsDisponibilidadService $disponibilidad,
    ) {}

    public function nombre(): string
    {
        return 'evaluar_cambio_horario';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Comprueba si se puede cambiar la hora o el día de entrada o salida '
                . 'de una estancia, y explica qué implicaría. NO aplica el cambio: sólo lo '
                . 'evalúa. Necesita el evento_id que devuelve buscar_estancias_de_reserva. '
                . 'Úsala cuando pregunten si un huésped puede entrar antes, salir más tarde o '
                . 'quedarse un día más. Lee siempre el campo aplicable_por_el_agente de la '
                . 'respuesta: si viene false, NO existe ninguna skill que haga ese cambio '
                . 'aunque el negocio lo permita, así que dilo y no llames a '
                . 'aplicar_cambio_horario. Mover días (mover_salida, mover_entrada) nunca es '
                . 'aplicable: se hace a mano en el calendario del panel.',
            parametros: [
                SkillParameter::texto('evento_id', 'Identificador de la estancia, tal cual lo '
                    . 'devolvió buscar_estancias_de_reserva.'),
                SkillParameter::texto('cambio', 'Qué se quiere: "salida_tardia", '
                    . '"entrada_temprana", "mover_salida" o "mover_entrada".'),
                SkillParameter::texto('valor', 'La hora (HH:MM) para los horarios, o la fecha '
                    . '(YYYY-MM-DD) para mover días.', requerido: false),
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
        return [Roles::RESERVAS_WRITE];
    }

    /** Lectura: no toca nada. Aplicar el cambio será otra skill, de Escritura. */
    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Lectura;
    }

    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        $eventoId = trim((string) ($entrada['evento_id'] ?? ''));
        $cambio = strtolower(trim((string) ($entrada['cambio'] ?? '')));

        if (!Uuid::isValid($eventoId)) {
            return SkillResult::error(
                'El evento_id no es válido. Localiza primero la estancia con buscar_estancias_de_reserva.'
            );
        }

        $evento = $this->em->getRepository(PmsEventoCalendario::class)->find($eventoId);
        if ($evento === null) {
            return SkillResult::error('No existe ninguna estancia con ese identificador.');
        }

        $base = [
            'evento_id' => $eventoId,
            'casita' => $evento->getPmsUnidad()?->getNombre() ?? '—',
            'entrada_actual' => $evento->getInicio()?->format('Y-m-d H:i'),
            'salida_actual' => $evento->getFin()?->format('Y-m-d H:i'),
            'es_ota' => $evento->isOta(),
        ];

        return match ($cambio) {
            'salida_tardia' => SkillResult::ok($base + $this->evaluarHorarioExtra($evento, 'salida')),
            'entrada_temprana' => SkillResult::ok($base + $this->evaluarHorarioExtra($evento, 'entrada')),
            'mover_salida', 'mover_entrada' => SkillResult::ok($base + $this->evaluarMoverFecha($evento)),
            default => SkillResult::error(
                'Cambio no reconocido. Usa "salida_tardia", "entrada_temprana", "mover_salida" o "mover_entrada".'
            ),
        };
    }

    /**
     * Salida tardía y entrada temprana SÍ valen en reservas de OTA: no tocan las fechas de la
     * reserva, crean una extensión aparte que se manda al canal como bloqueo.
     *
     * @return array<string, mixed>
     */
    private function evaluarHorarioExtra(PmsEventoCalendario $evento, string $extremo): array
    {
        $yaMarcado = $extremo === 'salida'
            ? $evento->isSalidaTardia()
            : $evento->isEntradaTemprana();

        if ($yaMarcado) {
            return [
                'permitido' => true,
                'aplicable_por_el_agente' => true,
                'ya_aplicado' => true,
                'explicacion' => sprintf(
                    'Esta estancia ya tiene marcada la %s. No hace falta volver a aplicarla.',
                    $extremo === 'salida' ? 'salida tardía' : 'entrada temprana'
                ),
            ];
        }

        return array_filter([
            'permitido' => true,
            'aplicable_por_el_agente' => true,
            'ya_aplicado' => false,
            // 🗓️ La noche que el horario extra va a bloquear. Se evaluaba `permitido: true`
            // sin mirarla, así que el operador decidía a ciegas sobre una noche que podía
            // estar vendida. ALERTA, no veto: la decisión sigue siendo suya.
            'noche_adyacente' => $this->alertaDeOcupacion($evento, $extremo === 'salida'),
            'implica' => sprintf(
                'Se marcará la %s y se creará un evento de extensión que bloquea la noche %s. '
                . 'La extensión viaja al canal como bloqueo, así que la casita deja de venderse '
                . 'esa noche en todos los portales.',
                $extremo === 'salida' ? 'salida tardía' : 'entrada temprana',
                $extremo === 'salida' ? 'del día de salida' : 'anterior a la llegada'
            ),
            'explicacion' => 'Permitido también en reservas de OTA: no cambia las fechas de la '
                . 'reserva, sólo añade la noche que el horario extra inutiliza.',
        ], static fn ($v) => $v !== null);
    }

    /**
     * Cómo está la noche que el horario extra ocuparía.
     *
     * Espejo de `AplicarCambioHorarioSkill::alertaDeOcupacion()` — y por eso los dos textos
     * dicen lo mismo: el operador que evalúa y el que aplica tienen que leer la misma advertencia,
     * o la evaluación deja de servir para decidir.
     *
     * ⚠️ No cambia `permitido`. Que la noche esté vendida es un motivo de peso para no hacerlo,
     * pero puede haber otro para hacerlo igual —la otra reserva entra tarde, se habló con los
     * dos huéspedes—, y eso lo sabe el operador y no esta skill.
     *
     * @return array{fecha: string, libre: bool, ocupa: ?string, aviso: string}|null
     */
    private function alertaDeOcupacion(PmsEventoCalendario $evento, bool $esSalida): ?array
    {
        try {
            $margenes = $this->disponibilidad->margenesDe($evento);
        } catch (\Throwable) {
            return null;
        }

        $margen = $esSalida ? $margenes['despues'] : $margenes['antes'];
        $cual = $esSalida ? 'del día de salida' : 'anterior a la entrada';

        return [
            'fecha' => $margen['fecha'],
            'libre' => $margen['libre'],
            'ocupa' => $margen['ocupa'],
            'aviso' => $margen['libre']
                ? sprintf('✅ La noche %s (%s) está LIBRE: se puede bloquear sin pisar a nadie.', $cual, $margen['fecha'])
                : sprintf(
                    '⛔ La noche %s (%s) YA ESTÁ OCUPADA%s. Si se aplica el horario extra, el '
                    . 'bloqueo se solapará con esa reserva. Está PERMITIDO técnicamente, pero '
                    . 'díselo al operador: la decisión es suya.',
                    $cual,
                    $margen['fecha'],
                    $margen['ocupa'] !== null ? ' por ' . $margen['ocupa'] : ''
                ),
        ];
    }

    /**
     * Mover las fechas es otra cosa: en OTA está vetado.
     *
     * @return array<string, mixed>
     */
    private function evaluarMoverFecha(PmsEventoCalendario $evento): array
    {
        if ($evento->isOta()) {
            return [
                'permitido' => false,
                'motivo' => 'reserva_ota',
                'explicacion' => sprintf(
                    'No se pueden cambiar las fechas de una reserva de %s desde el PMS: el '
                    . 'canal es la fuente de verdad y el cambio no se le enviaría, quedando '
                    . 'los dos sistemas en desacuerdo. Hay que hacerlo en el canal. '
                    . 'Si sólo se trata de una salida más tarde el mismo día, usa "salida_tardia", '
                    . 'que sí está permitido.',
                    $evento->getReserva()?->getChannel()?->getNombre() ?? 'una OTA'
                ),
            ];
        }

        return [
            'permitido' => true,
            'requiere_comprobacion' => true,
            // ⚠️ El negocio lo permite, pero NINGUNA skill lo aplica: aplicar_cambio_horario
            // sólo acepta salida_tardia y entrada_temprana. Sin decirlo aquí, el modelo lee
            // «permitido: true», intenta aplicarlo y choca con un error que no esperaba.
            'aplicable_por_el_agente' => false,
            'explicacion' => 'Es una reserva directa, así que el negocio permite mover las '
                . 'fechas — pero NO puedes hacerlo tú: no hay ninguna skill que mueva fechas. '
                . 'Dile al usuario que se puede hacer y que hay que hacerlo desde el '
                . 'calendario del panel, y no intentes aplicarlo con aplicar_cambio_horario, '
                . 'que sólo marca salidas tardías y entradas tempranas. Quien lo haga en el '
                . 'panel debe comprobar antes que la casita esté libre las noches nuevas: '
                . 'alargar sobre una noche ya vendida crearía un doble booking.',
        ];
    }
}
