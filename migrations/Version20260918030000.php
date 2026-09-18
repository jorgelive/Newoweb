<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El `boleto` genérico se parte en tres tickets, y el expediente gana su lista de exposición.
 *
 * ── Por qué se parte ────────────────────────────────────────────────────────
 * 🔥 **Un solo tipo impedía configurar qué ve el pasajero.** En el expediente del grupo había 123
 * tarjetas de embarque y, con el MISMO `boleto`, la entrada a Huayna Picchu, el tren de retorno y
 * el bus: exponer la tarjeta de embarque exponía también las entradas, y esconder una escondía la
 * otra. Ver `ArchivoTipoEnum` y `CotizacionFile::exponeAlPasajero()`.
 *
 * ── El reparto sale de los datos ────────────────────────────────────────────
 * Medido en producción el 18/09/2026: de 126 `boleto`, **123 tenían vuelo y dueño** —son tarjetas
 * de embarque— y 3 no tenían ninguno de los dos. Ésos tres se clasifican por su nombre, que es lo
 * único que dice qué son: «Entrada a Huayna Picchu» → ingreso; «Tren de retorno» y «Bus» →
 * transporte. Lo que no encaje en ninguna regla se queda en `ticket_ingreso`, que es el tipo sin
 * vuelo más común, y se cuenta en el `LEEME` de la salida.
 *
 * ⚠️ **Va por SQL a propósito, y aquí eso es lo correcto.** `EscaneoNuevoInvalidaVeredictoListener`
 * caduca el veredicto de un archivo al que le cambian el tipo, y un renombrado no es un documento
 * nuevo: pasar por el ORM habría invalidado lecturas buenas. Además, ninguno de estos tipos es
 * validable, así que no hay veredicto que conservar — pero el motivo hay que dejarlo escrito.
 *
 * ⚠️ **La columna nueva es NULABLE.** `null` significa «lo que diga el código», no «no expongas
 * nada», y una `json NOT NULL` añadida a una tabla con filas se rellena con el literal JSON `null`:
 * satisface el `NOT NULL`, no lo caza un `IS NULL` y revienta al leer. Ya pasó con
 * `observaciones_validacion`.
 */
final class Version20260918030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Parte el tipo `boleto` en ticket_aereo/ticket_ingreso/ticket_transporte y añade cotizacion_file.documentos_para_pasajero.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_file ADD documentos_para_pasajero JSON DEFAULT NULL');

        // Con vuelo es tarjeta de embarque, sin excepción: el vuelo lo pone `CargaMasivaDeArchivos`
        // al colgar un boarding pass de su tramo.
        $this->addSql("UPDATE cotizacion_file_archivo SET tipo_archivo = 'ticket_aereo' WHERE tipo_archivo = 'boleto' AND vuelo_id IS NOT NULL");

        // Sin vuelo: manda el nombre. `nombre` es i18n, así que se busca dentro del JSON.
        $this->addSql("UPDATE cotizacion_file_archivo SET tipo_archivo = 'ticket_transporte'
             WHERE tipo_archivo = 'boleto' AND vuelo_id IS NULL
               AND (LOWER(CAST(nombre AS CHAR)) LIKE '%tren%' OR LOWER(CAST(nombre AS CHAR)) LIKE '%bus%')");

        // El resto sin vuelo, a ingreso: es lo que son las entradas, y es el caso mayoritario.
        $this->addSql("UPDATE cotizacion_file_archivo SET tipo_archivo = 'ticket_ingreso' WHERE tipo_archivo = 'boleto'");
    }

    /**
     * Los tres vuelven a ser uno. Se pierde la distinción, que es justo lo que había antes.
     */
    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE cotizacion_file_archivo SET tipo_archivo = 'boleto' WHERE tipo_archivo IN ('ticket_aereo', 'ticket_ingreso', 'ticket_transporte')");
        $this->addSql('ALTER TABLE cotizacion_file DROP documentos_para_pasajero');
    }
}
