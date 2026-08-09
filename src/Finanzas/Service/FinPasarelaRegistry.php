<?php

declare(strict_types=1);

namespace App\Finanzas\Service;

use App\Finanzas\Contract\FinPasarelaClientInterface;
use App\Finanzas\Enum\FinPasarela;
use DomainException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Elige el cliente de la pasarela. Tercer registry del módulo, misma mecánica de tags.
 *
 * Existe porque las pasarelas **conviven**, no se sustituyen: Izipay sigue entero para
 * cuando nos habiliten, y Culqi cobra hoy. Un `if` por el `.env` habría dejado el conector
 * apagado y sin forma de probarlo sin tocar configuración global.
 */
final class FinPasarelaRegistry
{
    /** @var array<string, FinPasarelaClientInterface>|null */
    private ?array $porPasarela = null;

    /**
     * @param iterable<FinPasarelaClientInterface> $clientes
     */
    public function __construct(
        #[AutowireIterator('finanzas.pasarela_client')]
        private readonly iterable $clientes,
        #[Autowire('%finanzas.pasarela_por_defecto%')]
        private readonly string $pasarelaPorDefecto,
    ) {}

    /**
     * Cliente de una pasarela concreta.
     *
     * Falla si no está CONFIGURADA, no sólo si no existe: un cliente sin credenciales
     * acepta la llamada y revienta después contra la API con un 401 que no dice nada.
     * Mejor un mensaje claro en el momento de emitir el enlace.
     */
    public function para(FinPasarela $pasarela): FinPasarelaClientInterface
    {
        $cliente = $this->mapa()[$pasarela->value] ?? null;

        if ($cliente === null) {
            throw new DomainException(sprintf('No hay cliente para la pasarela "%s".', $pasarela->value));
        }

        if (!$cliente->estaConfigurado()) {
            throw new DomainException(sprintf(
                'La pasarela %s no está configurada: faltan sus credenciales en .env.local.',
                $pasarela->etiqueta(),
            ));
        }

        return $cliente;
    }

    /**
     * Pasarela a usar cuando el operador no elige.
     *
     * Si la del `.env` no está configurada se cae a la primera que sí lo esté, en vez de
     * fallar: con dos conectores en paralelo, un `.env` a medio rellenar es lo normal —
     * y que no se pueda cobrar por una errata de configuración sería peor que cobrar por
     * la otra pasarela.
     */
    public function porDefecto(): FinPasarela
    {
        $preferida = FinPasarela::tryFrom($this->pasarelaPorDefecto);

        if ($preferida !== null && $this->estaConfigurada($preferida)) {
            return $preferida;
        }

        $disponibles = $this->disponibles();

        if ($disponibles === []) {
            throw new DomainException(
                'Ninguna pasarela de pago está configurada: revisa las credenciales en .env.local.'
            );
        }

        return $disponibles[0];
    }

    public function estaConfigurada(FinPasarela $pasarela): bool
    {
        return ($this->mapa()[$pasarela->value] ?? null)?->estaConfigurado() === true;
    }

    /**
     * Pasarelas con credenciales, en el orden del enum.
     *
     * @return FinPasarela[]
     */
    public function disponibles(): array
    {
        return array_values(array_filter(
            FinPasarela::cases(),
            fn (FinPasarela $pasarela): bool => $this->estaConfigurada($pasarela),
        ));
    }

    /**
     * Catálogo para el selector de la SPA. Sólo las configuradas: ofrecer una pasarela sin
     * credenciales es prometer un cobro que va a fallar al pulsar el botón.
     *
     * @return array<int, array{value: string, label: string, porDefecto: bool}>
     */
    public function opcionesDisponibles(): array
    {
        $defecto = $this->disponibles() === [] ? null : $this->porDefecto();

        return array_map(
            static fn (FinPasarela $p): array => [
                'value' => $p->value,
                'label' => $p->etiqueta(),
                'porDefecto' => $p === $defecto,
            ],
            $this->disponibles(),
        );
    }

    /** @return array<string, FinPasarelaClientInterface> */
    private function mapa(): array
    {
        if ($this->porPasarela === null) {
            $this->porPasarela = [];
            foreach ($this->clientes as $cliente) {
                $this->porPasarela[$cliente->pasarela()->value] = $cliente;
            }
        }

        return $this->porPasarela;
    }
}
