<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Pms\Entity\PmsEstablecimiento;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsReserva;
use App\Pms\Guia\PmsGuiaAcceso;
use App\Pms\Guia\PmsGuiaAccesoEstado;
use App\Pms\Guia\PmsGuiaEstanciaResolver;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Los códigos para entrar: la caja de las llaves y la puerta de la casita.
 *
 * Sirve para las dos formas de pedirlo, que son la misma consulta:
 *
 * - el huésped preguntando «¿cuál es el código?» por su chat,
 * - el operador diciendo «mándale el código a Ana» desde el panel — con el dato en la mano,
 *   el agente encadena `enviar_mensaje_huesped`.
 *
 * ### 🔒 Tres condiciones, no una
 *
 * Un código sale sólo si se cumplen las tres, y cada una responde a algo distinto:
 *
 * 1. **La estancia da acceso** — {@see PmsGuiaAcceso::paraEvento()}: ni cancelada, ni de otro,
 *    ni sin pago cuando el pago es lo que abre.
 * 2. **La ventana está abierta** — desde 24 h antes del check-in hasta el check-out. Un código
 *    entregado tres semanas antes circula por WhatsApp mucho después de que el huésped se vaya.
 * 3. **El campo está relleno.** Que no haya código configurado NO es lo mismo que no poder
 *    verlo, y se dice distinto: uno es «todavía no», el otro es «falta ponerlo en el panel».
 *
 * Es el mismo criterio que la guía web ({@see \App\Pms\Guia\PmsGuiaInterpolador}), y a propósito:
 * si el chat diera el código cuando la guía lo tapa, la ventana no serviría de nada.
 *
 * ### 💰 La caja del DINERO no sale nunca por aquí
 *
 * `PmsEstablecimiento::$codigoCajaSecundaria` es la caja de la recaudación, no la de las llaves.
 * No es contenido de huésped bajo ninguna condición: no aparece en esta skill ni con el actor
 * del equipo. Se consulta y se cambia con `cambiar_codigo_caja`, que exige rol de escritura.
 */
