<?php

declare(strict_types=1);

namespace App\Cotizacion\Documento;

/**
 * Contra qué identidad se coteja un trámite, y **de dónde salió esa identidad**.
 *
 * ── 🔥 El manifiesto NO es la verdad, y creerlo acusa al que tiene razón ────
 * Hay tres fuentes del nombre y del número de pasaporte, y **dos están tecleadas a mano**:
 *
 * | Fuente | Cómo se produjo | Fiable |
 * |---|---|---|
 * | El escaneo del pasaporte con MRZ | el documento real, con dígitos de control | **sí** |
 * | El escaneo sin MRZ | lo leyó un modelo | a medias |
 * | El manifiesto | tecleado a mano al cargar el padrón | no |
 * | El E-Ticket | tecleado a mano por el pasajero | no |
 *
 * Cotejar el E-Ticket contra el **manifiesto** es comparar dos transcripciones entre sí: se sabe que
 * discrepan, no quién tiene razón. Y falla hacia el lado peor — medido sobre los ocho primeros
 * documentos reales, **dos de ocho** habrían salido acusados injustamente:
 *
 * ```
 * manifiesto : Matheo Gamarra Zanabria            ← al manifiesto le falta un nombre
 * pasaporte  : MATHEO ANTONIO GAMARRA ZANABRIA
 * e-ticket   : MATHEO ANTONIO GAMARRA ZANABRIA    ← el correcto, y sería el acusado
 * ```
 *
 * Por eso la referencia es **el escaneo del pasaporte** cuando lo hay, y el manifiesto sólo como
 * último recurso. Es la misma jerarquía que ya aplica {@see Cotejo} al decidir qué respalda a qué;
 * aquí sólo se hace explícita.
 *
 * ── Por qué se guarda de DÓNDE viene ────────────────────────────────────────
 * ⚠️ Porque cambia qué hay que hacer con la discrepancia. «`Ascarsa` ≠ `Ascarza` según el escaneo de
 * su pasaporte» manda a corregir el trámite; la misma frase «según el manifiesto» no manda nada,
 * porque el manifiesto puede ser el equivocado. Un aviso que no dice a quién creer se ignora.
 */
final readonly class ReferenciaDeIdentidad
{
    private function __construct(
        public ?string $pasaporte,
        public ?string $nombre,
        /** Para escribirlo en el aviso: «según el escaneo de su pasaporte». */
        public string $fuente,
        /** ¿Se puede mandar a corregir el trámite, o la referencia también es de fiar a medias? */
        public bool $esFiable,
    ) {}

    /** Lo leído del escaneo del pasaporte: la única fuente que no tecleó nadie. */
    public static function delEscaneo(DatosDeDocumento $leido): self
    {
        $nombre = trim(($leido->nombres ?? '').' '.($leido->apellidos ?? ''));

        return new self(
            $leido->numero,
            $nombre === '' ? null : $nombre,
            $leido->verificadoPorMrz() ? 'el escaneo de su pasaporte (MRZ)' : 'el escaneo de su pasaporte',
            $leido->verificadoPorMrz(),
        );
    }

    /**
     * El manifiesto, **como último recurso**: sin escaneo legible no hay nada mejor.
     *
     * ⚠️ `esFiable` es `false` a propósito. Quien pinte esto tiene que poder decir «el trámite no
     * coincide con el manifiesto, y el manifiesto puede ser el equivocado» en vez de mandar a
     * rehacer un trámite que a lo mejor está bien.
     */
    public static function delManifiesto(?string $pasaporte, ?string $nombre): self
    {
        return new self($pasaporte, $nombre, 'el manifiesto (tecleado a mano)', false);
    }

    public static function vacia(): self
    {
        return new self(null, null, 'nada', false);
    }
}
