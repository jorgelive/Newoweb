<?php

declare(strict_types=1);

namespace App\Message\Factory;

use App\Entity\Maestro\MaestroIdioma;
use App\Message\Contract\MessageContextInterface;
use App\Message\Contract\SincronizadorDeEnlaceInterface;
use App\Message\Enum\IdentidadTipo;
use Psr\Log\LoggerInterface;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use App\Message\Service\Conversacion\ResolutorDeHilo;
use App\Message\Service\Queue\AgendaDeAsunto;
use App\Message\Entity\MessageConversation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

/**
 * MessageConversationFactory
 *
 * Se encarga de crear o actualizar (Upsert) una conversación basándose en su Contexto.
 * Mantiene actualizado el Snapshot (Nombre y Teléfono) para listados rápidos.
 */
readonly class MessageConversationFactory
{
    /**
     * @param iterable<SincronizadorDeEnlaceInterface> $sincronizadores
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ResolutorDeHilo $resolutor,
        private EnlacesDeConversacion $enlaces,
        private LoggerInterface $logger,
        #[AutowireIterator('app.message.sincronizador_enlace')]
        private iterable $sincronizadores = [],
    ) {}

    public function upsertFromContext(MessageContextInterface $context, bool $flush = false): MessageConversation
    {
        $repository = $this->entityManager->getRepository(MessageConversation::class);

        // 0. El ASUNTO manda si ya tiene hilo. Es el camino DURADERO.
        //
        // ⚠️ Va ANTES que la identidad, y ese orden es el arreglo. La identidad sirve para
        // DESCUBRIR a la persona la primera vez; en cuanto el asunto tiene enlace titular, ése
        // es su hilo y no se discute. Con la identidad mandando siempre pasaba esto:
        //
        //   - corriges el teléfono de una reserva y el nuevo número resulta ser de otro hilo
        //     → el asunto MIGRABA solo a ese hilo, en silencio. Y como el sincronizador busca
        //       el enlace dentro de la conversación, creaba otro: la misma reserva colgando de
        //       dos hilos, con DOS agendas, y el huésped recibiendo cada automático dos veces.
        //
        // Con el titular delante, el asunto se queda donde está y el número nuevo es sólo un
        // identificador más. Unir dos historiales sigue siendo decisión de persona:
        // `app:message:fusionar-hilos`.
        $conversation = $this->enlaces->hiloTitularDe($context->getContextType(), $context->getContextId());

        // 1. Por IDENTIDAD: el hilo es de la PERSONA.
        //
        // ⚠️ Este orden es el que impide seguir duplicando hilos. Antes se buscaba sólo por
        // `(contextType, contextId)` —una conversación por reserva— mientras WhatsApp buscaba
        // por teléfono: dos criterios sobre la misma tabla, y de ahí los 20 teléfonos con más
        // de un hilo que mide `ConversacionEnlaceInterface`.
        //
        // Los identificadores los declara el dominio, que es quien sabe cuáles son: para una
        // reserva de OTA el importante es el `bookId` de Beds24, porque nace sin teléfono y sin
        // correo y aun así se le puede escribir.
        $identificadores = $context->getIdentificadores();
        $conversation ??= $this->porIdentificadores($identificadores);

        // 2. Si no, por la llave vieja: es lo que encuentra los hilos que ya existen y todavía
        //    no tienen identidades registradas.
        $conversation ??= $repository->findOneBy([
            'contextType' => $context->getContextType(),
            'contextId'   => $context->getContextId(),
        ]);

        // 3. Si no existe, la instanciamos (NACIMIENTO)
        if (!$conversation) {
            $conversation = new MessageConversation(
                $context->getContextType(),
                $context->getContextId()
            );
            $this->entityManager->persist($conversation);
        }

        // 3-bis. Si el hilo que encontramos nació como WALK-IN, ahora sí sabemos de qué va.
        //
        // Pasa cuando alguien escribe por WhatsApp ANTES de tener reserva: nace `manual`, y al
        // reservar la resolución por identidad devuelve ese mismo hilo. Sin esta línea la
        // cabecera se quedaba en `manual` y el agente —que aún decide por `getContextType()`—
        // le diría que no tiene ninguna reserva, teniéndola enlazada dos líneas más abajo.
        if ($conversation->promoverDesdeManual($context->getContextType(), $context->getContextId())) {
            $this->logger->info('Hilo manual promovido a su asunto real.', [
                'conversacion' => (string) $conversation->getId(),
                'context_type' => $context->getContextType(),
                'context_id'   => $context->getContextId(),
            ]);
        }

        // 4. Se registran los identificadores. Idempotente, y hace que la próxima resolución
        //    —venga por donde venga— caiga en este mismo hilo.
        foreach ($identificadores as $tipo => $valor) {
            if (($caso = IdentidadTipo::tryFrom((string) $tipo)) !== null) {
                $this->resolutor->vincular($conversation, $caso, $valor, 'contexto');
            }
        }

        // =====================================================================
        // 🔥 GESTIÓN DE IDIOMA CON CERROJO (Sin redundancias)
        // =====================================================================
        if (!$conversation->isIdiomaFijado()) {
            // Extraemos los 2 primeros caracteres directo del contrato (ej: de 'en_US' a 'en')
            $langCode = substr($context->getContextLanguage() ?? MaestroIdioma::DEFAULT_IDIOMA, 0, 2);

            // Inyectamos la referencia directamente sin ensuciar con llamadas extra
            $idiomaRef = $this->entityManager->getReference(MaestroIdioma::class, $langCode);
            $conversation->setIdioma($idiomaRef);
        }

        // 3. Snapshot de contacto
        $conversation->setGuestName($context->getContextName());

        // ⚠️ **El teléfono sólo se SIEMBRA; después mandan las identidades.**
        //
        // Esto escribía la semilla del dominio en CADA recálculo, y deshacía en silencio lo que
        // el editor de identidades acababa de decidir: se retiraba un número equivocado, se
        // añadía el bueno, y el siguiente mensaje entrante de Beds24 —que llama aquí— devolvía
        // `guestPhone` al retirado. Todos los WhatsApp volvían a salir al número de un extraño,
        // sin error y con el panel diciendo lo contrario. Y de propina, como el valor «cambiaba»,
        // `setGuestPhone()` levantaba el veto de Meta de un número quizá vetado con razón.
        //
        // Con varios asuntos era peor: el snapshot es del asunto que se recalculó ÚLTIMO, así
        // que un hilo de agencia con cinco reservas iba cambiando de teléfono según cuál tocara.
        //
        // Ahora: si la persona ya tiene teléfonos propios, la copia se recalcula desde ELLOS;
        // la semilla sólo entra cuando no hay ninguno, que es el alta.
        if ($conversation->getTelefonoPrincipal() === null && !$conversation->tieneTelefonoVivo()) {
            $conversation->setGuestPhone($context->getContextPhone());
        }

        // 4. Llenado estricto del JSON (Agnóstico)
        $conversation->setContextOrigin($context->getOrigin());
        $conversation->setContextAgency($context->getAgencyId());
        $conversation->setContextStatusTag($context->getStatusTag());
        $conversation->setContextVinculo($context->getVinculo());
        $conversation->setContextMilestones($context->getMilestones());
        $conversation->setContextItems($context->getItems());
        $conversation->setContextFinancials($context->getFinancialTotal(), $context->isFinancialCleared());

        // 5. AUTO-ARCHIVADO y REACTIVACIÓN
        //
        // ⚠️ Un asunto cancelado cierra el hilo sólo si NO QUEDA OTRO VIVO en él.
        //
        // Aquí hubo un centinela que avisaba de esto: cancelar la reserva A cerraba la
        // conversación entera y silenciaba las agendas vivas de B, porque el motor no aplica
        // reglas a un hilo cerrado. «Es el primer sitio que hay que tocar el día de la fusión.»
        // Ese día llegó sin fusión: basta con que una persona cancele y vuelva a reservar.
        //
        // Vanessa (17/09/2026): 5GEFZ9 cancelada y 2KRERH, su nueva reserva, en el mismo hilo.
        // Cada sincronización recalcula las dos, y el hilo quedaba como lo dejara la ÚLTIMA:
        //
        // | Orden del lote | Hilo | Recordatorio de 2KRERH |
        // |---|---|---|
        // | 5GEFZ9 → 2KRERH | abierto | se crea uno NUEVO (el anterior ya lo cancelaron) |
        // | 2KRERH → 5GEFZ9 | cerrado | cancelado: **no le llega nada** |
        //
        // Reproducido en local con la copia de la base. En producción ese vaivén dejaba copias
        // vivas —8 del mismo recordatorio para Vanessa, 2 para Karina— o silencio, según quién
        // corriera último. El día 14 se arregló la mitad del síntoma (`MessageDispatcher`) y se
        // anotó el vaivén como ruido inofensivo; no lo era.
        //
        // La muerte del asunto no se pierde por no cerrar: el motor la mira POR ASUNTO
        // (`AgendaDeAsunto::estaMuerta()`) y la agenda de 5GEFZ9 no programa nada con el hilo
        // abierto. Lo que cerrar añadía era apagar también a los vivos.
        if ($context->isCancelled()) {
            if (!$this->quedaOtroAsuntoVivo($conversation, $context->getContextType(), $context->getContextId())) {
                $conversation->setStatus(MessageConversation::STATUS_CLOSED);
            }
        } else {
            if ($conversation->getStatus() === MessageConversation::STATUS_CLOSED) { //Cambiado
                $conversation->setStatus(MessageConversation::STATUS_OPEN);
            }
        }

        // 6. EL ENLACE DEL ASUNTO, al día en el mismo movimiento
        //
        // Va aquí y no en un listener aparte porque este método es EL sitio por el que pasa cada
        // cambio de una reserva (`PmsReservaRecalculoService` lo llama en cada recálculo). Un
        // enlace que se refrescara en otro punto podría quedarse atrás sin que nada lo delatara:
        // el motor lee el enlace, así que unas fechas viejas ahí son mensajes en el día
        // equivocado.
        //
        // Sin sincronizador para este `context_type` no pasa nada: el asunto se queda sin enlace
        // y todo sigue por el camino de siempre, que es el fallo seguro mientras los negocios se
        // van enchufando uno a uno.
        foreach ($this->sincronizadores as $sincronizador) {
            if ($sincronizador->supports($context->getContextType())) {
                $sincronizador->sincronizar($conversation, $context);
                break;
            }
        }

        // La copia denormalizada, al día con las identidades. Va DESPUÉS de `vincular()` para
        // que una identidad recién sembrada ya cuente.
        $conversation->recalcularTelefonoPrincipal();

        if ($flush) {
            $this->entityManager->flush();
        }

        return $conversation;
    }

    /**
     * ¿Cuelga de este hilo otro asunto TITULAR que siga vivo?
     *
     * Con el mismo juez que el motor —`AgendaDeAsunto::estaMuerta()`: cancelado o con el vínculo
     * terminado—, porque si la fábrica y el motor discreparan sobre qué está vivo, el hilo se
     * cerraría con una agenda que el motor todavía quiere programar, que es el fallo de partida.
     *
     * Sólo titulares, como el motor: el acompañante no programa nada, así que no puede mantener
     * abierto un hilo para una agenda que no existe.
     *
     * ⚠️ Un enlace que no se deja leer (un proxy sin fila) cuenta como VIVO. Ante la duda se deja
     * el hilo abierto: el motor evalúa la muerte de cada asunto por su cuenta y no programa nada
     * para uno muerto, mientras que cerrar de más silencia a los vivos sin avisar.
     */
    private function quedaOtroAsuntoVivo(MessageConversation $conversation, string $contextType, string $contextId): bool
    {
        foreach ($this->enlaces->de($conversation) as $enlace) {
            if (!$enlace->esTitular()) {
                continue;
            }

            if ($enlace->getContextType() === $contextType && $enlace->getContextId() === $contextId) {
                continue;
            }

            try {
                if (!AgendaDeAsunto::deEnlace($enlace)->estaMuerta()) {
                    return true;
                }
            } catch (Throwable $e) {
                $this->logger->warning('Asunto ilegible al decidir si se cierra el hilo: se cuenta como vivo.', [
                    'conversacion' => (string) $conversation->getId(),
                    'error' => $e->getMessage(),
                ]);

                return true;
            }
        }

        return false;
    }

    /**
     * El hilo que reconozca alguno de estos identificadores.
     *
     * ── Cuando apuntan a hilos DISTINTOS: manda el teléfono ─────────────────
     * Pasa al crear una reserva tecleando un teléfono que ya es de alguien y un correo que es de
     * otra persona. Antes no se elegía ninguno y nacía un hilo nuevo, y el resultado era peor
     * que cualquiera de las dos opciones: el hilo nuevo se quedaba **sin identidades** —las dos
     * eran ajenas— pero **con `guestPhone`**, así que los envíos salían por ese número y las
     * respuestas aterrizaban en el hilo del dueño. Conversación partida, y de una sola dirección
     * en cada mitad.
     *
     * Gana el teléfono porque es el canal que de verdad lleva conversación. El correo se
     * **descarta**: no se le quita a su dueño —`ResolutorDeHilo::vincular()` lo impide— y queda
     * el aviso en el log con lo que se dejó fuera.
     *
     * ⚠️ **Sólo desempata el TELÉFONO.** Si el empate es entre un correo y un `bookId`, se sigue
     * sin elegir: ahí no hay una señal más fuerte que otra, y unir historiales a ciegas es lo que
     * este módulo lleva una semana deshaciendo.
     *
     * @param array<string, string> $identificadores
     */
    private function porIdentificadores(array $identificadores): ?MessageConversation
    {
        $encontrados = [];
        $porTelefono = null;

        foreach ($identificadores as $tipo => $valor) {
            $caso = IdentidadTipo::tryFrom((string) $tipo);

            if ($caso === null) {
                continue;
            }

            if (($hilo = $this->resolutor->porIdentidad($caso, $valor)) !== null) {
                $encontrados[(string) $hilo->getId()] = $hilo;

                if ($caso === IdentidadTipo::TELEFONO) {
                    $porTelefono = $hilo;
                }
            }
        }

        if (count($encontrados) <= 1) {
            return $encontrados === [] ? null : reset($encontrados);
        }

        if ($porTelefono !== null) {
            $this->logger->warning('Identificadores de un mismo contexto apuntan a hilos distintos: manda el teléfono.', [
                'identificadores' => array_keys($identificadores),
                'hilos' => array_keys($encontrados),
                'elegido' => (string) $porTelefono->getId(),
                'descartados' => array_values(array_diff(array_keys($encontrados), [(string) $porTelefono->getId()])),
            ]);

            return $porTelefono;
        }

        $this->logger->warning('Identificadores de un mismo contexto apuntan a hilos distintos y ninguno es un teléfono: no se une ninguno.', [
            'identificadores' => array_keys($identificadores),
            'hilos' => array_keys($encontrados),
        ]);

        return null;
    }
}