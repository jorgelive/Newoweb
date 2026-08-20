<?php

declare(strict_types=1);

namespace App\Tests\Travel\Entity;

use App\Travel\Entity\TravelOrganizacion;
use App\Travel\Entity\TravelOrganizacionServicio;
use App\Travel\Entity\TravelTarifa;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * Los tres roles de una tarifa y la guarda que los cruza.
 *
 * Sin contenedor ni base: sólo la lógica de la entidad.
 */
final class TravelTarifaRolesTest extends TestCase
{
    #[Test]
    public function losTresRolesEmpiezanVacios(): void
    {
        $t = new TravelTarifa();

        self::assertNull($t->getPrestador());
        self::assertNull($t->getPrestadorServicio());
        // Vacío NO es un olvido: significa «se le compra al prestador», el caso normal.
        self::assertNull($t->getComprador());
    }

    /** El comprador sale del mismo catálogo que el prestador: los dos son empresas. */
    #[Test]
    public function prestadorYCompradorPuedenSerEmpresasDistintas(): void
    {
        $ministerio = new TravelOrganizacion();
        $futurismo = new TravelOrganizacion();

        $t = (new TravelTarifa())->setPrestador($ministerio)->setComprador($futurismo);

        self::assertSame($ministerio, $t->getPrestador());
        self::assertSame($futurismo, $t->getComprador());
    }

    #[Test]
    public function elServicioDeOtraEmpresaSeRechaza(): void
    {
        $hotelA = new TravelOrganizacion();
        $hotelB = new TravelOrganizacion();
        $habitacionDeB = (new TravelOrganizacionServicio())->setOrganizacion($hotelB);

        $t = (new TravelTarifa())->setPrestador($hotelA)->setPrestadorServicio($habitacionDeB);

        self::assertSame(
            ['prestadorServicio'],
            self::rutasEnViolacion($t),
            'Guardar «Hotel A» con «habitación del Hotel B» no falla al escribir y sale mal al cotizar.'
        );
    }

    #[Test]
    public function elServicioDeLaMismaEmpresaPasa(): void
    {
        $hotel = new TravelOrganizacion();
        $habitacion = (new TravelOrganizacionServicio())->setOrganizacion($hotel);

        $t = (new TravelTarifa())->setPrestador($hotel)->setPrestadorServicio($habitacion);

        self::assertSame([], self::rutasEnViolacion($t));
    }

    /**
     * Sin prestador no hay con qué cruzar, así que no se inventa una violación: es el estado
     * en el que nace toda tarifa y el formulario tiene que dejar guardarla.
     */
    #[Test]
    public function sinPrestadorNoSeQuejaDelServicio(): void
    {
        $servicio = (new TravelOrganizacionServicio())->setOrganizacion(new TravelOrganizacion());

        self::assertSame([], self::rutasEnViolacion((new TravelTarifa())->setPrestadorServicio($servicio)));
        self::assertSame([], self::rutasEnViolacion(new TravelTarifa()));
    }

    /**
     * Corre el callback de la entidad y devuelve las rutas que se quejaron.
     *
     * @return list<string>
     */
    private static function rutasEnViolacion(TravelTarifa $tarifa): array
    {
        $rutas = [];

        $builder = new class ($rutas) implements ConstraintViolationBuilderInterface {
            /** @param list<string> $rutas */
            public function __construct(private array &$rutas) {}
            public function atPath(string $path): static { $this->rutas[] = $path; return $this; }
            public function setParameter(string $key, string $value): static { return $this; }
            public function setParameters(array $parameters): static { return $this; }
            public function setTranslationDomain(?string $translationDomain): static { return $this; }
            public function setInvalidValue(mixed $invalidValue): static { return $this; }
            public function setPlural(int $number): static { return $this; }
            public function setCode(?string $code): static { return $this; }
            public function setCause(mixed $cause): static { return $this; }
            public function disableTranslation(): static { return $this; }
            public function addViolation(): void {}
        };

        $context = self::createStub(ExecutionContextInterface::class);
        $context->method('buildViolation')->willReturn($builder);

        $tarifa->validarConsistenciaLogica($context);

        return $rutas;
    }
}
