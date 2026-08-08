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
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsEventoEstadoPago;
use App\Pms\Entity\PmsReserva;
use App\Pms\Guia\PmsGuiaEstanciaResolver;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Confirma una estancia. Sólo eso. **Escribe.**
 *
 * Existe porque «ponle confirmado a Théa» no tenía skill, y el modelo llegaba por el desvío:
 * marcaba `estadoPago = pago-alojamiento`, que confirma **de rebote** por la regla de
 * auto-confirmación. Funcionaba, pero decía que había un pago donde nadie había pagado nada.
 *
 * ### 🔀 Confirmar y cobrar son dos cosas
 *
 * | | Cambia | Abre la guía |
 * |---|---|---|
 * | **Esta skill** | `estado` → confirmada | ❌ NO |
 * | `modificar_reserva` con `estado_pago` | `estadoPago` → y el estado de rebote | ✅ sí |
 *
 * Lo que abre el contenido reservado de la guía es el **estado de pago**
 * ({@see \App\Pms\Guia\PmsGuiaAcceso::paraEvento()} mira `ESTADOS_PAGO_CONFIABLES`), no el
 * estado de la estancia. Confirmar sin más deja al huésped confirmado y sin guía — que suele
 * ser exactamente lo que se quiere hasta que pague, y por eso la respuesta lo dice y ofrece el
 * otro camino en vez de tomarlo por su cuenta.
 *
 * ### ⚠️ Confirmar es la mitad de reversible
 *
 * Si la estancia tiene un pago confiable, **no se puede volver a «pendiente»**: el listener de
 * integridad la sube otra vez a confirmada en el mismo flush
 * ({@see \App\Pms\EventListener\PmsEventoCalendarioIntegrityListener}). Sin pago sí se puede
 * bajar desde el panel. Esta skill no des-confirma: es una operación con más consecuencias que
 * su contraria y no se hace de pasada por chat.
 */
