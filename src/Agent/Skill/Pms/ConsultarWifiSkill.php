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
use App\Entity\Maestro\MaestroIdioma;
use App\Pms\Service\Agent\PmsFrentes;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsReserva;
use App\Pms\Guia\PmsGuiaAcceso;
use App\Pms\Guia\PmsGuiaAccesoEstado;
use App\Pms\Guia\PmsGuiaEstanciaResolver;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Las redes WiFi de la casita: nombre, contraseña y dónde llega mejor cada una.
 *
 * ### Por qué es una skill aparte y no parte de `consultar_guia`
 *
 * Porque el dato **no está en la guía**. Vive en `PmsUnidad::$wifiNetworks`, y en el cuerpo del
 * ítem sólo hay un `{{ wifi_data }}` que el navegador convierte en un widget
 * (`RichContentEngine.ts`). Resolver ese placeholder dentro de la skill de la guía funcionaba,
 * pero mezclaba dos cosas: una skill que LEE texto publicado y una que sirve una CREDENCIAL.
 *
 * Separarlas tiene consecuencias prácticas:
 *
 * - La contraseña sale de un sitio, no de un texto que alguien puede reescribir en el editor.
 * - «¿Cuál es la clave del wifi?» es de las preguntas más repetidas: merece una respuesta
 *   directa, no un párrafo del que extraerla.
 * - La ventana de acceso se comprueba aquí explícitamente, y se ve.
 *
 * ### 🔒 La ventana
 *
 * La contraseña **sólo sale con la estancia activa** ({@see PmsGuiaAccesoEstado::Activa}), igual
 * que en la web: `PmsGuiaHuespedProvider` manda `redesWifi` vacío mientras esté cerrada. Antes
 * de eso se devuelve el motivo y, si la hay, la fecha en que se abre — nunca la clave
 * enmascarada, que es una forma elegante de filtrarla igual.
 */
