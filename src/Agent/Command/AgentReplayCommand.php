<?php

declare(strict_types=1);

namespace App\Agent\Command;

use App\Agent\Access\AgentActorFactory;
use App\Service\Ensayo\ModoEnsayo;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use App\Agent\Conversation\AgentEngineRegistry;
use App\Agent\Conversation\ConversationRequest;
use App\Agent\Conversation\PotenciaRequerida;
use App\Agent\Conversation\SelectorDePotencia;
use App\Agent\Service\AiConversationProcessor;
use App\Agent\Triage\TipoDeMensaje;
use App\Agent\Triage\Triaje;
use App\Entity\User;
use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use InvalidArgumentException;
use App\Pms\Entity\PmsReserva;
use Doctrine\ORM\EntityManagerInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Vuelve a pasarle al agente ACTUAL una conversación que ya ocurrió, para ver qué contestaría hoy.
 *
 * Nació de la reserva V4JE5Q (docs/Mensajeria.md §16.1), donde el agente se inventó un número de
 * Yape, un tipo de cambio y una URL de pago. Arreglado el hueco de datos, la pregunta que queda
 * no se responde mirando el JSON de una skill: **lo que lee el huésped es lo que redacta el
 * modelo**, y sólo se ve haciéndole las mismas preguntas otra vez.
 *
 * ### Qué reproduce, y qué no
 *
 * El mismo camino que {@see AiConversationProcessor::generar()}: triaje primero y, si no era
 * charla, el catálogo entero con el actor del huésped y `permitirEscritura: false`. Los prompts
 * salen por reflexión de `reglas()` y `contexto()` — copiarlos aquí sería una copia que envejece
 * sola y acabaría probando algo que ya no está en producción.
 *
 * **No persiste nada**: el historial se acumula en memoria, así que no crea mensajes, no encola
 * envíos y no marca la conversación como no leída. Y desde el 18/10/2026 la conversación entera
 * corre dentro de una transacción que se DESHACE al terminar: lo que escriban las skills
 * —`confirmar_hora`, `anotar_peticion`, los avisos en cola— tampoco queda. Se puede correr veinte
 * veces seguidas sin que una pasada contamine a la siguiente, que es lo que pasaba: un «nos
 * retiraremos a las 4 am» del guion dejaba la salida de la reserva de prueba en 04:00.
 *
 * ### ⚠️ Lo que no pasa por la base
 *
 * Las skills se ejecutan de verdad. Lo que escriben en la base se deshace; lo que sale de ella en
 * el momento no volvería, y por eso el ensayo enciende {@see ModoEnsayo}: ni push web ni Mercure.
 * `confirmar_hora` avisa por PUSH cuando no consigue avisar por WhatsApp —justo cuando no hay
 * guardia con móvil—, y las skills que persisten mensajes los publicaban en el panel para que
 * desaparecieran al deshacerse. Cualquier otro envío directo por HTTP que se añada tiene que
 * consultar ese interruptor. Las skills que avisan a personas reales, todas por cola o por push:
 *
 * - `escalar_al_equipo` → WhatsApp a quien tenga `ROLE_CUSTOMER_SUPPORT` y móvil.
 * - `enviarme_plantilla` → un mensaje **al propio huésped**, por su canal.
 * - `confirmar_hora` → WhatsApp al equipo, y push si no llega.
 *
 * Las dos sobreviven al filtro de `permitirEscritura: false` porque son `NivelRiesgo::Interna`,
 * que es justo el matiz que las deja llegar al chat del huésped ({@see NivelRiesgo}).
 *
 * ### Comparar motores: `--motor`, `--modelo`, `--salida` (17/10/2026)
 *
 * Para decidir con conversaciones reales si otro proveedor contesta mejor que el actual. Con
 * `--motor` TODAS las respuestas las redacta ese motor: el triaje sigue clasificando —con el motor
 * de siempre, para que la pista y el tema sean los mismos—, pero su atajo de charla se salta, o la
 * mitad de las respuestas de la comparación serían del motor por defecto. `--salida` deja cada
 * turno (respuesta, latencia, herramientas, motivo) en un JSON para ponerlos lado a lado.
 *
 *   php bin/console app:agent:replay <reserva> --motor=anthropic --modelo=claude-sonnet-5-5 --salida=var/replay-sonnet.json
 *
 * ⚠️ La primera versión de este comando sólo vigilaba la primera. Probando el flujo de la
 * reserva V4JE5Q contra una copia de producción, el modelo llamó a la segunda ante «¿ofreces
 * tours?» y se creó un mensaje real con su cola de Beds24 apuntando a una huésped de verdad. No
 * salió de milagro —la plantilla no tenía ID de Meta para «es»—, pero pudo salir. **Vaciar los
 * teléfonos de guardia protege a los operadores, no al huésped.**
 *
 * Por eso el aviso nombra las dos y `--con-guardia` es lo que asume el riesgo entero.
 *
 * Uso:
 *   php bin/console app:agent:replay <uuid-reserva> --guion=var/guion.json
 *   php bin/console app:agent:replay <uuid-reserva>     # los mensajes de esa conversación
 */
