<?php

declare(strict_types=1);

namespace App\Tests\Message\Dto\PlantillaMeta;

use App\Message\Dto\PlantillaMeta\BotonDePlantillaMeta;
use App\Message\Dto\PlantillaMeta\ComponenteDePlantillaMeta;
use App\Message\Dto\PlantillaMeta\PlantillaMeta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El listado de plantillas de Meta se lee UNA vez, y lo que no encaja es «no llegó».
 *
 * Que el sincronizador escribe lo mismo que antes sobre las plantillas reales lo comprueba
 * `tools/pruebas/probar-dto-plantillas.php`. Esto fija la forma y los casos raros.
 */
#[CoversClass(PlantillaMeta::class)]
#[CoversClass(ComponenteDePlantillaMeta::class)]
#[CoversClass(BotonDePlantillaMeta::class)]
final class PlantillaMetaTest extends TestCase
{
    /** Una respuesta como la de `GET /{wabaId}/message_templates`, recortada. */
    private const array RESPUESTA = [
        'data' => [
            [
                'id' => '1234567890',
                'name' => 'welcome_airbnb',
                'language' => 'pt_BR',
                'status' => 'APPROVED',
                'category' => 'UTILITY',
                'components' => [
                    ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Olá {{guest_name}}'],
                    ['type' => 'BODY', 'text' => 'Bem-vindo a {{property}}'],
                    ['type' => 'FOOTER', 'text' => 'OpenPeru'],
                    ['type' => 'BUTTONS', 'buttons' => [
                        ['type' => 'URL', 'text' => 'Ver guia', 'url' => 'https://pax.openperu.pe/guia/{{1}}', 'example' => ['x']],
                        ['type' => 'QUICK_REPLY', 'text' => 'Tours'],
                    ]],
                ],
            ],
        ],
        'paging' => ['cursors' => ['before' => 'a', 'after' => 'b']],
    ];

    #[Test]
    public function lee_una_version_de_idioma_con_sus_componentes(): void
    {
        $plantillas = PlantillaMeta::listaDesdeRespuesta(self::RESPUESTA);

        self::assertCount(1, $plantillas);
        $p = $plantillas[0];
        self::assertSame('1234567890', $p->id);
        self::assertSame('welcome_airbnb', $p->nombre);
        self::assertSame('pt_BR', $p->idioma, 'el código de Meta tal cual; la traducción a «pt» es del sincronizador');
        self::assertSame('APPROVED', $p->estado);
        self::assertSame('UTILITY', $p->categoria);

        self::assertSame('Bem-vindo a {{property}}', $p->componente('BODY')?->texto);
        self::assertSame('TEXT', $p->componente('HEADER')?->formato);
        self::assertSame('OpenPeru', $p->componente('FOOTER')?->texto);

        $botones = $p->componente('BUTTONS')?->botones ?? [];
        self::assertCount(2, $botones);
        self::assertSame('URL', $botones[0]->tipo);
        self::assertSame('https://pax.openperu.pe/guia/{{1}}', $botones[0]->url);
        self::assertSame('Tours', $botones[1]->texto);
        self::assertNull($botones[1]->url, 'un quick_reply no trae url, y eso es «no llegó», no «»');
    }

    /** El sincronizador comparaba con `strtoupper()`: `body` en minúsculas era el cuerpo. */
    #[Test]
    public function el_tipo_de_componente_no_distingue_mayusculas(): void
    {
        $p = PlantillaMeta::fromArray(['components' => [['type' => 'body', 'text' => 'hola']]]);

        self::assertSame('hola', $p->componente('BODY')?->texto);
    }

    /** Como hacían los `extract*()` del sincronizador, que salían del bucle al encontrarlo. */
    #[Test]
    public function si_hay_dos_del_mismo_tipo_manda_el_primero(): void
    {
        $p = PlantillaMeta::fromArray(['components' => [
            ['type' => 'BODY', 'text' => 'primero'],
            ['type' => 'BODY', 'text' => 'segundo'],
        ]]);

        self::assertSame('primero', $p->componente('BODY')?->texto);
    }

    #[Test]
    public function lo_que_no_encaja_se_descarta_sin_avisos(): void
    {
        $plantillas = PlantillaMeta::listaDesdeRespuesta(['data' => [
            'no soy una plantilla',
            ['name' => ['welcome'], 'language' => 5, 'components' => 'nada'],
        ]]);

        self::assertCount(1, $plantillas);
        self::assertNull($plantillas[0]->nombre, 'un array donde iba texto ya no es la palabra «Array»');
        self::assertSame('5', $plantillas[0]->idioma, 'un número pasa a texto como en una interpolación');
        self::assertSame([], $plantillas[0]->componentes);
        self::assertNull($plantillas[0]->componente('BODY'));
    }

    #[Test]
    public function una_respuesta_sin_data_es_una_lista_vacia(): void
    {
        self::assertSame([], PlantillaMeta::listaDesdeRespuesta(['error' => ['message' => 'x']]));
        self::assertSame([], PlantillaMeta::listaDesdeRespuesta([]));
    }
}
