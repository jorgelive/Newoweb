<?php

declare(strict_types=1);

namespace App\Exchange\Dto\Meta;

use App\Dto\Lee;

/**
 * Lo que contesta la Graph API de Meta a una llamada NUESTRA: el envío de un mensaje de WhatsApp o
 * la gestión de plantillas (listar, crear, editar, borrar).
 *
 * No confundir con `src/Message/Dto/Meta/`: ésos leen lo que Meta nos MANDA por webhook. Esto es
 * la respuesta síncrona a lo que le pedimos, y tiene otra forma.
 *
 * | Campo | Clave | Lo leía |
 * |---|---|---|
 * | `hayError` | `isset(error)` | el envío, para separar el éxito del fallo |
 * | `errorMensaje` | `error.message` | el envío y las plantillas |
 * | `errorCodigo` | `error.code` | el envío (`error_code` de la fila normalizada) |
 * | `errorMensajeUsuario` | `error.error_user_msg` | las plantillas: el motivo legible del rechazo |
 * | `errorDetalles` | `error.error_data.details` | las plantillas |
 * | `idMensaje` | `messages[0].id` | el envío: el `wamid` |
 *
 * Se lee con {@see Lee::texto()} —tal cual, sin recortar—, igual que los DTO del webhook: sustituye
 * lecturas crudas y no debe cambiar ni un carácter de lo que se guarda o se enseña.
 *
 * ⚠️ `errorCodigo` se deja como llega (`int|string`): acaba en la fila normalizada del envío y
 * pasarlo a texto cambiaría `132000` por `"132000"`.
 *
 * Ver `docs/Mensajeria.md` §14.c y `docs/TiposDeFrontera.md`.
 */
final readonly class RespuestaGraphMeta
{
    /**
     * @param array<mixed> $crudo El cuerpo entero, tal cual: lo que devuelven las llamadas de
     *                            plantillas y lo que el envío guarda en `raw`.
     */
    public function __construct(
        public bool $hayError,
        public ?string $errorMensaje,
        public int|string|null $errorCodigo,
        public ?string $errorMensajeUsuario,
        public ?string $errorDetalles,
        public ?string $idMensaje,
        public array $crudo,
    ) {}

    /** @param array<mixed> $cuerpo */
    public static function fromArray(array $cuerpo): self
    {
        $codigo = Lee::en($cuerpo, 'error', 'code');

        return new self(
            hayError: isset($cuerpo['error']),
            errorMensaje: Lee::texto(Lee::en($cuerpo, 'error', 'message')),
            errorCodigo: is_int($codigo) || is_string($codigo) ? $codigo : null,
            errorMensajeUsuario: Lee::texto(Lee::en($cuerpo, 'error', 'error_user_msg')),
            errorDetalles: Lee::texto(Lee::en($cuerpo, 'error', 'error_data', 'details')),
            idMensaje: Lee::texto(Lee::en($cuerpo, 'messages', 0, 'id')),
            crudo: $cuerpo,
        );
    }

    /**
     * El cuerpo de una respuesta tal como llega por la red. Lo que no es un objeto JSON —una página
     * de error HTML, un cuerpo vacío— no trae nada que leer.
     */
    public static function deCuerpo(string $contenido): self
    {
        $decodificado = json_decode($contenido, true);

        return self::fromArray(is_array($decodificado) ? $decodificado : []);
    }
}
