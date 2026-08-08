<?php

declare(strict_types=1);

namespace App\Finanzas\Service;

use App\Finanzas\Contract\FinOrigenCobroResolverInterface;
use App\Finanzas\Dto\FinOrigenCobroDto;
use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Enum\FinOrigenCobro;
use DomainException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Uid\Uuid;

/**
 * Despachador de la relación soft: dado un `FinOrigenCobro`, encuentra quién sabe leerlo.
 *
 * Es la pieza que hace que "el módulo N+1 sea barato": recoge por tag todas las
 * implementaciones de `FinOrigenCobroResolverInterface` y las indexa por su `soporta()`.
 * Registrar el módulo de tours será crear su resolver — el contenedor hace el resto.
 */
final class FinOrigenCobroRegistry
{
    /** @var array<string, FinOrigenCobroResolverInterface>|null Indexado en el primer uso. */
    private ?array $porTipo = null;

    /**
     * @param iterable<FinOrigenCobroResolverInterface> $resolvers
     */
    public function __construct(
        #[AutowireIterator('finanzas.origen_cobro_resolver')]
        private readonly iterable $resolvers,
    ) {}

    /**
     * Resolver del tipo, o excepción.
     *
     * Falla ruidosamente y no en silencio: un origen sin resolver significa que alguien
     * declaró un case del enum y se dejó la implementación. Devolver null aquí crearía
     * enlaces de pago que nadie sabría imputar cuando llegase el dinero — un agujero
     * contable mucho peor que un 500 en el momento de emitir.
     */
    public function para(FinOrigenCobro $tipo): FinOrigenCobroResolverInterface
    {
        $resolver = $this->mapa()[$tipo->value] ?? null;

        if ($resolver === null) {
            throw new DomainException(sprintf(
                'No hay resolver de cobro para el origen "%s". Implementa %s en el módulo dueño.',
                $tipo->value,
                FinOrigenCobroResolverInterface::class,
            ));
        }

        return $resolver;
    }

    public function soporta(FinOrigenCobro $tipo): bool
    {
        return isset($this->mapa()[$tipo->value]);
    }

    /** Foto del documento, o null si ya no existe (enlace histórico de algo borrado). */
    public function resolver(FinOrigenCobro $tipo, Uuid $origenId): ?FinOrigenCobroDto
    {
        return $this->para($tipo)->resolver($origenId);
    }

    /** Imputa el cobro en el módulo dueño. Sólo lo llama `FinEnlacePagoService`. */
    public function registrarCobro(FinEnlacePago $enlace): ?Uuid
    {
        return $this->para($enlace->getOrigenTipo())->registrarCobro($enlace);
    }

    /**
     * Orígenes que HOY pueden cobrarse (los que tienen resolver).
     *
     * La SPA pinta este catálogo y no `FinOrigenCobro::cases()`: el enum declara el
     * vocabulario completo, incluidos los módulos que aún no existen.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function opcionesDisponibles(): array
    {
        $opciones = [];

        foreach (array_keys($this->mapa()) as $valor) {
            $tipo = FinOrigenCobro::from($valor);
            $opciones[] = ['value' => $tipo->value, 'label' => $tipo->etiqueta()];
        }

        return $opciones;
    }

    /** @return array<string, FinOrigenCobroResolverInterface> */
    private function mapa(): array
    {
        if ($this->porTipo === null) {
            $this->porTipo = [];
            foreach ($this->resolvers as $resolver) {
                $this->porTipo[$resolver->soporta()->value] = $resolver;
            }
        }

        return $this->porTipo;
    }
}
