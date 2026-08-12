<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Pms\Entity\PmsReserva;
use App\Finanzas\Entity\FinMedioCobro;
use App\Finanzas\Repository\FinMedioCobroRepository;
use App\Pms\Finanzas\PmsPrepagoEnlaceService;
use App\Pms\Finanzas\PmsProcedenciaHuesped;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Por dónde puede pagar el huésped: Yape, Plin, transferencia, Western Union, efectivo.
 *
 * ### Por qué existe: el agujero de la reserva V4JE5Q
 *
 * El 09/08/2026 un huésped preguntó cuatro veces seguidas cómo pagar. El agente tenía
 * herramientas para decirle CUÁNTO debía ({@see ConsultarCuentaSkill}) y ninguna para decirle
 * POR DÓNDE pagarlo, así que se lo inventó: una URL que no existe
 * (`https://micasita.com/pagar-tarjeta`), un tipo de cambio de 3.80 cuando el real era 3.391 y
 * un número de Yape con pinta de relleno (`984 000 000`). Nada falló; simplemente no había de
 * dónde sacar el dato y el modelo rellenó el hueco.
 *
 * La lección va en la descripción de esta skill y no en el prompt: el hueco no era de criterio,
 * era de datos. Ver docs/Mensajeria.md §16.
 *
 * ### La audiencia no es un permiso, es dinero
 *
 * Cada medio dice a quién sirve ({@see \App\Finanzas\Enum\FinAudienciaCobro}) porque el equivocado le
 * cuesta al huésped: una transferencia internacional a una cuenta peruana se lleva en
 * comisiones media parte de un adelanto de 30 USD. Aquí se **filtra**, no se le pasa al modelo
 * para que decida — sólo vería un número de cuenta, y un número de cuenta no dice cuánto cuesta
 * llegar hasta él.
 *
 * Si no se sabe de dónde paga, pasan todos: enumerar de más es recuperable, ocultarle el único
 * medio que le servía no. Quién es «de Perú» lo decide {@see PmsProcedenciaHuesped}, que es el
 * MISMO servicio que usa la guía del huésped — si cada uno lo dedujera por su cuenta, la
 * pantalla y el chat acabarían ofreciendo cosas distintas.
 *
 * ### ⚠️ El enlace de tarjeta no está aquí, y eso se dice en voz alta
 *
 * Mientras `FINANZAS_ENLACES_PREPAGO=0` no hay enlace de pago que dar, y la respuesta lo
 * declara con `pago_con_tarjeta.disponible = false` más una instrucción explícita de no
 * inventarlo. Es deliberado que viaje el `false` en vez de omitir la clave: el silencio es
 * justo lo que el modelo rellena solo. Cuando se encienda el flag y Culqi esté en producción,
 * el enlace lo emitirá {@see GenerarEnlacePrepagoSkill} y aquí pasará a `true`.
 */