final readonly class ConsultarWifiSkill implements SkillInterface, SkillDominioInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private PmsGuiaEstanciaResolver $estancias,
    ) {}

    public function nombre(): string
    {
        return 'consultar_wifi';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Da el WiFi de la casita: nombre de la red, contraseña y en qué parte '
                . 'de la casa llega mejor cada una. Úsala para cualquier pregunta de internet, '
                . 'wifi, clave, contraseña o «no me conecto», en vez de buscarlo en la guía: la '
                . 'contraseña sale de aquí y es la buena. Puede haber VARIAS redes con la misma '
                . 'clave y distinta cobertura; si es así dilas todas con su ubicación, porque la '
                . 'que funciona depende de en qué habitación esté. Si la respuesta viene '
                . 'bloqueada, NO te inventes una clave ni la busques en otro sitio: explica el '
                . 'motivo y, si trae fecha, di desde cuándo estará disponible.',
            parametros: [
                SkillParameter::texto('reserva_id', 'Sólo para el equipo, cuando consulta el WiFi '
                    . 'de OTRO huésped. Al hablar con el propio huésped no hace falta.',
                    requerido: false),
                SkillParameter::texto('casita', 'Nombre o slug de la casita, si la reserva tiene '
                    . 'varias.', requerido: false),
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
        return [Roles::HUESPED, Roles::RESERVAS_SHOW];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Lectura;
    }

    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        // 🔒 Misma frontera que en consultar_guia: el contexto manda sobre el parámetro, o un
        // huésped podría pedir la contraseña de otra casita escribiendo un UUID.
        $reservaId = $actor->contextoId() ?? trim((string) ($entrada['reserva_id'] ?? ''));

        if ($actor->contextoId() === null && !$actor->esDelEquipo()) {
            return SkillResult::error('Esta conversación no está asociada a ninguna reserva.');
        }

        if (!Uuid::isValid($reservaId)) {
            return SkillResult::error(
                'No sé de qué reserva es el WiFi. Identifícala primero con buscar_reserva.'
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

        // 🏠 Cada casita tiene SUS redes. Dar la contraseña de la de al lado no es un error
        // menor: el huésped no se conecta y cree que el wifi no funciona.
        $eleccion = $this->estancias->resolver($eventos, trim((string) ($entrada['casita'] ?? '')));

        if ($eleccion['ambiguo']) {
            return SkillResult::ok([
                'huesped' => $reserva->getNombreApellido(),
                'falta_datos' => ['casita'],
                'casitas' => $this->nombresDe($eleccion['candidatas']),
                'pregunta' => 'Esta reserva tiene varias casitas a la vez y cada una tiene sus '
                    . 'propias redes. Pregúntale al huésped en cuál está y vuelve a llamarme '
                    . 'con «casita».',
            ]);
        }

        $evento = $eleccion['evento'];

        if ($evento === null) {
            return SkillResult::error(sprintf(
                'Esa casita no es de esta reserva. Las suyas son: %s.',
                implode(', ', $this->nombresDe($eleccion['candidatas']))
            ));
        }

        $unidad = $evento->getPmsUnidad();
        if ($unidad === null) {
            return SkillResult::error('Esta estancia todavía no tiene casita asignada.');
        }

        $acceso = PmsGuiaAcceso::paraEvento($evento);
        $base = ['casita' => $unidad->getNombre(), 'huesped' => $reserva->getNombreApellido()];

        // 🔒 La ventana, explícita. Sin esto la contraseña de la casa viajaría a cualquiera que
        // tenga una reserva futura —o una ya terminada— por el mero hecho de preguntar.
        if (!$acceso->estaAbierto()) {
            return SkillResult::ok($base + array_filter([
                'disponible' => false,
                'motivo' => $this->motivo($acceso),
                'disponible_desde' => $acceso->liberaEn?->format('d/m/Y H:i'),
            ], static fn ($v) => $v !== null));
        }

        $redes = $this->redes($unidad->getWifiNetworks(), $reserva->getIdioma()?->getId() ?? 'es');

        if ($redes === []) {
            return SkillResult::ok($base + [
                'disponible' => false,
                'motivo' => 'Esta casita no tiene ninguna red WiFi registrada en el sistema. '
                    . 'No la inventes: dile al huésped que lo consultas y avisa a un operador.',
            ]);
        }

        return SkillResult::ok($base + [
            'disponible' => true,
            'redes' => $redes,
        ]);
    }

    /**
     * Por qué no se puede dar todavía, en una frase que el modelo pueda repetir.
     *
     * Se escribe aquí y no se reutiliza `PmsGuiaMensajes`: aquellos son textos de interfaz —
     * `[Disponible el 12/08]` entre corchetes, pensados para pintarse dentro de un párrafo—, y
     * lo que hace falta aquí es una instrucción para el modelo, que además le prohíbe rellenar
     * el hueco por su cuenta.
     */
    private function motivo(PmsGuiaAcceso $acceso): string
    {
        return match ($acceso->estado) {
            PmsGuiaAccesoEstado::Pendiente => 'Todavía no ha empezado su estancia. La '
                . 'contraseña se entrega al acercarse el check-in; dile desde cuándo la tendrá.',
            PmsGuiaAccesoEstado::SinPago => 'La reserva no está confirmada. La contraseña se '
                . 'entrega al confirmar el pago: díselo sin dar cifras de la clave.',
            PmsGuiaAccesoEstado::Expirada => 'Su estancia ya terminó, así que la contraseña ya '
                . 'no está disponible.',
            default => 'No puedo dar la contraseña de esta casita ahora mismo. Que lo mire un '
                . 'operador.',
        };
    }

    /**
     * Las redes en la forma en que se van a leer en voz alta.
     *
     * `ubicacion` es i18n (`[{language, content}]`) porque el mismo sitio se dice distinto en
     * cada idioma; aquí se resuelve a UNO, el del huésped: mandarle al modelo «Salón / Living
     * Room / Salon» por cada red es pagar tres veces la misma palabra.
     *
     * @param array<int, array<string, mixed>> $wifiNetworks
     * @return list<array<string, string>>
     */
    private function redes(array $wifiNetworks, string $idioma): array
    {
        $redes = [];

        foreach ($wifiNetworks as $red) {
            $ssid = trim((string) ($red['ssid'] ?? ''));

            // Sin nombre de red no hay nada que decir, aunque haya contraseña: el huésped no
            // sabría a qué conectarse.
            if ($ssid === '') {
                continue;
            }

            $redes[] = array_filter([
                'red' => $ssid,
                'clave' => trim((string) ($red['password'] ?? '')),
                'donde_llega_mejor' => is_array($red['ubicacion'] ?? null)
                    ? $this->enIdioma($red['ubicacion'], $idioma)
                    : '',
            ], static fn (string $v): bool => $v !== '');
        }

        return $redes;
    }

    /**
     * @param array<int, array{language?: string, content?: string}> $i18n
     */
    private function enIdioma(array $i18n, string $idioma): string
    {
        $fallback = '';

        foreach ($i18n as $bloque) {
            $contenido = trim((string) ($bloque['content'] ?? ''));

            if ($contenido === '') {
                continue;
            }

            if (($bloque['language'] ?? '') === $idioma) {
                return $contenido;
            }

            if (($bloque['language'] ?? '') === MaestroIdioma::DEFAULT_IDIOMA || $fallback === '') {
                $fallback = $contenido;
            }
        }

        return $fallback;
    }

    /**
     * @param array<int, PmsEventoCalendario> $eventos
     * @return list<string>
     */
    private function nombresDe(array $eventos): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (PmsEventoCalendario $e): ?string => $e->getPmsUnidad()?->getNombre(),
            $eventos
        ))));
    }
}
