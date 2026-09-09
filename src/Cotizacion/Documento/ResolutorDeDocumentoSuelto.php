<?php

declare(strict_types=1);

namespace App\Cotizacion\Documento;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFilearchivo;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Entity\CotizacionPasajeroIdentificacion;
use App\Entity\Maestro\MaestroPais;
use App\Enum\SexoEnum;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

/**
 * Resuelve un documento suelto: lo vincula a quien es, o le crea su ficha en el manifiesto.
 *
 * ⚠️ **Atómico de verdad**, en una transacción: crear la persona, crear su identificación y
 * colgarle el archivo son tres escrituras que **no pueden quedar a medias**. Una persona creada
 * sin su documento es una fila fantasma en el manifiesto que rompe los conteos y que nadie echa
 * de menos — es exactamente el tipo de fallo de datos que este proyecto persigue.
 */
final readonly class ResolutorDeDocumentoSuelto
{
    public function __construct(
        private EntityManagerInterface $em,
        private ValidadorDeDocumento $validador,
    ) {}

    /**
     * Cuelga el archivo de una persona que ya existe.
     *
     * No crea la identificación: si la persona ya está y el número no lo tiene guardado, eso es
     * trabajo del control de validación, que lo dejará `observado` con su motivo. Aquí sólo se
     * decide **de quién es el archivo**.
     */
    public function vincular(CotizacionFilearchivo $archivo, CotizacionFilepasajero $pasajero): void
    {
        if ($pasajero->getFile()?->getId()?->equals($archivo->getFile()?->getId()) !== true) {
            // El mismo invariante que `validarDuenoDelMismoExpediente()`, comprobado ANTES de
            // escribir para poder decirlo con una frase en vez de con un 500.
            throw new RuntimeException('Esa persona es de otro expediente.');
        }

        $archivo->setPasajero($pasajero);
        $this->em->flush();
    }

    /**
     * Crea la ficha del manifiesto **a partir del documento** y le cuelga el archivo.
     *
     * ⚠️ **Es la única operación que AÑADE una persona**, así que es la que más caro sale
     * equivocada: dos fichas de la misma persona rompen todos los conteos del manifiesto. Por eso
     * nunca se ejecuta sola — sólo cuando alguien la pide desde el panel, habiendo visto que no
     * había candidatos.
     *
     * ⚠️ Y **se relee comprobando que sigue sin dueño**: entre que el panel se pintó y alguien
     * pulsó, otra persona pudo asignarlo. Sin esto, dos operadores mirando la misma cola crean la
     * misma persona dos veces.
     */
    public function crear(CotizacionFilearchivo $archivo): CotizacionFilepasajero
    {
        if ($archivo->getPasajero() !== null) {
            throw new RuntimeException('Ese archivo ya tiene dueño: recarga la lista.');
        }

        $leido = $this->validador->lecturaDe($archivo);
        if ($leido === null || !$leido->esUtilizable()) {
            throw new RuntimeException('No se pudo leer el documento: no hay con qué crear la ficha.');
        }

        $expediente = $archivo->getFile();
        if (!$expediente instanceof CotizacionFile) {
            throw new RuntimeException('El archivo no cuelga de ningún expediente.');
        }

        $creado = null;

        $this->em->wrapInTransaction(function () use ($archivo, $leido, $expediente, &$creado): void {
            $pasajero = new CotizacionFilepasajero();
            $pasajero->setFile($expediente);
            $pasajero->setNombre($leido->nombres ?? '');
            $pasajero->setApellido($leido->apellidos ?? '');

            if ($leido->nacimiento !== null) {
                $pasajero->setFechanacimiento($leido->nacimiento);
            }

            if ($leido->sexo !== null) {
                $pasajero->setSexo(SexoEnum::tryFrom($leido->sexo));
            }

            // El id de `MaestroPais` ES el ISO-2, y `nacionalidadIso2` ya viene traducida del
            // ISO-3 del documento. Si ese país no está en el maestro se deja vacío: **no se
            // inventa la fila**, que es un dato que añadir al maestro, no un error del documento.
            if ($leido->nacionalidadIso2 !== null) {
                $pasajero->setPais($this->em->getRepository(MaestroPais::class)->find($leido->nacionalidadIso2));
            }

            $this->em->persist($pasajero);

            if ($leido->tipo !== null) {
                $identificacion = new CotizacionPasajeroIdentificacion();
                $identificacion->setPasajero($pasajero);
                $identificacion->setTipo($leido->tipo);
                $identificacion->setNumero($leido->numero);
                $identificacion->setVencimiento($leido->vencimiento);
                $this->em->persist($identificacion);
            }

            $archivo->setPasajero($pasajero);
            $creado = $pasajero;
        });

        if (!$creado instanceof CotizacionFilepasajero) {
            throw new RuntimeException('La transacción no dejó la ficha creada.');
        }

        return $creado;
    }
}
