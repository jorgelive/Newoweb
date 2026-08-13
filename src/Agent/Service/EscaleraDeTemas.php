<?php

declare(strict_types=1);

namespace App\Agent\Service;

use App\Message\Entity\Message;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * En qué peldaño de un tema va esta conversación.
 *
 * ── Qué problema resuelve ───────────────────────────────────────────────────
 * Una persona no contesta lo mismo dos veces: dice «prueba esto», y si vuelven, «prueba lo otro»,
 * y si nada funciona manda al técnico. El agente, en cambio, tenía toda la escalera escrita de
 * corrido en el contenido del tema y la orden de dosificarla **en prosa** («si insiste, no
 * repitas las instrucciones: avisa al equipo»). Es decir: se le enseñaba entero y se le pedía que
 * se autocensurara la mitad. Eso ya falló otras veces aquí —los teléfonos del personal de
 * limpieza se colaron tres veces pese a una instrucción en mayúsculas—, y además tiene un coste
 * comercial: al que sólo pregunta cómo funciona la ducha se le contestaba con avisos de avería.
 *
 * Aquí el peldaño lo decide **código**, contando lo que ya se le dijo.
 *
 * ── Cómo se cuenta ──────────────────────────────────────────────────────────
 * Cada respuesta que sale con un tema de guía deja su huella en la metadata del mensaje saliente
 * (`tema_peldano`). Para saber el peldaño de ahora, se cuentan las huellas de ese mismo tema que
 * ya salieron. No hay contador que mantener: **se recalcula leyendo los mensajes**.
 *
 * Esa decisión es deliberada y tiene un motivo concreto: la fusión de conversaciones por contacto
 * está pendiente (§20). Un contador agregado en `MessageConversation` se corrompería al fusionar;
 * los mensajes, en cambio, viajan enteros y se llevan su huella puesta.
 *
 * ── Lo que NO se cuenta ─────────────────────────────────────────────────────
 * ⚠️ **Si un humano contestó después**, la cuenta se reinicia: lo que venga a partir de ahí es
 * conversación con la persona, no insistencia con el bot. Subir peldaños sobre una conversación
 * que ya lleva un operador dentro sería escalar lo que ya está escalado.
 *
 * ⚠️ **Fuera de la ventana no cuenta.** Preguntar por la ducha en marzo y volver en junio no es
 * insistir: es una avería nueva, y merece empezar por el principio como cualquiera.
 */
final class EscaleraDeTemas
{
    /**
     * El tema que se ha servido en el turno que se está respondiendo ahora.
     *
     * Existe porque las dos mitades del mecanismo viven en sitios distintos: quien SABE qué tema
     * salió es la skill, y quien ESCRIBE el mensaje saliente es el procesador. En vez de hacer
     * que el procesador rebusque en los resultados de las herramientas —frágil: cambia la forma
     * de un array y la escalera deja de contar sin que nada avise—, la skill lo deja anotado aquí
     * y el procesador lo recoge.
     *
     * ⚠️ Se **consume** al recogerlo ({@see tomarServido()}). Un proceso que atiende varios
     * mensajes seguidos comparte esta instancia, y un resto sin consumir se pegaría al mensaje
     * siguiente: la huella acabaría en una conversación que no habló de ese tema y la escalera
     * subiría peldaños sola.
     *
     * ⚠️ Es una LISTA, no una plaza única. Un mensaje puede tocar dos temas —«no hay agua
     * caliente y el calefactor no prende»— y con una sola plaza el segundo pisaba al primero:
     * la escalera de la ducha no subía. También es lo que permite que convivan temas de dominios
     * distintos en el mismo turno cuando Turismo tenga los suyos.
     *
     * @var list<array{tema: string, peldano: int}>
     */
    private array $servido = [];

    /**
     * Cuánto dura la memoria de un tema.
     *
     * Ni tan corta que un huésped que escribe de noche y vuelve por la mañana empiece de cero
     * —ahí sí insiste—, ni tan larga que arrastre una avería resuelta de la estancia anterior.
     * Dos semanas cubre una estancia entera, que es el horizonte real de estas conversaciones.
     */
    private const int DIAS_DE_MEMORIA = 14;

    /**
     * Tope de peldaños que se recorren, pase lo que pase.
     *
     * Red por si alguien llena un ítem con ocho pasos: a partir de aquí se escala igual. Un tema
     * que necesita más de dos intentos por chat no es un tema mal contado, es una visita.
     */
    public const int MAX_PELDANOS = 3;

