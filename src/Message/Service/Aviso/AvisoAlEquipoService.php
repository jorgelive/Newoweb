<?php

declare(strict_types=1);

namespace App\Message\Service\Aviso;

use App\Entity\Maestro\MaestroIdioma;
use App\Entity\User;
use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageTemplate;
use App\Message\Enum\IdentidadTipo;
use App\Message\Service\Conversacion\ResolutorDeHilo;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hacer sonar el teléfono de quien está de guardia.
 *
 * Nació dentro de `EscalarAlEquipoSkill` y sale de ahí en cuanto hubo un segundo interesado
 * (los cobros). No se copió: lo que hay aquí son **cinco decisiones ya pagadas** en producción,
 * y una copia nueva se habría dejado alguna por el camino sin que nada fallara —que es el modo
 * en que este código duele—.
 *
 * ### Las cinco
 *
 * 1. **El hilo del operador se busca por TELÉFONO antes que por contexto.** Si alguien del
 *    equipo es además huésped, su conversación deja de ser `staff` al fusionarse; buscarla por
 *    `contextType` crearía una nueva **en cada aviso**, partiéndole el historial cada vez.
 * 2. **La plantilla se adjunta SÓLO fuera de la ventana de 24 h.** Dentro gana el texto libre,
 *    que puede ser multilínea; Meta prohíbe los saltos de línea en los parámetros de plantilla.
 * 3. **`SENDER_SYSTEM`, nunca `SENDER_HOST`.** El segundo silencia al autoresponder 30 minutos
 *    («humano al mando»), un efecto que aquí no pinta nada y que dejaría al huésped sin
 *    respuesta justo después.
 * 4. **Un destinatario que falla no impide avisar al resto.** Se captura por operador.
 * 5. **El fallo se comprueba DESPUÉS del flush**, leyendo el estado del mensaje.
 *    `MessageDispatcher::dispatch()` no lanza: atrapa por canal, deja el mensaje en `FAILED` y
 *    anota el motivo. Sin mirarlo aquí diríamos «avisado» de un aviso que no salió — que es
 *    exactamente lo que ocurre mientras una plantilla no está aprobada por Meta.
 *
 * ### Lo que este servicio NO hace, y es deliberado
 *
 * No sabe de qué avisa: recibe texto y plantilla ya resueltos ({@see AvisoAlEquipo}). No
 * deduplica ni enfría —cada dominio decide qué significa «ya avisé de esto»: el escalado lo
 * mide por conversación y media hora, un cobro es único y no lo necesita—. Y no marca nada
 * como pendiente: esa es una consecuencia del escalado, no de avisar.
 */
