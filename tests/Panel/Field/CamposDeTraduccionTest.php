<?php

declare(strict_types=1);

namespace App\Tests\Panel\Field;

use App\Panel\Field\CamposDeTraduccion;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Un maestro de rótulos abre la edición con «Traducir automáticamente» DESMARCADO y el alta
 * marcado. El interruptor es virtual: si el `data` del formulario no lo fija, sale siempre `true`.
 */
#[CoversClass(CamposDeTraduccion::class)]
final class CamposDeTraduccionTest extends TestCase
{
    public function testAlEditarNoSeTraduceYAlCrearSi(): void
    {
        self::assertFalse($this->traducir(Crud::PAGE_EDIT));
        self::assertTrue($this->traducir(Crud::PAGE_NEW));
    }

    public function testDevuelveLosDosInterruptoresConSuPermiso(): void
    {
        $campos = CamposDeTraduccion::deMaestroDeRotulos(Crud::PAGE_EDIT, 'ROLE_X');

        self::assertSame(['ejecutarTraduccion', 'sobreescribirTraduccion'], array_map(
            static fn ($c): string => $c->getAsDto()->getProperty(),
            $campos,
        ));
        self::assertSame('ROLE_X', $campos[0]->getAsDto()->getPermission());
    }

    private function traducir(string $pagina): mixed
    {
        return CamposDeTraduccion::deMaestroDeRotulos($pagina)[0]->getAsDto()->getFormTypeOptions()['data'] ?? null;
    }
}
