<?php

declare(strict_types=1);

namespace App\Agent\Service;

use App\Agent\Conversation\SelectorDePotencia;
use App\Agent\Conversation\MotoresFueraDeServicio;
use App\Entity\User;
use App\Message\Service\Aviso\AvisoAlEquipo;
use App\Message\Service\Aviso\AvisoAlEquipoService;
use App\Repository\UserRepository;
use App\Security\Roles;
use App\Service\WebPushNotificationService;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Throwable;

/**
 * Avisa a una persona cuando el agente deja de poder contestar.
 *
 * ── Lo que pasó y por qué hacía falta ───────────────────────────────────────
 * El 19/09/2026 a las 06:49 la API de Google empezó a devolver `402: Your prepayment credits
 * are depleted`. El agente no volvió a generar una sola respuesta. Cada mensaje entrante caía
 * al acuse de recibo —«un compañero te responderá en breve»— y salía idéntico, una vez por
 * mensaje: a un huésped que quería reservar tres tours le llegó cuatro veces seguidas, incluso
 * después de un «gracias».
 *
 * **Y nadie se enteró en 31 horas.** El fallo estaba en el log, en `warning`, junto a los otros
 * miles de líneas del día: 57 veces. Lo que descubrió la avería fue que Jorge leyera el chat y
 * le extrañara que el bot repitiera la misma frase.
 *
 * El acuse funcionó como estaba diseñado —es el suelo para que el huésped no se quede mirando
 * el vacío— y por eso mismo es un disfraz perfecto: **degrada tan bien que esconde la caída**.
 * Un sistema que se cae en silencio y se tapa solo es peor que uno que se cae y lo dice.
 *
 * ── Por qué al fallar y no en un vigilante por horas ────────────────────────
 * `VigilanteDeColas` revisa cada cierto tiempo porque lo que mira —trabajo atascado— sólo tiene
 * sentido contarlo acumulado. Esto es al revés: el primer 402 ya es la avería entera, y esperar
 * a la siguiente pasada del cron son mensajes de huéspedes contestados con una frase hecha.
 *
 * ── El silencio que sí hace falta ───────────────────────────────────────────
 * ⚠️ Sin freno, esto avisaría una vez por mensaje entrante: 57 avisos ese día. Y un aviso que
 * llega 57 veces se silencia, que es como se pierden los avisos que importan. Por eso hay
 * ventana: **un aviso por hora**, con el motivo y el recuento de lo que falló mientras tanto.
 * La avería se cuenta cuando empieza y se recuerda mientras dura, no se repite a cada golpe.
 *
 * ── Por dónde sale el aviso ─────────────────────────────────────────────────
 * Por **WhatsApp al grupo de seguridad `soporte`**, con {@see AvisoAlEquipoService}: el mismo
 * camino que usa `escalar_al_equipo`, que reparte a todos los que tienen ese rol y un móvil
 * puesto. Y a propósito por ahí y no sólo por el push del panel: la cola de Meta es
 * **independiente del motor de IA**, así que sigue viva justo cuando esto hace falta. El push
 * se queda como respaldo para cuando WhatsApp no puede entregar —fuera de la ventana de 24 h y
 * sin plantilla aprobada, o sin nadie con móvil—, porque un aviso que no sale es el fallo que
 * esta clase existe para no repetir.
 */
