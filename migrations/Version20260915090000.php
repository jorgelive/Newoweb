<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Qué documentos pide cada expediente, en vez de una lista fija para todos.
 *
 * ── Por qué ────────────────────────────────────────────────────────────────
 * La lista vivía escrita en el front —pasaporte y las dos caras del DNI— y aguantó mientras fueron
 * documentos que pide cualquier viaje. Se rompió al entrar el **E-Ticket migratorio dominicano**,
 * que está atado a UN destino: con la lista fija, un expediente a Cusco empezó a pedir a sus
 * pasajeros un formulario de Migración de República Dominicana, y cada uno de ellos veía «te falta
 * un documento» para siempre.
 *
 * ⚠️ **Ese fallo no se queja.** El pasajero no escribe para decir que le piden algo raro: deja de
 * intentarlo, o manda cualquier cosa. Se descubre mirando su pantalla, que es lo que casi nunca se
 * hace.
 *
 * ── El relleno va en TRES pasos, y no es ceremonia ──────────────────────────
 * 🔥 Una columna `JSON NOT NULL` añadida a una tabla **con filas** se rellena con el literal JSON
 * `null`, que NO es SQL NULL: satisface el `NOT NULL`, no lo caza un `WHERE … IS NULL`, y
 * `json_decode('null')` devuelve `null` de PHP — que Doctrine asigna a una propiedad `array` y
 * revienta con un `TypeError` **al leer**, días después, la primera vez que alguien abre una fila
 * vieja. Ya pasó aquí el 09/09/2026 con `observaciones_validacion`.
 *
 * Por eso: se añade **nulable**, se rellena, y sólo entonces se pone `NOT NULL`.
 *
 * ── Qué se rellena ─────────────────────────────────────────────────────────
 * Los tres de siempre, que es exactamente lo que esos expedientes ya estaban pidiendo: esta
 * migración **no cambia lo que ve nadie**. El E-Ticket queda fuera a propósito y se enciende a mano
 * en los expedientes que van a República Dominicana.
 */
final class Version20260915090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CotizacionFile.documentos_pedidos: qué documentos exige cada expediente.';
    }

    public function up(Schema $schema): void
    {
        // 1. Nulable, para poder rellenar sin que el literal JSON `null` se cuele.
        $this->addSql('ALTER TABLE cotizacion_file ADD documentos_pedidos JSON DEFAULT NULL');

        // 2. El default de lo que ya existía: lo que esos expedientes ya pedían.
        //    ⚠️ La condición lleva las dos mitades: `IS NULL` no ve el literal JSON `null`.
        $this->addSql(<<<'SQL'
            UPDATE cotizacion_file
               SET documentos_pedidos = CAST('["pasaporte","dni_anverso","dni_reverso"]' AS JSON)
             WHERE documentos_pedidos IS NULL
                OR JSON_TYPE(documentos_pedidos) = 'NULL'
        SQL);

        // 3. Y ahora sí, obligatoria. La lista VACÍA sigue siendo válida —un expediente que no
        //    recoge documentos—; lo que deja de ser posible es la ausencia de respuesta.
        $this->addSql('ALTER TABLE cotizacion_file MODIFY documentos_pedidos JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_file DROP documentos_pedidos');
    }
}