final readonly class ConsultarCodigosSkill implements SkillInterface
{
    /**
     * El aviso que acompaña SIEMPRE al código, en el idioma del huésped.
     *
     * Un código de caja cambia —y `cambiar_codigo_caja` avisa de que los mensajes ya enviados no
     * se actualizan solos—. Mandarlo «a pelo» crea una copia que envejece en su WhatsApp: el día
     * que se cambie, seguirá teniendo el viejo y creyendo que es el bueno.
     *
     * Con el enlace, el mensaje deja de ser la fuente y pasa a ser un atajo: la guía siempre da
     * el valor de ahora. Va en su idioma porque un aviso que no se entiende no se sigue.
     */
    private const array AVISO = [
        'es' => 'Este código puede cambiar. Consulta siempre tu guía para tener el actualizado:',
        'en' => 'This code may change. Always check your guide for the current one:',
        'pt' => 'Este código pode mudar. Consulte sempre o seu guia para ter o atual:',
        'fr' => 'Ce code peut changer. Consultez toujours votre guide pour avoir le bon :',
        'it' => 'Questo codice può cambiare. Controlla sempre la tua guida per quello aggiornato:',
        'de' => 'Dieser Code kann sich ändern. Den aktuellen findest du immer in deinem Guide:',
        'nl' => 'Deze code kan veranderen. Kijk altijd in je gids voor de actuele code:',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private PmsGuiaEstanciaResolver $estancias,
        #[Autowire('%pax_book_guide_url%')]
        private string $urlGuia,
    ) {}

    public function nombre(): string
    {
        return 'consultar_codigos';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Los códigos para entrar: el de la caja fuerte donde están las llaves y '
                . 'el de la puerta de la casita. Úsala igual si el huésped pregunta «¿cuál es el '
                . 'código?» que si el operador dice «mándale el código a Ana» — te devuelve el '
                . 'dato y tú lo envías. Para el WiFi usa consultar_wifi, no ésta. Si viene '
                . 'bloqueado, NO te lo inventes ni lo busques en otro sitio: di el motivo tal '
                . 'cual y, si trae fecha, desde cuándo lo tendrá. Y si dice que falta '
                . 'configurarlo, eso NO es que el huésped no pueda verlo: es que nadie lo ha '
                . 'puesto en el panel, así que avisa al equipo en vez de decirle que espere. '
                . '⚠️ AL DAR EL CÓDIGO, PON SIEMPRE el texto de «avisale_de_esto» con su enlace: '
                . 'ya viene escrito en el idioma del huésped y le dice que el código puede '
                . 'cambiar y que la guía tiene el bueno. Sin eso le dejas en el chat una copia '
                . 'que envejece, y el día que se cambie el código seguirá usando el viejo. '
                . '💰 LA CAJA DEL DINERO NO EXISTE PARA EL HUÉSPED: si pregunta por ella, o por '
                . '«la otra caja», o por dónde se guarda la recaudación, dile que ésa es interna '
                . 'del alojamiento y no la compartas ni la busques por otro lado. Esta skill no '
                . 'la devuelve nunca.',
            parametros: [
                SkillParameter::texto('reserva_id', 'La reserva de la que se quieren los '
                    . 'códigos. Hablando con el propio huésped no hace falta: sale de su chat. '
                    . 'Y si un OPERADOR pregunta por la caja en general —«¿cuál es el código de '
                    . 'la caja?», sin hablar de ningún huésped— OMÍTELO: la caja de las llaves '
                    . 'es de la casa y te la doy sin más. Sólo pásalo cuando el operador '
                    . 'pregunte por alguien en concreto o quiera el código de una puerta.',
                    requerido: false),
                SkillParameter::texto('casita', 'Nombre o slug de la casita, si la reserva tiene '
                    . 'varias.', requerido: false),
            ],
        );
    }

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
        // 🔒 El contexto manda sobre el parámetro: un huésped no puede pedir los códigos de
        // otra casita escribiendo un UUID. Misma frontera que consultar_guia y consultar_wifi.
        $reservaId = $actor->contextoId() ?? trim((string) ($entrada['reserva_id'] ?? ''));

        if ($actor->contextoId() === null && !$actor->esDelEquipo()) {
            return SkillResult::error('Esta conversación no está asociada a ninguna reserva.');
        }

        // 🔑 Sin reserva, pero siendo del equipo: la caja de las llaves es del ESTABLECIMIENTO,
        // no del huésped. «¿Cuál es el código de la caja?» es una pregunta legítima de un
        // operador que está en la puerta, y obligarle a nombrar a un huésped para que el
        // sistema le diga un código que es de la casa era un rodeo sin sentido.
        //
        // La ventana horaria no aplica aquí porque no hay estancia contra la que medirla: lo
        // que protege este camino es el rol, no la fecha. Al huésped lo para el `if` de arriba.
        if ($reservaId === '' && $actor->esDelEquipo()) {
            return $this->codigosDeLaCasa();
        }

        if (!Uuid::isValid($reservaId)) {
            return SkillResult::error(
                'No sé de qué reserva son los códigos. Identifícala con buscar_reserva.'
            );
        }

        $reserva = $this->em->getRepository(PmsReserva::class)->find($reservaId);

        if ($reserva === null) {
            return SkillResult::error('No existe ninguna reserva con ese identificador.');
        }

        $eventos = $reserva->getEventosActivosGuia();

        if ($eventos === []) {
            return SkillResult::error('Esta reserva no tiene ninguna estancia activa.');
        }

        $eleccion = $this->estancias->resolver($eventos, trim((string) ($entrada['casita'] ?? '')));

        if ($eleccion['ambiguo']) {
            return SkillResult::ok([
                'huesped' => $reserva->getNombreApellido(),
                'falta_datos' => ['casita'],
                'casitas' => array_values(array_filter(array_map(
                    static fn (PmsEventoCalendario $e): ?string => $e->getPmsUnidad()?->getNombre(),
                    $eleccion['candidatas']
                ))),
                'pregunta' => 'Esta reserva tiene varias casitas y cada una tiene su propia '
                    . 'puerta. Pregúntale al huésped en cuál está y vuelve a llamarme con «casita».',
            ]);
        }

        $evento = $eleccion['evento'];

        if ($evento === null) {
            return SkillResult::error('Esa casita no es de esta reserva.');
        }

        $unidad = $evento->getPmsUnidad();

        if ($unidad === null) {
            return SkillResult::error('Esta estancia todavía no tiene casita asignada.');
        }

        $acceso = PmsGuiaAcceso::paraEvento($evento);
        $base = [
            'casita' => $unidad->getNombre(),
            'huesped' => $reserva->getNombreApellido(),
        ];

        // Condiciones 1 y 2: si la ventana está cerrada no hay nada que enseñar, y el motivo
        // es el mismo para todos los códigos.
        if (!$acceso->estaAbierto()) {
            return SkillResult::ok($base + array_filter([
                'disponible' => false,
                'motivo' => $this->motivo($acceso),
                'disponible_desde' => $acceso->liberaEn?->format('d/m/Y H:i'),
            ], static fn ($v) => $v !== null));
        }

        // Condición 3, por código: el establecimiento guarda el de la caja de llaves y la
        // unidad el de su puerta. Que falte uno no impide dar el otro.
        $codigos = [];
        $faltan = [];

        $cajaLlaves = trim((string) $unidad->getEstablecimiento()?->getCodigoCajaPrincipal());

        if ($cajaLlaves !== '') {
            $codigos['caja_de_las_llaves'] = $cajaLlaves;
        } else {
            $faltan[] = 'el de la caja de las llaves';
        }

        $puerta = trim((string) $unidad->getCodigoPuerta());

        if ($puerta !== '') {
            $codigos['puerta_de_la_casita'] = $puerta;
        } else {
            $faltan[] = sprintf('el de la puerta de %s', $unidad->getNombre());
        }

        // Alguna unidad tiene además su propia caja; la mayoría no.
        $cajaUnidad = trim((string) $unidad->getCodigoCaja());

        if ($cajaUnidad !== '') {
            $codigos['caja_de_la_casita'] = $cajaUnidad;
        }

        if ($codigos === []) {
            return SkillResult::ok($base + [
                'disponible' => false,
                'motivo' => 'Esta casita no tiene NINGÚN código configurado en el sistema. No es '
                    . 'que el huésped no pueda verlo todavía: es que falta ponerlo. Díselo al '
                    . 'equipo, y al huésped que se lo confirmas en un momento.',
            ]);
        }

        $idioma = $reserva->getIdioma()?->getId() ?? 'es';
        $enlace = $reserva->getLocalizador() !== null
            ? rtrim($this->urlGuia, '/') . '/' . $reserva->getLocalizador()
            : null;

        return SkillResult::ok($base + array_filter([
            'disponible' => true,
            'codigos' => $codigos,
            // 🔗 El código NO se manda solo: va con el aviso y el enlace, en el idioma del
            // huésped. Así el mensaje deja de ser la fuente —que envejece en su WhatsApp el día
            // que se cambie el código— y pasa a ser un atajo hacia la guía, que siempre da el
            // valor de ahora. Ver el docblock de self::AVISO.
            'avisale_de_esto' => $enlace !== null
                ? sprintf('%s %s', self::AVISO[$idioma] ?? self::AVISO['es'], $enlace)
                : (self::AVISO[$idioma] ?? self::AVISO['es']),
            'enlace_guia' => $enlace,
            'idioma_del_aviso' => $idioma,
            // Se anuncia lo que falta aunque haya otros: si el huésped pide justo ése, el
            // modelo tiene que saber que no existe en vez de decir que no le toca verlo.
            'sin_configurar' => $faltan !== []
                ? sprintf(
                    'Falta configurar en el panel: %s. Si es lo que pregunta, avisa al equipo: '
                    . 'no es un problema de permisos.',
                    implode(' y ', $faltan)
                )
                : null,
        ], static fn ($v) => $v !== null));
    }

    /**
     * Los códigos que son de la casa y no de nadie en particular.
     *
     * Sólo la caja de las LLAVES. La secundaria es la de la recaudación y no se devuelve por
     * aquí ni al equipo —igual que en el camino con reserva—: se cambia con
     * `cambiar_codigo_caja` y se consulta en el panel, donde queda claro quién la está mirando.
     */
    private function codigosDeLaCasa(): SkillResult
    {
        $establecimientos = $this->em->getRepository(PmsEstablecimiento::class)->findAll();

        if ($establecimientos === []) {
            return SkillResult::error('No hay ningún establecimiento configurado.');
        }

        // Hoy hay uno solo. Con varios habría que preguntar de cuál, no elegir por el operador.
        if (count($establecimientos) > 1) {
            return SkillResult::error(
                'Hay más de un establecimiento y esta skill no sabe cuál quieres. Dime la '
                . 'casita o el huésped y te doy los suyos.'
            );
        }

        $codigo = trim((string) $establecimientos[0]->getCodigoCajaPrincipal());

        if ($codigo === '') {
            return SkillResult::ok([
                'establecimiento' => $establecimientos[0]->getNombreComercial(),
                'disponible' => false,
                'motivo' => 'La caja de las llaves no tiene código configurado en el sistema. '
                    . 'No es un problema de permisos: falta ponerlo en el panel.',
            ]);
        }

        return SkillResult::ok([
            'establecimiento' => $establecimientos[0]->getNombreComercial(),
            'disponible' => true,
            'codigos' => ['caja_de_las_llaves' => $codigo],
            'nota' => 'Es el código general de la caja de las llaves, el mismo para todas las '
                . 'casitas. Para el de la puerta de una casita concreta, o para dárselo a un '
                . 'huésped, dime de quién es la reserva: ahí se comprueba además que le toque '
                . 'saberlo.',
        ]);
    }

    /**
     * Por qué todavía no, en una frase que el modelo pueda repetir.
     *
     * No se reutiliza `PmsGuiaMensajes`: aquellos son textos de interfaz —`[Disponible el 12/08]`,
     * entre corchetes para pintarse dentro de un párrafo— y aquí hace falta una instrucción para
     * el modelo, que además le prohíbe rellenar el hueco por su cuenta.
     */
    private function motivo(PmsGuiaAcceso $acceso): string
    {
        return match ($acceso->estado) {
            PmsGuiaAccesoEstado::Pendiente => 'Todavía no ha empezado su estancia. Los códigos se '
                . 'entregan al acercarse el check-in; dile desde cuándo los tendrá.',
            PmsGuiaAccesoEstado::SinPago => 'La reserva no está confirmada. Los códigos se '
                . 'entregan al confirmar el pago: díselo sin dar ninguna cifra.',
            PmsGuiaAccesoEstado::Expirada => 'Su estancia ya terminó, así que los códigos ya no '
                . 'están disponibles.',
            default => 'No puedo dar los códigos de esta casita ahora mismo. Que lo mire un '
                . 'operador.',
        };
    }
}