final readonly class ConfirmarEstanciaSkill implements SkillInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private PmsGuiaEstanciaResolver $estancias,
    ) {}

    public function nombre(): string
    {
        return 'confirmar_estancia';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Pasa una estancia a CONFIRMADA. Úsala cuando digan «confírmale la '
                . 'reserva», «ponle confirmado» o «ya está apalabrado». Cambia SÓLO el estado: '
                . 'no registra ningún pago ni marca nada de dinero. IMPORTANTE: confirmar NO le '
                . 'abre al huésped el contenido reservado de la guía —eso lo abre el estado de '
                . 'pago, no el estado—. Si la respuesta trae «sugerencia_pago», ofrécesela al '
                . 'operador como una pregunta aparte DESPUÉS de confirmar, nunca lo hagas por tu '
                . 'cuenta: decir que alguien pagó cuando no ha pagado ensucia la contabilidad. '
                . 'Para marcar el estado de pago está modificar_reserva; para apuntar dinero de '
                . 'verdad, registrar_pago. MODIFICA DATOS: llama primero con confirmado=false.',
            parametros: [
                SkillParameter::texto('reserva_id', 'La reserva, de buscar_reserva.'),
                SkillParameter::texto('casita', 'Sólo si la reserva tiene varias estancias y hay '
                    . 'que confirmar una en concreto.', requerido: false),
                SkillParameter::booleano('confirmado', 'true SÓLO tras la aprobación explícita '
                    . 'del operador. false para previsualizar.'),
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
        $reservaId = trim((string) ($entrada['reserva_id'] ?? ''));

        if (!Uuid::isValid($reservaId)) {
            return SkillResult::error('Necesito el reserva_id. Localízala con buscar_reserva.');
        }

        $reserva = $this->em->getRepository(PmsReserva::class)->find($reservaId);

        if ($reserva === null) {
            return SkillResult::error('No existe ninguna reserva con ese identificador.');
        }

        if ($reserva->isCancelada()) {
            return SkillResult::error(sprintf(
                'La reserva de %s está CANCELADA. Confirmar una estancia cancelada no la revive: '
                . 'si el huésped vuelve, se crea una reserva nueva.',
                $reserva->getNombreApellido() ?? 'ese huésped'
            ));
        }

        $eventos = $reserva->getEventosActivosGuia();

        if ($eventos === []) {
            return SkillResult::error('Esta reserva no tiene ninguna estancia activa que confirmar.');
        }

        $eleccion = $this->estancias->resolver($eventos, trim((string) ($entrada['casita'] ?? '')));

        if ($eleccion['ambiguo']) {
            return SkillResult::ok([
                'reserva_id' => $reservaId,
                'huesped' => $reserva->getNombreApellido(),
                'falta_datos' => ['casita'],
                'casitas' => array_values(array_filter(array_map(
                    static fn (PmsEventoCalendario $e): ?string => $e->getPmsUnidad()?->getNombre(),
                    $eleccion['candidatas']
                ))),
                'pregunta' => 'Esta reserva tiene varias casitas y cada una se confirma por su '
                    . 'cuenta. Pregúntale al operador cuál quiere confirmar —o si son todas— y '
                    . 'vuelve a llamarme con «casita».',
            ]);
        }

        $evento = $eleccion['evento'];

        if ($evento === null) {
            return SkillResult::error('Esa casita no es de esta reserva.');
        }

        $estadoActual = $evento->getEstado()?->getId();

        if ($estadoActual === PmsEventoEstado::CODIGO_CONFIRMADA) {
            return SkillResult::ok([
                'reserva_id' => $reservaId,
                'huesped' => $reserva->getNombreApellido(),
                'casita' => $evento->getPmsUnidad()?->getNombre(),
                'aplicado' => false,
                'motivo' => 'ya_estaba_confirmada',
                'mensaje' => 'Esta estancia ya estaba confirmada. No se ha cambiado nada.',
            ]);
        }

        $pagoActual = $evento->getEstadoPago()?->getId();
        $tienePagoConfiable = in_array($pagoActual, PmsEventoEstadoPago::ESTADOS_PAGO_CONFIABLES, true);

        $resumen = array_filter([
            'reserva_id' => $reservaId,
            'huesped' => $reserva->getNombreApellido(),
            'localizador' => $reserva->getLocalizador(),
            'casita' => $evento->getPmsUnidad()?->getNombre(),
            'entrada' => $evento->getInicio()?->format('Y-m-d'),
            'salida' => $evento->getFin()?->format('Y-m-d'),
            'estado_actual' => $estadoActual,
            'estado_pago_actual' => $pagoActual,
            'es_ota' => $evento->isOta(),
            'canal' => $reserva->getChannel()?->getNombre(),
            // 💡 Se OFRECE, no se hace. Es el desvío por el que el modelo llegaba antes —marcar
            // el pago para confirmar de rebote— y aquí queda como lo que es: una segunda
            // decisión, del operador, sobre dinero.
            'sugerencia_pago' => $tienePagoConfiable ? null : sprintf(
                'Esta estancia quedará confirmada pero SIN acceso a la guía: lo que abre el '
                . 'contenido reservado es el estado de pago, y ahora está en «%s». Si el huésped '
                . 'paga al llegar, pregúntale al operador —DESPUÉS de confirmar y como cosa '
                . 'aparte— si quiere marcarla como «pago-alojamiento» con modificar_reserva. No '
                . 'lo hagas tú.',
                $pagoActual ?? 'sin estado'
            ),
        ], static fn ($v) => $v !== null);

        if (!filter_var($entrada['confirmado'] ?? false, FILTER_VALIDATE_BOOL)) {
            return SkillResult::ok($resumen + [
                'aplicado' => false,
                'motivo' => 'falta_confirmacion',
                'que_va_a_pasar' => $this->consecuencias($evento, $tienePagoConfiable),
                'pregunta_aprobacion' => sprintf(
                    '¿Confirmo la estancia de %s en %s?',
                    $resumen['huesped'] ?? 'el huésped',
                    $resumen['casita'] ?? 'esa casita'
                ),
            ]);
        }

        $evento->setEstado(
            $this->em->getReference(PmsEventoEstado::class, PmsEventoEstado::CODIGO_CONFIRMADA)
        );

        $this->em->flush();

        return SkillResult::ok($resumen + [
            'aplicado' => true,
            'estado_nuevo' => PmsEventoEstado::CODIGO_CONFIRMADA,
            'que_ha_pasado' => $this->consecuencias($evento, $tienePagoConfiable),
            'mensaje' => sprintf(
                'Confirmada la estancia de %s en %s. NO se ha tocado el estado de pago ni se ha '
                . 'registrado ningún importe.',
                $resumen['huesped'] ?? 'el huésped',
                $resumen['casita'] ?? 'la casita'
            ),
        ]);
    }

    /**
     * Qué cambia de verdad al confirmar.
     *
     * @return list<string>
     */
    private function consecuencias(PmsEventoCalendario $evento, bool $tienePagoConfiable): array
    {
        $lista = [
            sprintf(
                'La estancia pasa de «%s» a CONFIRMADA.',
                $evento->getEstado()?->getId() ?? '?'
            ),
            'NO se registra ningún pago ni se cambia el estado de pago: confirmar y cobrar son '
            . 'cosas distintas.',
        ];

        // Lo que el operador da por hecho y no ocurre. Se dice con el signo cambiado según el
        // caso porque «no se abre la guía» sobre una reserva que ya la tenía abierta sería
        // falso y le haría dudar de si algo se rompió.
        $lista[] = $tienePagoConfiable
            ? 'El huésped ya tenía acceso al contenido reservado de la guía por su estado de '
                . 'pago, y lo mantiene.'
            : 'El huésped NO gana acceso al contenido reservado de la guía: eso lo abre el '
                . 'estado de pago, no el estado de la estancia.';

        if ($evento->isOta()) {
            $lista[] = 'Es una reserva de OTA: el canal es quien manda su estado, así que un '
                . 'pull posterior puede devolverla a como esté allí. Confirma aquí sólo si el '
                . 'cambio ya se hizo en el portal, o si es un apaño temporal.';
        }

        return $lista;
    }
}
