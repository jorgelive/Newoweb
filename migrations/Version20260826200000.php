<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Marca como `DELETED` los cinco idiomas de `aviso_escalado_interno` que ya no están en Meta.
 *
 * ### Qué pasó
 *
 * El 26/08/2026 se borraron en Meta `pt`, `fr`, `it`, `de` y `nl` para poder recrearlos con los
 * marcadores corregidos (`{{guest}}` → `{{huesped}}`). El borrado entró; la recreación no, y no
 * podía: **Meta reserva el par nombre+idioma durante cuatro semanas**.
 *
 * ### Por qué hace falta esta migración
 *
 * Porque nuestra copia seguía diciendo `APPROVED` de algo que ya no existe, y **el sincronizador
 * no lo corrige**: `WhatsappMetaTemplateSyncService` sólo crea o actualiza lo que Meta devuelve,
 * y lo borrado no vuelve en esa lista. Un dato que miente en silencio es justo lo que este
 * proyecto paga caro.
 *
 * No se borra el bloque del idioma: su TEXTO —ya con los marcadores buenos— es lo que se
 * volverá a subir pasado el plazo. Lo único que cambia es que deja de decir que está aprobado.
 *
 * ### Cómo se sale de aquí
 *
 * A partir de finales de septiembre de 2026: panel → «Push a Meta» → marcar esos cinco. Al no
 * existir en Meta, entran por el camino de CREACIÓN, que es el probado.
 *
 * Va por SQL directo: es un JSON que no dispara `AutoTranslate` —y no queremos que lo dispare.
 */
final class Version20260826200000 extends AbstractMigration
{
    private const string CODIGO = 'aviso_escalado_interno';

    /** @var list<string> */
    private const array BORRADOS = ['pt', 'fr', 'it', 'de', 'nl'];

    public function getDescription(): string
    {
        return 'Marca como DELETED los idiomas de aviso_escalado_interno borrados en Meta.';
    }

    public function up(Schema $schema): void
    {
        $json = $this->connection->fetchOne(
            'SELECT whatsapp_meta_tmpl FROM msg_template WHERE code = ?',
            [self::CODIGO]
        );

        if (!is_string($json) || $json === '') {
            $this->write(sprintf('  -> No existe la plantilla "%s"; nada que marcar.', self::CODIGO));

            return;
        }

        /** @var array<string, mixed> $meta */
        $meta = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $marcados = 0;

        if (isset($meta['body']) && is_array($meta['body'])) {
            foreach ($meta['body'] as $i => $bloque) {
                if (!is_array($bloque) || !in_array((string) ($bloque['language'] ?? ''), self::BORRADOS, true)) {
                    continue;
                }

                $meta['body'][$i]['status'] = 'DELETED';
                ++$marcados;
            }
        }

        if ($marcados === 0) {
            $this->write('  -> Ningún idioma coincidió; no se toca nada.');

            return;
        }

        $this->addSql(
            'UPDATE msg_template SET whatsapp_meta_tmpl = :meta WHERE code = :code',
            [
                'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'code' => self::CODIGO,
            ]
        );

        $this->write(sprintf('  -> %d idioma(s) marcados como DELETED.', $marcados));
    }

    public function down(Schema $schema): void
    {
        // No se revierte: decir `APPROVED` de algo que Meta ya no tiene era el fallo.
        $this->write('  -> No se revierte: esos idiomas no existen en Meta.');
    }
}
