<?php

declare(strict_types=1);

namespace App\Operacion\Service;

use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use App\Message\Service\Conversacion\AperturaDeHilo;
use App\Message\Service\Conversacion\CanalesDisponibles;
use App\Operacion\Entity\OperacionMensaje;
use App\Operacion\Entity\OperacionOrdenServicio;
use App\Operacion\Enum\EstadoOrdenServicioEnum;
use App\Travel\Service\Message\TravelOrganizacionMessageContext;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

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
    ) {
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
        $doc = $this->documento->para($orden);
        $hilo = $this->hiloDelProveedor($orden);

        return $doc + [
            'destinatario' => (string) $orden->getCompradorNombre(),
            'canales' => $this->canales->para($hilo),
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

        $doc = $this->documento->para($orden);

        if ($doc['lineas'] === 0) {
            throw new DomainException('La orden no tiene líneas que enviar.');
        }

        $hilo = $this->hiloDelProveedor($orden);

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
        $mensaje->setContentExternal($doc['cuerpo']);
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
        $anotacion->setCuerpoHtml(nl2br(htmlspecialchars($doc['cuerpo'], ENT_QUOTES | ENT_SUBSTITUTE)));

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

        return $this->apertura->abrir(TravelOrganizacionMessageContext::CONTEXT_TYPE, $id);
    }
}
