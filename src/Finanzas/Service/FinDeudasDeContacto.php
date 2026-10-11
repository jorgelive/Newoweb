<?php

declare(strict_types=1);

namespace App\Finanzas\Service;

use App\Finanzas\Contract\FinDeudasDeContactoInterface;
use App\Finanzas\Dto\FinDeudaDeContacto;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Junta lo que dicen todos los módulos. Ver `FinDeudasDeContactoInterface`. */
final readonly class FinDeudasDeContacto
{
    /** @param iterable<FinDeudasDeContactoInterface> $modulos */
    public function __construct(
        #[AutowireIterator('finanzas.deudas_de_contacto')]
        private iterable $modulos,
    ) {}

    /** @return list<FinDeudaDeContacto> */
    public function de(?string $telefono, ?string $email): array
    {
        $deudas = [];

        foreach ($this->modulos as $modulo) {
            foreach ($modulo->deudasDe($telefono, $email) as $deuda) {
                $deudas[] = $deuda;
            }
        }

        return $deudas;
    }
}
