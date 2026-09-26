<?php

declare(strict_types=1);

namespace App\Exchange\Dto\Tuya;

use App\Dto\Lee;

/**
 * El sobre con que contesta la nube de Tuya: `{success, msg, result, t}`.
 *
 * Todo lo que interesa está en `result`, y su forma cambia por endpoint —una lista de aparatos,
 * un mapa de estados, la hora de cada propiedad—. Esa forma la conoce cada método de `TuyaClient`,
 * que ya la recorre comprobando cada elemento; lo que faltaba era un sitio para leer el SOBRE y
 * llegar a `result` sin encadenar índices sobre `mixed`.
 *
 * ⚠️ **El éxito es `success === true` estricto**, como lo comprobaba el cliente: Tuya contesta
 * 200 con `success: false` cuando rechaza (firma, permisos, región), y cualquier otra cosa que no
 * sea el booleano no es un sí.
 *
 * Tuya no se guarda en ninguna tabla —las lecturas se convierten en consumo y estado al vuelo—,
 * así que esta frontera no tiene datos guardados contra los que comparar: la cubre
 * `RespuestaTuyaTest`. Ver `docs/Domotica.md` §13.
 */
final readonly class RespuestaTuya
{
    public function __construct(
        public bool $exito,
        public ?string $mensaje,
        public mixed $resultado,
    ) {}

    /** @param array<mixed> $respuesta */
    public static function fromArray(array $respuesta): self
    {
        $mensaje = $respuesta['msg'] ?? null;

        return new self(
            exito: ($respuesta['success'] ?? false) === true,
            // Sólo texto, como antes: un `msg` que no lo sea se cuenta como «sin detalle».
            mensaje: is_string($mensaje) ? $mensaje : null,
            resultado: $respuesta['result'] ?? null,
        );
    }

    /**
     * Un nodo dentro de `result`, por su ruta (`resultado('devices')`); sin ruta, `result` entero.
     * Lo que falte por el camino es `null`.
     */
    public function resultado(string|int ...$ruta): mixed
    {
        return Lee::en($this->resultado, ...$ruta);
    }

    /** El token de acceso de la respuesta de `/v1.0/token`, si la hay y no está vacío. */
    public function token(): ?string
    {
        $token = $this->resultado('access_token');

        return is_string($token) && $token !== '' ? $token : null;
    }
}
