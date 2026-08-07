<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Entity\Maestro\MaestroIdioma;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsEventoEstadoPago;
use App\Pms\Entity\PmsReserva;
use App\Pms\Guia\PmsGuiaEstanciaResolver;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Corrige los datos de una reserva que ya existe: teléfono, correo, idioma, cuántos son y el
 * estado de pago. **Escribe.**
 *
 * Es el hueco que quedaba: se podía crear una reserva y añadirle estancias, pero no arreglarla.
 * Un teléfono mal apuntado, un huésped que avisa de que vienen tres y no dos, o el caso más
 * frecuente de todos —«paga al llegar»— obligaban a entrar al panel.
 *
 * ### 🔑 `pago-alojamiento` no es sólo un dato: abre la guía
 *
 * La cadena completa, que no se ve desde aquí y por eso va enumerada en la previsualización:
 *
 * ```
 * estadoPago = pago-alojamiento          (o parcial, o total)
 *   → PmsEventoCalendario::requiereAutoConfirmacionPorPago()  ⇒ estado = confirmada  (§9.5)
 *   → PmsGuiaAcceso::paraEvento() deja de devolver SinPago
 *   → PmsGuiaAccesoEstado::pagoConfirmado() ⇒ se abre el nivel ClienteConfirmado de la guía
 * ```
 *
 * O sea: marcar que paga en el alojamiento **confirma la estancia y le desbloquea contenido de
 * la guía**. Es lo que se quiere —el huésped que paga al llegar tiene derecho a sus
 * instrucciones—, pero aprobar «cambio el estado de pago» sin saber las otras dos no es aprobar
 * lo que de verdad pasa.
 *
 * ### Lo que NO deja hacer, y por qué
 *
 * - **Cancelar.** Dispara el push de cancelación al canal y toda la cadena de avisos (§12.12).
 *   Se hace en el calendario, mirando lo que se lleva por delante.
 * - **Mover fechas o cambiar de casita.** Es reprogramar, no corregir; en OTA está prohibido.
 * - **El nombre del huésped.** Cambiarlo es casi siempre señal de que se está tocando la
 *   reserva equivocada. Si de verdad hay una errata, se corrige en el panel.
 *
 * ### Un campo por ámbito
 *
 * Teléfono, correo e idioma viven en la RESERVA (son del huésped). Los adultos, los niños y el
 * estado de pago viven en la ESTANCIA, y por eso hay que decir en cuál: en un grupo repartido
 * cada casita tiene su gente y puede tener su propio estado de pago.
 */
