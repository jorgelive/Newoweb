<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\ProcessorInterface;
use App\Cotizacion\Entity\CotizacionFileGrupo;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Entity\CotizacionPasajeroGrupo;
use App\Cotizacion\Entity\CotizacionPasajeroIdentificacion;
use App\Cotizacion\State\CotizacionFilepasajeroProcessor;
use App\Enum\DocumentoTipoEnum;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Guardar la ficha de un pasajero no puede chocar con lo que ya estaba guardado.
 *
 * La ficha manda las listas enteras y sin identidad, así que el deserializador quita las filas de
 * antes y mete otras nuevas equivalentes. Doctrine hace los INSERT antes que los DELETE, y las dos
 * filas coinciden en el índice único `(pasajero, tipo)` —o `(pasajero, grupo)`—: 1062 y un 500 en
 * la cara del vendedor, aunque no hubiera tocado esa parte de la ficha.
 *
 * Ver {@see CotizacionFilepasajeroProcessor}.
 */
final class CotizacionFilepasajeroProcessorTest extends TestCase
{
    #[Test]
    public function reescribir_el_dni_conserva_la_fila_y_le_cambia_el_numero(): void
    {
        $pasajero = new CotizacionFilepasajero();
        $original = $this->identificacion(DocumentoTipoEnum::DNI, '73924317');
        $this->cargarComoSiVinieraDeBase($pasajero, 'identificaciones', [$original]);

        // Lo que deja el deserializador: la de antes fuera, una nueva del mismo tipo dentro.
        $pasajero->removeIdentificacion($original);
        $pasajero->addIdentificacion($this->identificacion(DocumentoTipoEnum::DNI, '73924318'));

        $this->procesar($pasajero);

        self::assertCount(1, $pasajero->getIdentificaciones());
        self::assertSame($original, $pasajero->getIdentificaciones()->first(), 'La fila tiene que ser la de siempre: un UPDATE, no un DELETE peleándose con un INSERT.');
        self::assertSame('73924318', $original->getNumero());
    }

    /**
     * El caso que reventó en producción: sólo se venía a cambiar el teléfono, y la lista de
     * documentos volvió igual que estaba.
     */
    #[Test]
    public function guardar_sin_tocar_los_documentos_no_duplica_ninguno(): void
    {
        $pasajero = new CotizacionFilepasajero();
        $dni = $this->identificacion(DocumentoTipoEnum::DNI, '73924317');
        $pasaporte = $this->identificacion(DocumentoTipoEnum::PASAPORTE, '125996350');
        $this->cargarComoSiVinieraDeBase($pasajero, 'identificaciones', [$dni, $pasaporte]);

        $pasajero->removeIdentificacion($dni);
        $pasajero->removeIdentificacion($pasaporte);
        $pasajero->addIdentificacion($this->identificacion(DocumentoTipoEnum::DNI, '73924317'));
        $pasajero->addIdentificacion($this->identificacion(DocumentoTipoEnum::PASAPORTE, '125996350'));

        $this->procesar($pasajero);

        self::assertSame([$dni, $pasaporte], array_values($pasajero->getIdentificaciones()->toArray()));
    }

    /** La lista es la verdad: lo que el cliente deja fuera se borra, y eso no cambia. */
    #[Test]
    public function el_documento_que_ya_no_viene_en_la_lista_se_queda_fuera(): void
    {
        $pasajero = new CotizacionFilepasajero();
        $dni = $this->identificacion(DocumentoTipoEnum::DNI, '73924317');
        $pasaporte = $this->identificacion(DocumentoTipoEnum::PASAPORTE, '125996350');
        $this->cargarComoSiVinieraDeBase($pasajero, 'identificaciones', [$dni, $pasaporte]);

        $pasajero->removeIdentificacion($dni);
        $pasajero->removeIdentificacion($pasaporte);
        $pasajero->addIdentificacion($this->identificacion(DocumentoTipoEnum::DNI, '73924317'));

        $this->procesar($pasajero);

        self::assertSame([$dni], array_values($pasajero->getIdentificaciones()->toArray()));
    }

    /** Cambiar el tipo sí estrena fila: nada la reclama en base con esa clave. */
    #[Test]
    public function cambiar_el_tipo_del_documento_estrena_fila(): void
    {
        $pasajero = new CotizacionFilepasajero();
        $original = $this->identificacion(DocumentoTipoEnum::DNI, '73924317');
        $this->cargarComoSiVinieraDeBase($pasajero, 'identificaciones', [$original]);

        $pasajero->removeIdentificacion($original);
        $nueva = $this->identificacion(DocumentoTipoEnum::CE, '73924317');
        $pasajero->addIdentificacion($nueva);

        $this->procesar($pasajero);

        self::assertSame([$nueva], array_values($pasajero->getIdentificaciones()->toArray()));
    }

