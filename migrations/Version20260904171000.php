<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * Reapunta la última referencia de cotización huérfana: «Bus Bimodal desde Ollantaytambo».
 *
 * Salió al verificar `Version20260904121500`, y **no la dejó esa migración**: el uuid no es
 * ninguno de los ocho que retiró. Es un resto de la tanda de fusiones bidireccionales del
 * 30/08/2026, donde se borró el componente sin llevarse consigo la línea que lo citaba.
 *
 * ## Qué pasó exactamente
 *
 * La fusión **renombró en sitio** al superviviente en vez de crear uno nuevo, y eso es lo que
 * despista al leerlo hoy:
 *
 * | uuid | en la copia local (anterior) | en producción (fusionado) |
 * |---|---|---|
 * | `465d8735…` | `Bus Bimodal a Ollantatyambo` | `Bus Bimodal Cusco ↔ Ollantatyambo` |
 * | `5645b94b…` | `Bus Bimodal desde Ollantaytambo` | — borrado |
 *
 * El emparejamiento no es una suposición: los enlaces a segmento cuadran exactamente. «a
 * Ollantatyambo» tenía 3 y «desde Ollantaytambo» 2; el fusionado tiene 5.
 *
 * ## Por qué esta va por uuid y la anterior por nombre
 *
 * `Version20260904121500` pudo usar `nombre_interno` porque sus ocho componentes seguían vivos
 * en las dos bases. Aquí el origen **ya no existe en producción**, así que no hay nombre al que
 * agarrarse: lo único que queda de él es el uuid escrito en la columna de la cotización.
 *
 * Y el destino tampoco se puede buscar por nombre, porque se llama distinto en cada entorno —es
 * la tabla de arriba—. Su uuid, en cambio, es el mismo en ambos. El `JOIN` contra
 * `travel_componente` no es decorativo: si el destino no existiera, la actualización no toca
 * nada en vez de escribir una referencia rota nueva.
 */
final class Version20260904171000 extends AbstractMigration
{
    /** «Bus Bimodal desde Ollantaytambo», borrado en la fusión del 30/08/2026. */
    private const HUERFANO = '5645b94b-ad31-4b76-b1e4-8753d06b3579';

    /** El superviviente de esa fusión, hoy «Bus Bimodal Cusco ↔ Ollantatyambo». */
    private const DESTINO = '465d8735-9dcc-4588-bc45-c4ebd9ab4494';

    public function getDescription(): string
    {
        return 'Reapunta la referencia huérfana de «Bus Bimodal desde Ollantaytambo» al componente fusionado.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'UPDATE cotizacion_cotcomponente cc
                JOIN travel_componente destino ON BIN_TO_UUID(destino.id) = :destino
                 SET cc.componente_maestro_id = BIN_TO_UUID(destino.id)
               WHERE cc.componente_maestro_id = :huerfano',
            ['destino' => self::DESTINO, 'huerfano' => self::HUERFANO]
        );
    }

    public function down(Schema $schema): void
    {
        // Devolverlas sería volver a dejarlas colgando, y además no hay forma de distinguir las
        // filas que movió esta migración de las que ya apuntaban al destino desde la fusión.
        throw new IrreversibleMigration(
            'Reparar una referencia rota no se deshace: volvería a apuntar a un componente inexistente.'
        );
    }
}
