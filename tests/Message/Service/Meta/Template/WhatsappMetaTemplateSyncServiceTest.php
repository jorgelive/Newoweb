<?php

declare(strict_types=1);

namespace App\Tests\Message\Service\Meta\Template;

use App\Exchange\Service\Client\WhatsappMetaClient;
use App\Message\Dto\PlantillaMeta\PlantillaMeta;
use App\Message\Entity\MessageTemplate;
use App\Message\Service\Meta\Template\WhatsappMetaTemplateSyncService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;

/**
 * Lo que el sincronizador escribe en la plantilla local a partir de UNA versión de idioma de Meta.
 *
 * Se llama a `processTemplateRecord()` directamente, con la plantilla ya en su caché: así no hace
 * falta base de datos ni red. Lo que se fija son las reglas de §18 de `docs/Mensajeria.md` que
 * más caro salen si se rompen — la `resolver_key` se preserva, el `type` y el `content` se pisan.
 */
#[CoversClass(WhatsappMetaTemplateSyncService::class)]
final class WhatsappMetaTemplateSyncServiceTest extends TestCase
{
    private static function servicio(): WhatsappMetaTemplateSyncService
    {
        // El cliente es `final` y aquí no se llama: basta con una instancia sin construir.
        $cliente = (new ReflectionClass(WhatsappMetaClient::class))->newInstanceWithoutConstructor();

        return new WhatsappMetaTemplateSyncService(self::createStub(EntityManagerInterface::class), $cliente, new NullLogger());
    }

    /**
     * @param array<mixed> $registroDeMeta
     */
    private static function sincronizar(MessageTemplate $plantilla, array $registroDeMeta): ?bool
    {
        $cache = ['welcome_airbnb' => $plantilla];
        $metodo = new ReflectionMethod(WhatsappMetaTemplateSyncService::class, 'processTemplateRecord');

        $resultado = $metodo->invokeArgs(self::servicio(), [PlantillaMeta::fromArray($registroDeMeta), &$cache, ['es', 'en', 'pt']]);

        return is_bool($resultado) ? $resultado : null;
    }

    private static function plantillaLocal(): MessageTemplate
    {
        $plantilla = new MessageTemplate();
        $plantilla->setCode('welcome_airbnb');
        $plantilla->setWhatsappMetaTmpl([
            'meta_template_name' => 'welcome_airbnb',
            'is_active' => true,
            'body' => [['language' => 'es', 'status' => 'APPROVED', 'content' => 'Hola viejo']],
            'buttons_map' => [[
                'index' => 0,
                'type' => 'quick_reply',
                'resolver_key' => 'guide_path',
                'content' => '',
                'button_text' => [['language' => 'es', 'content' => 'Guía']],
            ]],
        ]);

        return $plantilla;
    }

    #[Test]
    public function actualiza_el_idioma_y_preserva_la_resolver_key(): void
    {
        $plantilla = self::plantillaLocal();

        $resultado = self::sincronizar($plantilla, [
            'name' => 'welcome_airbnb',
            'language' => 'es',
            'status' => 'APPROVED',
            'category' => 'UTILITY',
            'components' => [
                ['type' => 'BODY', 'text' => 'Hola nuevo'],
                ['type' => 'BUTTONS', 'buttons' => [
                    ['type' => 'URL', 'text' => 'Ver guía', 'url' => 'https://pax/guia/{{1}}'],
                    ['type' => 'QUICK_REPLY', 'text' => 'Tours'],
                ]],
            ],
        ]);

        self::assertFalse($resultado, 'una plantilla que ya existía se ACTUALIZA');
        $tmpl = $plantilla->getWhatsappMetaTmpl() ?? [];

        self::assertSame([['language' => 'es', 'status' => 'APPROVED', 'content' => 'Hola nuevo']], $tmpl['body'] ?? null);
        self::assertTrue($tmpl['is_official_meta'] ?? false);
        self::assertSame('UTILITY', $tmpl['category'] ?? null);

        $botones = $tmpl['buttons_map'] ?? [];
        self::assertCount(2, $botones);
        // El botón 0 ya existía: pisa type y content, NO la resolver_key (§18, «Repuntar»).
        self::assertSame('url', $botones[0]['type'] ?? null);
        self::assertSame('https://pax/guia/{{1}}', $botones[0]['content'] ?? null);
        self::assertSame('guide_path', $botones[0]['resolver_key'] ?? null);
        self::assertSame([['language' => 'es', 'content' => 'Ver guía']], $botones[0]['button_text'] ?? null);
        // El 1 es nuevo: nace sin resolver_key y con content vacío (un quick_reply no trae url).
        self::assertSame(1, $botones[1]['index'] ?? null);
        self::assertSame('quick_reply', $botones[1]['type'] ?? null);
        self::assertSame('', $botones[1]['content'] ?? null);
        self::assertArrayHasKey('resolver_key', $botones[1]);
        self::assertNull($botones[1]['resolver_key']);
    }

    #[Test]
    public function un_idioma_nuevo_se_anade_y_el_codigo_regional_se_recorta(): void
    {
        $plantilla = self::plantillaLocal();

        self::sincronizar($plantilla, [
            'name' => 'welcome_airbnb',
            'language' => 'pt_BR',
            'status' => 'PENDING',
            'components' => [
                ['type' => 'HEADER', 'format' => 'text', 'text' => 'Olá'],
                ['type' => 'BODY', 'text' => 'Bem-vindo'],
                ['type' => 'FOOTER', 'text' => 'OpenPeru'],
            ],
        ]);

        $tmpl = $plantilla->getWhatsappMetaTmpl() ?? [];
        self::assertSame(['language' => 'pt', 'status' => 'PENDING', 'content' => 'Bem-vindo'], $tmpl['body'][1] ?? null);
        self::assertSame([['language' => 'pt', 'format' => 'TEXT', 'content' => 'Olá']], $tmpl['header'] ?? null);
        self::assertSame([['language' => 'pt', 'content' => 'OpenPeru']], $tmpl['footer'] ?? null);
    }

    #[Test]
    public function un_idioma_que_no_trabajamos_no_toca_nada(): void
    {
        $plantilla = self::plantillaLocal();
        $antes = $plantilla->getWhatsappMetaTmpl();

        self::assertNull(self::sincronizar($plantilla, ['name' => 'welcome_airbnb', 'language' => 'ja', 'status' => 'APPROVED']));
        self::assertSame($antes, $plantilla->getWhatsappMetaTmpl());
    }
}
