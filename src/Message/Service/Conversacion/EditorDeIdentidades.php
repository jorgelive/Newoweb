<?php

declare(strict_types=1);

namespace App\Message\Service\Conversacion;

use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageIdentidad;
use App\Message\Enum\IdentidadTipo;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Añadir, retirar, marcar principal y vetar identificadores de una persona.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * Hasta ahora los identificadores sólo entraban desde el dominio —`upsertFromContext()` los
 * registra en cada recálculo— y no salían nunca: no había un solo sitio que retirase uno. Eso
 * deja dos casos reales sin arreglo posible:
 *
 * 1. **El número equivocado.** El huésped se equivoca al teclear, Meta rechaza el envío y ese
 *    número se queda reclamando el hilo para siempre. Y si el equivocado pertenece a una
 *    persona real, su WhatsApp entra en el hilo del huésped.
 * 2. **La persona con dos móviles.** Uno muerto y otro vivo: había que apagar el canal entero
 *    o dejarlo entero encendido, porque el veto era del hilo.
 *
 * ── Las reglas, todas aquí ──────────────────────────────────────────────────
 * No están en el controlador a propósito: el editor del panel es un cliente más, y estas mismas
 * decisiones las va a querer tomar el comando de fusión y —el día que exista— una skill.
 */
