<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Corrige la nota de Yape y Plin: por el chat de Booking no se pueden mandar imágenes.
 *
 * {@see Version20260810040000} las sembró diciendo «envíanos la captura del Yape **por este
 * chat** para confirmarlo». Es falso para la mayoría de los huéspedes: el canal de Beds24 —el
 * chat de Booking— no admite adjuntos. Un huésped lo dijo con todas las letras («este chat no
 * tiene la opción para adjuntar, porfa envíame tu correo»), y los números lo confirman: **8
 * adjuntos en 222 conversaciones de Beds24, contra 75 en 111 de WhatsApp**.
 *
 * Así que se le estaba pidiendo algo que no podía hacer, justo después de haber pagado.
 *
 * ### Por qué se corrige AQUÍ y no en la guía
 *
 * Se intentó primero en el ítem «Pago» y no sirvió de nada: el agente lee esta nota, que viene
 * de `consultar_medios_pago`, y la repitió literalmente. Es la regla de que **lo específico gana
 * sobre la guía** vista desde el otro lado — si la skill manda, el texto hay que corregirlo en
 * la skill.
 *
 * La nota nueva no nombra el canal del huésped porque el catálogo no lo conoce: dice qué sí
 * funciona (WhatsApp) y ofrece la salida de avisar y que el equipo lo pida.
 */
final class Version20260810220000 extends AbstractMigration
{
    /** @var list<array{language: string, content: string}> */
    private const array NOTA = [
        ['language' => 'es', 'content' => 'Avisanos cuando lo hayas hecho y el equipo lo confirma. Si quieres mandarnos la captura, por WhatsApp si se puede; el chat de Booking no admite imagenes.'],
        ['language' => 'en', 'content' => 'Let us know once you have sent it and the team will confirm. If you want to send us the screenshot, WhatsApp works; the Booking chat does not accept images.'],
        ['language' => 'pt', 'content' => 'Avise-nos quando o tiver feito e a equipa confirma. Se quiser enviar o comprovativo, por WhatsApp e possivel; o chat da Booking nao aceita imagens.'],
        ['language' => 'fr', 'content' => 'Prevenez-nous une fois fait et l equipe confirmera. Pour envoyer la capture, WhatsApp fonctionne; le chat Booking n accepte pas les images.'],
        ['language' => 'it', 'content' => 'Avvisaci quando lo hai fatto e il team conferma. Se vuoi inviare lo screenshot, con WhatsApp si puo; la chat di Booking non accetta immagini.'],
        ['language' => 'de', 'content' => 'Sagen Sie uns Bescheid, sobald Sie es gesendet haben. Fuer den Beleg funktioniert WhatsApp; der Booking-Chat akzeptiert keine Bilder.'],
        ['language' => 'nl', 'content' => 'Laat het ons weten zodra u het heeft gedaan. Wilt u de screenshot sturen, dan kan dat via WhatsApp; de Booking-chat accepteert geen afbeeldingen.'],
    ];

    /** Yape y Plin comparten número, titular y por tanto también la nota. */
    private const array TIPOS = ['yape', 'plin'];

    public function getDescription(): string
    {
        return 'Corrige la nota de Yape y Plin: el chat de Booking no admite adjuntos.';
    }

    public function up(Schema $schema): void
    {
        $nota = json_encode(self::NOTA, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        foreach (self::TIPOS as $tipo) {
            $this->addSql(
                'UPDATE fin_medio_cobro SET nota = :nota WHERE tipo = :tipo',
                ['nota' => $nota, 'tipo' => $tipo]
            );
        }
    }

    public function down(Schema $schema): void
    {
        // No se restaura el texto anterior: pedía algo imposible. Dejarlo a NULL es peor —el
        // vacío el modelo lo rellena— así que se revierte a lo mínimo cierto, sin el «por este
        // chat» que era el error.
        foreach (self::TIPOS as $tipo) {
            $this->addSql(
                'UPDATE fin_medio_cobro SET nota = :nota WHERE tipo = :tipo',
                [
                    'nota' => json_encode(
                        [['language' => 'es', 'content' => 'Avisanos cuando lo hayas hecho y el equipo lo confirma.']],
                        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                    ),
                    'tipo' => $tipo,
                ]
            );
        }
    }
}
