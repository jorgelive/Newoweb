<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Arregla dos destrozos del traductor automático en `aviso_escalado_interno`.
 *
 * ### 1. «PMS» no es el síndrome premenstrual
 *
 * El pie en francés decía literalmente **«Notification automatique du syndrome prémenstruel»**.
 * El traductor leyó las siglas del sistema como las del síndrome. Sólo lo ve el equipo y sólo
 * en francés, así que no ha hecho daño — pero está en una plantilla que se manda.
 *
 * ### 2. ⚠️ Y lo que sí rompe: tradujo los NOMBRES de las variables
 *
 * `EscalarAlEquipoSkill` manda `['huesped' => …, 'motivo' => …]`, pero los cuerpos traducidos
 * piden otra cosa:
 *
 * | Idioma | Pedía | Manda el código |
 * |---|---|---|
 * | pt, fr, it, de | `{{guest}}`, `{{reason}}` | `huesped`, `motivo` |
 * | nl | `{{gast}}`, `{{reden}}` | `huesped`, `motivo` |
 *
 * En esos cinco idiomas la variable no hidrata, llega vacía y `WhatsappMetaSendMappingStrategy`
 * lanza: el aviso **no sale**. Está dormido porque `AvisoAlEquipoService::conversacionStaff()`
 * crea el hilo del operador en español, así que en la práctica siempre se usa el cuerpo `es`
 * (y el `en`, que por suerte se salvó). El día que alguien ponga en portugués el idioma de un
 * operador, sus escalados dejan de llegar en silencio.
 *
 * Como los cinco ya estaban rotos, unificar los nombres no puede empeorar nada.
 *
 * ### ⚠️ Esto NO cambia lo que Meta tiene aprobado
 *
 * El cuerpo y el pie que ve el destinatario los renderiza Meta desde SU copia. Esta migración
 * arregla la nuestra, que es la que se usa para crear/actualizar la plantilla allí y la que lee
 * el sync. Para que el cambio se note de verdad hay que **volver a someter la plantilla** en el
 * WhatsApp Manager y, una vez aprobada, correr `app:whatsapp:sync-templates`.
 *
 * Va por SQL directo con conciencia: es un JSON que no dispara `AutoTranslate` —y no queremos
 * que lo dispare, que es justo quien lo estropeó—.
 */
final class Version20260825160100 extends AbstractMigration
{
    private const string CODIGO = 'aviso_escalado_interno';

    public function getDescription(): string
    {
        return 'Corrige el pie francés y unifica los nombres de variable de aviso_escalado_interno.';
    }

    public function up(Schema $schema): void
    {
        $this->arreglar([
            'guest' => 'huesped',
            'reason' => 'motivo',
            'gast' => 'huesped',
            'reden' => 'motivo',
        ], 'Notification automatique du syndrome prémenstruel', 'Notification automatique du PMS');
    }

    public function down(Schema $schema): void
    {
        // No se revierte: devolver los nombres traducidos sería devolver el fallo. El pie sí,
        // por simetría, pero tampoco tiene gracia. Se deja explícito en vez de fingir.
        $this->write('  -> No se revierte: volver a los nombres traducidos reintroduciría el fallo.');
    }

    /** @param array<string, string> $variables nombre traducido => nombre que manda el código */
    private function arreglar(array $variables, string $pieMalo, string $pieBueno): void
    {
        $json = $this->connection->fetchOne(
            'SELECT whatsapp_meta_tmpl FROM msg_template WHERE code = ?',
            [self::CODIGO]
        );

        if (!is_string($json) || $json === '') {
            $this->write(sprintf('  -> No existe la plantilla "%s"; nada que arreglar.', self::CODIGO));

            return;
        }

        /** @var array<string, mixed> $meta */
        $meta = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (isset($meta['body']) && is_array($meta['body'])) {
            foreach ($meta['body'] as $i => $bloque) {
                if (!is_array($bloque) || !isset($bloque['content']) || !is_string($bloque['content'])) {
                    continue;
                }

                $texto = $bloque['content'];

                foreach ($variables as $traducido => $real) {
                    $texto = str_replace('{{' . $traducido . '}}', '{{' . $real . '}}', $texto);
                }

                $meta['body'][$i]['content'] = $texto;
            }
        }

        if (isset($meta['footer']) && is_array($meta['footer'])) {
            foreach ($meta['footer'] as $i => $bloque) {
                if (is_array($bloque) && ($bloque['content'] ?? null) === $pieMalo) {
                    $meta['footer'][$i]['content'] = $pieBueno;
                }
            }
        }

        $this->addSql(
            'UPDATE msg_template SET whatsapp_meta_tmpl = :meta WHERE code = :code',
            [
                'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'code' => self::CODIGO,
            ]
        );
    }
}
