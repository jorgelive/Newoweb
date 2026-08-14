<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Saca el costo de proveedor del JSON que se le sirve al cliente.
 *
 * ### Qué pasaba
 *
 * `clasificacion_financiera_cliente` es la foto que consume la app del huésped, y en
 * `clasesPasajeros[].detalle[]` llevaba `montoCotizado` — que pese al nombre contiene
 * `montoCosto`, **lo que le cuesta al negocio cada línea**. Cualquiera con el enlace de su
 * cotización y las herramientas del navegador veía el margen. Las 10 de producción lo llevaban.
 *
 * Dos errores sumados, ya corregidos en el front:
 *
 * - El campo se llamaba `montoCotizado`, que se lee como «lo que se le cotiza al cliente». Al
 *   montar la lista blanca de `expurgarParaCliente()` alguien lo dio por publicable.
 * - `LineaDetalleClaseInterna extends LineaDetalleClaseCliente`: la herencia va al revés de lo
 *   seguro, así que **todo lo declarado sale publicado** salvo que se recuerde ponerlo en la
 *   extensión interna.
 *
 * ### Por qué hace falta esto además del arreglo de código
 *
 * El JSON está **persistido**. Corregir el expurgador solo limpia lo que se vuelva a guardar: las
 * cotizaciones ya emitidas seguirían expuestas hasta que alguien las tocara. Y son justo las que
 * tienen enlaces circulando.
 *
 * Es dato plano en una columna JSON, sin listeners de por medio: va por migración (CLAUDE.md
 * §Qué entra por migración y qué tiene que entrar por comando).
 */
final class Version20260814000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quita el costo de proveedor (montoCotizado) del snapshot financiero del cliente.';
    }

    public function up(Schema $schema): void
    {
        // Se opera sobre el texto y se deja que MySQL revalide el JSON al asignarlo: si el
        // resultado no fuera JSON válido, el UPDATE falla en vez de guardar algo roto.
        //
        // ⚠️ HACEN FALTA LOS DOS PATRONES. El campo lleva coma detrás cuando tiene hermanos
        // después, y coma DELANTE cuando es el último de su objeto —`…"montoCotizado": "20.00"}`—.
        // Con sólo el primero, en dev quedó 1 de 10 sin limpiar: justo el que la tenía delante.
        $this->addSql(<<<'SQL'
            UPDATE cotizacion_cotizacion
               SET clasificacion_financiera_cliente = REGEXP_REPLACE(
                     CAST(clasificacion_financiera_cliente AS CHAR),
                     '"montoCotizado": "[^"]*", ',
                     ''
                   )
             WHERE CAST(clasificacion_financiera_cliente AS CHAR) REGEXP '"montoCotizado": "[0-9]'
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE cotizacion_cotizacion
               SET clasificacion_financiera_cliente = REGEXP_REPLACE(
                     CAST(clasificacion_financiera_cliente AS CHAR),
                     ', "montoCotizado": "[^"]*"',
                     ''
                   )
             WHERE CAST(clasificacion_financiera_cliente AS CHAR) REGEXP '"montoCotizado": "[0-9]'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Quita un dato que nunca debió publicarse. Devolverlo sería reintroducir la fuga.'
        );
    }
}
