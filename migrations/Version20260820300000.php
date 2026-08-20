<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Travel entra en la mensajería: los expedientes de viaje cuelgan de una conversación.
 *
 * La hermana de `pms_conversacion_enlace` para el otro negocio. Un cliente de hotel puede además
 * comprar tours: con los dos enlaces, **las dos cosas viven en el mismo hilo** y quien atiende ve
 * el historial completo — que es la razón de ser de la separación persona/asunto.
 *
 * ⚠️ **Tabla propia y no una común con `context_id` de texto.** Eso convertiría la clave foránea
 * en una cadena y perdería la integridad referencial. Y `file_id` **no lleva `CASCADE`**, igual
 * que `reserva_id` en el PMS: borrar un expediente no puede llevarse por delante el hilo de
 * mensajes con esa persona. Que falle el borrado es preferible a perder la conversación en
 * silencio.
 *
 * Nace **vacía**: nadie crea enlaces de Travel todavía. Es la mesa puesta — el núcleo ya sabe
 * recogerlos por `ProveedorDeEnlacesInterface`, así que en cuanto algo los cree, aparecen.
 */
final class Version20260820300000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Travel en la mensajería: enlace entre conversación y expediente de viaje.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cotizacion_conversacion_enlace (
            id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\',
            conversacion_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\',
            file_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\',
            vinculo VARCHAR(30) DEFAULT \'ninguno\' NOT NULL,
            milestones JSON DEFAULT NULL,
            origen VARCHAR(50) DEFAULT NULL,
            agencia VARCHAR(100) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_CA0D92ADABD5A1D6 (conversacion_id),
            INDEX IDX_CA0D92AD93CB796C (file_id),
            UNIQUE INDEX uq_cot_enlace_conversacion_file (conversacion_id, file_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE cotizacion_conversacion_enlace
            ADD CONSTRAINT FK_CA0D92ADABD5A1D6 FOREIGN KEY (conversacion_id)
                REFERENCES msg_conversation (id) ON DELETE CASCADE');

        // Sin ON DELETE: ver el docblock. Borrar un expediente debe fallar, no vaciar el hilo.
        $this->addSql('ALTER TABLE cotizacion_conversacion_enlace
            ADD CONSTRAINT FK_CA0D92AD93CB796C FOREIGN KEY (file_id) REFERENCES cotizacion_file (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cotizacion_conversacion_enlace');
    }
}