final readonly class AvisoAlEquipoService
{
    /**
     * Tipo de contexto de las conversaciones internas con el equipo.
     *
     * No se reutiliza `manual` —el walk-in de un desconocido— porque son cosas distintas y
     * quien filtre la bandeja tiene que poder separarlas: esto no es un huésped.
     */
    public const string CONTEXTO_STAFF = 'staff';

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $usuarios,
        private ResolutorDeHilo $resolutor,
        private LoggerInterface $logger,
    ) {}

    /**
     * Manda el aviso a todos los destinatarios del rol. **Hace flush.**
     *
     * Nunca lanza por un destinatario concreto: lo que no salió vuelve en `noAvisados` para que
     * quien llamó pueda decirlo.
     */
    public function notificar(AvisoAlEquipo $aviso): ResultadoAviso
    {
        $destinatarios = $this->destinatarios($aviso->rol);

        if ($destinatarios === []) {
            $this->logger->warning('[aviso] nadie a quien avisar: sin usuarios con el rol y móvil.', [
                'rol' => $aviso->rol,
                'metadata' => $aviso->metadata,
            ]);

            return new ResultadoAviso(sinDestinatarios: true);
        }

        $plantilla = $aviso->plantillaCodigo === null ? null : $this->em
            ->getRepository(MessageTemplate::class)
            ->findOneBy(['code' => $aviso->plantillaCodigo]);

        if ($aviso->plantillaCodigo !== null && $plantilla === null) {
            // No es motivo para abortar: dentro de ventana el aviso sale igual. Se registra
            // porque fuera de ventana no saldrá y conviene saber por qué.
            $this->logger->warning('[aviso] la plantilla de respaldo no existe.', [
                'plantilla' => $aviso->plantillaCodigo,
            ]);
        }

        /** @var array<string, Message> $encolados nombre del operador => mensaje */
        $encolados = [];
        /** @var list<string> $fallos */
        $fallos = [];

        foreach ($destinatarios as $operador) {
            $nombre = $operador->getFullname() ?: $operador->getUserIdentifier();

            try {
                $encolados[$nombre] = $this->componer($operador, $aviso, $plantilla);
            } catch (Throwable $e) {
                $fallos[] = $nombre;
                $this->logger->error('[aviso] no se pudo componer el aviso de un operador.', [
                    'operador' => $operador->getUserIdentifier(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->em->flush();

        /** @var list<string> $avisados */
        $avisados = [];

        foreach ($encolados as $nombre => $mensaje) {
            if ($mensaje->getStatus() !== Message::STATUS_FAILED) {
                $avisados[] = $nombre;

                continue;
            }

            $fallos[] = $nombre;
            $this->logger->error('[aviso] el aviso no se pudo encolar.', [
                'operador' => $nombre,
                'errores' => $mensaje->getMetadata()['dispatch_errors'] ?? [],
            ]);
        }

        return new ResultadoAviso(avisados: $avisados, noAvisados: $fallos);
    }

    /**
     * Quién está de guardia: el rol **y** un móvil escrito.
     *
     * Sin teléfono no hay a dónde mandarlo, y colarlo en la lista sólo produciría un fallo más
     * abajo con peor mensaje.
     *
     * @return list<User>
     */
    public function destinatarios(string $rol): array
    {
        return array_values(array_filter(
            $this->usuarios->findByRole($rol),
            static fn (User $u): bool => trim((string) $u->getTelefono()) !== ''
        ));
    }

    /** Arma el mensaje de UN destinatario y lo deja persistido (sin flush). */
    private function componer(User $operador, AvisoAlEquipo $aviso, ?MessageTemplate $plantilla): Message
    {
        $conversacion = $this->conversacionStaff($operador);

        $mensaje = new Message();
        $mensaje->setConversation($conversacion);
        $mensaje->setDirection(Message::DIRECTION_OUTGOING);
        // Ver la decisión 3 de la cabecera: SENDER_HOST silenciaría al autoresponder.
        $mensaje->setSenderType(Message::SENDER_SYSTEM);
        $mensaje->setStatus(Message::STATUS_PENDING);
        $mensaje->setTransientChannels(['whatsapp_meta']);
        $mensaje->setContentLocal($aviso->texto);
        $mensaje->setContentExternal($aviso->texto);
        $mensaje->setLanguageCode($conversacion->getIdioma()->getId() ?? 'es');

        foreach ($aviso->metadata as $clave => $valor) {
            $mensaje->addMetadata($clave, $valor);
        }

        // Decisión 2: la plantilla sólo cuando el texto libre no puede salir.
        if ($plantilla !== null && !$conversacion->isWhatsappSessionActive()) {
            $mensaje->setTemplate($plantilla);
            $mensaje->setVariablesPlantilla($aviso->variables);
        }

        // Lo que sólo sabe el dominio, justo antes de persistir.
        if ($aviso->ajustarMensaje !== null) {
            ($aviso->ajustarMensaje)($mensaje);
        }

        $conversacion->addMessage($mensaje);
        $this->em->persist($mensaje);

        return $mensaje;
    }

    /**
     * El hilo interno con el operador: se busca, y si no existe se crea.
     *
     * ⚠️ Decisión 1 de la cabecera. Por TELÉFONO primero: es lo que sobrevive a fusionar el
     * hilo. Si alguien del equipo es además huésped, su conversación puede haber dejado de ser
     * `staff` — y buscarla por ahí crearía una nueva en cada aviso.
     */
    private function conversacionStaff(User $operador): MessageConversation
    {
        $repo = $this->em->getRepository(MessageConversation::class);

        $telefono = (string) $operador->getTelefono();
        $conversacion = $telefono !== ''
            ? $this->resolutor->porIdentidad(IdentidadTipo::TELEFONO, $telefono)
            : null;

        $conversacion ??= $repo->findOneBy([
            'contextType' => self::CONTEXTO_STAFF,
            'contextId' => (string) $operador->getId(),
        ]);

        if ($conversacion !== null) {
            // El móvil pudo cambiar desde la última vez: se refresca, o los avisos seguirían
            // yendo al número viejo.
            $conversacion->setGuestPhone((string) $operador->getTelefono());

            return $conversacion;
        }

        $conversacion = new MessageConversation(self::CONTEXTO_STAFF, (string) $operador->getId());
        $conversacion->setContextOrigin('interno');
        $conversacion->setGuestPhone((string) $operador->getTelefono());
        $conversacion->setGuestName($operador->getFullname() ?: $operador->getUserIdentifier());
        $conversacion->setStatus(MessageConversation::STATUS_OPEN);

        $repoIdioma = $this->em->getRepository(MaestroIdioma::class);
        $idioma = $repoIdioma->find('es') ?? $repoIdioma->findOneBy([]);

        if ($idioma !== null) {
            $conversacion->setIdioma($idioma);
        }

        $this->resolutor->vincular($conversacion, IdentidadTipo::TELEFONO, $telefono, 'staff');

        $this->em->persist($conversacion);

        return $conversacion;
    }
}
