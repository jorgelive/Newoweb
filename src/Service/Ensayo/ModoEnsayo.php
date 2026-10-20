<?php

declare(strict_types=1);

namespace App\Service\Ensayo;

/**
 * Mientras está activo, lo que sale del servidor EN EL MOMENTO —push web, Mercure— no sale
 * (19/10/2026).
 *
 * Lo enciende `app:agent:replay`. El ensayo corre dentro de una transacción que se deshace, y eso
 * cubre todo lo que se escribe en la base —colas de envío y de Messenger incluidas—, pero no lo que
 * sale por HTTP sin pasar por ella: `confirmar_hora` avisa por push cuando no consigue avisar por
 * WhatsApp (justo cuando no hay guardia con móvil, que es cuando el replay corre sin
 * `--con-guardia`), y cada mensaje que una skill persiste se publica por Mercure en el panel y
 * desaparece al deshacerse.
 *
 * Es estado del proceso, no de la petición: sólo tiene sentido en un comando de consola, que es
 * un proceso por ejecución. Nada en web lo enciende.
 */
final class ModoEnsayo
{
    private bool $activo = false;

    public function activar(): void
    {
        $this->activo = true;
    }

    public function desactivar(): void
    {
        $this->activo = false;
    }

    public function activo(): bool
    {
        return $this->activo;
    }
}
