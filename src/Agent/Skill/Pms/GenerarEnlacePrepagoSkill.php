<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillConmutableInterface;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Entity\User;
use App\Pms\Entity\PmsReserva;
use App\Pms\Enum\PmsPoliticaPrepago;
use App\Pms\Finanzas\PmsPrepagoEnlaceService;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Component\Uid\Uuid;

/**
 * Emite el enlace con el que el huésped paga su adelanto. **Crea un cobro.**
 *
 * ### Genera, pero NO envía — y es a propósito
 *
 * El encargo era «una skill que genera y envía el enlace». Envía la de siempre:
 * {@see EnviarMensajeHuespedSkill}. Aquí sólo se emite y se devuelve la URL.
 *
 * Dos razones, y las dos ya están escritas en docs/Mensajeria.md §11:
 *
 * 1. **El texto lo compone el modelo, no la skill.** Es la misma decisión que evitó un
 *    `enviar_estado_de_cuenta`: un enlace de cobro se manda con distintas palabras a un
 *    huésped que lo pidió, a uno al que se le está reclamando y a uno que escribe en inglés.
 * 2. **El envío ya tiene su puerta.** `enviar_mensaje_huesped` compone un borrador y espera
 *    un «¿lo mando?». Meter el envío aquí dentro sacaría un enlace de cobro hacia un huésped
 *    real sin pasar por esa confirmación — y con el autorespondedor encendido, sin que ningún
 *    humano lo vea. Un enlace de dinero mal dirigido no se puede recoger.
 *
 * El flujo completo son dos llamadas encadenadas, como `localizar_conversacion` →
 * `enviar_mensaje_huesped`, y la descripción de la skill se lo dice al modelo.
 *
 * ### Apagada hasta que la pasarela esté en producción
 *
 * Implementa {@see SkillConmutableInterface}: con `FINANZAS_ENLACES_PREPAGO=0` no aparece
 * siquiera en el catálogo. En modo test Culqi acepta el cobro sin mover dinero, y un huésped
 * que paga ahí se queda convencido de que ya está.
 *
 * ⚠️ La comprobación se repite en `ejecutar()`. El filtro del registro no es seguridad: por
 * nombre se puede llegar igual desde `app:agent:skill` o desde un plan de una conversación
 * que empezó antes de apagar el flag.
 *
 * ### Sólo el equipo, aunque el beneficiario sea el huésped
 *
 * `RESERVAS_WRITE`, no `HUESPED`. Emitir un enlace es escribir un cobro, y el chat del huésped
 * se abre en modo sólo-lectura ({@see \App\Agent\Skill\SkillRegistry::paraActor()}), así que
 * esta skill no existe en su contexto. El huésped ve sus enlaces en su app, que sólo enseña
 * los que ya existen — leer no es emitir.
 */