final readonly class ConsultarMediosPagoSkill implements SkillInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private FinMedioCobroRepository $catalogo,
        private PmsProcedenciaHuesped $procedencia,
        private PmsPrepagoEnlaceService $prepagoEnlaces,
    ) {}

    public function nombre(): string
    {
        return 'consultar_medios_pago';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'POR DÓNDE se paga: los datos reales de cobro de este alojamiento '
                . '—Yape, Plin, número de cuenta bancaria, Western Union, efectivo—, cada uno '
                . 'con su titular, su número, su banco, su moneda y una nota. Úsala SIEMPRE que '
                . 'pregunten «¿cómo pago?», «¿me pasas la cuenta?», «¿tienen Yape?», «¿a qué '
                . 'número yapeo?», «¿aceptan transferencia?» o quieran pagar por cualquier vía. '
                . 'Para saber CUÁNTO se paga usa consultar_cuenta; ésta dice por dónde. '
                . '🔥 ESTOS DATOS NO ESTÁN EN NINGÚN OTRO SITIO: no los busques en la guía ni '
                . 'los recuerdes de otra conversación. Si esta skill no te devuelve un número, '
                . 'ESE NÚMERO NO EXISTE — no lo inventes, no lo aproximes y no ofrezcas un medio '
                . 'que no venga en la lista: avisa al equipo con escalar_al_equipo. '
                . 'Ya te llega filtrado por si el huésped paga desde Perú o desde fuera, así que '
                . 'ofrécele lo que venga en «medios» y nada más. '
                . 'El campo «pago_con_tarjeta.disponible» manda sobre la tarjeta: si es false NO '
                . 'ofrezcas enlace de pago, ni una URL, ni digas que se lo mandarás — no existe. '
                . 'El tipo de cambio NO viene aquí: si lo preguntan usa consultar_tipo_cambio. '
                . 'Hay VARIOS bancos y cada uno con cuenta en soles y en dólares: no se los '
                . 'sueltes todos de golpe, pregúntale con qué banco y en qué moneda va a '
                . 'transferir y dale sólo ésa. Si trae «titular_como_aparece_en_el_banco», '
                . 'menciónalo al dar una cuenta: es el mismo nombre sin tildes, y quien ve otro '
                . 'distinto en su banca se detiene. Las cuentas son SÓLO para transferencias '
                . 'dentro de Perú, así que no hables de SWIFT ni de transferencias '
                . 'internacionales: a quien pague desde fuera ya no le sale ninguna cuenta en la '
                . 'lista. Si te piden el CCI y la cuenta no lo trae, no lo compongas a partir '
                . 'del número: di que se lo pasamos y llama a escalar_al_equipo.',
            parametros: [
                SkillParameter::texto('reserva_id', 'Identificador de la reserva. Sólo desde el '
                    . 'panel: hablando con el huésped se usa siempre la suya y se ignora.',
                    requerido: false),
            ],
        );
    }

    /** El huésped la tiene por serlo —acotada a SU reserva—; el equipo, por ver reservas. */
    public function rolesRequeridos(): array
    {
        return [Roles::HUESPED, Roles::RESERVAS_SHOW];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Lectura;
    }

    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        // 🚧 Por un canal restringido esto no sale, y se corta AQUÍ y no en el prompt.
        //
        // Esta skill devuelve titulares, números de Yape y cuentas bancarias: es exactamente
        // lo que una OTA prohíbe entregar antes de que la reserva se cierre, porque es lo que
        // permite cobrar por fuera. El filtro por categorías protege la guía, pero esta skill
        // no pasa por la guía — se saltaba la resta entera.
        //
        // Y no es que falte permiso: quien pregunta tiene ROLE_HUESPED como cualquier otro. Lo
        // que no encaja es el TUBO, así que el corte va por el tubo.
        if ($actor->restriccion()->restringe()) {
            return SkillResult::error(
                'Por este canal no se pueden dar medios de pago: la reserva aún no está '
                . 'confirmada y la plataforma lo prohíbe. Dile que el pago se gestiona en la '
                . 'propia plataforma donde reservó, y no le ofrezcas ninguna otra forma.'
            );
        }

        $reservaId = $this->reservaDelContexto($actor)
            ?? trim((string) ($entrada['reserva_id'] ?? ''));

        if (!Uuid::isValid($reservaId)) {
            return SkillResult::error(
                'El reserva_id no es válido. Identifica primero la reserva con buscar_reserva.'
            );
        }

        $reserva = $this->em->getRepository(PmsReserva::class)->find($reservaId);

        if ($reserva === null) {
            return SkillResult::error('No existe ninguna reserva con ese identificador.');
        }

        // 🔒 Antes de enseñar nada, la reserva tiene que dar acceso — el mismo criterio que la
        // guía. `getEventosActivosGuia()` ya filtra por estado y por guía deshabilitada, así
        // que una cancelada o un inquiry de Airbnb no pasan.
        //
        // ⚠️ Y NO se usa `PmsGuiaAcceso::estaAbierto()`, que es lo que parecería. Esa es la
        // ventana de las CREDENCIALES —códigos, WiFi— y exige la estancia en curso. Quien
        // todavía no ha llegado es justo el que necesita pagar el adelanto: cerrarle esto sería
        // pedirle dinero y no decirle por dónde. El nivel que toca es el de «cliente», que es
        // exactamente lo que responde esta comprobación.
        //
        // Al equipo no se le acota: consulta reservas de todo tipo, canceladas incluidas.
        if (!$actor->esDelEquipo() && $reserva->getEventosActivosGuia() === []) {
            return SkillResult::error(
                'Esta reserva no tiene ninguna estancia activa, así que no se le pasan los datos '
                . 'de cobro. Si insiste en pagar algo, avisa al equipo.'
            );
        }

        $desdePeru = $this->procedencia->pagaDesdePeru($reserva);
        $idioma = $reserva->getIdioma()?->getId() ?? 'es';
        $medios = $this->medios($this->catalogo->ofrecibles($desdePeru), $idioma);

        // Sin medios configurados NO se devuelve una lista vacía y ya: una lista vacía se lee
        // como «este alojamiento no cobra» y el modelo improvisa igual. Se dice qué hacer.
        if ($medios === []) {
            return SkillResult::ok([
                'reserva_id' => $reservaId,
                'medios' => [],
                'pago_con_tarjeta' => $this->tarjeta(),
                'instruccion' => 'No hay medios de cobro configurados para este cliente. NO te '
                    . 'inventes una cuenta, un número de Yape ni un enlace: dile al huésped que '
                    . 'le pasas los datos en un momento y llama a escalar_al_equipo.',
            ]);
        }

        return SkillResult::ok(array_filter([
            'reserva_id' => $reservaId,
            // Viaja para que el modelo entienda POR QUÉ la lista es la que es y no prometa un
            // medio que ha visto en otra conversación. `null` = no se pudo deducir.
            'huesped_paga_desde_peru' => $desdePeru,
            'medios' => $medios,
            'pago_con_tarjeta' => $this->tarjeta(),
            'idioma_huesped' => $reserva->getIdioma()?->getId(),
        ], static fn ($v) => $v !== null));
    }

    /**
     * Los medios del catálogo, aplanados a lo que el modelo necesita leer en voz alta.
     *
     * Se enumeran **campo a campo** y no se vuelca la entidad: lo que no se nombre aquí no
     * llega al modelo, que es la otra cara de la regla de docs/Mensajeria.md §11.
     *
     * El filtro de audiencia ya lo hizo {@see FinMedioCobroRepository::ofrecibles()}, igual que
     * el descarte de los medios sin número: aquí sólo se formatea.
     *
     * @param list<FinMedioCobro> $catalogo
     *
     * @return list<array<string, mixed>>
     */
    private function medios(array $catalogo, string $idioma): array
    {
        $filas = [];

        foreach ($catalogo as $medio) {
            $filas[] = array_filter([
                'medio' => $medio->getTipo()->label(),
                'titular' => $medio->getTitular(),
                // Va con nombre propio y no metido en la nota porque el cliente que ve en su
                // banca un nombre distinto al que le dimos se para y pregunta. Ver
                // FinMedioCobro::$titularAlterno.
                'titular_como_aparece_en_el_banco' => $medio->getTitularAlterno(),
                'numero' => $medio->getNumero(),
                'banco' => $medio->getBanco(),
                'cci' => $medio->getCci(),
                'moneda' => $medio->getMoneda(),
                'para' => $medio->getAudiencia()->label(),
                'nota' => $medio->getNotaEn($idioma),
            ], static fn ($v) => $v !== null && $v !== '');
        }

        return $filas;
    }

    /**
     * El estado del pago con tarjeta, que hoy es «no».
     *
     * La nota es una instrucción y no una descripción a propósito: «no disponible» invita a
     * explicarlo con imaginación, «no ofrezcas ninguna URL» no.
     *
     * @return array<string, mixed>
     */
    private function tarjeta(): array
    {
        $activo = $this->prepagoEnlaces->estaActivo();

        return [
            'disponible' => $activo,
            'nota' => $activo
                ? 'Hay enlace de pago con tarjeta. El importe con el recargo de la pasarela lo '
                    . 'da consultar_cuenta en «pago_con_tarjeta».'
                : 'NO hay enlace de pago con tarjeta todavía. No ofrezcas ninguno, no escribas '
                    . 'ninguna URL y no digas que se lo enviarás: ofrécele los medios de «medios» '
                    . 'y, si insiste con la tarjeta, llama a escalar_al_equipo.',
        ];
    }

    /**
     * 🔒 La reserva que impone la conversación, si la hay.
     *
     * Igual que en {@see ConsultarCuentaSkill}: si el chat fija una reserva, el `reserva_id`
     * del modelo ni se mira.
     */
    private function reservaDelContexto(ActorInterface $actor): ?string
    {
        return $actor->contextoTipo() === 'pms_reserva' ? $actor->contextoId() : null;
    }
}
