<?php

declare(strict_types=1);

namespace App\Message\Service\Conversacion;

use App\Message\Contract\ChannelEnqueuerInterface;
use App\Message\Entity\MessageChannel;
use App\Message\Entity\MessageConversation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Qué canales puede ofrecerle el panel al operador para este hilo, y por qué no los demás.
 *
 * ── Qué problema cierra ─────────────────────────────────────────────────────
 * `ChatView.vue` lo decidía en TypeScript, con una regla de negocio **copiada a mano**:
 *
 * ```js
 * if (chat.contextType !== 'pms_reserva') return false;            // ← espejo sin declarar
 * const bannedOrigins = ['directo', 'whatsapp'];                   // ← copia de Beds24SendEnqueuer
 * ```
 *
 * Dos problemas a la vez. Uno, es la misma regla escrita dos veces, que es justo lo que
 * `CLAUDE.md` prohíbe sin declararlo como espejo en ambos lados. Y dos —el grave—, la copia de
 * TypeScript juzga por la **CABECERA** del hilo, que es la señal que dejó de ser fiable el día
 * que las conversaciones se fusionaron por persona: un hilo puede llevar una estancia de
 * Booking y un viaje a la vez, y la cabecera sólo nombra a uno.
 *
 * ── Las dos capas, que son de distinto tipo ─────────────────────────────────
 * ```
 * ¿EXISTE el canal para este asunto?     ConversacionEnlaceInterface::canalesPosibles()
 *                                        estructural: un expediente de viaje no tiene Beds24
 *
 * ¿ESTÁ USABLE ahora mismo?              ChannelEnqueuerInterface::disponiblePara()
 *                                        por registro: sin bookId, sin teléfono, canal vetado
 * ```
 *
 * Se preguntan a quien ya las sabe. Aquí no se decide nada: se junta.
 */
final readonly class CanalesDisponibles
{
    /** @param iterable<ChannelEnqueuerInterface> $encoladores */
    public function __construct(
        private EntityManagerInterface $em,
        private EnlacesDeConversacion $enlaces,
        #[AutowireIterator('app.message.enqueuer')]
        private iterable $encoladores,
    ) {
    }

    /**
     * Todos los canales activos, ninguno disponible, con el mismo motivo.
     *
     * Para cuando **no hay hilo que consultar**: sin teléfono ni correo no se puede abrir, y sin
     * hilo `para()` no tiene contra qué preguntar. Antes eso hacía reventar la previsualización
     * entera, así que el operador no veía ni el documento ni por qué no podía mandarlo — sólo un
     * error. Enseñar los canales apagados con su motivo dice lo mismo y deja leer el resto.
     *
     * @return list<array{id: string, nombre: string, disponible: bool, motivo: ?string}>
     */
    public function ningunoDisponible(string $motivo): array
    {
        /** @var list<MessageChannel> $canales */
        $canales = $this->em->getRepository(MessageChannel::class)->findBy(['isActive' => true], ['id' => 'ASC']);

        return array_map(
            static fn (MessageChannel $c): array => [
                'id'         => (string) $c->getId(),
                'nombre'     => (string) $c->getName(),
                'disponible' => false,
                'motivo'     => $motivo,
            ],
            $canales
        );
    }

    /**
     * @return list<array{id: string, nombre: string, disponible: bool, motivo: ?string}>
     */
    public function para(
        MessageConversation $conversacion,
        ?string $asuntoType = null,
        ?string $asuntoId = null
    ): array {
        $posibles = $this->enlaces->canalesPosibles($conversacion, $asuntoType, $asuntoId);

        /** @var list<MessageChannel> $canales */
        $canales = $this->em->getRepository(MessageChannel::class)->findBy(['isActive' => true], ['id' => 'ASC']);

        $salida = [];

        foreach ($canales as $canal) {
            $id = (string) $canal->getId();

            // Los motivos son CÓDIGOS opacos, no frases. El texto lo escribe el panel, que es
            // quien sabe en qué idioma y con cuántos caracteres cabe; y así el núcleo no acaba
            // redactando para una pantalla que no conoce.
            $motivo = match (true) {
                // Lista vacía = sin acotar. Ver `canalesPosibles()`.
                $posibles !== [] && !in_array($id, $posibles, true) => 'no_existe_para_el_asunto',
                !$this->encoladorDe($canal)?->disponiblePara($conversacion, $asuntoType, $asuntoId) => 'sin_datos_o_vetado',
                default => null,
            };

            $salida[] = [
                'id' => $id,
                'nombre' => (string) $canal->getName(),
                'disponible' => $motivo === null,
                'motivo' => $motivo,
            ];
        }

        return $salida;
    }

    private function encoladorDe(MessageChannel $canal): ?ChannelEnqueuerInterface
    {
        foreach ($this->encoladores as $encolador) {
            if ($encolador->supports($canal)) {
                return $encolador;
            }
        }

        // Un canal activo sin encolador no se puede ofrecer: no hay quien lo mande. Pasa
        // mientras se da de alta uno nuevo —`email` lleva meses así— y el panel tiene que
        // mostrarlo apagado, no ausente.
        return null;
    }
}
