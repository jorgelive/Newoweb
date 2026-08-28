<?php

declare(strict_types=1);

namespace App\Operacion\Service;

use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use App\Message\Service\Conversacion\AperturaDeHilo;
use App\Message\Service\Conversacion\CanalesDisponibles;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use App\Operacion\Entity\OperacionMensaje;
use App\Operacion\Entity\OperacionOrdenServicio;
use App\Operacion\Enum\EstadoOrdenServicioEnum;
use App\Travel\Service\Message\TravelOrganizacionMessageContext;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use RuntimeException;

/**
 * Manda la Orden de Servicio al proveedor, por el canal que se elija.
 *
 * ── Enviar es una acción APARTE de emitir ───────────────────────────────────
 * Y no por comodidad: son dos decisiones distintas y fallan distinto. Emitir congela el
 * contenido —un hecho interno, reversible sólo anulando—; enviar se lo pone delante a alguien de
 * fuera, y eso no se retira. Metidos en el mismo botón, un fallo de red al mandar el correo
 * dejaría la orden emitida y al proveedor sin enterarse, o al revés.
 *
 * Separados, además, **«reenviar» no necesita código propio**: es el mismo botón otra vez. Y eso
 * importa porque reenviar es lo normal —el proveedor perdió el correo, cambió el contacto, hubo
 * un cambio menor que no justifica reemitir—.
 *
 * ── Va por el hilo del proveedor, no por un mailer suelto ───────────────────
 * El destinatario es una `TravelOrganizacion`, y desde el 21/08/2026 tiene conversación como
 * cualquier otro asunto. Así el envío hereda todo lo que ya está resuelto: cola con reintentos,
 * resolución del destino por identidad —no por el campo del catálogo, que es sólo la semilla—,
 * veto de números muertos y el historial en el chat.
 *
 * Un mailer suelto habría duplicado esas cuatro cosas, y la primera vez que un proveedor
 * cambiara de correo se habría notado.
 */
