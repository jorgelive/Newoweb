<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El Two Day MAPI bimodal empieza en Cusco (23/10/2026): su nombre lo dice, y el bus deja de
 * decir «Ollantatyambo».
 *
 * La plantilla gana el traslado al terminal y el bus bimodal del día 1 con
 * `app:travel:two-day-mapi-bimodal-completo`, que la busca por el slug nuevo. Sólo nombres
 * internos y slug: ninguno lleva `#[AutoTranslate]`. El título del componente que ve el cliente
 * ya estaba bien escrito.
 */
final class Version20261023230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Two Day MAPI bimodal: CUZ OLLA MAPI OLLA CUZ; «Ollantaytambo» en el bus bimodal';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE travel_itinerario SET slug = ?, nombre_interno = ? WHERE slug = ?', ['2D MAPI: CUZ OLLA MAPI OLLA CUZ (BM)', 'Two Day MAPI: CUZ OLLA MAPI OLLA CUZ (bimodal)', '2D MAPI: OLLA MAPI OLLA CUZ (BM)']);
        $this->addSql('UPDATE travel_componente SET nombre_interno = ? WHERE nombre_interno = ?', ['Bus Bimodal Cusco ↔ Ollantaytambo (ida o vuelta)', 'Bus Bimodal Cusco ↔ Ollantatyambo (ida o vuelta)']);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE travel_itinerario SET slug = ?, nombre_interno = ? WHERE slug = ?', ['2D MAPI: OLLA MAPI OLLA CUZ (BM)', 'Two Day MAPI: OLLA MAPI OLLA CUZ (bimodal)', '2D MAPI: CUZ OLLA MAPI OLLA CUZ (BM)']);
        $this->addSql('UPDATE travel_componente SET nombre_interno = ? WHERE nombre_interno = ?', ['Bus Bimodal Cusco ↔ Ollantatyambo (ida o vuelta)', 'Bus Bimodal Cusco ↔ Ollantaytambo (ida o vuelta)']);
    }
}
