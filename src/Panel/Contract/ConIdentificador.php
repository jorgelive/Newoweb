<?php

declare(strict_types=1);

namespace App\Panel\Contract;

use Symfony\Component\Uid\Uuid;

/**
 * La entidad tiene un identificador que se puede pedir.
 *
 * Existe para una cosa concreta: `RenderGaleriaTrait` construye el id del modal a partir de
 * `$entity->getId()`, y su parámetro era `object` — o sea, cualquier cosa. Con `object`, el día
 * que se use el trait desde un controlador cuya entidad no tenga `getId()`, el fallo sale al
 * pintar la galería en el panel, no al escribirlo.
 *
 * Extiende `Stringable` porque el trait también hace `(string) $entity` para el título del
 * modal. Es la otra mitad del mismo contrato: quien se pinta en una galería necesita id **y**
 * etiqueta.
 *
 * ⚠️ **No añade comportamiento a nadie.** Las entidades que lo implementan ya tienen `getId()`
 * por `IdTrait` y su `__toString()`; declarar la interfaz sólo lo hace visible al analizador. Si
 * mañana hace falta en otra, es una palabra en la firma de la clase.
 */
interface ConIdentificador extends \Stringable
{
    public function getId(): ?Uuid;
}