final readonly class OperacionOrdenEnvio
{
    public function __construct(
        private EntityManagerInterface $em,
        private OperacionOrdenDocumento $documento,
        private AperturaDeHilo $apertura,
        private CanalesDisponibles $canales,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * El enlace público de la orden, o `null` si todavía no tiene llave.
     *
     * Se sella al emitir, así que un borrador no lo tiene — y tampoco tiene documento.
     */
    private function enlace(OperacionOrdenServicio $orden): ?string
    {
        $token = $orden->getTokenPublico();

        return $token === null
            ? null
            : $this->urls->generate('operacion_orden_publica', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * ⚠️ **Fuera de la ventana de 24 h, WhatsApp NO admite texto libre.**
     *
     * Meta sólo deja mandar plantillas aprobadas, y una orden de servicio no cabe en una: las
     * plantillas tienen variables sueltas, no filas — y una orden tiene tantas líneas como
     * servicios. Ésa es la razón de que exista el enlace público: la plantilla lleva un botón
     * con la URL y el detalle vive al otro lado.
     *
     * Mientras esa plantilla no esté dada de alta y aprobada por Meta, esto se **niega con el
     * motivo** en vez de intentarlo: un envío que Meta rechaza deja al proveedor sin enterarse y
     * al operador creyendo que salió.
     */
    private function exigirVentanaAbierta(MessageConversation $hilo, string $canal): void
    {
        if ($canal !== 'whatsapp_meta' || $hilo->isWhatsappSessionActive()) {
            return;
        }

        throw new DomainException(
            'La ventana de 24 h de WhatsApp con este proveedor está cerrada, así que Meta sólo '
            . 'admite plantillas aprobadas — y una orden con varias líneas no cabe en una. '
            . 'Mándala por correo, o espera a que el proveedor escriba para reabrir la ventana.'
        );
    }

    /**
     * Qué se mandaría y por dónde, sin mandar nada.
     *
     * @return array{
     *     asunto: string, cuerpo: string, lineas: int, destinatario: string,
     *     canales: list<array{id: string, nombre: string, disponible: bool, motivo: string|null}>
     * }
     */
    public function previsualizar(OperacionOrdenServicio $orden): array
    {
        // El enlace se le pasa al documento para que lo componga DENTRO del cuerpo, presentado.
        // Lo que se previsualiza tiene que ser exactamente lo que se manda: si aquí faltara, el
        // operador aprobaría un texto y saldría otro.
        $doc = $this->documento->para($orden, $this->enlace($orden));
        $hilo = $this->hiloDelProveedor($orden);

        return $doc + [
            'destinatario' => (string) $orden->getCompradorNombre(),
            'canales' => $this->canales->para($hilo),
            'enlace' => $this->enlace($orden),
            // Para que el panel avise ANTES de que el operador elija WhatsApp y se lleve el
            // rechazo después de haber leído todo el documento.
            'ventanaWhatsappAbierta' => $hilo->isWhatsappSessionActive(),
        ];
    }

    /**
     * Lo manda y lo anota en la bitácora.
     *
     * @throws DomainException con el motivo, que API Platform devuelve como 422
     */
    public function enviar(OperacionOrdenServicio $orden, string $canal): OperacionMensaje
    {
        // ⚠️ Sólo lo emitido. Un borrador no tiene ítems congelados, así que el documento
        // saldría vacío — y mandarle al proveedor una orden en blanco es peor que no mandarla.
        if ($orden->getEstadoOs() === EstadoOrdenServicioEnum::BORRADOR) {
            throw new DomainException('Esta orden todavía está en borrador: emítela antes de enviarla.');
        }

        if ($orden->getEstadoOs() === EstadoOrdenServicioEnum::CANCELADA) {
            throw new DomainException('Esta orden está anulada: no se le manda al proveedor. Emite la que la reemplaza.');
        }

        $doc = $this->documento->para($orden, $this->enlace($orden));

        if ($doc['lineas'] === 0) {
            throw new DomainException('La orden no tiene líneas que enviar.');
        }

        $hilo = $this->hiloDelProveedor($orden);
        $this->exigirVentanaAbierta($hilo, $canal);

        $disponible = false;
        foreach ($this->canales->para($hilo) as $fila) {
            if ($fila['id'] === $canal && $fila['disponible']) {
                $disponible = true;
                break;
            }
        }

        if (!$disponible) {
            throw new DomainException(sprintf(
                'No se le puede escribir a %s por %s. Revisa sus identificadores.',
                $orden->getCompradorNombre() ?? 'ese proveedor',
                $canal
            ));
        }

        $mensaje = new Message();
        $mensaje->setConversation($hilo);
        $mensaje->setDirection(Message::DIRECTION_OUTGOING);
        // SENDER_HOST, no SYSTEM: lo manda una persona pulsando un botón, y en el chat tiene
        // que verse como lo que es. Mismo criterio que la skill del agente.
        $mensaje->setSenderType(Message::SENDER_HOST);
        $mensaje->setStatus(Message::STATUS_PENDING);
        $mensaje->setTransientChannels([$canal]);
        // El enlace va SIEMPRE que exista, no sólo en WhatsApp: en correo también sirve —el
        // proveedor archiva el PDF— y evita que el cuerpo tenga dos formas según el canal.
        //
        // ⚠️ Ya viene DENTRO del cuerpo, presentado por `OperacionOrdenDocumento`. Antes se pegaba
        // aquí y el botón «Copiar» del front lo repetía por su cuenta: dos sitios componiendo el
        // mismo texto es cómo el proveedor de un grupo acaba recibiendo una versión distinta del
        // que lo recibe por chat.
        $cuerpo = $doc['cuerpo'];

        $mensaje->setContentExternal($cuerpo);
        $mensaje->setLanguageCode('es');
        $mensaje->addMetadata('orden_servicio', (string) $orden->getNumeroOs());

        $hilo->addMessage($mensaje);
        $this->em->persist($mensaje);

        // La bitácora de la orden: quién y cuándo, para que se vea desde Operación sin ir al
        // chat. El texto va aquí también porque un reenvío posterior puede llevar otro —el
        // documento se recompone de los ítems, y los ítems pueden haber cambiado por
        // `aplicar-menores`.
        $anotacion = new OperacionMensaje();
        $anotacion->setOrdenServicio($orden);
        $anotacion->setTipo($canal);
        $anotacion->setCuerpoHtml(nl2br(htmlspecialchars($cuerpo, ENT_QUOTES | ENT_SUBSTITUTE)));

        $this->em->persist($anotacion);
        $this->em->flush();

        return $anotacion;
    }

    /**
     * El hilo del proveedor, abriéndolo si todavía no lo tiene.
     *
     * Es idempotente: si ya existe devuelve ése. Y si el proveedor no tiene ni teléfono ni
     * correo se niega con el motivo escrito, que es lo que hay que leer antes de preguntarse por
     * qué no llegó nada.
     */
    private function hiloDelProveedor(OperacionOrdenServicio $orden): MessageConversation
    {
        $id = trim((string) $orden->getCompradorMaestroId());

        if ($id === '') {
            throw new DomainException(
                'Esta orden no tiene un proveedor del catálogo como destinatario, así que no hay a quién escribirle.'
            );
        }

        try {
            return $this->apertura->abrir(TravelOrganizacionMessageContext::CONTEXT_TYPE, $id);
        } catch (RuntimeException $e) {
            // ⚠️ **Se traduce la excepción, no se mapea `RuntimeException` a 422.**
            //
            // `AperturaDeHilo` se niega con un motivo perfectamente legible —«no hay ni teléfono
            // ni correo registrados»— pero lo lanza como `RuntimeException`, que no está en el
            // mapa de `api_platform.yaml`. Resultado: el operador leía **«Internal Server Error»**
            // teniendo la respuesta delante, y sin forma de saber que sólo faltaba un teléfono.
            //
            // Mapear `RuntimeException: 422` en la configuración lo arreglaría de un plumazo y
            // sería peor: es la excepción genérica de PHP, la lanza medio mundo, y convertirla en
            // «error del usuario» escondería como 422 fallos que sí son nuestros.
            throw new DomainException($e->getMessage(), 0, $e);
        }
    }
}