final readonly class EditorDeIdentidades
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private EnlacesDeConversacion $enlaces,
    ) {
    }

    /**
     * Añade un identificador, o revive el que estaba retirado.
     *
     * ⚠️ **Si el valor ya es de OTRO hilo, no se mueve: se avisa.** `(tipo, valor)` es único, así
     * que moverlo sería robárselo al otro y dejar su historial mudo — y si de verdad son la
     * misma persona, lo que toca es FUNDIR los dos hilos, no repartirse un identificador. Unir
     * historiales es decisión de persona y tiene su herramienta: `app:message:fusionar-hilos`.
     *
     * Sin esta comprobación el fallo sería un 500 por violación de unicidad, justo al operador
     * que estaba haciendo lo correcto.
     *
     * @throws RuntimeException si el identificador pertenece a otra conversación
     */
    public function anadir(MessageConversation $hilo, IdentidadTipo $tipo, string $valor, string $origen = 'manual'): MessageIdentidad
    {
        $normalizado = $tipo->normalizar($valor);

        if ($normalizado === '') {
            throw new RuntimeException('El identificador está vacío o no tiene una forma reconocible.');
        }

        $existente = $this->em->getRepository(MessageIdentidad::class)
            ->findOneBy(['tipo' => $tipo, 'valor' => $normalizado]);

        if ($existente !== null && $existente->getConversacion() !== $hilo) {
            throw new RuntimeException(sprintf(
                'Ese identificador ya es de otra conversación (%s). Si son la misma persona, únelas con «app:message:fusionar-hilos».',
                $existente->getConversacion()?->getGuestName() ?? 'sin nombre'
            ));
        }

        // Ya está en este hilo. Si se retiró, volver a añadirlo es una persona rectificando —la
        // lápida frena al DOMINIO, que re-registra sin criterio, no a quien decide a mano—.
        if ($existente !== null) {
            $this->revivir($existente);

            return $existente;
        }

        $identidad = new MessageIdentidad($tipo, $normalizado, $origen);
        $hilo->addIdentidad($identidad);
        $this->em->persist($identidad);
        $hilo->recalcularBloqueoWhatsapp();
        $hilo->recalcularTelefonoPrincipal();

        return $identidad;
    }

    /**
     * Retira: sigue resolviendo el historial, deja de ser salida.
     *
     * No se borra nunca. Además de que el historial de esa persona quedaría partido, la fila es
     * lo que impide que el dominio la resucite en el siguiente recálculo — ver
     * {@see MessageIdentidad::$retiradoEn}.
     */
    public function retirar(MessageIdentidad $identidad, DateTimeImmutable $cuando): void
    {
        $identidad->retirar($cuando);
        $identidad->getConversacion()?->recalcularBloqueoWhatsapp();
        $identidad->getConversacion()?->recalcularTelefonoPrincipal();

        $this->logger->info('Identidad retirada.', [
            'conversacion' => (string) $identidad->getConversacion()?->getId(),
            'tipo' => $identidad->getTipo()->value,
            'valor' => $identidad->getValor(),
        ]);
    }

    /**
     * La marca como salida por defecto y **quita la marca a las demás de su tipo**.
     *
     * Sin ese barrido quedarían dos principales y quien lea tendría que elegir por orden de
     * llegada, que no es una decisión: es un accidente reproducible.
     */
    public function marcarPrincipal(MessageIdentidad $identidad): void
    {
        if (!$identidad->estaViva()) {
            throw new RuntimeException('Un identificador retirado no puede ser la salida por defecto.');
        }

        $hilo = $identidad->getConversacion();

        if ($hilo !== null && $this->esDeUnaPlataforma($hilo, $identidad)) {
            throw new RuntimeException(
                'Ese correo no es suyo: lo emite la plataforma para UNA reserva, y sólo sirve para ésa. '
                . 'Marca como principal un correo personal.'
            );
        }

        foreach ($hilo?->getIdentidades() ?? [] as $otra) {
            if ($otra->getTipo() === $identidad->getTipo()) {
                $otra->setPrincipal($otra === $identidad);
            }
        }

        // El destino de los envíos sale de `guestPhone`, no de aquí: sin esta línea, marcar
        // principal cambiaría lo que pinta el panel y no a dónde sale el mensaje.
        $hilo?->recalcularTelefonoPrincipal();
    }

    /** Veta o levanta el veto de UN identificador, y deja el espejo del hilo al día. */
    public function bloquear(MessageIdentidad $identidad, bool $bloqueado, string $motivo = 'Bloqueado a mano desde el panel'): void
    {
        $bloqueado ? $identidad->bloquear($motivo) : $identidad->desbloquear();

        $identidad->getConversacion()?->recalcularBloqueoWhatsapp();
    }

    /**
     * ¿Este correo lo emitió una OTA para una reserva concreta?
     *
     * ── Por qué se deduce y no se guarda ────────────────────────────────────
     * Porque el dato ya existe: el alias ES el `correoDeContacto()` de un asunto que se declara
     * exclusivo. Guardarlo aparte en la identidad crearía un segundo autor sobre el mismo hecho,
     * y el día que una reserva directa se reclasifique como de OTA —o al revés— la copia
     * mentiría sin que nada lo dijera.
     *
     * Que el alias esté registrado como identidad **es correcto y hace falta**: es lo que hace
     * que el correo entrante de Booking caiga en el hilo de esa persona. Lo que no puede es ser
     * la salida por defecto de TODO, porque entonces la reserva de Airbnb del mes que viene
     * saldría por el buzón de la de Booking del mes pasado.
     *
     * ⚠️ Espejo en el panel: `EditConversationModal.vue` esconde la estrella con esta misma
     * regla, comparando contra `correoExclusivo` de la lista de asuntos. Si cambia aquí, cambia
     * allí — el de aquí es el que manda, el de allí sólo evita ofrecer un botón que va a fallar.
     */
    private function esDeUnaPlataforma(MessageConversation $hilo, MessageIdentidad $identidad): bool
    {
        if ($identidad->getTipo() !== IdentidadTipo::EMAIL) {
            return false;
        }

        foreach ($this->enlaces->de($hilo) as $asunto) {
            if (!$asunto->correoEsExclusivo()) {
                continue;
            }

            $alias = $asunto->correoDeContacto();

            if ($alias !== null && strcasecmp(trim($alias), $identidad->getValor()) === 0) {
                return true;
            }
        }

        return false;
    }

    private function revivir(MessageIdentidad $identidad): void
    {
        if ($identidad->estaViva()) {
            return;
        }

        $identidad->revivir();

        $this->logger->info('Identidad retirada que vuelve a añadirse a mano.', [
            'conversacion' => (string) $identidad->getConversacion()?->getId(),
            'valor' => $identidad->getValor(),
        ]);
    }
}