final readonly class GenerarEnlacePrepagoSkill implements SkillInterface, SkillConmutableInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private PmsPrepagoEnlaceService $prepagoEnlaces,
    ) {}

    public function nombre(): string
    {
        return 'generar_enlace_prepago';
    }

    public function estaActiva(): bool
    {
        return $this->prepagoEnlaces->estaActivo();
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Emite el enlace de pago con el que el huésped abona el ADELANTO '
                . '(prepago) de su reserva, y devuelve la URL. CREA UN COBRO: llama primero '
                . 'con confirmado=false, enséñale al operador el huésped, el importe y la '
                . 'política, y espera su sí antes de confirmado=true. NO ENVÍA NADA: cuando '
                . 'tengas la URL, redáctale el mensaje al huésped en su idioma y mándalo con '
                . 'enviar_mensaje_huesped, que es quien pide la confirmación de envío. El '
                . 'importe NO lo eliges tú: sale de la política del establecimiento y viene '
                . 'calculado. Si la respuesta dice reutilizado=true, ese enlace ya existía y '
                . 'sigue vivo — dilo, y no emitas otro. Si el huésped ya pagó algo, esta skill '
                . 'te dirá que no hay prepago pendiente: no insistas ni inventes un importe. '
                . 'Para consultar cuánto es el adelanto SIN emitir nada, usa consultar_cuenta, '
                . 'que ya trae prepago_pendiente. Necesita el reserva_id.',
            parametros: [
                SkillParameter::texto('reserva_id', 'Identificador de la reserva, tal cual lo '
                    . 'devolvió buscar_reserva.'),
                SkillParameter::booleano('confirmado', 'true SÓLO tras la confirmación '
                    . 'explícita del operador. false para previsualizar sin emitir nada.'),
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
        // El filtro del catálogo no basta: por nombre se llega igual. Ver el docblock.
        if (!$this->prepagoEnlaces->estaActivo()) {
            return SkillResult::error(
                'Los enlaces de prepago están desactivados hasta que la pasarela pase a '
                . 'producción. Dile al operador que lo cobre por otro medio.'
            );
        }

        $reservaId = trim((string) ($entrada['reserva_id'] ?? ''));
        $confirmado = filter_var($entrada['confirmado'] ?? false, FILTER_VALIDATE_BOOL);

        if (!Uuid::isValid($reservaId)) {
            return SkillResult::error(
                'El reserva_id no es válido. Identifica primero la reserva con buscar_reserva.'
            );
        }

        $reserva = $this->em->getRepository(PmsReserva::class)->find($reservaId);

        if ($reserva === null) {
            return SkillResult::error('No existe ninguna reserva con ese identificador.');
        }

        // La previsualización se calcula con el MISMO servicio que emite, no con una copia
        // de sus reglas: si el prepago ya no procede, el operador se entera antes de aprobar
        // y no después de que el enlace exista.
        $prepago = $this->prepagoEnlaces->emitirSimulado($reserva);

        if ($prepago === null) {
            return SkillResult::error(
                'Esta reserva no tiene prepago pendiente: o ya hay un pago registrado, o su '
                . 'establecimiento no pide adelanto, o el canal cobró por nosotros. '
                . 'Consulta consultar_cuenta para ver cómo está la cuenta.'
            );
        }

        $huesped = trim($reserva->getNombreCliente() . ' ' . $reserva->getApellidoCliente());
        $politica = PmsPoliticaPrepago::tryFrom($prepago['politica'])?->etiqueta();

        if (!$confirmado) {
            return SkillResult::ok(array_filter([
                'confirmado' => false,
                'huesped' => $huesped,
                'localizador' => $reserva->getLocalizador(),
                'monto' => $prepago['monto'],
                'moneda' => $prepago['moneda'],
                'politica' => $politica,
                'ya_existe_enlace' => $prepago['reutilizado'],
                'idioma_huesped' => $reserva->getIdioma()?->getId(),
                'pregunta_aprobacion' => sprintf(
                    '¿Emito el enlace de adelanto de %s %s para %s (%s)?',
                    $prepago['monto'],
                    $prepago['moneda'],
                    $huesped,
                    $reserva->getLocalizador() ?? 'sin localizador',
                ),
            ], static fn ($v) => $v !== null));
        }

        try {
            $emitido = $this->prepagoEnlaces->emitir($reserva, $this->autorPersistido($actor));
        } catch (DomainException $e) {
            return SkillResult::error($e->getMessage());
        }

        return SkillResult::ok(array_filter([
            'confirmado' => true,
            'huesped' => $huesped,
            'localizador' => $reserva->getLocalizador(),
            'url' => $emitido['url'],
            'monto' => $emitido['monto'],
            'moneda' => $emitido['moneda'],
            // Lo que se le cobra a la tarjeta: el neto más la comisión de la pasarela. Se
            // dice porque es lo que el huésped verá en su extracto, y no coincide con el
            // adelanto — explicarlo después es peor que decirlo antes.
            'total_con_tarjeta' => $emitido['enlace']->getMontoTotal(),
            'recargo_porcentaje' => $emitido['enlace']->getRecargoPorcentaje(),
            'politica' => $politica,
            'caduca' => $emitido['enlace']->getExpiraEn()?->format('Y-m-d'),
            'reutilizado' => $emitido['reutilizado'],
            'idioma_huesped' => $reserva->getIdioma()?->getId(),
            'siguiente_paso' => 'El enlace NO se ha enviado. Redacta el mensaje en el idioma '
                . 'del huésped y mándalo con enviar_mensaje_huesped.',
        ], static fn ($v) => $v !== null));
    }

    /**
     * Quién emite el enlace, sólo si es alguien de verdad.
     *
     * 🔥 El usuario del actor **no siempre está en la base de datos**: `AgentSkillCommand`
     * fabrica un `new User()` con SUPER_ADMIN para poder probar skills desde la consola.
     * Ponerlo en `creadoPor` hace que Doctrine reviente al flush con «A new entity was found
     * through the relationship», y el mensaje no dice nada del problema real.
     *
     * Comprobar el id no sirve: `IdTrait` lo genera en el constructor, así que el usuario
     * fantasma también tiene uno. Lo que distingue a los dos es estar gestionado.
     *
     * Sin autor identificable se emite igual, con `creadoPor` a null — la columna es opcional
     * y quedarse sin cobrar por no saber quién lo pidió sería peor.
     */
    private function autorPersistido(ActorInterface $actor): ?User
    {
        $usuario = $actor->usuario();

        return $usuario instanceof User && $this->em->contains($usuario) ? $usuario : null;
    }
}