    /** Tope de salientes que se miran hacia atrás. Con 14 días de ventana no hace falta más. */
    private const int MAX_MENSAJES = 50;

    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    /**
     * El peldaño que toca ahora: 0 la primera vez, 1 tras la primera respuesta, y así.
     *
     * Devuelve 0 cuando no hay conversación (el equipo consultando la guía desde el panel, por
     * ejemplo): sin hilo no hay insistencia que contar, y lo correcto es la respuesta normal.
     */
    public function peldanoPara(?string $conversacionId, string $temaId): int
    {
        if ($conversacionId === null || !Uuid::isValid($conversacionId) || $temaId === '') {
            return 0;
        }

        $desde = new \DateTimeImmutable(sprintf('-%d days', self::DIAS_DE_MEMORIA));

        // ⚠️ EL ID CON TIPO `uuid`, NUNCA LA ENTIDAD. Los ids son BINARY(16) y pasar el objeto
        // aquí no da error: **devuelve cero filas**. Con eso la escalera se quedaría clavada en
        // el primer peldaño para siempre, repitiendo la misma respuesta sin que nada lo delate,
        // que es justo el fallo que este servicio existe para evitar. Mismo patrón que
        // {@see \App\Message\Service\Push\NotificadorPushConversacion}.
        /** @var list<Message> $salientes */
        $salientes = $this->em->getRepository(Message::class)->createQueryBuilder('m')
            ->andWhere('IDENTITY(m.conversation) = :c')
            ->setParameter('c', $conversacionId, 'uuid')
            ->andWhere('m.direction = :dir')->setParameter('dir', Message::DIRECTION_OUTGOING)
            ->andWhere('m.createdAt >= :desde')->setParameter('desde', $desde)
            // Lo que nunca llegó no cuenta como peldaño servido: una huella en un mensaje que
            // Meta rechazó daría por explicado algo que el huésped jamás leyó.
            ->andWhere('m.status NOT IN (:muertos)')
            ->setParameter('muertos', [Message::STATUS_CANCELLED, Message::STATUS_FAILED])
            ->orderBy('m.createdAt', 'DESC')
            // Desempate estable: `created_at` tiene precisión de segundo, y dos mensajes del
            // mismo segundo salían en orden arbitrario. Eso importa aquí más que en otros sitios
            // porque lo primero que se mira es QUIÉN habló último: con el orden suelto, la
            // respuesta de un operador podía quedar detrás de la del bot y no cortar la cuenta.
            // Los ids son UUID v7, así que ordenan por tiempo de creación.
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults(self::MAX_MENSAJES)
            ->getQuery()->getResult();

        $cuenta = 0;
        $servidoMax = -1;

        foreach ($salientes as $mensaje) {
            // ⚠️ CORTA UNA PERSONA, NO «CUALQUIER COSA QUE NO SEA LA IA». La primera versión
            // rompía la cuenta con `generado_por !== 'ia'`, y esa clave sólo la escriben dos
            // sitios: los recordatorios programados de `MessageRuleEngine`, las plantillas del
            // autoresponder y los avisos de escalado salen todos SIN ella. Como prácticamente
            // toda reserva tiene mensajes programados, la escalera se habría quedado clavada en
            // el peldaño 0 casi siempre —repitiendo la misma respuesta— sin que nada lo delatara.
            //
            // El discriminador bueno es una columna, y ya lo usa `hayHumanoAtendiendo()`:
            // SENDER_HOST es una persona escribiendo. Lo demás, si no lleva huella, se ignora.
            if ($mensaje->getSenderType() === Message::SENDER_HOST) {
                break;
            }

            foreach ($this->huellasDe($mensaje) as $huella) {
                if (($huella['tema'] ?? null) !== $temaId) {
                    continue;
                }

                ++$cuenta;
                $servidoMax = max($servidoMax, (int) ($huella['peldano'] ?? 0));
            }
        }

        // ⚠️ MANDA EL PELDAÑO GUARDADO, NO SÓLO CUÁNTAS HUELLAS HAY. Contar asume que se
        // sirvieron los peldaños 0..N−1 en orden, y `ya_lo_intento` rompe ese invariante: quien
        // entra directo al paso 1 deja UNA huella, y contándola volvería a recibir el paso 1 —el
        // fallo exacto que esto existe para evitar—. La huella sabe en qué peldaño se sirvió.
        $peldano = $servidoMax >= 0 ? max($cuenta, $servidoMax + 1) : 0;

        // Lo servido en ESTE turno todavía no está en la base. Sin esto, la promesa de
        // `si_no_le_funciona` —«vuelve a llamarme y te doy el siguiente»— sería mentira: la
        // segunda llamada del mismo turno devolvería el mismo texto y el modelo daría vueltas.
        foreach ($this->servido as $servido) {
            if ($servido['tema'] === $temaId) {
                $peldano = max($peldano, $servido['peldano'] + 1);
            }
        }

        return min($peldano, self::MAX_PELDANOS);
    }

    /**
     * Las huellas de un saliente, en las dos formas que existen.
     *
     * `temas_peldano` es una lista y es la forma actual; `tema_peldano` era un objeto suelto y
     * **sigue habiendo mensajes con ella en producción**. Dejar de leerla sería reiniciar a cero
     * la escalera de todas las conversaciones vivas el día del despliegue.
     *
     * @return list<array{tema?: string, peldano?: int}>
     */
    private function huellasDe(Message $mensaje): array
    {
        $metadata = $mensaje->getMetadata();

        if (is_array($metadata['temas_peldano'] ?? null)) {
            return array_values(array_filter($metadata['temas_peldano'], 'is_array'));
        }

        return is_array($metadata['tema_peldano'] ?? null) ? [$metadata['tema_peldano']] : [];
    }

    /** La skill deja constancia de qué tema y qué peldaño acaba de entregar. */
    public function anotarServido(string $temaId, int $peldano): void
    {
        if ($temaId !== '') {
            $this->servido[] = ['tema' => $temaId, 'peldano' => $peldano];
        }
    }

    /**
     * Tira lo anotado sin usarlo.
     *
     * Se llama al ABRIR cada turno y antes de encolar un acuse de recibo. Sin esto, un turno que
     * consultó la guía y acabó callándose —la ráfaga, el humano que entró a mitad, el motor sin
     * texto— dejaba la huella colgando, y el worker de Messenger, que es un proceso largo que
     * atiende conversaciones distintas en fila, se la pegaba al saliente del **siguiente**
     * huésped. Como los ítems se comparten entre casitas, ese id sí colisiona de verdad: alguien
     * que preguntaba por primera vez recibía el paso 1.
     */
    public function limpiar(): void
    {
        $this->servido = [];
    }

    /**
     * Lo servido en este turno, y se olvida.
     *
     * Se vacía al leerlo a propósito: ver el aviso de {@see $servido}.
     *
     * @return list<array{tema: string, peldano: int}>
     */
    public function tomarServido(): array
    {
        $servido = $this->servido;
        $this->servido = [];

        return $servido;
    }
}
