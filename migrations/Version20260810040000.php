<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rellena las notas de los medios de cobro en los siete idiomas del sitio.
 *
 * {@see Version20260810020000} las sembró **sólo en español**, con el razonamiento de que
 * `AutoTranslationService` las completaría al primer guardado desde el panel. Es verdad, pero
 * llega tarde: hasta que alguien abra y guarde cada fila —cuatro filas que nadie tiene motivo
 * para tocar—, un huésped francés ve la nota en español en su guía. Y la nota de la guía **no
 * pasa por ningún modelo que la redacte**: la pinta `MediosPagoWidget` tal cual venga.
 *
 * Así que se traducen aquí, una vez, y a partir de ahora las mantiene el traductor automático
 * como cualquier otro texto: se edita el español en el panel y, marcando «Retraducir la nota
 * al guardar», se rehace el resto.
 *
 * Los siete idiomas son los de `maestro_idioma` con `prioridad > 0`, en su orden de prioridad:
 * es, en, pt, fr, it, de, nl. Si mañana se activa un octavo, esta migración no lo sabe — lo
 * rellenará el traductor al siguiente guardado, que es el camino normal.
 *
 * ⚠️ **Pisa la nota entera** de esas cuatro filas. Corre una sola vez y antes de que nadie
 * las haya tocado en producción (allí la tabla no existe todavía). Las ocho cuentas bancarias
 * no llevan nota y no se tocan.
 */
final class Version20260810040000 extends AbstractMigration
{
    /**
     * Nota por tipo de medio y por idioma.
     *
     * El español es la fuente: si se corrige, se corrige aquí arriba y el resto se rehace
     * desde el panel, no a mano.
     *
     * @var array<string, array<string, string>>
     */
    private const array NOTAS = [
        'yape' => [
            'es' => 'Envíanos la captura del Yape por este chat para confirmarlo.',
            'en' => 'Send us the Yape screenshot in this chat so we can confirm it.',
            'pt' => 'Envie-nos o comprovativo do Yape por este chat para o confirmarmos.',
            'fr' => "Envoyez-nous la capture d'écran du Yape sur ce chat pour que nous puissions le confirmer.",
            'it' => 'Inviaci lo screenshot del Yape in questa chat per confermarlo.',
            'de' => 'Senden Sie uns den Yape-Beleg in diesem Chat, damit wir ihn bestätigen können.',
            'nl' => 'Stuur ons de screenshot van de Yape-betaling via deze chat, zodat we die kunnen bevestigen.',
        ],
        'plin' => [
            'es' => 'Envíanos la captura del Plin por este chat para confirmarlo.',
            'en' => 'Send us the Plin screenshot in this chat so we can confirm it.',
            'pt' => 'Envie-nos o comprovativo do Plin por este chat para o confirmarmos.',
            'fr' => "Envoyez-nous la capture d'écran du Plin sur ce chat pour que nous puissions le confirmer.",
            'it' => 'Inviaci lo screenshot del Plin in questa chat per confermarlo.',
            'de' => 'Senden Sie uns den Plin-Beleg in diesem Chat, damit wir ihn bestätigen können.',
            'nl' => 'Stuur ons de screenshot van de Plin-betaling via deze chat, zodat we die kunnen bevestigen.',
        ],
        'western_union' => [
            'es' => 'El cobro se hace en tienda, en persona. Envíanos el número de seguimiento (MTCN) por este chat en cuanto lo tengas.',
            'en' => 'The money is collected in person at a branch. Send us the tracking number (MTCN) in this chat as soon as you have it.',
            'pt' => 'O levantamento é feito presencialmente numa loja. Envie-nos o número de seguimento (MTCN) por este chat assim que o tiver.',
            'fr' => "Le retrait se fait en personne en agence. Envoyez-nous le numéro de suivi (MTCN) sur ce chat dès que vous l'avez.",
            'it' => 'Il ritiro avviene di persona in agenzia. Inviaci il numero di tracciamento (MTCN) in questa chat appena lo hai.',
            'de' => 'Das Geld wird persönlich in einer Filiale abgeholt. Senden Sie uns die Trackingnummer (MTCN) in diesem Chat, sobald Sie sie haben.',
            'nl' => 'Het geld wordt persoonlijk in een filiaal opgehaald. Stuur ons het trackingnummer (MTCN) via deze chat zodra u het heeft.',
        ],
        'efectivo' => [
            'es' => 'Puedes pagar en efectivo al hacer el check-in, en soles o en dólares.',
            'en' => 'You can pay in cash at check-in, in soles or in US dollars.',
            'pt' => 'Pode pagar em dinheiro no check-in, em soles ou em dólares.',
            'fr' => "Vous pouvez payer en espèces à l'arrivée, en soles ou en dollars.",
            'it' => 'Puoi pagare in contanti al check-in, in soles o in dollari.',
            'de' => 'Sie können beim Check-in bar bezahlen, in Soles oder in US-Dollar.',
            'nl' => 'U kunt bij het inchecken contant betalen, in soles of in dollars.',
        ],
    ];

    public function getDescription(): string
    {
        return 'Traduce a los 7 idiomas las notas de Yape, Plin, Western Union y efectivo.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::NOTAS as $tipo => $porIdioma) {
            $nota = [];

            foreach ($porIdioma as $idioma => $texto) {
                $nota[] = ['language' => $idioma, 'content' => $texto];
            }

            $this->addSql(
                'UPDATE fin_medio_cobro SET nota = :nota WHERE tipo = :tipo',
                [
                    'nota' => json_encode($nota, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'tipo' => $tipo,
                ]
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Volver al estado anterior es dejar sólo el español, que es lo que había.
        foreach (self::NOTAS as $tipo => $porIdioma) {
            $this->addSql(
                'UPDATE fin_medio_cobro SET nota = :nota WHERE tipo = :tipo',
                [
                    'nota' => json_encode(
                        [['language' => 'es', 'content' => $porIdioma['es']]],
                        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                    ),
                    'tipo' => $tipo,
                ]
            );
        }
    }
}
