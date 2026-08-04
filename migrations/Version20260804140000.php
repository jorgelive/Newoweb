<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Visibilidad de los ítems de guía: de 3 niveles a 4 (tipo ACL).
 *
 * `privado` y `llegada` se renombran, y entre ambos aparece un peldaño nuevo
 * —`cliente-confirmado`— que exige pago pero NO ventana temporal. Antes ese
 * caso no se podía expresar: había que meterlo en `llegada`, que además exige
 * estar dentro de las 24 h previas al check-in.
 *
 *   publico  →  publico             (sin cambios; único nivel del catálogo)
 *   privado  →  cliente             (cualquiera con el localizador)
 *              cliente-confirmado   (NUEVO: además, pago confiable)
 *   llegada  →  solo-ventana        (además, ventana abierta)
 *
 * La equivalencia es exacta, así que ningún ítem cambia de audiencia con esta
 * migración: `cliente-confirmado` nace vacío y se puebla a mano desde el CRUD.
 *
 * Ver docs/PmsGuiaHuesped.md §3 y App\Pms\Enum\PmsGuiaVisibilidad.
 */
final class Version20260804140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Visibilidad de guía a 4 niveles: privado→cliente, llegada→solo-ventana, + cliente-confirmado.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE pms_guia_item SET visibilidad = 'cliente' WHERE visibilidad = 'privado'");
        $this->addSql("UPDATE pms_guia_item SET visibilidad = 'solo-ventana' WHERE visibilidad = 'llegada'");

        $this->addSql("ALTER TABLE pms_guia_item CHANGE visibilidad visibilidad VARCHAR(20) DEFAULT 'cliente' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        // `cliente-confirmado` no existe en el modelo de 3 niveles. Se degrada a
        // `llegada`, que es el nivel antiguo MÁS restrictivo de los dos que
        // podrían corresponderle: revertir nunca debe abrir contenido.
        $this->addSql("UPDATE pms_guia_item SET visibilidad = 'llegada' WHERE visibilidad = 'cliente-confirmado'");
        $this->addSql("UPDATE pms_guia_item SET visibilidad = 'llegada' WHERE visibilidad = 'solo-ventana'");
        $this->addSql("UPDATE pms_guia_item SET visibilidad = 'privado' WHERE visibilidad = 'cliente'");

        $this->addSql("ALTER TABLE pms_guia_item CHANGE visibilidad visibilidad VARCHAR(20) DEFAULT 'privado' NOT NULL");
    }
}
