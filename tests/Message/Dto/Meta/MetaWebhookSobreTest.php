<?php

declare(strict_types=1);

namespace App\Tests\Message\Dto\Meta;

use App\Message\Dto\Meta\MetaCambio;
use App\Message\Dto\Meta\MetaEstado;
use App\Message\Dto\Meta\MetaMensajeEntrante;
use App\Message\Dto\Meta\MetaWebhookSobre;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * El sobre del webhook de Meta se lee UNA vez, y lo que no encaja es «no llegó», no un warning.
 *
 * Que los DTO leen lo mismo que el código viejo sobre los webhooks reales lo comprueba
 * `tools/pruebas/probar-dto-meta.php` contra la auditoría (3 250 payloads: idénticos). Esto fija
 * las formas raras, que en producción todavía no han llegado.
 */
#[CoversClass(MetaWebhookSobre::class)]
#[CoversClass(MetaCambio::class)]
#[CoversClass(MetaMensajeEntrante::class)]
#[CoversClass(MetaEstado::class)]
final class MetaWebhookSobreTest extends TestCase
{
    public function testUnMensajeDeTextoConSuContacto(): void
    {
        $sobre = MetaWebhookSobre::fromArray([
            'object' => 'whatsapp_business_account',
            'entry' => [['changes' => [['value' => [
                'contacts' => [['wa_id' => '51999888777', 'profile' => ['name' => 'Ana']]],
                'messages' => [['id' => 'wamid.1', 'type' => 'text', 'timestamp' => '1727312345', 'text' => ['body' => 'Hola']]],
            ]]]]],
        ]);

        self::assertSame('whatsapp_business_account', $sobre->objeto);
        $cambio = $sobre->cambios[0];
        self::assertSame('51999888777', $cambio->contacto?->waId);
        self::assertSame('Ana', $cambio->contacto?->nombre);
        self::assertSame('Hola', $cambio->mensajes[0]->texto);
        self::assertSame(1727312345, $cambio->mensajes[0]->timestamp, 'Meta manda el timestamp como texto');
    }

    /**
     * Las formas que el recorrido viejo leía sin mirar: `changes` que falta y `contacts` vacío. Antes
     * eran un warning a mitad del controlador; ahora, un sobre con menos piezas.
     */
    public function testUnSobreIncompletoNoAvisaSeQuedaSinPiezas(): void
    {
        $sobre = MetaWebhookSobre::fromArray([
            'entry' => [['id' => 'sin changes'], ['changes' => [['value' => ['contacts' => [], 'messages' => [['id' => 'x']]]]]]],
        ]);

        self::assertCount(1, $sobre->cambios);
        self::assertNull($sobre->cambios[0]->contacto, 'contacts vacío = sin contacto, no un índice que no existe');
        self::assertCount(1, $sobre->cambios[0]->mensajes);
        self::assertSame('text', $sobre->cambios[0]->mensajes[0]->tipo, 'sin `type`, texto: lo mismo que hacía el persister');
    }

    /** Un array donde se esperaba texto no se convierte en la palabra «Array». */
    public function testUnArrayDondeSeEsperabaTextoEsNull(): void
    {
        $mensaje = MetaMensajeEntrante::fromArray(['id' => ['raro'], 'text' => ['body' => ['no', 'es', 'texto']]]);

        self::assertNull($mensaje->id);
        self::assertNull($mensaje->texto);
    }

    /** La respuesta interactiva vive bajo su propio tipo, y el adjunto bajo el suyo. */
    public function testInteractivoYAdjuntoSeLeenDeSuTipo(): void
    {
        $lista = MetaMensajeEntrante::fromArray(['type' => 'interactive', 'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => 'OPC_2', 'title' => 'Dos']]]);
        self::assertSame('OPC_2', $lista->interactivoId);
        self::assertSame('Dos', $lista->interactivoTitulo);

        $doc = MetaMensajeEntrante::fromArray(['type' => 'document', 'document' => ['id' => 'media.9', 'mime_type' => 'application/pdf', 'filename' => 'e-ticket.pdf']]);
        self::assertSame('media.9', $doc->adjuntoId);
        self::assertSame('e-ticket.pdf', $doc->adjuntoNombre);

        // Una imagen no lee el `document` aunque viniera: el adjunto es el de SU tipo.
        $img = MetaMensajeEntrante::fromArray(['type' => 'image', 'document' => ['id' => 'otro']]);
        self::assertNull($img->adjuntoId);
    }

    public function testUnEstadoFallidoTraeCodigoYMotivo(): void
    {
        $estado = MetaEstado::fromArray(['id' => 'wamid.2', 'status' => 'failed', 'errors' => [['code' => 131047, 'message' => 'Re-engagement message']]]);

        self::assertSame('131047', $estado->errorCodigo, 'el código llega como número y se lee como texto');
        self::assertSame('Re-engagement message', $estado->errorMensaje);
        self::assertCount(1, $estado->errores);
    }
}
