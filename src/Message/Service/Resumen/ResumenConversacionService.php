<?php

declare(strict_types=1);

namespace App\Message\Service\Resumen;

use App\Agent\Access\AgentActorFactory;
use App\Agent\Conversation\ConversationRequest;
use App\Agent\Conversation\PotenciaRequerida;
use App\Agent\Conversation\SelectorDePotencia;
use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Resume en una línea lo que el huésped ha escrito desde la última respuesta del equipo.
 *
 * ¿Por qué esta ventana y no la conversación entera?: porque es la pregunta que el
 * operador tiene delante —«¿qué me están pidiendo AHORA?»— y porque hace la llamada
 * ridículamente barata. Entran solo los mensajes del huésped posteriores al último
 * saliente (normalmente dos o tres líneas) y sale una frase. Con el tramo BAJO de
 * potencia el coste por conversación es despreciable.
 *
 * Lo consume:
 * - el cuerpo de la notificación push (`MessageConversationMercureListener`),
 * - el panel de chats sin leer del portal (`ResumenNoLeidosService`),
 * - la bandeja del chat (grupo de serialización `conversation:read`).
 *
 * Corre SIEMPRE en segundo plano (`GenerarResumenDispatch`): es una llamada de red y no
 * tiene nada que hacer dentro de un listener de Doctrine.
 */
