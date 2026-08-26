<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Devuelve su nombre al marcador `{{guest_name}}` en los cuerpos NEERLANDESES.
 *
 * ### Qué pasó
 *
 * Resto del traductor automático de antes de `ProtectorDeMarcadores` (12/08/2026): Google no
 * dejaba quietos los `{{ marcadores }}`, les traducía el nombre de dentro. En neerlandés
 * `{{guest_name}}` volvió como `{{gastnaam}}`, y el interpolador busca el primero.
 *
 * Auditadas las 44 columnas auto-traducidas de producción, es lo ÚNICO que quedaba descuadrado:
 * cinco bloques, un solo idioma y un solo marcador.
 *
 * | Plantilla | Campos |
 * |---|---|
 * | `welcome_booking` | `email_tmpl` |
 * | `despedida_airbnb` | `beds24_tmpl`, `whatsapp_link_tmpl` |
 * | `despedida_booking` | `beds24_tmpl`, `whatsapp_link_tmpl` |
 *
 * ### Por qué corría prisa relativa, y no absoluta
 *
 * Un marcador sin resolver **se envía crudo** (ver `EmailSendMappingStrategy::hidratar()`): el
 * huésped habría recibido un `{{gastnaam}}` literal. Pero no llegó a pasar —0 filas con ese
 * `failed_reason` en las colas— y hay **1 sola reserva** en neerlandés de 349.
 *
 * ### Ninguno es un cuerpo de Meta
 *
 * Y eso es lo que hace este arreglo barato: los cinco están en canales nuestros (correo, Beds24
 * y enlace de WhatsApp), donde la verdad es nuestra copia. No hay que volver a someter ni
 * esperar aprobación, al contrario que con `aviso_escalado_interno`.
 *
 * Va en PHP y no en un `REPLACE()` de SQL para no depender de si el JSON guarda el marcador con
 * espacios o sin ellos.
 */
final class Version20260826120000 extends AbstractMigration
{
    private const array CAMPOS = ['email_tmpl', 'beds24_tmpl', 'whatsapp_link_tmpl', 'whatsapp_meta_tmpl'];

    /** traducido => el que busca el interpolador */
    private const array MARCADORES = ['gastnaam' => 'guest_name'];

    public function getDescription(): string
    {
        return 'Restaura {{guest_name}} en los cuerpos neerlandeses donde el traductor lo dejó como {{gastnaam}}.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::CAMPOS as $campo) {
            $filas = $this->connection->fetchAllAssociative(
                sprintf('SELECT code, `%s` AS json FROM msg_template WHERE `%s` LIKE ?', $campo, $campo),
                ['%gastnaam%']
            );

            foreach ($filas as $fila) {
                $texto = (string) $fila['json'];

                foreach (self::MARCADORES as $malo => $bueno) {
                    // Con o sin espacios dentro de las llaves: las dos formas circulan.
                    $texto = (string) preg_replace(
                        '/\{\{\s*' . preg_quote($malo, '/') . '\s*\}\}/u',
                        '{{' . $bueno . '}}',
                        $texto
                    );
                }

                $this->addSql(
                    sprintf('UPDATE msg_template SET `%s` = :json WHERE code = :code', $campo),
                    ['json' => $texto, 'code' => $fila['code']]
                );

                $this->write(sprintf('  -> %s · %s corregido.', $fila['code'], $campo));
            }
        }
    }

    public function down(Schema $schema): void
    {
        // No se revierte: devolver el marcador traducido es devolver el fallo.
        $this->write('  -> No se revierte: {{gastnaam}} nunca lo resolvió nadie.');
    }
}
