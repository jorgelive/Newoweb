<?php

declare(strict_types=1);

namespace App\Tests\Agent\Provider\Anthropic;

use App\Agent\Provider\Anthropic\AnthropicClientFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Los ajustes de Claude vienen de variables de entorno: un valor que no se reconoce los APAGA. Ver `AnthropicEngine::AJUSTES_DE_CHAT`.
 */
final class AnthropicClientFactoryTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function ajustes(): iterable
    {
        yield 'vacío' => ['', false];
        yield 'uno' => ['1', true];
        yield 'con espacios y mayúsculas' => [' ON ', true];
        yield 'desconocido' => ['quizá', false];
    }

    #[Test]
    #[DataProvider('ajustes')]
    public function leeSiLosAjustesDeChatEstanEncendidos(string $valor, bool $esperado): void
    {
        $fabrica = new AnthropicClientFactory('', 'claude-sonnet-5-5', ajustes: $valor);

        self::assertSame($esperado, $fabrica->ajustesDeChat());
    }

    #[Test]
    public function unEsfuerzoDesconocidoDejaElDelModelo(): void
    {
        self::assertNull((new AnthropicClientFactory('', 'claude-sonnet-5-5', esfuerzo: 'altísimo'))->esfuerzo());
        self::assertSame('medium', (new AnthropicClientFactory('', 'claude-sonnet-5-5', esfuerzo: 'MEDIUM'))->esfuerzo());
    }
}
