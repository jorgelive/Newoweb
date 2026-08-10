<?php

declare(strict_types=1);

namespace App\Agent\Controller\Api;

use App\Agent\Access\AgentActorFactory;
use App\Agent\Alexa\AlexaFirma;
use App\Agent\Alexa\AlexaUsuarios;
use App\Agent\Alexa\PeticionAlexa;
use App\Agent\Alexa\RespuestaAlexa;
use App\Agent\Service\VoiceAssistant;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Endpoint del skill de Alexa: el agente por voz.
 *
 * ⚠️ **Este controlador NO lleva `#[IsGranted]`, y es intencionado.** Alexa no manda cookie de
 * sesión ni cabecera de autorización: quien autentica la petición es {@see AlexaFirma}, y quien
 * decide de quién es la voz es {@see AlexaUsuarios}. Cae en el host de API, que no está en
 * `access_control` y por tanto es `PUBLIC_ACCESS` (ver el comentario de `agent_api_controllers`
 * en `config/routes.yaml`). Si alguna vez se quita la validación de firma, este endpoint queda
 * abierto a cualquiera con la URL.
 *
 * El canal es de SOLO LECTURA; eso lo impone {@see VoiceAssistant}, no este archivo.
 *
 * Ver docs/AgentVoz.md.
 */
#[Route('/alexa')]
final class AlexaController extends AbstractController
{
    /** Lo que se recuerda de la conversación. Alexa limita el tamaño de `sessionAttributes`. */
    private const int MAX_TURNOS_RECORDADOS = 6;

    /**
     * Va a juego con el nombre de invocación («Alexa, abre openperu»). Ver docs/AgentVoz.md.
     *
     * Si se cambia el nombre en la consola de Amazon, esta línea es lo ÚNICO del código que hay
     * que tocar — y hay que tocarla: un skill que se abre diciendo un nombre y contesta con otro
     * suena a que has llamado a quien no era.
     */
    private const string BIENVENIDA = 'Openperu listo. ¿Qué necesitas?';

    private const string REINTENTO = '¿Sigues ahí? Dime qué necesitas.';

    private const string AYUDA = 'Puedes preguntarme por la ocupación, la disponibilidad, '
        . 'las salidas del día o el estado de cuenta de un huésped. Sólo consultas: por voz '
        . 'no registro pagos ni cambio reservas.';

    public function __construct(
        private readonly AlexaFirma $firma,
        private readonly AlexaUsuarios $usuarios,
        private readonly VoiceAssistant $asistente,
        private readonly AgentActorFactory $actores,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default::ALEXA_SKILL_ID)%')]
        private readonly ?string $skillId = null,
    ) {}

    #[Route('', name: 'app_agent_alexa', methods: ['POST'])]
    public function __invoke(Request $peticion): Response
    {
        // El cuerpo CRUDO, antes de tocarlo: la firma se calcula sobre estos bytes exactos.
        $cuerpo = $peticion->getContent();

        if (!$this->firma->esValida($peticion, $cuerpo)) {
            // Único caso sin cuerpo hablable: no hay usuario a quien explicárselo, y un 200
            // aquí es una invitación a insistir.
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $sobre = json_decode($cuerpo, true);
        if (!is_array($sobre)) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $alexa = PeticionAlexa::desde($sobre);

        if (!$this->firma->marcaDeTiempoEsReciente($alexa->timestamp)) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        // Sin skill id configurado NO se atiende a nadie. Cierra en falso a propósito: sin esta
        // comprobación, cualquier skill de Alexa del mundo podría apuntar a esta URL y hablar
        // con el PMS con una firma perfectamente válida — la firma sólo prueba que viene de
        // Amazon, no que venga de NUESTRO skill.
        if ($this->skillId === null || $this->skillId === '' || $alexa->applicationId !== $this->skillId) {
            $this->logger->warning(sprintf(
                'Alexa: applicationId inesperado (%s).',
                $alexa->applicationId ?? 'ninguno'
            ));

            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        // A partir de aquí la petición es legítima: todo se responde 200 con voz, incluso los
        // errores. Un 500 hace que el dispositivo diga «hubo un problema con la skill» y el
        // operador se queda sin saber qué falló.
        //
        // `new JsonResponse` y no `$this->json()`: el helper del controlador pasa por el
        // serializer del proyecto si está registrado, y el sobre de Alexa tiene una forma fija
        // que cualquier normalizador de por medio rompería.
        return new JsonResponse($this->resolver($alexa)->aArray());
    }

    private function resolver(PeticionAlexa $alexa): RespuestaAlexa
    {
        if ($alexa->esFin()) {
            return RespuestaAlexa::silencio();
        }

        $usuario = $this->usuarios->resolver($alexa);
        if ($usuario === null) {
            return RespuestaAlexa::despedida(
                $this->usuarios->estaConfigurado()
                    ? 'Este dispositivo no está autorizado.'
                    : 'El asistente de voz no está configurado todavía.'
            );
        }

        if ($alexa->esLanzamiento()) {
            return RespuestaAlexa::sigueEscuchando(self::BIENVENIDA, [], self::REINTENTO);
        }

        if (!$alexa->esIntent()) {
            return RespuestaAlexa::silencio();
        }

        if ($alexa->pideSalir()) {
            return RespuestaAlexa::despedida('Hasta luego.');
        }

        if ($alexa->pideAyuda()) {
            return RespuestaAlexa::sigueEscuchando(self::AYUDA, $alexa->historial(self::MAX_TURNOS_RECORDADOS), self::REINTENTO);
        }

        $consulta = $alexa->consulta;
        if ($consulta === null) {
            // `AMAZON.FallbackIntent` y cualquier intent sin slot acaban aquí: Alexa oyó algo
            // que su modelo no supo encajar. Se repregunta en vez de cerrar.
            return RespuestaAlexa::sigueEscuchando(
                'No te he entendido. ¿Me lo repites?',
                $alexa->historial(self::MAX_TURNOS_RECORDADOS),
                self::REINTENTO
            );
        }

        $historial = $alexa->historial(self::MAX_TURNOS_RECORDADOS);

        try {
            // El actor lleva los roles EFECTIVOS —con la jerarquía de security.yaml expandida—
            // y `origen: 'alexa'`, que es lo que verán las skills y el log. No hace falta un
            // constructor nuevo: `delEquipoPorChat()` ya recibe el origen como parámetro.
            $texto = $this->asistente->preguntar(
                $consulta,
                $this->actores->delEquipoPorChat($usuario, 'alexa'),
                $historial
            );
        } catch (Throwable $e) {
            $this->logger->error('Alexa: ' . $e->getMessage(), ['exception' => $e]);

            return RespuestaAlexa::sigueEscuchando(
                'Ahora mismo no puedo consultarlo. Inténtalo en un momento.',
                $historial,
                self::REINTENTO
            );
        }

        $historial[] = ['rol' => 'usuario', 'texto' => $consulta];
        $historial[] = ['rol' => 'asistente', 'texto' => $texto];

        return RespuestaAlexa::sigueEscuchando(
            $texto,
            array_slice($historial, -self::MAX_TURNOS_RECORDADOS),
            self::REINTENTO
        );
    }
}
