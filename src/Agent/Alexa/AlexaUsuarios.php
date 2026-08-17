<?php

declare(strict_types=1);

namespace App\Agent\Alexa;

use App\Entity\User;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A quién atribuimos lo que dice un Echo.
 *
 * ### El problema, que no es técnico
 *
 * Un altavoz **identifica un sitio, no una persona**. Y es un escalón por debajo del WhatsApp:
 * allí el número lo verifica Meta en cada mensaje y hace falta tener el móvil desbloqueado,
 * mientras que a un Echo le habla cualquiera que pase cerca sin haber tocado nada. No hay
 * contraseña que pedirle a un micrófono. Por eso el chat del equipo sí abrió la escritura y la
 * voz no.
 *
 * ### Los tres identificadores, y por qué importa cuál se usa
 *
 * Alexa manda hasta tres, y **no son intercambiables**:
 *
 * | Identificador | Qué es | Cuándo distingue |
 * |---|---|---|
 * | `amzn1.ask.person.…`  | La voz reconocida contra un perfil entrenado | Distingue PERSONAS |
 * | `amzn1.ask.device.…`  | El aparato concreto | Distingue SITIOS (recepción vs. oficina) |
 * | `amzn1.ask.account.…` | La cuenta de Amazon | **No distingue nada** si todos los Echo cuelgan de la misma cuenta, que es lo normal |
 *
 * Por eso se resuelve **en cascada, del más específico al más vago** ({@see resolver()}): si la
 * voz está registrada se atribuye a esa persona; si no, al aparato; y sólo como último recurso
 * a la cuenta. Registrar únicamente la cuenta es legítimo —«todo lo que se pregunte aquí es de
 * recepción»— pero hay que saber que es lo que se está diciendo.
 *
 * ⚠️ El `personId` **no es una prueba de identidad**: es el reconocimiento de voz de Amazon,
 * que se equivoca y que no llega si nadie entrenó su perfil. Sirve para atribuir, no para
 * autorizar. Es otra razón por la que el canal entero es de SOLO LECTURA — eso se decide en
 * `VoiceAssistant`, porque no depende de quién hable sino de por dónde habla.
 *
 * Y en todo caso: **cierra en falso**. Sin configuración, o con un id que no está en el mapa,
 * no se responde nada útil. Un canal de voz que ante la duda contesta es un canal que filtra.
 *
 * ### Configuración
 *
 * `ALEXA_USUARIOS` es un JSON de identificador de Alexa a `username` del panel. **Las claves
 * pueden ser de cualquiera de los tres tipos y mezclarse**; no hace falta una variable por
 * tipo, porque los prefijos ya los distinguen:
 *
 * ```
 * ALEXA_USUARIOS='{
 *   "amzn1.ask.person.AB...":  "susan",      ← su voz, la reconozca el Echo que la reconozca
 *   "amzn1.ask.device.CD...":  "recepcion",  ← el Echo del mostrador, hable quien hable
 *   "amzn1.ask.account.EF...": "recepcion"   ← red de seguridad para el resto de la casa
 * }'
 * ```
 *
 * Va con `default::` a propósito: una variable que no existe reventaría el contenedor entero
 * al resolver parámetros, y no sólo este endpoint (el caso de `CULQI_ENDPOINT`, §12 de
 * docs/FinanzasEnlacesPago.md). Sin variable, el skill queda mudo pero el sistema sigue en pie.
 *
 * Ver docs/AgentVoz.md §5.
 */
final readonly class AlexaUsuarios
{
    public function __construct(
        private UserRepository $usuarios,
        private LoggerInterface $logger,
        #[Autowire('%env(default::ALEXA_USUARIOS)%')]
        private ?string $mapaJson = null,
    ) {}

    /**
     * El primero de los tres identificadores que esté en el mapa gana: persona, luego
     * dispositivo, luego cuenta. Ver {@see PeticionAlexa::identidades()}.
     */
    public function resolver(PeticionAlexa $peticion): ?User
    {
        $mapa = $this->mapa();
        $username = null;
        $porDonde = null;

        foreach ($peticion->identidades() as $identidad) {
            if (isset($mapa[$identidad])) {
                $username = $mapa[$identidad];
                $porDonde = $identidad;
                break;
            }
        }

        if ($username === null) {
            // Se registran los TRES ids completos: son exactamente lo que hay que copiar a
            // `ALEXA_USUARIOS` para dar de alta a alguien, y sin esta línea toca ir a buscarlos
            // a la consola de Amazon. Van los tres porque cuál de ellos registrar es una
            // decisión —persona, sitio o casa entera— y aquí no se puede tomar por nadie.
            // No son secretos: sólo identifican dentro de este skill.
            $this->logger->warning(sprintf(
                'Alexa: consulta no autorizada. persona=%s dispositivo=%s cuenta=%s',
                $peticion->persona ?? '(voz no reconocida)',
                $peticion->dispositivo ?? '(sin id)',
                $peticion->usuarioAlexa ?? '(sin id)'
            ));

            return null;
        }

        $usuario = $this->usuarios->findOneBy(['username' => $username]);

        if ($usuario === null) {
            $this->logger->error(sprintf(
                'Alexa: ALEXA_USUARIOS apunta a «%s», que no existe en la base de datos.',
                $username
            ));

            return null;
        }

        // Dar de baja a alguien tiene que cerrarle también esta puerta. Se comprueba aquí y no
        // en el firewall porque este endpoint no pasa por el firewall.
        if (!$usuario->isEnabled()) {
            $this->logger->warning(sprintf('Alexa: «%s» está deshabilitado.', $username));

            return null;
        }

        // 🔑 También se registra lo que SÍ se resolvió, y no es adorno: en cuanto hay una
        // entrada de dispositivo o de cuenta haciendo de red, la línea de «no autorizada» deja
        // de aparecer — siempre se reconoce algo—, y con ella se perdía la única forma de
        // averiguar el `personId` de alguien para darlo de alta por voz. Aquí sale aunque la
        // consulta se haya resuelto por la red.
        //
        // Es además la traza de QUIÉN preguntó, que es media razón de ser del mapa: sin esto,
        // con la red puesta, todo el log dice lo mismo pregunte quien pregunte.
        $this->logger->info(sprintf(
            'Alexa: consulta de «%s» (por %s). persona=%s',
            $username,
            $this->tipoDeIdentidad($porDonde),
            $peticion->persona ?? 'voz no reconocida'
        ));

        return $usuario;
    }

    /** Traduce el prefijo del id a algo legible de un vistazo en el log. */
    private function tipoDeIdentidad(string $identidad): string
    {
        return match (true) {
            str_starts_with($identidad, 'amzn1.ask.person.') => 'voz',
            str_starts_with($identidad, 'amzn1.ask.device.') => 'dispositivo',
            default => 'cuenta',
        };
    }

    public function estaConfigurado(): bool
    {
        return $this->mapa() !== [];
    }

    /**
     * @return array<string, string>
     */
    private function mapa(): array
    {
        if ($this->mapaJson === null || trim($this->mapaJson) === '') {
            return [];
        }

        $mapa = json_decode($this->mapaJson, true);

        if (!is_array($mapa)) {
            $this->logger->error('Alexa: ALEXA_USUARIOS no es un JSON válido.');

            return [];
        }

        $limpio = [];
        foreach ($mapa as $idAlexa => $username) {
            if (is_string($idAlexa) && is_string($username) && $idAlexa !== '' && $username !== '') {
                $limpio[$idAlexa] = $username;
            }
        }

        return $limpio;
    }
}