final readonly class ResumenConversacionService
{
    /**
     * Tope de mensajes que entran al prompt.
     *
     * La ventana ya es corta por definición, pero un huésped que escriba cuarenta líneas
     * sin que nadie conteste no debe convertir esto en una llamada cara. Se quedan los
     * más RECIENTES, que son los que importan.
     */
    private const int MAX_MENSAJES = 15;

    /** Tope de caracteres por mensaje: evita que un pegote enorme infle el prompt. */
    private const int MAX_CARACTERES_MENSAJE = 500;

    private const string SYSTEM_PROMPT = <<<'TXT'
        Resumes mensajes de huéspedes de un alojamiento para que el equipo sepa, de un
        vistazo, qué le están pidiendo.

        Reglas:
        - Responde con UNA sola frase, en español, de 12 palabras como máximo.
        - Di QUÉ quiere o necesita el huésped, no que "escribió" o "envió un mensaje".
        - Sin saludos, sin comillas, sin emojis, sin punto final.
        - Si son varios asuntos, únelos con "y".
        - Si el mensaje no pide nada (agradecimiento, saludo suelto), dilo tal cual:
          por ejemplo "Agradece la ayuda" o "Saluda sin pedir nada".
        TXT;

    public function __construct(
        private EntityManagerInterface $em,
        private SelectorDePotencia $potencias,
        private AgentActorFactory $actores,
        private LoggerInterface $logger,
        #[Autowire('%env(bool:AGENT_IA_RESUMEN_CONVERSACION)%')]
        private bool $habilitado,
        #[Autowire('%env(AGENT_IA_RESUMEN_POTENCIA)%')]
        private string $potencia,
    ) {}

    /**
     * Regenera el resumen si hay algo nuevo que resumir.
     *
     * Idempotente a propósito: comprueba `resumenIaHasta` contra el mensaje más reciente
     * de la ventana y se va sin llamar al modelo si ya está cubierto. Es lo que permite
     * encolar un trabajo por mensaje entrante sin pagar una llamada por mensaje.
     *
     * @return string Motivo de lo ocurrido, para que el comando de mantenimiento pueda
     *         enseñarlo. NO basta con el logger: en prod monolog va en `fingers_crossed`
     *         con `action_level: error`, así que los warning y los info de un comando que
     *         termina bien **no se escriben nunca**. Diagnosticar por log aquí es
     *         imposible, y por eso el motivo se devuelve.
     */
    public function actualizar(MessageConversation $conversacion): string
    {
        if (!$this->habilitado) {
            return 'deshabilitado (AGENT_IA_RESUMEN_CONVERSACION=0)';
        }

        $mensajes = $this->ventanaSinResponder($conversacion);

        if ($mensajes === []) {
            // El equipo ya contestó: lo que hubiera resumido dejó de ser «lo pendiente».
            $conversacion->setResumenIa(null);
            $conversacion->setResumenIaHasta(null);
            $this->em->flush();

            return sprintf(
                'sin ventana: el equipo ya contestó (corte: %s)',
                $this->corteUltimaSalida($conversacion) ?? 'ninguno'
            );
        }

        $masReciente = $this->fechaDe($mensajes[array_key_last($mensajes)]);
        $cubierto = $conversacion->getResumenIaHasta();

        if ($cubierto !== null && $masReciente !== null && $masReciente <= $cubierto) {
            return 'ya cubierto por un resumen anterior';
        }

        $elegido = $this->potencias->elegir(PotenciaRequerida::desde($this->potencia));
        if ($elegido === null) {
            $this->logger->warning('[ResumenIA] Ningún motor disponible; se conserva el resumen anterior.');
            return 'ningún motor de IA disponible';
        }

        $texto = $this->componerEntrada($mensajes);
        if ($texto === '') {
            return sprintf('los %d mensajes de la ventana no tienen texto', count($mensajes));
        }

        try {
            // Actor de huésped: `turnoDirecto()` ignora skills y permisos —no hay
            // herramientas que autorizar—, así que se usa el de menor privilegio.
            $actor = $this->actores->huesped(
                $conversacion->getContextOrigin() ?? 'chat',
                $conversacion->getContextType(),
                $conversacion->getContextId(),
            );

            $resumen = $elegido->motor->turnoDirecto(new ConversationRequest(
                actor: $actor,
                systemPrompt: self::SYSTEM_PROMPT,
                mensaje: $texto,
                permitirEscritura: false,
                // ⚠️ NO bajar este tope «porque la salida es una frase». Los modelos que
                // razonan antes de contestar —Gemini 3.6 Flash, que es el que atiende
                // este tramo en producción— gastan parte del presupuesto pensando: con
                // 120 se lo comían entero razonando y devolvían texto VACÍO, así que el
                // resumen salía siempre null y sin más rastro que un log de nivel info.
                // La longitud la marca el prompt; esto es solo una red de seguridad.
                maxTokens: 1024,
                modelo: $elegido->modelo,
            ));
        } catch (Throwable $e) {
            // Nunca se propaga: un resumen es un adorno. Si falla, el operador ve el
            // texto del último mensaje, que es lo que veía antes de que esto existiera.
            $this->logger->error('[ResumenIA] Falló la generación: ' . $e->getMessage(), ['exception' => $e]);
            return 'excepción: ' . $e->getMessage();
        }

        $resumen = trim((string) $resumen);
        if ($resumen === '') {
            $this->logger->warning('[ResumenIA] El motor no devolvió texto; se conserva el anterior.');

            return sprintf(
                'el motor %s devolvió vacío (entrada: %d caracteres, %d mensajes)',
                $elegido->etiqueta(),
                mb_strlen($texto),
                count($mensajes)
            );
        }

        $conversacion->setResumenIa($resumen);
        $conversacion->setResumenIaHasta($masReciente);
        $this->em->flush();

        $this->logger->info(sprintf(
            '[ResumenIA] %s (%s): «%s»',
            $conversacion->getGuestName() ?? 'Sin nombre',
            $elegido->etiqueta(),
            $resumen
        ));

        return sprintf('resumido con %s', $elegido->etiqueta());
    }

    /**
     * Texto del último mensaje del huésped, recortado para una notificación.
     *
     * Es el plan B cuando no hay resumen: recién llegado el mensaje, IA apagada o el
     * motor caído. Android enseña unas dos líneas, así que pasar de ~120 caracteres no
     * aporta nada. Vive aquí y no en el listener para que toda la lectura de mensajes de
     * este flujo esté en un único sitio.
     */
    public function textoDeRespaldo(MessageConversation $conversacion): ?string
    {
        $ultimo = $this->em->getRepository(Message::class)->createQueryBuilder('m')
            ->where('m.conversation = :c')
            ->andWhere('m.direction = :entrante')
            ->setParameter('c', $conversacion->getId(), 'uuid')
            ->setParameter('entrante', Message::DIRECTION_INCOMING)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$ultimo instanceof Message) {
            return null;
        }

        $texto = trim(strip_tags((string) $ultimo->getContentLocal()));

        return $texto === '' ? null : mb_strimwidth($texto, 0, 120, '…');
    }

    /**
     * Los mensajes del huésped POSTERIORES a la última respuesta del equipo.
     *
     * El corte es la última salida, no una ventana de tiempo: es exactamente lo que el
     * operador todavía no ha atendido.
     *
     * ⚠️ El parámetro va como `$conversacion->getId()` **con el tipo `uuid` explícito**, no
     * como la entidad. Pasando la entidad, Doctrine extrae el identificador y lo enlaza
     * como la cadena canónica con guiones, mientras que `conversation_id` es un
     * `binary(16)`: la consulta se ejecuta sin error y devuelve CERO filas siempre. El SQL
     * generado se ve perfecto, que es lo que lo hace tan difícil de ver.
     *
     * @return list<Message>
     */
    private function ventanaSinResponder(MessageConversation $conversacion): array
    {
        $repo = $this->em->getRepository(Message::class);

        $ultimaSalida = $this->corteUltimaSalida($conversacion);

        // Se ordena por `createdAt` y no por la fecha efectiva por dos motivos: DQL no
        // admite COALESCE en ORDER BY (sí en SELECT y WHERE), y para un mensaje ENTRANTE
        // ambas coinciden — `scheduledAt` solo lo llevan los salientes programados, y un
        // huésped no programa nada.
        $qb = $repo->createQueryBuilder('m')
            ->where('m.conversation = :c')
            ->andWhere('m.direction = :entrante')
            ->setParameter('c', $conversacion->getId(), 'uuid')
            ->setParameter('entrante', Message::DIRECTION_INCOMING)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults(self::MAX_MENSAJES);

        if ($ultimaSalida !== null) {
            $qb->andWhere('COALESCE(m.scheduledAt, m.createdAt) > :corte')
                ->setParameter('corte', $ultimaSalida);
        }

        // Se piden los más recientes (DESC + límite) y se devuelven en orden de lectura.
        //
        // ⚠️ El `@var` no sobra: `getResult()` devuelve `mixed` y sin él ni el `array_reverse`
        // ni el tipo de retorno de este método comprueban nada (ver CLAUDE.md, §PHPStan).
        /** @var list<Message> $recientes */
        $recientes = $qb->getQuery()->getResult();

        return array_reverse($recientes);
    }


    /**
     * Fecha de la última respuesta REAL del equipo, o `null` si nunca contestó.
     *
     * ⚠️ Excluye los salientes programados a futuro. El motor de reglas deja encolados
     * avisos con `scheduledAt` de dentro de varios días (recordatorio de check-in, etc.):
     * contándolos, el corte se iba a esa fecha futura, ningún mensaje del huésped quedaba
     * «después» y la ventana salía SIEMPRE vacía. Un mensaje que aún no ha salido no es
     * una respuesta.
     *
     * ⚠️ Los paréntesis del OR son obligatorios: `andWhere()` concatena la cadena tal cual
     * y el AND liga más fuerte, así que sin ellos el MAX se calculaba sobre casi toda la
     * tabla de mensajes en vez de sobre esta conversación.
     */
    private function corteUltimaSalida(MessageConversation $conversacion): ?string
    {
        $corte = $this->em->getRepository(Message::class)->createQueryBuilder('m')
            ->select('MAX(COALESCE(m.scheduledAt, m.createdAt))')
            ->where('m.conversation = :c')
            ->andWhere('m.direction = :saliente')
            ->andWhere('(m.scheduledAt IS NULL OR m.scheduledAt <= :ahora)')
            ->setParameter('c', $conversacion->getId(), 'uuid')
            ->setParameter('saliente', Message::DIRECTION_OUTGOING)
            ->setParameter('ahora', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();

        return $corte === null ? null : (string) $corte;
    }

    /** @param list<Message> $mensajes */
    private function componerEntrada(array $mensajes): string
    {
        $lineas = [];

        foreach ($mensajes as $mensaje) {
            $texto = trim(strip_tags((string) $mensaje->getContentLocal()));
            if ($texto === '') {
                continue;
            }
            $lineas[] = mb_substr($texto, 0, self::MAX_CARACTERES_MENSAJE);
        }

        return implode("\n", $lineas);
    }

    private function fechaDe(Message $mensaje): ?\DateTimeInterface
    {
        return $mensaje->getEffectiveDateTime() ?? $mensaje->getCreatedAt();
    }
}
