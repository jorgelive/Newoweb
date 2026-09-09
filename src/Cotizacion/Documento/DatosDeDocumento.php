<?php

declare(strict_types=1);

namespace App\Cotizacion\Documento;

use App\Enum\DocumentoTipoEnum;
use DateTimeImmutable;

/**
 * Lo que se ha conseguido leer de un documento de identidad, y **cuánto se puede confiar**.
 *
 * 🔑 **Es una PROPUESTA, no un dato.** Nada de aquí se guarda solo: se enseña al lado de lo que ya
 * hay para que alguien confirme. Es la misma forma que ya tiene la carga por ZIP —plan primero,
 * aplicar después— y por el mismo motivo: con cien documentos, escribir a ciegas es pedir un
 * desastre callado.
 *
 * Todos los campos son nulables porque una foto movida deja media ficha, y media ficha con los
 * huecos marcados es útil; media ficha rellenada a ojo, no.
 */
final readonly class DatosDeDocumento
{
    /**
     * @param list<string> $avisos Lo que no cuadra. Vacío NO significa «correcto»: significa
     *        «nada que objetar», que con una lectura automática es lo máximo que se puede decir.
     */
    public function __construct(
        public ?DocumentoTipoEnum $tipo = null,
        public ?string $numero = null,
        public ?string $nombres = null,
        public ?string $apellidos = null,
        public ?string $paisEmisor = null,
        public ?string $nacionalidad = null,
        public ?string $sexo = null,
        public ?DateTimeImmutable $nacimiento = null,
        public ?DateTimeImmutable $vencimiento = null,
        /**
         * La nacionalidad ya traducida a ISO-2, que es la clave de `MaestroPais`.
         *
         * ⚠️ **El documento habla ISO-3 (`PER`) y el manifiesto ISO-2 (`PE`).** El puente lo pone
         * `LectorDeDocumentoIdentidad` con `symfony/intl`; se guarda resuelto para que nadie
         * compare las dos cosas creyendo que son la misma. `null` = no se pudo traducir, que es
         * distinto de «no se leyó»: `$nacionalidad` conserva lo que dijo el documento.
         */
        public ?string $nacionalidadIso2 = null,
        /**
         * Cuántos grados EN SENTIDO HORARIO hay que girar el escaneo para que se lea derecho.
         *
         * ⚠️ **Es un diagnóstico, no una corrección.** Se guarda para poder ofrecer el giro con el
         * valor ya puesto, pero **la corrección se hornea en los píxeles**: guardar el ángulo
         * aparte y aplicarlo al mostrar es exactamente el fallo que ya costó caro con el EXIF
         * —ver el aviso de `config/packages/liip_imagine.yaml`—, donde el huésped veía su
         * pasaporte derecho y al operador le llegaba tumbado. Con dos consumidores más (el gate,
         * el propio lector) ese fallo se multiplicaría.
         */
        public int $rotacion = 0,
        public ?Mrz $mrz = null,
        public array $avisos = [],
    ) {}

    /**
     * ¿Los datos vienen respaldados por la aritmética de la MRZ, o sólo por lo que dijo el modelo?
     *
     * ⚠️ **Es LA distinción de todo esto.** Con MRZ coherente, el número y las dos fechas están
     * comprobados con dígitos de control y se pueden proponer con confianza. Sin ella —una cédula,
     * una foto donde la banda no sale— lo único que hay es la lectura de un modelo, que se
     * inventa un carácter con la misma seguridad con la que acierta los otros treinta.
     *
     * Quien pinte esto en pantalla **tiene que enseñar la diferencia**: si las dos clases de dato
     * se ven igual, la confirmación humana se vuelve un clic y no una revisión.
     */
    public function verificadoPorMrz(): bool
    {
        return $this->mrz?->esCoherente() === true;
    }

    /** Sin número no hay nada que casar ni que guardar: es la clave natural del documento. */
    public function esUtilizable(): bool
    {
        return $this->numero !== null && trim($this->numero) !== '';
    }
}