final readonly class VigilanteDelMotor
{
    /** Cuánto se calla tras avisar. Una hora: suficiente para no ser ruido, poco para enterarse. */
    private const int VENTANA_SEGUNDOS = 3600;

    /** El respaldo de fuera de ventana. La crea `msg:crear:aviso-tecnico`. */
    private const string PLANTILLA = 'aviso_tecnico_interno';

    private const string CLAVE = 'agente.motor.caido';
    private const string CLAVE_CUENTA = 'agente.motor.fallos';

    /** Sondeos fallidos seguidos de `app:agent:vigilar-motor`. Ver `DecisionDelSondeo`. */
    private const string CLAVE_SONDEO_FALLOS = 'agente.motor.sondeo.fallos';

    /**
     * Ya se avisó de esta caída. Caduca a las 24 h: si el motor sigue caído, sale UN recordatorio
     * al día, nunca uno por sondeo.
     */
    private const string CLAVE_SONDEO_AVISADO = 'agente.motor.sondeo.avisado';

    private const int RECORDATORIO_SEGUNDOS = 86400;

    public function __construct(
        private CacheItemPoolInterface $cache,
        private AvisoAlEquipoService $avisos,
        private WebPushNotificationService $push,
        private UserRepository $usuarios,
        private RoleHierarchyInterface $jerarquia,
        private LoggerInterface $logger,
        private MotoresFueraDeServicio $fuera,
        private SelectorDePotencia $potencias,
    ) {}

    /**
     * El agente no ha podido contestar. Avisa, si toca.
     *
     * Ni una excepción sale de aquí, igual que en los otros vigilantes: el aviso de una avería
     * no puede provocar una segunda. Si el aviso falla, el huésped ya tiene su acuse y el fallo
     * original ya está en el log.
     *
     * @param string $motivo Lo que dijo el proveedor. Va recortado al aviso: el mensaje de
     *                       Google trae una URL de consola de tres líneas que no cabe.
     */
    public function motorCaido(string $motivo): void
    {
        try {
            $fallos = $this->sumarFallo();

            // La comprobación periódica ya avisó de esta caída: el equipo lo sabe, y un segundo
            // aviso por cada huésped que escriba es el spam que se pidió evitar. Queda en el log.
            if ($this->cache->getItem(self::CLAVE_SONDEO_AVISADO)->isHit()) {
                $this->logger->error(sprintf('[Agente] El motor sigue sin responder (ya avisado): %s', $this->recortar($motivo)));

                return;
            }

            $marca = $this->cache->getItem(self::CLAVE);

            if ($marca->isHit()) {
                return;
            }

            $marca->set(true)->expiresAfter(self::VENTANA_SEGUNDOS);
            $this->cache->save($marca);

            $resumen = $this->recortar($motivo);

            $this->logger->error(sprintf(
                '[Agente] EL MOTOR NO RESPONDE y se está contestando con el acuse de recibo: %s '
                . '(%d fallo(s) acumulado(s)).',
                $resumen,
                $fallos
            ));

            // Se dice lo que el huésped está recibiendo, no sólo que algo falló: es lo que decide
            // si hay que entrar a los chats ahora mismo o puede esperar.
            $resultado = $this->avisos->notificar(new AvisoAlEquipo(
                rol: Roles::CUSTOMER_SUPPORT,
                texto: sprintf(
                    "🤖 El agente NO está contestando.\n\n%s\n\nA los huéspedes les sale «un "
                    . "compañero te responderá en breve» y no lo está contestando nadie. "
                    . "Fallos acumulados: %d.",
                    $resumen,
                    $fallos
                ),
                // El respaldo para fuera de la ventana de 24 h. Mientras Meta no la apruebe, el
                // encolado de la plantilla falla y el aviso cae al push de más abajo; en cuanto
                // esté aprobada, la misma línea empieza a funcionar sin tocar nada.
                plantillaCodigo: self::PLANTILLA,
                // ⚠️ UNA LÍNEA por valor: Meta no admite saltos en los parámetros. `recortar()`
                // ya aplasta el mensaje del proveedor, que llega con URL y tres renglones.
                variables: ['sistema' => 'El agente de IA', 'motivo' => $resumen],
                metadata: ['aviso_motor_caido' => true, 'fallos' => $fallos],
            ));

            if ($resultado->alguienFueAvisado()) {
                return;
            }

            // 🛟 RESPALDO. Nadie de guardia con móvil, o WhatsApp no pudo entregar. El push llega
            // sólo a quien tenga el panel instalado —hoy no todo el equipo—, pero es gratis y es
            // mejor que quedarse sin avisar.
            $this->logger->warning(
                '[Agente] El aviso de motor caído no salió por WhatsApp; se intenta por push.'
            );

            $payload = [
                'title' => '🤖 El agente no está contestando',
                'body' => sprintf(
                    '%s · A los huéspedes les está saliendo «un compañero te responderá en '
                    . 'breve» y nadie lo está contestando.',
                    $resumen
                ),
                'actionUrl' => '/admin',
            ];

            foreach ($this->destinatarios() as $usuario) {
                $this->push->sendToUser($usuario, $payload);
            }
        } catch (Throwable $e) {
            $this->logger->error('[Agente] No se pudo avisar de que el motor está caído: ' . $e->getMessage());
        }
    }

    /**
     * Una ronda de la comprobación periódica (`app:agent:vigilar-motor`), con un resultado por
     * motor: avisa al CAMBIAR de estado —caída, recuperación—, nunca en cada sondeo. La regla es
     * `DecisionDelSondeo::deLaRonda()`; aquí sólo se guarda el estado entre rondas y se avisa.
     *
     * El estado va POR MOTOR (`…sondeo.fallos.<motor>`). La marca sin sufijo
     * (`CLAVE_SONDEO_AVISADO`) se queda como «algún motor está avisado», que es lo único que
     * `motorCaido()` puede consultar: no sabe qué motor le falló al huésped.
     *
     * @param array<string, string|null> $fallos motor => motivo, o `null` si respondió.
     *
     * @return array<string, string> motor => acción tomada (`DecisionDelSondeo::*`).
     */
    public function resultadosDelSondeo(array $fallos): array
    {
        try {
            $previos = [];
            $avisados = [];

            foreach (array_keys($fallos) as $motor) {
                $cuenta = $this->cache->getItem(self::CLAVE_SONDEO_FALLOS . '.' . $motor);
                $previos[$motor] = $cuenta->isHit() && is_int($cuenta->get()) ? $cuenta->get() : 0;
                $avisados[$motor] = $this->cache->getItem(self::CLAVE_SONDEO_AVISADO . '.' . $motor)->isHit();
            }

            $ronda = DecisionDelSondeo::deLaRonda($fallos, $previos, $avisados, $this->cache->getItem(self::CLAVE)->isHit());
            $acciones = [];

            foreach ($ronda['motores'] as $motor => $decision) {
                $acciones[$motor] = $decision['accion'];
                $motor = (string) $motor;

                $cuenta = $this->cache->getItem(self::CLAVE_SONDEO_FALLOS . '.' . $motor);
                $cuenta->set($decision['fallos'])->expiresAfter(2 * self::RECORDATORIO_SEGUNDOS);
                $this->cache->save($cuenta);

                $fallo = $fallos[$motor] ?? null;

                // Responde: vuelve a atender su tramo, se hubiera avisado o no de su caída (un turno
                // pudo sacarlo de servicio sin que el sondeo llegara a verlo caído).
                if ($fallo === null && $this->fuera->devolver($motor)) {
                    $this->logger->info(sprintf('[Agente] «%s» vuelve a atender su tramo de potencia.', $motor));
                }

                if ($decision['accion'] === DecisionDelSondeo::AVISAR_CAIDA && $fallo !== null) {
                    foreach ([self::CLAVE_SONDEO_AVISADO . '.' . $motor, self::CLAVE_SONDEO_AVISADO] as $clave) {
                        $this->cache->save($this->cache->getItem($clave)->set(true)->expiresAfter(self::RECORDATORIO_SEGUNDOS));
                    }

                    $resumen = $this->recortar($fallo);
                    $pista = DecisionDelSondeo::pista($motor, $fallo);

                    // Si hay otro motor que lo cubra, el huésped sigue recibiendo respuesta: el aviso
                    // lo dice, porque es lo que decide si hay que entrar a los chats ya.
                    $this->fuera->sacar($motor, $fallo);
                    $suplente = $this->potencias->suplenteDe($motor)?->nombre();
                    $cubierto = $suplente !== null;

                    $this->logger->error(sprintf('[Agente] La comprobación periódica da el motor «%s» por CAÍDO: %s', $motor, $resumen));

                    $this->avisar(
                        texto: sprintf(
                            "🤖 El motor de IA «%s» NO responde (comprobación automática).\n\n%s%s\n\n%s "
                            . "Te aviso otra vez cuando vuelva.",
                            $motor,
                            $resumen,
                            $pista !== '' ? "\n\n👉 " . $pista : '',
                            $cubierto
                                ? sprintf('Mientras tanto contesta «%s»: los huéspedes siguen recibiendo respuesta.', $suplente)
                                : 'Mientras siga así, a los huéspedes les saldrá «un compañero te responderá en breve».',
                        ),
                        conPlantilla: true,
                        motivoPlantilla: $pista !== '' ? $this->recortar($pista) : sprintf('%s: %s', $motor, $resumen),
                        titulo: sprintf('🤖 El motor «%s» no responde', $motor),
                        cuerpo: $pista !== '' ? $pista : $resumen,
                    );
                }

                if ($decision['accion'] === DecisionDelSondeo::AVISAR_RECUPERACION) {
                    $this->cache->deleteItem(self::CLAVE_SONDEO_AVISADO . '.' . $motor);
                    $this->logger->info(sprintf('[Agente] El motor «%s» vuelve a responder.', $motor));

                    $this->avisar(
                        texto: sprintf('✅ El motor de IA «%s» vuelve a responder (comprobación automática).', $motor),
                        conPlantilla: false,
                        motivoPlantilla: '',
                        titulo: sprintf('✅ «%s» vuelve a responder', $motor),
                        cuerpo: 'La comprobación automática vuelve a recibir respuesta del motor.',
                    );
                }
            }

            if ($ronda['recuperarTurno']) {
                $this->logger->info('[Agente] Todos los motores vuelven a responder tras el aviso de un turno.');

                $this->avisar(
                    texto: '✅ El agente de IA vuelve a responder: todos sus motores contestan (comprobación automática).',
                    conPlantilla: false,
                    motivoPlantilla: '',
                    titulo: '✅ El agente vuelve a responder',
                    cuerpo: 'La comprobación automática vuelve a recibir respuesta de todos los motores.',
                );
            }

            // Con todos respondiendo no queda avería abierta: una caída nueva tiene que avisar ya.
            if ($ronda['todosResponden']) {
                $this->cache->deleteItems([self::CLAVE_SONDEO_AVISADO, self::CLAVE, self::CLAVE_CUENTA]);
            }

            return $acciones;
        } catch (Throwable $e) {
            $this->logger->error('[Agente] No se pudo procesar el sondeo del motor: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Al equipo de atención por WhatsApp y, si no llega a nadie, por push. Con `conPlantilla`, fuera
     * de la ventana de 24 h sale la plantilla aprobada de aviso técnico; la recuperación no la usa
     * (su texto dice que algo NO responde) y depende de la ventana o del push.
     */
    private function avisar(string $texto, bool $conPlantilla, string $motivoPlantilla, string $titulo, string $cuerpo): void
    {
        $resultado = $this->avisos->notificar(new AvisoAlEquipo(
            rol: Roles::CUSTOMER_SUPPORT,
            texto: $texto,
            plantillaCodigo: $conPlantilla ? self::PLANTILLA : null,
            variables: $conPlantilla ? ['sistema' => 'El agente de IA', 'motivo' => $motivoPlantilla] : [],
            metadata: ['aviso_sondeo_motor' => true],
        ));

        if ($resultado->alguienFueAvisado()) {
            return;
        }

        foreach ($this->destinatarios() as $usuario) {
            $this->push->sendToUser($usuario, ['title' => $titulo, 'body' => $cuerpo, 'actionUrl' => '/admin']);
        }
    }

    /** Cuántas veces ha fallado en esta ventana. Sirve para que el aviso diga si es uno o cien. */
    private function sumarFallo(): int
    {
        $item = $this->cache->getItem(self::CLAVE_CUENTA);
        $previa = $item->isHit() ? $item->get() : null;
        $cuenta = is_int($previa) ? $previa + 1 : 1;

        $item->set($cuenta)->expiresAfter(self::VENTANA_SEGUNDOS);
        $this->cache->save($item);

        return $cuenta;
    }

    /**
     * El motivo, en una línea.
     *
     * El 402 de Google llega con instrucciones y un enlace a AI Studio; en una notificación del
     * móvil eso tapa lo único que importa, que es qué proveedor y qué dijo.
     */
    private function recortar(string $motivo): string
    {
        $limpio = trim((string) preg_replace('/\s+/', ' ', $motivo));

        return mb_strlen($limpio) > 120 ? mb_substr($limpio, 0, 117) . '…' : $limpio;
    }

    /**
     * Quién recibe el aviso: los mismos que el de colas, los de operaciones.
     *
     * @return list<User>
     */
    private function destinatarios(): array
    {
        $elegibles = [];

        foreach ($this->usuarios->findAll() as $usuario) {
            $roles = $this->jerarquia->getReachableRoleNames($usuario->getRoles());

            if (in_array(Roles::OPERACIONES_SHOW, $roles, true)) {
                $elegibles[] = $usuario;
            }
        }

        return $elegibles;
    }
}
