<?php

declare(strict_types=1);

namespace App\Cotizacion\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Entity\CotizacionPasajeroGrupo;
use App\Cotizacion\Entity\CotizacionPasajeroIdentificacion;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\PersistentCollection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Al guardar un pasajero, reutiliza las filas que ya existían en vez de cambiarlas por otras.
 *
 * ## El problema que resuelve
 *
 * La ficha manda `identificaciones` y `pertenencias` **enteras y sin identidad**: la lista que se
 * ve en pantalla es la lista que queda. Es la forma natural de editar tres filas en un formulario
 * y no exige que la identificación sea un `ApiResource` propio, que no lo es —sólo existe colgando
 * de su pasajero—.
 *
 * El precio es que, sin esto, cada guardado es «borra las de antes y mete estas»… y **Doctrine
 * ejecuta los INSERT antes que los DELETE**. La fila nueva del DNI entra mientras la vieja sigue
 * viva, las dos con el mismo `(pasajero, tipo)`, y MySQL corta con un 1062 que sale como **500**
 * —no como 422: una violación de índice único no pasa por el validador—. Pasó en producción el
 * 24/08/2026 al editar a un pasajero que ya tenía DNI y pasaporte.
 *
 * ⚠️ Y era determinista: cualquier pasajero con un documento o un grupo ya guardado reventaba al
 * volver a guardarlo, aunque no se tocara esa parte de la ficha.
 *
 * ## Cómo lo resuelve
 *
 * Casando por lo mismo que hace único a cada fila —el `tipo` del documento, el `grupo` de la
 * pertenencia— y quedándose con la fila **original**, a la que se le copian los datos que vengan.
 * Lo que sale es un UPDATE donde antes había un DELETE peleándose con un INSERT.
 *
 * Es exactamente el criterio que ya usa {@see \App\Cotizacion\Service\Padron\PadronImportador},
 * que reutiliza con `identificacionDe($tipo)`. Por eso reimportar el padrón corregido nunca
 * duplicó nada y la ficha sí: eran dos caminos con dos reglas.
 *
 * Lo que el cliente deja fuera de la lista se sigue borrando, que es lo que se espera: `null` no
 * es «no lo toques», la lista es la verdad. Y el índice único se queda donde está —es lo que
 * garantiza que nadie acabe con dos pasaportes— porque ahora nada lo desafía.
 *
 * @implements ProcessorInterface<mixed, mixed>
 */
final readonly class CotizacionFilepasajeroProcessor implements ProcessorInterface
{
    /** @param ProcessorInterface<mixed, mixed> $persistProcessor */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor
    ) {}

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof CotizacionFilepasajero) {
            $this->reutilizarIdentificaciones($data);
            $this->reutilizarPertenencias($data);
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }

    private function reutilizarIdentificaciones(CotizacionFilepasajero $pasajero): void
    {
        /** @var array<string, CotizacionPasajeroIdentificacion> $titulares */
        $titulares = [];
        foreach ($this->comoEstabanEnBase($pasajero->getIdentificaciones()) as $original) {
            if ($original->getTipo() !== null) {
                $titulares[$original->getTipo()->value] = $original;
            }
        }

        /** @var CotizacionPasajeroIdentificacion $entrante */
        foreach ($pasajero->getIdentificaciones()->toArray() as $entrante) {
            $clave = $entrante->getTipo()?->value;
            if ($clave === null) {
                continue;
            }

            $titular = $titulares[$clave] ?? null;

            // Sin fila que reclame ese tipo, la entrante se queda y pasa a ser la titular: si
            // detrás viene otra del mismo tipo, se funde en ésta en vez de estrellarse las dos
            // contra el índice único. El formulario no ofrece un tipo repetido, pero la API la
            // usan más clientes que el formulario.
            if ($titular === null) {
                $titulares[$clave] = $entrante;

                continue;
            }

            if ($titular === $entrante) {
                continue;
            }

            // El número y el vencimiento son justamente lo que se venía a corregir.
            $titular
                ->setNumero($entrante->getNumero())
                ->setVencimiento($entrante->getVencimiento())
                ->setPaisEmisor($entrante->getPaisEmisor());

            $pasajero->removeIdentificacion($entrante);
            $pasajero->addIdentificacion($titular);
        }
    }

    /**
     * Una pertenencia no tiene datos propios: es el par `(pasajero, grupo)` y nada más. Así que
     * aquí no hay nada que copiar —basta con conservar la fila que ya estaba y descartar el
     * duplicado—.
     */
    private function reutilizarPertenencias(CotizacionFilepasajero $pasajero): void
    {
        /** @var array<string, CotizacionPasajeroGrupo> $titulares */
        $titulares = [];
        foreach ($this->comoEstabanEnBase($pasajero->getPertenencias()) as $original) {
            if ($original->getGrupo()?->getId() !== null) {
                $titulares[(string) $original->getGrupo()->getId()] = $original;
            }
        }

        /** @var CotizacionPasajeroGrupo $entrante */
        foreach ($pasajero->getPertenencias()->toArray() as $entrante) {
            $clave = $entrante->getGrupo()?->getId();
            if ($clave === null) {
                continue;
            }

            $titular = $titulares[(string) $clave] ?? null;

            if ($titular === null) {
                $titulares[(string) $clave] = $entrante;

                continue;
            }

            if ($titular === $entrante) {
                continue;
            }

            $pasajero->removePertenencia($entrante);
            $pasajero->addPertenencia($titular);
        }
    }

    /**
     * Las filas tal como se leyeron de base, antes de que el deserializador tocara la colección.
     *
     * Es la foto que Doctrine guarda al cargar una `PersistentCollection`; comparar contra ella es
     * lo que distingue «esta identificación es nueva» de «es la de siempre, escrita otra vez». En
     * un POST la colección todavía no viene de base y no hay nada que reutilizar: lista vacía.
     *
     * ⚠️ `getSnapshot()` es público pero Doctrine lo marca `INTERNAL:`. Es estable en todo 2.x y
     * sigue existiendo en 3.x; aun así, el día que se migre el ORM este método es de los que hay
     * que volver a mirar. La alternativa sin internals es releer las filas del repositorio, que
     * cuesta una consulta por pasajero guardado.
     *
     * @template T of object
     * @param Collection<int, T> $coleccion
     * @return list<T>
     */
    private function comoEstabanEnBase(Collection $coleccion): array
    {
        if (!$coleccion instanceof PersistentCollection || !$coleccion->isInitialized()) {
            return [];
        }

        return array_values($coleccion->getSnapshot());
    }
}
