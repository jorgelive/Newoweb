<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `agente_contenido`: lo que el asistente cuenta de un ítem, cuando no es lo que se publica.
 *
 * Nace de dos necesidades opuestas que resultaron ser la misma:
 *
 * 1. **Callar** lo que en pantalla está bien y por chat espanta. El ítem de pagos explica el
 *    depósito de garantía de S/ 300; leído en la guía, junto a la explicación de que se
 *    devuelve entero, es información. Soltado a quien sólo preguntó «¿el pago no es por
 *    Booking?», es un peaje sorpresa.
 * 2. **Contar** lo que el agente necesita saber y no es contenido publicable: por qué el
 *    importe que muestra la app del canal no cuadra con el nuestro, por ejemplo. Sin un sitio
 *    donde escribirlo, el modelo improvisa una explicación verosímil distinta cada vez.
 *
 * Nace vacío: sin nada escrito, `consultar_guia` sigue devolviendo el cuerpo de siempre y no
 * cambia el comportamiento de ningún ítem existente.
 */
final class Version20260810120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade agente_contenido a pms_guia_item: el texto que el asistente usa en lugar del cuerpo.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_guia_item ADD agente_contenido LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_guia_item DROP agente_contenido');
    }
}
