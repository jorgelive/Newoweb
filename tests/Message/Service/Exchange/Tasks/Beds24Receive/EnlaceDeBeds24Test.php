<?php

declare(strict_types=1);

namespace App\Tests\Message\Service\Exchange\Tasks\Beds24Receive;

use App\Message\Service\Exchange\Tasks\Beds24Receive\EnlaceDeBeds24;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EnlaceDeBeds24Test extends TestCase
{
    /**
     * El caso que lo motivó: el adjunto de Booking.com en el hilo de Susanna Pasquali,
     * copiado literal de lo que devolvió la API el 19/08/2026.
     */
    #[Test]
    public function elAdjuntoRealDeBookingQuedaApuntandoABeds24(): void
    {
        $entrada = '<a href="api/booking.com/getattach.php?bookid=88591163&attachid=69241390-9bf9-11f1-9ef8-63ba270c9423" target="_blank">attachment</a>';

        $salida = EnlaceDeBeds24::absolutizar($entrada);

        self::assertStringContainsString(
            'href="https://beds24.com/api/booking.com/getattach.php?bookid=88591163&attachid=69241390-9bf9-11f1-9ef8-63ba270c9423"',
            $salida
        );

        // El resto del ancla no se toca: el texto y el target siguen donde estaban.
        self::assertStringContainsString('target="_blank"', $salida);
        self::assertStringContainsString('>attachment</a>', $salida);
    }

    /** Lo que YA es absoluto no se toca: el prefijo lo dejaría inservible. */
    #[Test]
    #[DataProvider('urlsQueNoSeTocan')]
    public function loAbsolutoSeQuedaComoEsta(string $html): void
    {
        self::assertSame($html, EnlaceDeBeds24::absolutizar($html));
    }

    /** @return iterable<string, array{string}> */
    public static function urlsQueNoSeTocan(): iterable
    {
        yield 'https' => ['<a href="https://beds24.com/api/x.php?a=1">x</a>'];
        yield 'http' => ['<a href="http://ejemplo.com/foto.png">x</a>'];
        yield 'imagen de Airbnb' => ['<img src="https://a0.muscache.com/im/pictures/abc.jpg">'];
        yield 'sin esquema pero con host' => ['<img src="//cdn.ejemplo.com/a.png">'];
        yield 'correo' => ['<a href="mailto:hola@openperu.pe">escríbenos</a>'];
        yield 'teléfono' => ['<a href="tel:+51984000000">llama</a>'];
        yield 'ancla interna' => ['<a href="#seccion">baja</a>'];
        yield 'href vacío' => ['<a href="">nada</a>'];
        yield 'texto sin html' => ['Hola, ¿a qué hora es el check-in?'];
        yield 'una url suelta en texto plano' => ['Paga aquí: https://secure.micuentaweb.pe/t/8kwer06x'];
    }

    /** Varias en el mismo mensaje, mezcladas: cada una se juzga por su cuenta. */
    #[Test]
    public function mezclaRelativasYAbsolutasSinConfundirlas(): void
    {
        $salida = EnlaceDeBeds24::absolutizar(
            '<a href="api/a.php?x=1">uno</a> y <a href="https://otro.com/b">dos</a> y <img src="/img/c.png">'
        );

        self::assertStringContainsString('href="https://beds24.com/api/a.php?x=1"', $salida);
        self::assertStringContainsString('href="https://otro.com/b"', $salida);
        self::assertStringContainsString('src="https://beds24.com/img/c.png"', $salida);
        // La barra inicial no se duplica.
        self::assertStringNotContainsString('beds24.com//', $salida);
    }

    /** Con comillas simples también, que Beds24 no promete cuáles usa. */
    #[Test]
    public function aguantaComillasSimples(): void
    {
        self::assertStringContainsString(
            "href='https://beds24.com/api/booking.com/getattach.php?bookid=1'",
            EnlaceDeBeds24::absolutizar("<a href='api/booking.com/getattach.php?bookid=1'>a</a>")
        );
    }

    /**
     * Idempotente: el backfill de los mensajes viejos puede correrse dos veces, y correrlo dos
     * veces no puede anidar el dominio dentro de sí mismo.
     */
    #[Test]
    public function aplicarloDosVecesDaLoMismo(): void
    {
        $una = EnlaceDeBeds24::absolutizar('<a href="api/booking.com/getattach.php?bookid=1">a</a>');

        self::assertSame($una, EnlaceDeBeds24::absolutizar($una));
    }

    /**
     * El caso de la captura del 19/08/2026: la burbuja pintaba `<a href="…` literal, porque el
     * panel escapa el HTML a propósito. Aplanado, queda una URL que el formateador sí enlaza.
     */
    #[Test]
    public function elAnclaSeAplanaATextoConLaUrlEntera(): void
    {
        $salida = EnlaceDeBeds24::normalizar(
            '<a href="api/booking.com/getattach.php?bookid=88591163&attachid=6924" target="_blank">adjunto</a>'
        );

        self::assertSame(
            'adjunto: https://beds24.com/api/booking.com/getattach.php?bookid=88591163&attachid=6924',
            $salida
        );
        self::assertStringNotContainsString('<a', $salida);
        self::assertStringNotContainsString('href', $salida);
    }

    /** El orden importa: aplanar antes de absolutizar dejaría la URL corta en el texto. */
    #[Test]
    public function normalizarPoneElHostAntesDeAplanar(): void
    {
        self::assertStringContainsString(
            'https://beds24.com/api/x.php',
            EnlaceDeBeds24::normalizar('<a href="api/x.php">a</a>')
        );
    }

    /** Un texto de ancla que no aporta se cae: `https://…: https://…` es ruido. */
    #[Test]
    #[DataProvider('anclasSinTextoUtil')]
    public function siElTextoNoAportaSeQuedaSoloLaUrl(string $html): void
    {
        self::assertSame('https://beds24.com/x', EnlaceDeBeds24::aplanarAnclas($html));
    }

    /** @return iterable<string, array{string}> */
    public static function anclasSinTextoUtil(): iterable
    {
        yield 'texto vacío' => ['<a href="https://beds24.com/x"></a>'];
        yield 'sólo espacios' => ['<a href="https://beds24.com/x">   </a>'];
        yield 'el texto ES la url' => ['<a href="https://beds24.com/x">https://beds24.com/x</a>'];
        yield 'texto envuelto en etiquetas' => ['<a href="https://beds24.com/x"><span> </span></a>'];
    }

    /** Las entidades HTML se deshacen: un `&amp;` en la query rompería la descarga. */
    #[Test]
    public function deshaceLasEntidadesDeLaUrl(): void
    {
        self::assertSame(
            'x: https://beds24.com/a.php?b=1&c=2',
            EnlaceDeBeds24::aplanarAnclas('<a href="https://beds24.com/a.php?b=1&amp;c=2">x</a>')
        );
    }

    /** Varias anclas en el mismo mensaje se aplanan todas. */
    #[Test]
    public function aplanaTodasLasQueHaya(): void
    {
        self::assertSame(
            'uno: https://a.com/1 y dos: https://b.com/2',
            EnlaceDeBeds24::aplanarAnclas('<a href="https://a.com/1">uno</a> y <a href="https://b.com/2">dos</a>')
        );
    }

    /** Lo que no lleva anclas no se toca, ni siquiera el HTML de otra clase. */
    #[Test]
    public function sinAnclasNoHaceNada(): void
    {
        $html = '<img src="https://a0.muscache.com/im/x.jpg"> Hola';

        self::assertSame($html, EnlaceDeBeds24::aplanarAnclas($html));
    }
}