    /** El mismo choque, con el otro índice único: `(pasajero, grupo)`. */
    #[Test]
    public function seguir_en_el_mismo_grupo_conserva_la_pertenencia(): void
    {
        $pasajero = new CotizacionFilepasajero();
        $grupo = new CotizacionFileGrupo();
        $original = (new CotizacionPasajeroGrupo())->setGrupo($grupo);
        $this->cargarComoSiVinieraDeBase($pasajero, 'pertenencias', [$original]);

        $pasajero->removePertenencia($original);
        $pasajero->addPertenencia((new CotizacionPasajeroGrupo())->setGrupo($grupo));

        $this->procesar($pasajero);

        self::assertSame([$original], array_values($pasajero->getPertenencias()->toArray()));
    }

    /** Un pasajero nuevo no tiene nada que reutilizar, y el procesador no puede estorbar. */
    #[Test]
    public function un_pasajero_nuevo_pasa_de_largo(): void
    {
        $pasajero = new CotizacionFilepasajero();
        $dni = $this->identificacion(DocumentoTipoEnum::DNI, '73924317');
        $pasajero->addIdentificacion($dni);

        $this->procesar($pasajero);

        self::assertSame([$dni], array_values($pasajero->getIdentificaciones()->toArray()));
    }

    /**
     * Dos entradas del mismo tipo en una sola petición: el formulario no las ofrece, pero la API
     * la usan más clientes que el formulario, y las dos filas van contra la misma clave única.
     */
    #[Test]
    public function dos_documentos_del_mismo_tipo_en_una_peticion_se_funden_en_uno(): void
    {
        $pasajero = new CotizacionFilepasajero();
        $original = $this->identificacion(DocumentoTipoEnum::DNI, '73924317');
        $this->cargarComoSiVinieraDeBase($pasajero, 'identificaciones', [$original]);

        $pasajero->removeIdentificacion($original);
        $pasajero->addIdentificacion($this->identificacion(DocumentoTipoEnum::DNI, '73924318'));
        $pasajero->addIdentificacion($this->identificacion(DocumentoTipoEnum::DNI, '73924319'));

        $this->procesar($pasajero);

        self::assertSame([$original], array_values($pasajero->getIdentificaciones()->toArray()));
        self::assertSame('73924319', $original->getNumero(), 'Gana la última, que es lo que hace cualquier lista que se manda entera.');
    }

    /** Y sin fila previa que reclame el tipo, la primera entrante hace de titular. */
    #[Test]
    public function dos_documentos_del_mismo_tipo_sin_nada_en_base_tampoco_duplican(): void
    {
        $pasajero = new CotizacionFilepasajero();
        $primera = $this->identificacion(DocumentoTipoEnum::CE, '001');
        $pasajero->addIdentificacion($primera);
        $pasajero->addIdentificacion($this->identificacion(DocumentoTipoEnum::CE, '002'));

        $this->procesar($pasajero);

        self::assertSame([$primera], array_values($pasajero->getIdentificaciones()->toArray()));
        self::assertSame('002', $primera->getNumero());
    }

    /** Mudarse de grupo sí estrena fila: la clave `(pasajero, grupo)` está libre. */
    #[Test]
    public function cambiar_de_grupo_estrena_pertenencia(): void
    {
        $pasajero = new CotizacionFilepasajero();
        $original = (new CotizacionPasajeroGrupo())->setGrupo(new CotizacionFileGrupo());
        $this->cargarComoSiVinieraDeBase($pasajero, 'pertenencias', [$original]);

        $pasajero->removePertenencia($original);
        $otra = (new CotizacionPasajeroGrupo())->setGrupo(new CotizacionFileGrupo());
        $pasajero->addPertenencia($otra);

        $this->procesar($pasajero);

        self::assertSame([$otra], array_values($pasajero->getPertenencias()->toArray()));
    }

    private function identificacion(DocumentoTipoEnum $tipo, string $numero): CotizacionPasajeroIdentificacion
    {
        return (new CotizacionPasajeroIdentificacion())->setTipo($tipo)->setNumero($numero);
    }

    /**
     * Deja la colección como la deja Doctrine al hidratar: una `PersistentCollection` con su foto
     * ya tomada. Es esa foto la que el procesador compara, así que sin ella el test no probaría
     * nada de lo que pasa en producción.
     *
     * @param list<object> $filas
     */
    private function cargarComoSiVinieraDeBase(CotizacionFilepasajero $pasajero, string $propiedad, array $filas): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($this->createStub(UnitOfWork::class));

        $coleccion = new PersistentCollection(
            $em,
            $this->createStub(ClassMetadata::class),
            new ArrayCollection($filas)
        );
        $coleccion->takeSnapshot();

        $reflejo = new ReflectionProperty(CotizacionFilepasajero::class, $propiedad);
        $reflejo->setValue($pasajero, $coleccion);

        foreach ($filas as $fila) {
            $fila->setPasajero($pasajero);
        }
    }

    private function procesar(CotizacionFilepasajero $pasajero): void
    {
        $persistidor = new class implements ProcessorInterface {
            /**
             * @param array<string, mixed> $uriVariables
             * @param array<string, mixed> $context
             */
            public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
            {
                return $data;
            }
        };

        (new CotizacionFilepasajeroProcessor($persistidor))->process($pasajero, new Patch());
    }
}
