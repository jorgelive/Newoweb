<?php

declare(strict_types=1);

namespace App\Cotizacion\Enum;

use App\Enum\DocumentoTipoEnum;

/**
 * En qué punto del control está **un número del manifiesto**: este DNI, este pasaporte.
 *
 * 🔑 **Vive en la identificación y no en el archivo, y ése es el giro que ordena todo.** El
 * documento escaneado no es lo que se pone en duda —es el documento oficial de una persona—: lo
 * que se valida es **lo que alguien tecleó en el manifiesto**. Así, el sello se ve donde se mira
 * el dato, al lado del número, y no en una lista de ficheros aparte.
 *
 * Cuatro estados y no tres, porque **cómo** se validó cambia cuánto vale:
 *
 * | | Qué lo respalda | Se puede equivocar en |
 * |---|---|---|
 * | `VALIDADO_MRZ` | dígitos de control de la banda: aritmética | nada que un OCR pueda leer mal |
 * | `VALIDADO_OCR` | la lectura del documento coincide con lo guardado | los dos a la vez, si el error venía del padrón original |
 *
 * ⚠️ `VALIDADO_MRZ` **sólo existe para el pasaporte**: el DNI peruano no lleva banda TD3. Que un
 * DNI se quede siempre en `VALIDADO_OCR` no es que esté peor leído, es que **no hay banda que
 * comprobar** — y confundir las dos cosas llevaría a perseguir una calidad de escaneo que no
 * cambiaría nada.
 */
enum ValidacionIdentificacionEnum: string
{
    /** Nadie lo ha mirado todavía, o no había documento que leer. */
    case NO_VALIDADO = 'no_validado';

    /** Se leyó un documento y **algo no encaja** con lo guardado. Va a la cola humana. */
    case OBSERVADO = 'observado';

    /** El documento leído concuerda con lo guardado. Sin banda: dos lecturas que coinciden. */
    case VALIDADO_OCR = 'validado_ocr';

    /** Concuerda **y** la lectura la respaldan los dígitos de control de la MRZ. */
    case VALIDADO_MRZ = 'validado_mrz';

    /**
     * Una **persona** miró el documento y dijo que la ficha es correcta.
     *
     * 🔥 **Existe porque sin él la ficha copiada del escaneo no tenía salida.** Un número que salió
     * del escaneo no puede cotejarse contra el escaneo —sería compararse consigo mismo— así que
     * quedaba `OBSERVADO` pidiendo «que alguien la confirme»… sin que existiera ningún sitio donde
     * confirmar. La única forma de apagar el aviso era cambiar el número a otro, guardar, volver a
     * poner el bueno y guardar otra vez: dos escrituras falsas como peaje.
     *
     * ⚠️ **No es `VALIDADO_OCR` y no debe serlo.** Ahí hay dos lecturas independientes que
     * coinciden; aquí hay una lectura y alguien que la mira. Es respaldo suficiente para cerrar el
     * trabajo, pero es de otra clase, y este código ya tiene escrita la regla de que **cuál fue el
     * camino importa**. Con un sello propio se puede además contar cuántos documentos se apoyan
     * hoy en el ojo de una persona, que es justo lo que nadie podría responder si se mezclaran.
     */
    case CONFIRMADO = 'confirmado';

    public function getLabel(): string
    {
        return match ($this) {
            self::NO_VALIDADO => 'Sin validar',
            self::OBSERVADO => 'Observado',
            self::VALIDADO_OCR => 'Validado (OCR)',
            self::VALIDADO_MRZ => 'Validado (MRZ)',
            self::CONFIRMADO => 'Confirmado a mano',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NO_VALIDADO => 'slate',
            self::OBSERVADO => 'amber',
            self::VALIDADO_OCR => 'sky',
            self::VALIDADO_MRZ => 'emerald',
            // Verde como los otros dos sellos —el trabajo está cerrado— pero no el mismo: de un
            // vistazo se distingue lo que respalda una máquina de lo que respalda una persona.
            self::CONFIRMADO => 'teal',
        };
    }

    /**
     * ¿Ya está resuelto? Es lo que hace **idempotente** el proceso por tandas: lo validado no se
     * vuelve a leer, y así una segunda pasada sólo cuesta lo que falta.
     */
    public function estaResuelto(): bool
    {
        // ⚠️ `CONFIRMADO` cuenta como resuelto, y es la mitad del arreglo: si la tanda volviera a
        // cotejarlo, lo devolvería a `OBSERVADO` —sigue sin haber con qué cotejar— y la
        // confirmación de la persona duraría hasta la siguiente pasada.
        return in_array($this, [self::VALIDADO_OCR, self::VALIDADO_MRZ, self::CONFIRMADO], true);
    }

    /**
     * ¿Este estado tiene sentido para ese tipo de documento?
     *
     * ⚠️ **Aquí se excluía el DNI de `VALIDADO_MRZ`, y era falso.** Se daba por hecho que el DNI
     * peruano no lleva banda; el DNIe la lleva en el **REVERSO**, en formato TD1 — aquí llegó a
     * decir «anverso», que es donde se buscó durante meses sin encontrarla. Así que un DNI también
     * puede quedar respaldado por aritmética, con la cara correcta delante.
     *
     * Los que siguen sin poder son los que no tienen banda de ninguna clase — carné de
     * extranjería, RUC—, y ésos se quedan en `VALIDADO_OCR`, que no es peor lectura: es que no hay
     * dígitos que comprobar.
     */
    public function aplicaA(DocumentoTipoEnum $tipo): bool
    {
        return $this !== self::VALIDADO_MRZ
            || in_array($tipo, [DocumentoTipoEnum::PASAPORTE, DocumentoTipoEnum::DNI], true);
    }
}
