<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Los siete «Puerta (casa N)» pasan a mandarle el croquis al asistente.
 *
 * ── Qué cambia, y qué no ───────────────────────────────────────────────────
 * El texto es el mismo que ya había escrito el operador, **uno por casita y distinto en cada una**
 * —unas entran por la calle y otras por el pasaje—. No se unifican ni se reescriben. Tres cambios:
 *
 * 1. `la Casa N` → `{{ unit_name }}`. El nombre sale del sistema, así que si la casita se renombra
 *    el texto la sigue. Tecleado, no.
 * 2. Las tildes que faltaban («esta» → «está», «porton» → «portón», «Pidele» → «Pídele»).
 * 3. **La línea del croquis**, que es lo único nuevo de verdad: `{{ croquis }}` resuelve al croquis
 *    de ESA casita, el que numera sólo su puerta.
 *
 * ── Por qué `{{ unit_name }}` y no `{{ numero }}` ──────────────────────────
 * `numero` es sensible —es el número grabado en la llave— y fuera de la ventana se sustituye por
 * el mensaje de bloqueo: «La entrada de la Casa [Disponible al confirmar]» sería ilegible.
 * `unit_name` da «Casita 3» y no está tapado nunca.
 *
 * **Y el número no se escribe en el texto a propósito**: lo lleva dibujado el croquis. Es la razón
 * del plan entero (`docs/PmsGuiaHuesped.md` §3.c) — el dato en un solo sitio.
 *
 * ⚠️ Sólo toca los que todavía NO mencionan el croquis, para no pisar una edición hecha a mano.
 */
final class Version20260913170000 extends AbstractMigration
{
    /** @var array<string, string> nombre_interno → texto para el asistente. */
    private const TEXTOS = [
        'Puerta (casa 1)' =>
            'La entrada de {{ unit_name }} está en la calle, no en el pasaje: justo a la izquierda '
            . 'del portón de rejas verde. Es una puerta a nivel de calle, fácil de reconocer al '
            . "llegar a la dirección.\n\nSu puerta va señalada en este croquis: {{ croquis }}",

        'Puerta (casa 2)' =>
            'La entrada de {{ unit_name }} está directamente sobre la calle, no en el pasaje. Es '
            . 'una puerta de madera verde, a nivel de calle y fácil de reconocer al llegar a la '
            . "dirección.\n\nSu puerta va señalada en este croquis: {{ croquis }}",

        'Puerta (casa 3)' =>
            'La entrada de {{ unit_name }} está en el pasaje: es la segunda puerta, de color verde, '
            . "justo al pie de las gradas.\n\nSu puerta va señalada en este croquis: {{ croquis }}",

        // «la caja fuerte donde se recogen las llaves» → «la caja de las llaves»: ahora hay TRES
        // cajas y cada una se nombra por lo que guarda.
        'Puerta (casa 4)' =>
            'La puerta de {{ unit_name }} está en el pasaje: es la primera puerta verde a la '
            . "izquierda, muy cerca de la caja de las llaves.\n\nSu puerta va señalada en este "
            . 'croquis: {{ croquis }}',

        'Puerta (casa 5)' =>
            'Sube las gradas del final del pasaje. Al terminarlas, de frente está la puerta de '
            . "{{ unit_name }}, de color verde.\n\nSu puerta va señalada en este croquis: "
            . '{{ croquis }}',

        // ⚠️ La frase de la puerta roja se reordena. La única roja que se VE en el croquis es la
        // de la Casita 7; la de la 6 está dentro del recibidor y no aparece dibujada. Sin el «ya
        // dentro», un huésped podría irse a la puerta de la 7.
        'Puerta (casa 6)' =>
            'Sube las gradas del final del pasaje, gira a la izquierda, luego a la derecha, y de '
            . 'nuevo a la izquierda para subir otras gradas. La puerta verde de {{ unit_name }} es '
            . "la de la derecha.\n\nSu puerta va señalada en este croquis: {{ croquis }}\n\n"
            . 'Después de esa puerta verde se entra a un recibidor: la del departamento es la roja '
            . 'de la izquierda, ya dentro. Pídele que deje ambas puertas cerradas al pasar.',

        'Puerta (casa 7)' =>
            'Sube las gradas del final del pasaje y gira a la derecha. {{ unit_name }} es la '
            . "primera puerta del lado derecho, de color rojo.\n\nSu puerta va señalada en este "
            . 'croquis: {{ croquis }}',
    ];

    public function getDescription(): string
    {
        return 'Los siete ítems de puerta le pasan su croquis al asistente.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TEXTOS as $item => $texto) {
            $this->addSql(
                "UPDATE pms_guia_item
                    SET agente_contenido = :texto
                  WHERE nombre_interno = :item
                    AND COALESCE(agente_contenido, '') NOT LIKE '%croquis%'",
                ['texto' => $texto, 'item' => $item]
            );
        }
    }

    /**
     * ⚠️ No repone los textos viejos: eran los mismos menos las tildes y la línea del croquis, y
     * volver a ellos sólo serviría para que el asistente deje de mandar el plano. Si hace falta
     * deshacerlo, se edita en el panel — que es donde vive este contenido.
     */
    public function down(Schema $schema): void
    {
        $this->warnIf(true, 'Version20260913170000 no se deshace: es contenido, se edita en el panel.');
    }
}
