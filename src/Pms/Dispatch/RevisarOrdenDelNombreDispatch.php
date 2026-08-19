<?php

declare(strict_types=1);

namespace App\Pms\Dispatch;

/**
 * «Mira si el nombre y el apellido de esta reserva vienen cruzados.»
 *
 * Lleva dentro las cadenas que había cuando se encoló, y no sólo el id: el veredicto se aplica
 * únicamente si al llegar la respuesta siguen siendo esas
 * ({@see \App\Pms\Nombre\OrdenDelNombre::resultado()}). Entre medias pudo entrar otro pull.
 */
final readonly class RevisarOrdenDelNombreDispatch
{
    public function __construct(
        public string $reservaId,
        public string $nombre,
        public string $apellido,
    ) {}
}
