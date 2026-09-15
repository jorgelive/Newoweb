<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Entity;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Enum\ArchivoTipoEnum;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Qué documentos puede EXIGIR un expediente.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * 🔥 La lista era fija en el front —pasaporte y las dos caras del DNI— y aguantó mientras fueron
 * documentos que pide cualquier viaje. Se rompió con el **E-Ticket migratorio dominicano**, atado a
 * UN destino: un expediente a Cusco empezó a pedir a sus pasajeros un formulario de Migración de
 * República Dominicana, y cada uno veía «te falta un documento» sin poder hacer nada.
 *
 * ⚠️ **Y ese fallo no se queja.** El pasajero no escribe para decir que le piden algo raro: deja de
 * intentarlo, o manda cualquier cosa. Se descubre mirando su pantalla, que es lo que casi nunca se
 * hace — así que la única red es esto.
 *
 * Sin base de datos ni contenedor: la entidad y sus atributos de validación.
 */
final class DocumentosPedidosTest extends TestCase
{
    private ValidatorInterface $validador;

    protected function setUp(): void
    {
        $this->validador = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    /** @param list<string|null> $pedidos */
    private function motivos(array $pedidos): array
    {
        $file = (new CotizacionFile())->setDocumentosPedidos($pedidos);

        return array_map(
            static fn ($e): string => (string) $e->getMessage(),
            iterator_to_array($this->validador->validateProperty($file, 'documentosPedidos')),
        );
    }

    /**
     * 🔥 **Un expediente NUEVO nace pidiendo los tres, no pidiendo nada.**
     *
     * La propiedad nacía en `[]` y eso era una regresión muda: hasta que esto fue configurable,
     * TODOS los expedientes pedían estos tres, así que cualquiera creado después dejaba de pedir
     * nada **y nadie se enteraba** — ni el operador, que no sabe que existe una casilla que no ha
     * visto, ni el pasajero, al que sencillamente no se le pide.
     *
     * ⚠️ El valor tiene que ser el mismo con el que la migración rellenó las filas viejas: un
     * expediente nuevo y uno de antes tienen que comportarse igual.
     */
    public function testUnExpedienteNuevoPideLosTresDeSiempre(): void
    {
        self::assertSame(
            ['pasaporte', 'dni_anverso', 'dni_reverso'],
            (new CotizacionFile())->getDocumentosPedidos(),
        );
        self::assertSame(
            CotizacionFile::DOCUMENTOS_PEDIDOS_POR_DEFECTO,
            (new CotizacionFile())->getDocumentosPedidos(),
        );
    }

    /**
     * ⚠️ **`Assert\Choice` se rinde ante un `null`**: su validador sale antes de comparar, así que
     * un `[null]` pasaba entero. Luego el getter incumplía su propio `list<string>` y `pax` pintaba
     * el panel con una fila fantasma. Lo cierran `NotNull` y `Type`, no el `Choice`.
     */
    public function testUnNuloSeRechaza(): void
    {
        self::assertNotSame([], $this->motivos([null]));
    }

    /**
     * 🔥 **La lista vacía es un valor legítimo, no un «sin configurar».**
     *
     * Un catálogo o un tour suelto no recoge documentos, y a esa gente no hay que pedirle nada: el
     * panel entero desaparece de su pantalla. Si esto se rechazara, el operador no tendría forma de
     * decir «aquí no se pide» y acabaría dejando los tres de siempre puestos por no poder quitarlos.
     */
    public function testLaListaVaciaSeAcepta(): void
    {
        self::assertSame([], $this->motivos([]));
    }

    /** Lo que pide cualquier viaje, que es el default de los expedientes que ya existían. */
    public function testLosTresDeSiempreSeAceptan(): void
    {
        self::assertSame([], $this->motivos(['pasaporte', 'dni_anverso', 'dni_reverso']));
    }

    /** Y el de destino, que es justo el caso que obligó a hacerlo configurable. */
    public function testElEticketSeAcepta(): void
    {
        self::assertSame([], $this->motivos(['pasaporte', 'eticket']));
    }

    /**
     * ⚠️ **No se puede exigir lo que el pasajero no puede subir.**
     *
     * Un boleto lo emitimos nosotros: pedírselo sería pedirle lo imposible y dejarle un «te falta»
     * que no puede resolver nunca. Lo mismo que rechaza aquí lo rechaza
     * {@see \App\Cotizacion\Controller\Publico\SubirDocumentoPasajeroController}, y por la misma
     * razón — las dos preguntan a `ArchivoTipoEnum::loSubeElPasajero()`.
     */
    public function testNoSePuedeExigirLoQueSubeElOperador(): void
    {
        self::assertNotSame([], $this->motivos([ArchivoTipoEnum::BOLETO->value]));
    }

    /** Un valor que no es del enum tampoco: el selector manda cadenas y una errata no debe entrar. */
    public function testUnTipoInventadoSeRechaza(): void
    {
        self::assertNotSame([], $this->motivos(['pasaporte_falso']));
    }

    /**
     * ⚠️ El selector manda lo que tenga marcado y la columna es `json`: guarda tal cual lo que
     * reciba. Un repetido saldría como dos filas iguales en la pantalla del pasajero.
     */
    public function testLosRepetidosSeQuitanAlEntrar(): void
    {
        $file = (new CotizacionFile())->setDocumentosPedidos(['pasaporte', 'pasaporte', 'eticket']);

        self::assertSame(['pasaporte', 'eticket'], $file->getDocumentosPedidos());
    }

    /**
     * 🔥 **El catálogo de lo pedible se DERIVA, no se escribe a mano.**
     *
     * Si alguien añade un tipo que sube el pasajero y se olvida de la otra lista, el selector no lo
     * ofrecería y nadie se enteraría. Esto ata las dos.
     */
    public function testLoPedibleEsExactamenteLoQueSubeElPasajero(): void
    {
        $esperado = array_values(array_map(
            static fn (ArchivoTipoEnum $c): string => $c->value,
            array_filter(ArchivoTipoEnum::cases(), static fn (ArchivoTipoEnum $c): bool => $c->loSubeElPasajero()),
        ));

        self::assertSame($esperado, ArchivoTipoEnum::pedibles());
        self::assertContains('eticket', ArchivoTipoEnum::pedibles());
        self::assertNotContains('boleto', ArchivoTipoEnum::pedibles());
    }
}
