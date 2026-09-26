<?php
declare(strict_types=1);

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

trait IdTrait
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true, options: ['fixed' => true])]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private ?Uuid $id = null;

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    /**
     * El id de una entidad ya GUARDADA (o con `initializeId()`), que siempre lo tiene.
     *
     * ⚠️ El getter nulable se queda: una entidad recién creada no tiene id hasta el flush. Esto es
     * para el código que lee filas de la base —un `find()`, un `findBy()`— y necesita su id como
     * texto o binario: ahí un `null` no es un caso, es un dato roto, y tiene que decir de qué
     * clase era en vez de reventar con «Call to a member function toRfc4122() on null».
     * Criterio en `docs/PmsBeds24ReservasSync.md` §12.19.
     */
    public function getIdOrFail(): Uuid
    {
        return $this->id ?? throw new \LogicException(static::class . ' sin id: no se ha guardado ni inicializado.');
    }

    /**
     * Genera un ID si la entidad aún no lo tiene.
     * Útil cuando necesitas el ID antes del flush (colas, dedupe, etc.).
     */
    public function initializeId(): void
    {
        if ($this->id === null) {
            $this->id = new UuidV7();
        }
    }

    /**
     * Genera un nuevo identificador UUID v7 de forma forzada, sobrescribiendo el actual.
     * Este método existe para garantizar la unicidad al realizar una clonación profunda (__clone),
     * evitando que el motor ORM intente actualizar el registro original debido a una colisión de IDs.
     *
     * Ejemplo de uso:
     * public function __clone() {
     * $this->resetId();
     * }
     */
    public function resetId(): void
    {
        $this->id = new UuidV7();
    }
}