#[AsCommand(
    name: 'app:agent:replay',
    description: 'Reproduce una conversación de huésped contra el agente actual, sin guardar nada.',
)]
final class AgentReplayCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AiConversationProcessor $procesador,
        private readonly Triaje $triaje,
        private readonly SelectorDePotencia $potencias,
        private readonly AgentActorFactory $actores,
        private readonly AgentEngineRegistry $motores,
        private readonly EnlacesDeConversacion $enlaces,
        private readonly ModoEnsayo $ensayo,
        #[Autowire('%kernel.environment%')]
        private readonly string $entorno,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('UUID de la reserva cuyo chat se reproduce', name: 'reserva')]
        string $reservaId,
        #[Option('JSON con un array de mensajes del huésped. Sin él, se usan los mensajes entrantes de la conversación.')]
        ?string $guion = null,
        #[Option('Seguir aunque haya gente de guardia con móvil: una escalada les llegará de verdad.')]
        bool $conGuardia = false,
        #[Option('Proveedor que redacta TODAS las respuestas (google, deepseek, anthropic). Sin él, el tramo de potencia de siempre.')]
        ?string $motor = null,
        #[Option('Modelo dentro de ese proveedor. Sin él, el suyo por defecto.')]
        ?string $modelo = null,
        #[Option('Archivo JSON donde dejar cada turno, para comparar motores lado a lado.')]
        ?string $salida = null,
        #[Option('Correr contra la base de PRODUCCIÓN. La transacción del ensayo bloquea las filas que toque hasta el final.')]
        bool $enProduccion = false,
    ): int {
        // El motor fijo se resuelve ANTES de nada: descubrir que no tiene clave a mitad de la
        // conversación es haber gastado el triaje de los turnos anteriores para nada.
        $fijo = null;
        $modeloFijo = null;

        if ($motor !== null) {
            try {
                $fijo = $this->motores->elegir($motor);
                $modeloFijo = $fijo !== null ? $this->motores->validarModelo($fijo, $modelo) : null;
            } catch (InvalidArgumentException $e) {
                $io->error($e->getMessage());

                return Command::INVALID;
            }
        }


        $reserva = $this->em->getRepository(PmsReserva::class)->find($reservaId);

        if ($reserva === null) {
            $io->error(sprintf('No existe la reserva %s.', $reservaId));
            return Command::INVALID;
        }

        // El hilo TITULAR de la reserva, con la cabecera de respaldo: desde la fusión por contacto
        // la pareja (contextType, contextId) no es única. Ver docs/Agent.md §2.0.
        $conversacion = $this->enlaces->hiloTitularDe('pms_reserva', $reservaId)
            ?? $this->em->getRepository(MessageConversation::class)
                ->findOneBy(['contextType' => 'pms_reserva', 'contextId' => $reservaId]);

        if ($conversacion === null) {
            $io->error('Esa reserva no tiene conversación, y el agente del huésped se acota por ella.');
            return Command::INVALID;
        }

        if (!$this->guardiaDespejada($io, $conGuardia)) {
            return Command::FAILURE;
        }

        $mensajes = $this->guion($guion, $conversacion);

        if ($mensajes === []) {
            $io->error('No hay mensajes que reproducir.');
            return Command::INVALID;
        }

        // Los prompts REALES. Son privados a propósito y aquí se leen por reflexión: es una
        // herramienta de diagnóstico, no un consumidor con derecho a esa API.
        $reflex = new ReflectionClass(AiConversationProcessor::class);
        $reglas = $reflex->getMethod('reglas');
        $contextoDe = $reflex->getMethod('contexto');

        $actor = $this->actores->huesped('beds24', 'pms_reserva', $reservaId);
        $historial = [];
        /** @var list<array<string, mixed>> $turnos */
        $turnos = [];

        $io->title(sprintf(
            '%s · %s',
            (string) $reserva->getLocalizador(),
            trim($reserva->getNombreCliente() . ' ' . $reserva->getApellidoCliente())
        ));

        // ⚠️ Contra producción, sólo si se pide. La transacción dura el ensayo entero —minutos, con
        // las llamadas al modelo dentro— y retiene los bloqueos de lo que escriban las skills: un
        // webhook de Beds24 o de Meta sobre esa reserva o ese hilo espera, agota el
        // `innodb_lock_wait_timeout` y falla. Partirla por turno divergiría de producción, donde el
        // turno 3 sí ve lo que escribió el 1.
        if ($this->entorno === 'prod' && !$enProduccion) {
            $io->error('Esto es PRODUCCIÓN: el ensayo bloquea las filas que toque mientras dura y puede hacer esperar a un webhook. Repite con --en-produccion si lo asumes.');

            return Command::FAILURE;
        }

        // 🔒 TODO lo que escriban las skills se deshace al terminar (18/10/2026). El historial ya
        // vivía en memoria, pero las skills `Interna` escriben de verdad: `confirmar_hora` dejó
        // «salida 04:00» en la reserva de prueba porque un huésped del guion dijo «nos retiraremos
        // como a las 4 am», y las conversaciones siguientes lo leyeron como dato. Las peticiones
        // anotadas y los avisos en cola (Messenger y las colas de envío viven en esta misma base)
        // también se van. Lo que sale de la base en el momento —push, Mercure— lo corta ModoEnsayo.
        //
        // Con savepoints, porque DBAL 3 no los usa por defecto: sin ellos, una skill que falla y
        // deshace SU transacción marca la de fuera como «sólo rollback», y todas las escrituras
        // posteriores del ensayo fallarían — un resultado que no tiene nada que ver con el motor.
        // ⚠️ Cubre el rollback de una transacción anidada, no un `flush()` que revienta: ése además
        // CIERRA el EntityManager, y lo que venga después falla con «EntityManager is closed».
        $conexion = $this->em->getConnection();
        $conSavepoints = $conexion->getNestTransactionsWithSavepoints();
        $conexion->setNestTransactionsWithSavepoints(true);
        $conexion->beginTransaction();
        $this->ensayo->activar();

        try {
        foreach ($mensajes as $i => $mensaje) {
            $io->writeln(sprintf("\n<fg=cyan>[%d] 👤 %s</>", $i + 1, str_replace("\n", "\n         ", $mensaje)));

            $decision = $this->triaje->clasificar(
                $actor,
                $mensaje,
                $historial,
                $this->porNombre($contextoDe, [
                    'conversacion' => $conversacion,
                    'actor' => $actor,
                ])
            );

            $io->writeln(sprintf(
                '     <comment>triaje: %s%s</comment>',
                $decision->tipo->value,
                $decision->skill !== null ? " → {$decision->skill}" : ''
            ));

            $segundos = 0.0;
            $herramientas = [];
            $motivo = 'triaje';
            $usado = 'triaje';

            if ($fijo === null && $decision->tipo === TipoDeMensaje::Conversacion && $decision->respuesta !== null) {
                $respuesta = $decision->respuesta;
                $io->writeln('     <comment>(resuelto en el triaje, sin herramientas)</comment>');
            } else {
                $tramo = $decision->tipo === TipoDeMensaje::Emergencia
                    ? PotenciaRequerida::Alta
                    : PotenciaRequerida::Media;

                if ($fijo !== null) {
                    $quien = $fijo;
                    $conModelo = $modeloFijo;
                } else {
                    $elegido = $this->potencias->elegir($tramo);

                    if ($elegido === null) {
                        $io->error(sprintf('Sin proveedor con credenciales para el tramo %s.', $tramo->value));
                        return Command::FAILURE;
                    }

                    $quien = $elegido->motor;
                    $conModelo = $elegido->modelo;
                }

                $usado = $quien->nombre() . ':' . ($conModelo ?? $quien->modeloPorDefecto());
                $cronometro = microtime(true);

                $respuestaMotor = $quien->conversar(new ConversationRequest(
                    actor: $actor,
                    systemPrompt: $this->porNombre($reglas, ['actor' => $actor]),
                    mensaje: $mensaje,
                    historial: $historial,
                    // La real es `$actor->esDelEquipo()`. Aquí el actor SIEMPRE es el huésped,
                    // así que el valor coincide; se deja explícito para que se lea como lo que es
                    // —un ensayo no escribe— y no como una copia que se quedó atrás.
                    permitirEscritura: false,
                    // El mismo techo que el turno real (`AiConversationProcessor::TOPE_DEL_TURNO`):
                    // con uno más bajo, el ensayo fallaría por el tope —el pensamiento sale de
                    // ahí— y no por lo que se esté probando.
                    // El MISMO tope que producción, leído de allí para que no diverjan.
                    maxTokens: AiConversationProcessor::TOPE_DEL_TURNO,
                    modelo: $conModelo,
                    contexto: $this->porNombre($contextoDe, [
                        'conversacion' => $conversacion,
                        'actor' => $actor,
                        'decision' => $decision,
                    ]),
                ));

                $segundos = microtime(true) - $cronometro;
                $herramientas = $respuestaMotor->skillsUsadas;
                $motivo = $respuestaMotor->motivo;
                $respuesta = $respuestaMotor->tieneTexto() ? (string) $respuestaMotor->texto : sprintf('(sin texto: %s)', $respuestaMotor->motivo);
                $io->writeln(sprintf(
                    '     <comment>%s · %.1f s · motivo: %s · herramientas: %s</comment>',
                    $usado,
                    $segundos,
                    $motivo,
                    $herramientas === [] ? '—' : implode(', ', $herramientas),
                ));
            }

            $turnos[] = [
                'n' => $i + 1,
                'huesped' => $mensaje,
                'triaje' => $decision->tipo->value . ($decision->skill !== null ? ' → ' . $decision->skill : ''),
                'motor' => $usado,
                'segundos' => round($segundos, 1),
                'herramientas' => $herramientas,
                'motivo' => $motivo,
                'respuesta' => trim((string) $respuesta),
            ];

            $io->writeln(sprintf("    <fg=green>🤖 %s</>", str_replace("\n", "\n       ", trim((string) $respuesta))));

            $historial[] = ['rol' => 'usuario', 'texto' => $mensaje];
            $historial[] = ['rol' => 'asistente', 'texto' => (string) $respuesta];
        }
        } finally {
            $this->ensayo->desactivar();
            while ($conexion->isTransactionActive()) {
                $conexion->rollBack();
            }
            $conexion->setNestTransactionsWithSavepoints($conSavepoints);
            // Lo que quedó en memoria describe filas que ya no existen.
            $this->em->clear();
        }

        $io->newLine();

        $conMotor = array_values(array_filter($turnos, static fn (array $t): bool => $t['motor'] !== 'triaje'));
        if ($conMotor !== []) {
            $io->writeln(sprintf(
                '<info>%d turno(s) con motor · %.1f s de media · %d llamada(s) a herramientas.</info>',
                \count($conMotor),
                array_sum(array_column($conMotor, 'segundos')) / \count($conMotor),
                array_sum(array_map(static fn (array $t): int => \is_array($t['herramientas']) ? \count($t['herramientas']) : 0, $conMotor)),
            ));
        }

        if ($salida !== null) {
            $json = json_encode([
                'reserva' => $reservaId,
                'localizador' => $reserva->getLocalizador(),
                'motor' => $fijo !== null ? $fijo->nombre() . ':' . ($modeloFijo ?? $fijo->modeloPorDefecto()) : 'el de siempre (tramos de potencia)',
                'fecha' => date(DATE_ATOM),
                'turnos' => $turnos,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            if ($json === false || file_put_contents($salida, $json) === false) {
                $io->error(sprintf('No se pudo escribir %s.', $salida));

                return Command::FAILURE;
            }

            $io->writeln(sprintf('<info>Turnos guardados en %s.</info>', $salida));
        }

        $io->success('Nada de esto se ha guardado en la base: el historial vivió en memoria y lo que escribieron las skills se ha deshecho.');

        return Command::SUCCESS;
    }

    /**
     * ¿Se puede correr sin despertar a nadie?
     *
     * Se comprueba ANTES de la primera llamada al modelo: descubrirlo a mitad de la charla es
     * descubrirlo cuando el WhatsApp ya salió.
     */
    private function guardiaDespejada(SymfonyStyle $io, bool $forzar): bool
    {
        /** @var list<User> $guardias */
        $guardias = $this->em->getRepository(User::class)->createQueryBuilder('u')
            ->where('u.roles LIKE :rol')->setParameter('rol', '%CUSTOMER_SUPPORT%')
            ->andWhere('u.telefono IS NOT NULL')
            ->andWhere("u.telefono <> ''")
            ->getQuery()->getResult();

        if ($guardias === []) {
            return true;
        }

        $nombres = implode(', ', array_map(static fn (User $u) => $u->getUserIdentifier(), $guardias));

        if ($forzar) {
            $io->warning(sprintf('Sigues con guardia activa (%s): si el modelo escala, les llega el aviso.', $nombres));
            return true;
        }

        $io->error(sprintf(
            "Hay gente de guardia con móvil (%s): si el modelo llama a escalar_al_equipo les "
            . "llega un WhatsApp de verdad.\n\n"
            . "Y OJO, vaciar esos teléfonos NO deja la prueba limpia: «enviarme_plantilla» le "
            . "manda un mensaje AL HUÉSPED por su canal, y esa reserva puede ser de una persona "
            . "real. Si vas a preguntar por tours o por el menú, usa una reserva de mentira.\n\n"
            . "Repite con --con-guardia cuando asumas las dos cosas.",
            $nombres
        ));

        return false;
    }

    /**
     * Llama a un método privado del procesador emparejando los argumentos POR NOMBRE.
     *
     * ── Por qué no `invoke($obj, $a, $b)` ───────────────────────────────────
     * Porque esa forma es posicional y muda. `contexto()` y `reglas()` son privadas a propósito
     * —esto es una herramienta de diagnóstico, no un consumidor con derecho a esa API— y al
     * llamarlas por reflexión **PHPStan no ve la firma**. Las dos ganaron un parámetro `actor`
     * cuando el prompt pasó a depender de quién escribe, este comando siguió compilando, y
     * reventaba con un `ArgumentCountError` **a mitad de la primera respuesta**: después de
     * gastar la llamada al triaje, y sólo para quien fuera a probar el agente.
     *
     * Con los nombres, un parámetro nuevo NO revienta a media conversación: se detecta antes de
     * llamar y se dice cuál falta. Y un parámetro que se renombre deja de emparejar, que también
     * es lo que se quiere saber.
     *
     * Los dos que se llaman así devuelven el texto de un prompt; lo que no sea texto es otra
     * firma que cambió sin avisar, y se dice con nombre en vez de castearlo a «Array».
     *
     * @param array<string, mixed> $argumentos Por nombre de parámetro.
     */
    private function porNombre(ReflectionMethod $metodo, array $argumentos): string
    {
        $valores = [];

        foreach ($metodo->getParameters() as $parametro) {
            $nombre = $parametro->getName();

            if (array_key_exists($nombre, $argumentos)) {
                $valores[] = $argumentos[$nombre];
                continue;
            }

            if ($parametro->isDefaultValueAvailable()) {
                $valores[] = $parametro->getDefaultValue();
                continue;
            }

            throw new RuntimeException(sprintf(
                'El replay no sabe qué pasarle a «%s» en `%s()`: el procesador cambió de firma y '
                . 'esta herramienta no se enteró. Añádelo en AgentReplayCommand.',
                $nombre,
                $metodo->getName()
            ));
        }

        $prompt = $metodo->invokeArgs($this->procesador, $valores);
        if (!is_string($prompt)) {
            throw new RuntimeException(sprintf(
                '`%s()` ya no devuelve texto (%s): el procesador cambió de firma. Revisa AgentReplayCommand.',
                $metodo->getName(),
                get_debug_type($prompt)
            ));
        }

        return $prompt;
    }

    /**
     * Los mensajes a reproducir: los de un archivo, o los que entraron en la conversación.
     *
     * @return list<string>
     */
    private function guion(?string $ruta, MessageConversation $conversacion): array
    {
        if ($ruta !== null) {
            $crudo = @file_get_contents($ruta);
            $datos = $crudo === false ? null : json_decode($crudo, true);

            // Lo que no sea texto se descarta: un objeto metido en el guion era, con `strval`, un
            // aviso y la palabra «Array» mandada al modelo como si la hubiera escrito el huésped.
            return is_array($datos)
                ? array_values(array_filter(
                    array_map(static fn (mixed $m): ?string => is_scalar($m) ? (string) $m : null, $datos),
                    static fn (?string $m) => $m !== null && trim($m) !== ''
                ))
                : [];
        }

        $mensajes = [];

        foreach ($conversacion->getMessages() as $m) {
            if ($m->getDirection() !== Message::DIRECTION_INCOMING) {
                continue;
            }

            $texto = $m->getTextoEntrante();

            if ($texto !== '') {
                $mensajes[] = $texto;
            }
        }

        return $mensajes;
    }
}