final readonly class ModificarReservaSkill implements SkillInterface
{
    /** Los que hablamos: fuera de esto no hay plantillas traducidas. */
    private const array IDIOMAS = ['es', 'en', 'pt', 'fr', 'it', 'de', 'nl'];

    public function __construct(
        private EntityManagerInterface $em,
        private PmsGuiaEstanciaResolver $estancias,
    ) {}

    public function nombre(): string
    {
        return 'modificar_reserva';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Corrige datos de una reserva que ya existe: teléfono, correo, idioma, '
                . 'cuántos adultos y niños, y el estado de pago. Úsala para «apunta su WhatsApp», '
                . '«al final vienen 3», «paga al llegar», «me equivoqué en el correo». NO mueve '
                . 'fechas, NO cambia de casita, NO cancela y NO cambia el nombre: todo eso se '
                . 'hace en el calendario del panel, dilo así si te lo piden. MODIFICA DATOS: '
                . 'llama SIEMPRE primero con confirmado=false y enseña «que_va_a_pasar» al '
                . 'operador. OJO CON EL ESTADO DE PAGO: marcar «pago-alojamiento», '
                . '«pago-parcial» o «pago-total» CONFIRMA la estancia y le ABRE contenido de la '
                . 'guía al huésped; la respuesta te lo dice y tienes que leérselo al operador '
                . 'antes del sí. Si sólo quieres apuntar dinero recibido, usa registrar_pago: '
                . 'esta skill no registra importes, sólo marca el estado. Necesita el reserva_id '
                . 'de buscar_reserva.',
            parametros: [
                SkillParameter::texto('reserva_id', 'La reserva a corregir, de buscar_reserva.'),
                SkillParameter::texto('telefono', 'Teléfono con prefijo. Se guarda normalizado.',
                    requerido: false),
                SkillParameter::texto('telefono2', 'Segundo número: el del acompañante, el chip '
                    . 'local que sacaron aquí, o uno que sí funcione en WhatsApp.', requerido: false),
                SkillParameter::texto('email', 'Correo del huésped.', requerido: false),
                SkillParameter::texto('idioma', 'Idioma en que se le escribe: '
                    . implode(', ', self::IDIOMAS) . '.', requerido: false),
                SkillParameter::entero('adultos', 'Cuántos adultos en la estancia.', requerido: false),
                SkillParameter::entero('ninos', 'Cuántos niños en la estancia.', requerido: false),
                SkillParameter::texto('estado_pago', 'no-pagado, pago-alojamiento (paga al '
                    . 'llegar), pago-parcial o pago-total. Los tres últimos confirman la estancia '
                    . 'y le abren la guía.', requerido: false),
                SkillParameter::texto('casita', 'Sólo si la reserva tiene varias y los adultos, '
                    . 'niños o el estado de pago son de una en concreto.', requerido: false),
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
                'La reserva de %s está CANCELADA. Corregirle datos no la revive y confunde: si '
                . 'el huésped vuelve, crea una reserva nueva.',
                $reserva->getNombreApellido() ?? 'ese huésped'
            ));
        }

        $cambios = [];
        $consecuencias = [];

        $this->camposDelHuesped($entrada, $reserva, $cambios);

        // Adultos, niños y estado de pago son de UNA estancia. Sólo se resuelve cuál si de
        // verdad se pide alguno: preguntar por la casita para corregir un email sería absurdo.
        $tocaLaEstancia = $this->pidenAlgoDeLaEstancia($entrada);
        $evento = null;

        if ($tocaLaEstancia) {
            $eventos = $reserva->getEventosActivosGuia();

            if ($eventos === []) {
                return SkillResult::error('Esta reserva no tiene ninguna estancia activa que corregir.');
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
                    'pregunta' => 'Esta reserva tiene varias casitas y lo que quieres corregir es '
                        . 'de una en concreto (la gente y el estado de pago van por estancia, no '
                        . 'por reserva). Pregúntale al operador de cuál y vuelve a llamarme con '
                        . '«casita».',
                ]);
            }

            $evento = $eleccion['evento'];

            if ($evento === null) {
                return SkillResult::error('Esa casita no es de esta reserva.');
            }

            $error = $this->camposDeLaEstancia($entrada, $evento, $cambios, $consecuencias);

            if ($error !== null) {
                return SkillResult::error($error);
            }
        }

        if ($cambios === []) {
            return SkillResult::error(
                'No me has dicho qué cambiar. Puedo corregir teléfono, correo, idioma, adultos, '
                . 'niños y estado de pago.'
            );
        }

        $resumen = array_filter([
            'reserva_id' => $reservaId,
            'huesped' => $reserva->getNombreApellido(),
            'localizador' => $reserva->getLocalizador(),
            'casita' => $evento?->getPmsUnidad()?->getNombre(),
            'cambios' => $cambios,
        ], static fn ($v) => $v !== null);

        if (!filter_var($entrada['confirmado'] ?? false, FILTER_VALIDATE_BOOL)) {
            // Se revierte lo tocado en memoria: los setters ya se llamaron para poder describir
            // el «antes → después», y sin esto un flush ajeno en la misma petición lo guardaría.
            $this->em->refresh($reserva);

            if ($evento !== null) {
                $this->em->refresh($evento);
            }

            return SkillResult::ok($resumen + array_filter([
                'aplicado' => false,
                'motivo' => 'falta_confirmacion',
                'que_va_a_pasar' => $consecuencias !== [] ? $consecuencias : null,
                'pregunta_aprobacion' => '¿Apruebas el cambio?',
                'previsualizacion' => sprintf(
                    'Se corregirá la reserva de %s (%s): %s.%s',
                    $resumen['huesped'] ?? 'el huésped',
                    $resumen['localizador'] ?? 'sin localizador',
                    implode('; ', $cambios),
                    $consecuencias !== []
                        ? ' Léele al operador «que_va_a_pasar» ENTERO antes de pedirle el sí.'
                        : ''
                ),
            ], static fn ($v) => $v !== null));
        }

        $this->em->flush();

        return SkillResult::ok($resumen + array_filter([
            'aplicado' => true,
            'que_ha_pasado' => $consecuencias !== [] ? $consecuencias : null,
        ], static fn ($v) => $v !== null));
    }

    /** ¿Se pide algo que vive en la estancia y no en la reserva? */
    private function pidenAlgoDeLaEstancia(array $entrada): bool
    {
        foreach (['adultos', 'ninos', 'estado_pago'] as $campo) {
            if (trim((string) ($entrada[$campo] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Teléfono, correo e idioma: son del huésped, así que viven en la reserva.
     *
     * @param list<string> $cambios
     */
    private function camposDelHuesped(array $entrada, PmsReserva $reserva, array &$cambios): void
    {
        // El teléfono se guarda tal cual llega: PmsReservaIntegrityListener lo normaliza a
        // dígitos con código de país antes del INSERT (§12.9.b). Sanearlo aquí sería una
        // segunda verdad que se desincroniza.
        if (($tel = trim((string) ($entrada['telefono'] ?? ''))) !== '') {
            $cambios[] = sprintf('teléfono: %s → %s', $reserva->getTelefono() ?? '(vacío)', $tel);
            $reserva->setTelefono($tel);
        }

        if (($tel2 = trim((string) ($entrada['telefono2'] ?? ''))) !== '') {
            $cambios[] = sprintf('teléfono 2: %s → %s', $reserva->getTelefono2() ?? '(vacío)', $tel2);
            $reserva->setTelefono2($tel2);
        }

        if (($mail = trim((string) ($entrada['email'] ?? ''))) !== '') {
            $cambios[] = sprintf('correo: %s → %s', $reserva->getEmailCliente() ?? '(vacío)', $mail);
            $reserva->setEmailCliente($mail);
        }

        $idioma = strtolower(trim((string) ($entrada['idioma'] ?? '')));

        if ($idioma !== '' && in_array($idioma, self::IDIOMAS, true)) {
            $entidad = $this->em->getRepository(MaestroIdioma::class)->find($idioma);

            if ($entidad !== null && $entidad->getId() !== $reserva->getIdioma()?->getId()) {
                $cambios[] = sprintf(
                    'idioma: %s → %s (cambia la lengua de TODOS los mensajes automáticos)',
                    $reserva->getIdioma()?->getId() ?? '?',
                    $entidad->getId()
                );
                $reserva->setIdioma($entidad);
            }
        }
    }

    /**
     * Gente y estado de pago: son de la estancia.
     *
     * @param list<string> $cambios
     * @param list<string> $consecuencias
     * @return string|null Mensaje de error, o null si todo bien.
     */
    private function camposDeLaEstancia(
        array $entrada,
        PmsEventoCalendario $evento,
        array &$cambios,
        array &$consecuencias
    ): ?string {
        $adultos = (int) ($entrada['adultos'] ?? 0);

        if ($adultos > 0 && $adultos !== $evento->getCantidadAdultos()) {
            $cambios[] = sprintf('adultos: %d → %d', $evento->getCantidadAdultos() ?? 0, $adultos);
            $evento->setCantidadAdultos($adultos);
        }

        if (($entrada['ninos'] ?? null) !== null && trim((string) $entrada['ninos']) !== '') {
            $ninos = max(0, (int) $entrada['ninos']);

            if ($ninos !== $evento->getCantidadNinos()) {
                $cambios[] = sprintf('niños: %d → %d', $evento->getCantidadNinos() ?? 0, $ninos);
                $evento->setCantidadNinos($ninos);
            }
        }

        $estadoPago = strtolower(trim((string) ($entrada['estado_pago'] ?? '')));

        if ($estadoPago === '') {
            return null;
        }

        $entidad = $this->em->getRepository(PmsEventoEstadoPago::class)->find($estadoPago);

        if ($entidad === null) {
            return sprintf(
                'El estado de pago «%s» no existe. Los válidos son: no-pagado, pago-alojamiento, '
                . 'pago-parcial, pago-total.',
                $estadoPago
            );
        }

        if ($entidad->getId() === $evento->getEstadoPago()?->getId()) {
            return null;
        }

        $cambios[] = sprintf(
            'estado de pago: %s → %s',
            $evento->getEstadoPago()?->getId() ?? '?',
            $entidad->getId()
        );

        // 🔑 Las dos cosas que pasan solas y que nadie ve venir. Ver el docblock de la clase.
        if (in_array($entidad->getId(), PmsEventoEstadoPago::ESTADOS_PAGO_CONFIABLES, true)) {
            if ($evento->getEstado()?->getId() !== PmsEventoEstado::CODIGO_CONFIRMADA) {
                $consecuencias[] = sprintf(
                    'La estancia pasará de «%s» a CONFIRMADA sola: es la regla de '
                    . 'auto-confirmación por pago del PMS, no algo que decidas aquí.',
                    $evento->getEstado()?->getId() ?? '?'
                );
            }

            $consecuencias[] = 'Se le ABRE al huésped el contenido de la guía reservado a quien '
                . 'ya pagó. Los códigos de puerta y el WiFi siguen con su propia ventana horaria, '
                . 'pero lo demás pasa a verlo.';

            $consecuencias[] = 'NO se apunta ningún importe: esto marca el estado, no el dinero. '
                . 'Si de verdad recibiste un pago, regístralo con registrar_pago o la cuenta '
                . 'quedará descuadrada.';
        }

        $evento->setEstadoPago($entidad);

        return null;
    }
}
