<?php

declare(strict_types=1);

use App\Exchange\Service\Context\SyncContext;
use App\Finanzas\Service\FinEnlacePagoService;
use App\Pms\Finanzas\PmsPrepagoEnlaceService;
use App\Pms\Service\Message\SaldoPendiente;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * El kernel de `dev` con los servicios que una prueba necesita tocar, públicos.
 *
 * - `SyncContext`: las pruebas que simulan lo que hace Beds24 —cancelar una estancia de OTA—
 *   tienen que entrar en modo `pull`, o `PmsEventoCalendarioSecurityListener` lo veta como si
 *   fuera un operador cancelando a mano (que es lo correcto fuera de una sincronización).
 * - Los servicios de enlaces y el aviso del saldo, para llamarlos como el panel y el barrido.
 *
 * Caché propia: compilar con servicios públicos en `var/cache/dev` pisaría la del entorno normal.
 */
final class KernelDePrueba extends App\Kernel
{
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach ([SyncContext::class, FinEnlacePagoService::class, PmsPrepagoEnlaceService::class, SaldoPendiente::class] as $id) {
                    $container->getDefinition($id)->setPublic(true);
                }
            }
        });
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/prueba';
    }
}
