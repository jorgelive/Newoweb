<?php

declare(strict_types=1);

namespace App\Message\Service\Translation;

use App\Entity\Maestro\MaestroIdioma;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Detecta el idioma del texto entrante de forma local, sin llamar a ninguna API.
 *
 * La regla vive en {@see DetectorDeIdiomaLocal}; esto sólo le da los idiomas del maestro.
 *
 * ⚠️ **Se guardan los CÓDIGOS, nunca las entidades.** Esto corre en un worker que vive horas y que
 * limpia el EntityManager entre mensajes: una `MaestroIdioma` guardada aquí quedaría desconectada,
 * y asignársela a una conversación hace fallar el `flush()` («A new entity was found through the
 * relationship…»). Quien necesite la entidad la busca en su momento con el código.
 *
 * Los códigos sí se guardan para toda la vida del proceso: un idioma nuevo en el maestro entra al
 * reiniciar los workers, que es lo que hace cada despliegue.
 */
class GuestLanguageDetectorService
{
    private ?DetectorDeIdiomaLocal $detector = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * El idioma del texto entre los del maestro, o `$actual` si no se puede decidir o no compensa
     * cambiar. Nunca devuelve un idioma que no esté en el maestro, y nunca cae a inglés.
     */
    public function codigoDe(string $texto, string $actual): string
    {
        return $this->detector()->codigoDe($texto, $actual);
    }

    private function detector(): DetectorDeIdiomaLocal
    {
        if ($this->detector === null) {
            $ids = [];

            foreach ($this->em->getRepository(MaestroIdioma::class)->findAll() as $idioma) {
                $id = $idioma->getId();

                if ($id !== null && $id !== '') {
                    $ids[] = $id;
                }
            }

            $this->detector = new DetectorDeIdiomaLocal($ids);
        }

        return $this->detector;
    }
}
