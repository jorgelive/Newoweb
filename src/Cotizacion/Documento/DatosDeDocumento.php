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
