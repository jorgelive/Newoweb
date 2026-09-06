<?php

declare(strict_types=1);

/**
 * Comprueba SIN API el formateador de mensajes: normalización del Markdown del modelo,
 * degradación por canal y protección de URLs.
 *
 * Uso: php var/probar-formato.php
 */

use App\Message\Service\Formato\FormatoDeTexto;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$f = new FormatoDeTexto();

$fallos = 0;
$ok = static function (string $caso, string $obtenido, string $esperado) use (&$fallos): void {
    $bien = $obtenido === $esperado;
    if (!$bien) {
        $fallos++;
    }
    printf("%s  %s%s\n", $bien ? '  ok ' : 'FALLA', $caso, $bien ? '' : "\n       esperado: " . var_export($esperado, true) . "\n       obtenido: " . var_export($obtenido, true));
};

// ── Normalización (Markdown del modelo → canónico) ──────────────────────────────────────
$ok('doble asterisco → canónico', $f->normalizar('hola **mundo** feliz'), 'hola *mundo* feliz');
$ok('doble tilde → canónico', $f->normalizar('ya ~~no~~ sí'), 'ya ~no~ sí');
$ok('título → negrita', $f->normalizar("## Horarios\ntexto"), "*Horarios*\ntexto");
$ok('viñeta → punto medio', $f->normalizar("- uno\n- dos"), "• uno\n• dos");
$ok('viñeta con asterisco', $f->normalizar('* uno'), '• uno');
$ok('enlace markdown → texto: url', $f->normalizar('mira [tu guía](https://pax.x.com/g/1)'), 'mira tu guía: https://pax.x.com/g/1');
$ok('canónico queda intacto (idempotente)', $f->normalizar('*n* _c_ ~t~ __s__'), '*n* _c_ ~t~ __s__');
$ok('rango 2-3 no es viñeta', $f->normalizar('quedan 2-3 días'), 'quedan 2-3 días');

// ── WhatsApp: canónico menos el subrayado ───────────────────────────────────────────────
$ok('whatsapp conserva sus marcas', $f->paraWhatsapp('*n* _c_ ~t~'), '*n* _c_ ~t~');
$ok('whatsapp pierde el subrayado', $f->paraWhatsapp('esto __importa__ mucho'), 'esto importa mucho');
$ok('whatsapp normaliza el markdown', $f->paraWhatsapp('**fuerte** y ~~fuera~~'), '*fuerte* y ~fuera~');

// ── Texto puro (Beds24): sin ninguna marca ──────────────────────────────────────────────
$ok('plano pierde todas las marcas', $f->paraTextoPlano('*n* _c_ ~t~ __s__'), 'n c t s');
$ok('plano normaliza antes de quitar', $f->paraTextoPlano('**fuerte** y - lista'), "fuerte y - lista");
$ok('plano: viñeta de línea sí se convierte', $f->paraTextoPlano("- uno\n- dos"), "• uno\n• dos");
$ok('asterisco suelto se respeta', $f->paraTextoPlano('2 * 3 = 6'), '2 * 3 = 6');
$ok('guion bajo suelto se respeta', $f->paraTextoPlano('archivo_final'), 'archivo_final');

// ── URLs: intocables en todos los canales ───────────────────────────────────────────────
$url = 'https://pax.openperu.pe/mi_guia_es/ver*2';
$ok('la URL sobrevive al texto plano', $f->paraTextoPlano("entra en {$url} hoy"), "entra en {$url} hoy");
$ok('la URL sobrevive a whatsapp', $f->paraWhatsapp("__mira__ {$url}"), "mira {$url}");
$ok('marcas alrededor de la URL sí se procesan', $f->paraTextoPlano("*{$url}*"), $url);

printf("\n%s\n", $fallos === 0 ? '✅ Todo bien.' : "❌ {$fallos} comprobación(es) fallidas.");
exit($fallos === 0 ? 0 : 1);
