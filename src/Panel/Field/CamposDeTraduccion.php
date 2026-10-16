<?php

declare(strict_types=1);

namespace App\Panel\Field;

use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;

/**
 * Los dos interruptores de autotraducción de un formulario del panel, para un MAESTRO DE RÓTULOS.
 *
 * ── Qué es un maestro de rótulos ────────────────────────────────────────────
 * Textos CORTOS, reutilizados en muchos sitios y revisados a mano uno a uno: los textos de la
 * interfaz de pax (`UiI18n`), los nombres de moneda (`MaestroMoneda`), el diccionario de ítems de
 * Travel (`TravelItemDiccionario`), los títulos del catálogo del PMS (`PmsCatalogo`). Ahí el
 * traductor automático adivina —sin contexto, «Siguiente» salió «Following» y «Caja fuerte» salió
 * «Seguro»— y lo que interesa es que **lo revisado no se pise sin querer**.
 *
 * En un TEXTO LARGO (la guía, una nota, un segmento) es al revés: el traductor acierta y lo que
 * interesa es que corregir el español se propague solo. Esos formularios NO usan esto.
 *
 * ── La regla (Jorge, 16/10/2026) ────────────────────────────────────────────
 * - **Editar**: «Traducir automáticamente» sale DESMARCADO. Se guarda lo que se escribió y nada
 *   más; si se cambió el español, se marca la casilla o se corrigen los idiomas a mano.
 * - **Crear**: sale MARCADO. No hay nada revisado que proteger, y sin traducir el texto nacería
 *   con seis idiomas vacíos.
 *
 * ⚠️ El interruptor es VIRTUAL (`AutoTranslateControlTrait::$ejecutarTraduccion`, sin columna), y
 * por eso el valor por defecto se pone aquí, en el formulario, y no en la entidad: en el trait lo
 * comparten 29 entidades y todo lo que las guarda —util, la API, los comandos—, y ponerlo a
 * `false` allí apagaría la traducción de las cotizaciones, la guía y el catálogo sin un error.
 *
 * Ver docs/Autotraduccion.md, «Maestros de rótulos».
 */
final class CamposDeTraduccion
{
    /**
     * @param string|null $permiso Rol que puede ver los interruptores (EasyAdmin `setPermission`).
     *
     * @return list<BooleanField>
     */
    public static function deMaestroDeRotulos(string $pageName, ?string $permiso = null): array
    {
        $creando = $pageName === Crud::PAGE_NEW;

        $traducir = BooleanField::new('ejecutarTraduccion', 'Traducir automáticamente')
            ->onlyOnForms()
            ->setColumns(6)
            // `data` manda sobre el valor de la entidad, que para este campo virtual sale siempre
            // `true`: es la única forma de que el formulario de edición abra desmarcado.
            ->setFormTypeOption('data', $creando)
            ->setHelp($creando
                ? 'Escribe el español: el resto de idiomas se traduce al guardar.'
                : 'Desmarcado: se guarda tal cual, sin tocar las traducciones revisadas. Si cambiaste el español, márcalo o corrige los idiomas a mano.');

        $sobrescribir = BooleanField::new('sobreescribirTraduccion', 'Sobrescribir traducciones')
            ->onlyOnForms()
            ->setColumns(6)
            ->setHelp('⚠️ Rehace TODOS los idiomas con el traductor, aunque estén revisados. Sólo surte efecto con «Traducir automáticamente» marcado.');

        if ($permiso !== null) {
            $traducir->setPermission($permiso);
            $sobrescribir->setPermission($permiso);
        }

        return [$traducir, $sobrescribir];
    }
}
