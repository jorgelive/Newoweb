<?php

declare(strict_types=1);

/**
 * Las plantillas de Meta leídas por DTO escriben y mandan LO MISMO que el código de antes.
 *
 * Meta no guarda el listado que devuelve y aquí no se le llama, así que la fuente de verdad son
 * las plantillas GUARDADAS (`msg_template.whatsapp_meta_tmpl`): su texto es el que el
 * sincronizador copió de Meta. Sobre ellas se compara, con el código viejo copiado aquí debajo y
 * el nuevo cargado de verdad:
 *
 * 1. **El payload de subida** (`WhatsappMetaTemplatePushService::buildSingleLanguagePayload()`),
 *    por plantilla e idioma — lo que se manda a revisión — y la medición de topes.
 * 2. **La sincronización** (`processTemplateRecord()`): de cada plantilla se reconstruye la
 *    versión de idioma con la forma del listado de Meta y se aplica, con lo viejo y con lo nuevo,
 *    sobre (a) el JSON guardado y (b) uno vacío que sólo tiene el nombre. El JSON resultante
 *    tiene que ser idéntico, byte a byte.
 * 3. **Las lecturas del listado** del inventario y de la búsqueda de ids.
 * 4. **Los getters de las columnas JSON** de `MessageConversation` (`context_data`) y `Message`
 *    (`metadata`) sobre todas las filas: el valor nuevo contra el crudo de antes.
 *
 * Sólo LEE: no arranca el kernel, abre la base en una transacción de sólo lectura y no escribe en
 * ningún sitio. No imprime datos personales —sólo códigos de plantilla, campos y cuentas—.
 *
 * Uso, en el repo:
 *   php tools/pruebas/probar-dto-plantillas.php
 *
 * En el servidor ANTES de desplegar (ver `docs/TiposDeFrontera.md` §3): se copian a /tmp/x este
 * script y los `src/` nuevos, con su ruta, y se apunta a la app instalada:
 *   APP_RAIZ=/var/www/openperu.pe php /tmp/x/tools/pruebas/probar-dto-plantillas.php
 * Las clases que existan en /tmp/x/src se cargan de ahí; el resto, de la app.
 *
 * Ver `docs/Mensajeria.md` §18.
 */

use App\Message\Dto\PlantillaMeta\PlantillaMeta;
use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageTemplate;
use App\Message\Service\Meta\Template\WhatsappMetaTemplatePushService;
use App\Message\Service\Meta\Template\WhatsappMetaTemplateSyncService;
use Symfony\Component\Dotenv\Dotenv;

$propia = dirname(__DIR__, 2);
$raiz = getenv('APP_RAIZ') ?: $propia;

require $raiz . '/vendor/autoload.php';

if (realpath($raiz) !== realpath($propia)) {
    // Lo copiado junto al script gana a lo instalado: es el código que se quiere comprobar.
    spl_autoload_register(static function (string $clase) use ($propia): void {
        if (str_starts_with($clase, 'App\\')) {
            $archivo = $propia . '/src/' . str_replace('\\', '/', substr($clase, 4)) . '.php';
            if (is_file($archivo)) {
                require $archivo;
            }
        }
    }, true, true);
}

(new Dotenv())->bootEnv($raiz . '/.env');

$url = parse_url((string) ($_SERVER['DATABASE_URL'] ?? ''));
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $url['host'] ?? '127.0.0.1', $url['port'] ?? 3306, ltrim($url['path'] ?? '', '/')),
    urldecode($url['user'] ?? ''),
    urldecode($url['pass'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$pdo->exec('START TRANSACTION READ ONLY');

$diferencias = [];
$anotar = static function (string $que, bool $igual, string $detalle = '') use (&$diferencias): void {
    if (!$igual) {
        $diferencias[$que][] = $detalle;
    }
};
$cuenta = [];
$contar = static function (string $que) use (&$cuenta): void {
    $cuenta[$que] = ($cuenta[$que] ?? 0) + 1;
};

// ═════════════════════════════════════════════════════════════════════════════════════════════
// EL CÓDIGO VIEJO, copiado tal cual de `bc4e2875` (sólo cambia `$this->x()` por la función).
// ═════════════════════════════════════════════════════════════════════════════════════════════

$viejoTextoPorIdioma = static function (array $componentList, string $targetLang): string {
    foreach ($componentList as $item) {
        if (($item['language'] ?? '') === $targetLang) {
            return (string)($item['content'] ?? '');
        }
    }
    return '';
};

$viejoEjemplos = static function (string $text, array $previewVars): array {
    preg_match_all('/\{\{([a-zA-Z0-9_]+)\}\}/', $text, $matches);
    $varsInText = $matches[1];
    if (empty($varsInText)) {
        return [];
    }
    $namedExamples = [];
    foreach ($varsInText as $varName) {
        $namedExamples[] = ['param_name' => $varName, 'example' => (string)($previewVars[$varName] ?? 'Dato_Ejemplo')];
    }
    return $namedExamples;
};

$viejoPayload = static function (array $metaTmpl, string $localLang, string $metaLangCode, array $previewData) use ($viejoTextoPorIdioma, $viejoEjemplos): array {
    $components = [];
    $headerText = $viejoTextoPorIdioma($metaTmpl['header'] ?? [], $localLang);
    if ($headerText !== '') {
        $headerComp = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $headerText];
        $examples = $viejoEjemplos($headerText, $previewData);
        if (!empty($examples)) {
            $headerComp['example'] = ['header_text_named_params' => $examples];
        }
        $components[] = $headerComp;
    }
    $bodyText = $viejoTextoPorIdioma($metaTmpl['body'] ?? [], $localLang);
    if ($bodyText !== '') {
        $bodyComp = ['type' => 'BODY', 'text' => $bodyText];
        $examples = $viejoEjemplos($bodyText, $previewData);
        if (!empty($examples)) {
            $bodyComp['example'] = ['body_text_named_params' => $examples];
        }
        $components[] = $bodyComp;
    }
    $footerText = $viejoTextoPorIdioma($metaTmpl['footer'] ?? [], $localLang);
    if ($footerText !== '') {
        $components[] = ['type' => 'FOOTER', 'text' => $footerText];
    }
    if (!empty($metaTmpl['buttons_map'])) {
        $buttons = [];
        foreach ($metaTmpl['buttons_map'] as $btnMap) {
            $btnText = $viejoTextoPorIdioma($btnMap['button_text'] ?? [], $localLang);
            if ($btnText === '') continue;
            if (empty($btnMap['resolver_key'])) {
                throw new RuntimeException(sprintf(
                    'Error de validación: El botón "%s" (tipo: %s) en el idioma [%s] NO tiene definida una "resolver_key".',
                    $btnText,
                    $btnMap['type'] ?? 'unknown',
                    $localLang
                ));
            }
            if (@$btnMap['type'] === 'url') {
                $url = (string)($btnMap['content'] ?? '');
                $btnComp = ['type' => 'URL', 'text' => $btnText, 'url' => $url];
                if (str_contains($url, '{{1}}')) {
                    $btnComp['example'] = [str_replace('{{1}}', 'H6Q49C', $url)];
                }
                $buttons[] = $btnComp;
            } elseif (@$btnMap['type'] === 'quick_reply') {
                $buttons[] = ['type' => 'QUICK_REPLY', 'text' => $btnText];
            }
        }
        if (!empty($buttons)) {
            $components[] = ['type' => 'BUTTONS', 'buttons' => $buttons];
        }
    }
    return [
        'name'             => $metaTmpl['meta_template_name'],
        'language'         => $metaLangCode,
        'category'         => $metaTmpl['category'] ?? 'MARKETING',
        'components'       => $components,
        'parameter_format' => 'NAMED',
    ];
};

$viejoExcesos = static function (array $metaTmpl, string $idioma): ?string {
    $contenidoDe = static function (array $bloques, string $idioma): string {
        foreach ($bloques as $bloque) {
            if (($bloque['language'] ?? '') === $idioma) {
                return (string) ($bloque['content'] ?? '');
            }
        }
        return '';
    };
    $componentes = [
        'Body' => [1024, $contenidoDe($metaTmpl['body'] ?? [], $idioma)],
        'Header' => [60, $contenidoDe($metaTmpl['header'] ?? [], $idioma)],
        'Footer' => [60, $contenidoDe($metaTmpl['footer'] ?? [], $idioma)],
    ];
    $problemas = [];
    foreach ($componentes as $nombre => [$tope, $texto]) {
        $largo = mb_strlen($texto);
        if ($largo > $tope) {
            $problemas[] = sprintf('%s mide %d caracteres y el tope de Meta son %d: sobran %d.', $nombre, $largo, $tope, $largo - $tope);
        }
    }
    return $problemas === [] ? null : implode(' ', $problemas);
};

$viejoBuscarId = static function (array $metaTemplates, string $name, string $langCode): ?string {
    foreach ($metaTemplates as $tpl) {
        if (($tpl['name'] ?? '') === $name && ($tpl['language'] ?? '') === $langCode) {
            return (string)($tpl['id'] ?? '');
        }
    }
    return null;
};

/** `processTemplateRecord()` y sus `extract*()`, con la plantilla ya encontrada. */
$viejoSync = static function (array $data, array $metaTmpl, array $allowedLanguages): ?array {
    $extraer = static function (array $components, string $tipo): ?array {
        foreach ($components as $component) {
            if (strtoupper((string)($component['type'] ?? '')) === $tipo) {
                return $component;
            }
        }
        return null;
    };
    $metaName = (string)($data['name'] ?? '');
    $rawLanguage = (string)($data['language'] ?? '');
    $status = (string)($data['status'] ?? 'UNKNOWN');
    if ($metaName === '' || $rawLanguage === '') {
        return null;
    }
    $languageParts = explode('_', $rawLanguage);
    $language = strtolower($languageParts[0]);
    if (!in_array($language, $allowedLanguages, true)) {
        return null;
    }

    $metaTmpl['is_active'] = true;
    $metaTmpl['is_official_meta'] = true;
    $metaTmpl['meta_template_name'] = $metaName;
    $metaTmpl['category'] = $data['category'] ?? ($metaTmpl['category'] ?? 'UTILITY');

    $body = $extraer($data['components'] ?? [], 'BODY');
    $bodyText = $body !== null ? (string)($body['text'] ?? '') : '';
    $bodyArray = $metaTmpl['body'] ?? [];
    $foundLangBody = false;
    foreach ($bodyArray as &$b) {
        if (($b['language'] ?? '') === $language) {
            $b['status'] = $status;
            $b['content'] = $bodyText;
            $foundLangBody = true;
            break;
        }
    }
    unset($b);
    if (!$foundLangBody) {
        $bodyArray[] = ['language' => $language, 'status' => $status, 'content' => $bodyText];
    }
    $metaTmpl['body'] = $bodyArray;

    $botones = $extraer($data['components'] ?? [], 'BUTTONS');
    $metaButtons = $botones !== null ? ($botones['buttons'] ?? []) : [];
    $buttonsMap = $metaTmpl['buttons_map'] ?? [];
    foreach ($metaButtons as $index => $btn) {
        $foundBtn = false;
        foreach ($buttonsMap as &$bMap) {
            if (($bMap['index'] ?? -1) === $index) {
                $btnTextArray = $bMap['button_text'] ?? [];
                $foundText = false;
                foreach ($btnTextArray as &$txt) {
                    if (($txt['language'] ?? '') === $language) {
                        $txt['content'] = $btn['text'] ?? '';
                        $foundText = true;
                        break;
                    }
                }
                unset($txt);
                if (!$foundText) {
                    $btnTextArray[] = ['language' => $language, 'content' => $btn['text'] ?? ''];
                }
                $bMap['button_text'] = $btnTextArray;
                if (isset($btn['url'])) {
                    $bMap['content'] = $btn['url'];
                }
                $bMap['type'] = strtolower((string)($btn['type'] ?? 'url'));
                $foundBtn = true;
                break;
            }
        }
        unset($bMap);
        if (!$foundBtn) {
            $buttonsMap[] = [
                'index'        => $index,
                'type'         => strtolower((string)($btn['type'] ?? 'url')),
                'content'      => $btn['url'] ?? '',
                'resolver_key' => null,
                'button_text'  => [['language' => $language, 'content' => $btn['text'] ?? '']],
            ];
        }
    }
    $metaTmpl['buttons_map'] = $buttonsMap;

    $pie = $extraer($data['components'] ?? [], 'FOOTER');
    $footerText = $pie !== null ? (string)($pie['text'] ?? '') : '';
    $footerArray = $metaTmpl['footer'] ?? [];
    $foundLangFooter = false;
    foreach ($footerArray as &$f) {
        if (($f['language'] ?? '') === $language) {
            $f['content'] = $footerText;
            $foundLangFooter = true;
            break;
        }
    }
    unset($f);
    if (!$foundLangFooter && $footerText !== '') {
        $footerArray[] = ['language' => $language, 'content' => $footerText];
    }
    $metaTmpl['footer'] = $footerArray;

    $cabecera = $extraer($data['components'] ?? [], 'HEADER');
    $headerData = $cabecera !== null
        ? ['format' => strtoupper((string)($cabecera['format'] ?? 'TEXT')), 'content' => (string)($cabecera['text'] ?? '')]
        : [];
    if (!empty($headerData)) {
        $headerArray = $metaTmpl['header'] ?? [];
        $foundLangHeader = false;
        foreach ($headerArray as &$h) {
            if (($h['language'] ?? '') === $language) {
                $h['format'] = $headerData['format'];
                $h['content'] = $headerData['content'];
                $foundLangHeader = true;
                break;
            }
        }
        unset($h);
        if (!$foundLangHeader) {
            $headerArray[] = ['language' => $language, 'format' => $headerData['format'], 'content' => $headerData['content']];
        }
        $metaTmpl['header'] = $headerArray;
    }

    return $metaTmpl;
};

// ═════════════════════════════════════════════════════════════════════════════════════════════
// EL CÓDIGO NUEVO, llamado de verdad (los métodos son privados: se entra por reflexión).
// ═════════════════════════════════════════════════════════════════════════════════════════════

$push = (new ReflectionClass(WhatsappMetaTemplatePushService::class))->newInstanceWithoutConstructor();
$nuevoPayload = new ReflectionMethod(WhatsappMetaTemplatePushService::class, 'buildSingleLanguagePayload');
$nuevoExcesos = new ReflectionMethod(WhatsappMetaTemplatePushService::class, 'medirExcesos');
$nuevoBuscarId = new ReflectionMethod(WhatsappMetaTemplatePushService::class, 'findExistingTemplateId');

// Con la plantilla ya en la caché no toca ni el EntityManager ni el logger: no hace falta construirlo.
$sync = (new ReflectionClass(WhatsappMetaTemplateSyncService::class))->newInstanceWithoutConstructor();
$nuevoSync = new ReflectionMethod(WhatsappMetaTemplateSyncService::class, 'processTemplateRecord');

$aplicarNuevo = static function (array $registro, ?array $tmpl, array $idiomas) use ($sync, $nuevoSync): ?array {
    $plantilla = new MessageTemplate();
    $plantilla->setWhatsappMetaTmpl($tmpl);
    $cache = [(string) ($registro['name'] ?? '') => $plantilla];
    $resultado = $nuevoSync->invokeArgs($sync, [PlantillaMeta::fromArray($registro), &$cache, $idiomas]);

    return $resultado === null ? null : $plantilla->getWhatsappMetaTmpl();
};

// ═════════════════════════════════════════════════════════════════════════════════════════════

$mapaIdiomaMeta = ['pt' => 'pt_BR', 'es' => 'es', 'en' => 'en', 'it' => 'it', 'fr' => 'fr', 'de' => 'de', 'nl' => 'nl'];
$previa = ['guest_name' => 'John', 'locator' => 'PREVIEW-123456', 'nights' => 4, 'total_amount' => '150.00'];

$idiomas = [];
foreach ($pdo->query('SELECT LOWER(id) AS id FROM maestro_idioma WHERE prioridad > 0') as $fila) {
    $idiomas[] = (string) $fila['id'];
}

/** La versión de idioma como la devuelve el listado de Meta, reconstruida de lo guardado. */
$comoLaDevuelveMeta = static function (array $tmpl, string $idioma, string $idiomaMeta): array {
    $de = static function (array $lista) use ($idioma): ?array {
        foreach ($lista as $x) {
            if (is_array($x) && ($x['language'] ?? null) === $idioma) {
                return $x;
            }
        }
        return null;
    };
    $componentes = [];
    if (($h = $de($tmpl['header'] ?? [])) !== null) {
        $componentes[] = ['type' => 'HEADER', 'format' => $h['format'] ?? 'TEXT', 'text' => $h['content'] ?? ''];
    }
    $cuerpo = $de($tmpl['body'] ?? []);
    $componentes[] = ['type' => 'BODY', 'text' => $cuerpo['content'] ?? '', 'example' => ['body_text_named_params' => []]];
    if (($p = $de($tmpl['footer'] ?? [])) !== null && ($p['content'] ?? '') !== '') {
        $componentes[] = ['type' => 'FOOTER', 'text' => $p['content']];
    }
    $botones = [];
    foreach (($tmpl['buttons_map'] ?? []) as $b) {
        $texto = $de($b['button_text'] ?? []);
        if ($texto === null) {
            continue;
        }
        $boton = ['type' => strtoupper((string) ($b['type'] ?? 'url')), 'text' => $texto['content'] ?? ''];
        if (($b['type'] ?? 'url') === 'url') {
            $boton['url'] = $b['content'] ?? '';
        }
        $botones[] = $boton;
    }
    if ($botones !== []) {
        $componentes[] = ['type' => 'BUTTONS', 'buttons' => $botones];
    }

    return [
        'name' => $tmpl['meta_template_name'] ?? '',
        'parameter_format' => 'NAMED',
        'components' => $componentes,
        'language' => $idiomaMeta,
        'status' => $cuerpo['status'] ?? 'APPROVED',
        'category' => $tmpl['category'] ?? 'UTILITY',
        'id' => sprintf('%d', crc32(($tmpl['meta_template_name'] ?? '') . $idiomaMeta)),
    ];
};

$listado = [];

foreach ($pdo->query('SELECT code, whatsapp_meta_tmpl FROM msg_template ORDER BY code') as $fila) {
    $code = (string) $fila['code'];
    $tmpl = json_decode((string) ($fila['whatsapp_meta_tmpl'] ?? 'null'), true);
    $contar('plantillas');
    if (!is_array($tmpl) || !is_string($tmpl['meta_template_name'] ?? null) || $tmpl['meta_template_name'] === '') {
        $contar('plantillas sin nombre en Meta (no se suben ni se sincronizan)');
        continue;
    }
    $nombre = $tmpl['meta_template_name'];
    $locales = array_values(array_unique(array_map(static fn ($b) => $b['language'] ?? '', $tmpl['body'] ?? [])));

    $vacioViejo = ['meta_template_name' => $nombre];
    $vacioNuevo = ['meta_template_name' => $nombre];

    foreach ($locales as $idioma) {
        $idiomaMeta = $mapaIdiomaMeta[strtolower($idioma)] ?? strtolower($idioma);

        // ── 1. Lo que se SUBE a Meta ──
        $contar('payloads de subida comparados');
        try {
            $viejo = ['ok' => $viejoPayload($tmpl, $idioma, $idiomaMeta, $previa)];
        } catch (RuntimeException $e) {
            $viejo = ['error' => $e->getMessage()];
        }
        try {
            $nuevo = ['ok' => $nuevoPayload->invoke($push, $tmpl, $nombre, $idioma, $idiomaMeta, $previa)];
        } catch (RuntimeException $e) {
            $nuevo = ['error' => $e->getMessage()];
        }
        if (isset($viejo['error'])) {
            $contar('payloads que se niegan (falta resolver_key), igual en los dos');
        }
        $anotar('payload de subida', json_encode($viejo) === json_encode($nuevo), "$code/$idioma");
        $anotar('topes de Meta', $viejoExcesos($tmpl, $idioma) === $nuevoExcesos->invoke($push, $tmpl, $idioma), "$code/$idioma");

        // ── 2. Lo que se GUARDA al sincronizar ──
        $registro = $comoLaDevuelveMeta($tmpl, $idioma, $idiomaMeta);
        $listado[] = $registro;
        $contar('versiones de idioma sincronizadas');

        $anotar('filtro de estado del sync',
            strtoupper((string) ($registro['status'] ?? '')) === strtoupper(PlantillaMeta::fromArray($registro)->estado ?? ''), "$code/$idioma");
        $anotar('sync sobre lo guardado',
            json_encode($viejoSync($registro, $tmpl, $idiomas)) === json_encode($aplicarNuevo($registro, $tmpl, $idiomas)), "$code/$idioma");

        // Encadenado, como en la noche de las 03:15: cada idioma sobre el resultado del anterior.
        $vacioViejo = $viejoSync($registro, $vacioViejo, $idiomas) ?? $vacioViejo;
        $vacioNuevo = $aplicarNuevo($registro, $vacioNuevo, $idiomas) ?? $vacioNuevo;
    }

    $anotar('sync desde vacío, todos los idiomas', json_encode($vacioViejo) === json_encode($vacioNuevo), $code);
}

// ── 3. Las lecturas del listado: inventario y búsqueda de ids ──
$dtos = PlantillaMeta::listaDesdeRespuesta(['data' => $listado]);
$anotar('total del inventario', count($listado) === count($dtos));
foreach ($listado as $i => $crudo) {
    $dto = $dtos[$i];
    $anotar('nombre en el inventario', (string) ($crudo['name'] ?? '') === ($dto->nombre ?? ''));
    $anotar('idioma en el inventario', (string) ($crudo['language'] ?? '?') === ($dto->idioma ?? '?'));
    $anotar('estado en el inventario', strtoupper((string) ($crudo['status'] ?? '?')) === strtoupper($dto->estado ?? '?'));
    $contar('búsquedas de id');
    $anotar('id encontrado', $viejoBuscarId($listado, (string) $crudo['name'], (string) $crudo['language'])
        === $nuevoBuscarId->invoke($push, $dtos, (string) $crudo['name'], (string) $crudo['language']));
}
$anotar('id que no existe', $viejoBuscarId($listado, 'no_existe', 'es') === $nuevoBuscarId->invoke($push, $dtos, 'no_existe', 'es'));

// ── 4. Los getters de las columnas JSON ──
foreach ($pdo->query('SELECT context_data FROM msg_conversation') as $fila) {
    $crudo = json_decode((string) ($fila['context_data'] ?? 'null'), true);
    $c = new MessageConversation('x', 'y');
    $c->setContextData(is_array($crudo) ? $crudo : null);
    $crudo = is_array($crudo) ? $crudo : [];
    $contar('conversaciones');
    $anotar('context origin', ($crudo['origin'] ?? null) === $c->getContextOrigin());
    $anotar('context agency', ($crudo['agency'] ?? null) === $c->getContextAgency());
    $anotar('context status_tag', ($crudo['status_tag'] ?? null) === $c->getContextStatusTag());
    $anotar('context milestones', ($crudo['milestones'] ?? []) === $c->getContextMilestones());
    $anotar('context items', ($crudo['items'] ?? []) === $c->getContextItems());
    $anotar('context total', (isset($crudo['financials']['total']) ? (float) $crudo['financials']['total'] : null) === $c->getContextFinancialTotal());
    $anotar('context is_cleared', (bool) ($crudo['financials']['is_cleared'] ?? false) === $c->getContextFinancialIsCleared());
}

foreach ($pdo->query('SELECT metadata FROM msg_message') as $fila) {
    $crudo = json_decode((string) ($fila['metadata'] ?? '[]'), true);
    $crudo = is_array($crudo) ? $crudo : [];
    $m = new Message();
    $m->setMetadata($crudo);
    $contar('mensajes');
    foreach (['beds24' => ['sent_at', 'received_at', 'read_at'], 'whatsappMeta' => ['sent_at', 'delivered_at', 'read_at', 'error_code', 'error_reason']] as $bloque => $claves) {
        foreach ($claves as $clave) {
            $getter = 'get' . ucfirst($bloque) . str_replace('_', '', ucwords($clave, '_'));
            $anotar("metadata $bloque.$clave", ($crudo[$bloque][$clave] ?? null) === $m->$getter());
        }
    }
    $anotar('metadata beds24', ($crudo['beds24'] ?? []) === $m->getBeds24Metadata());
    $anotar('metadata whatsappMeta', ($crudo['whatsappMeta'] ?? []) === $m->getWhatsappMetaMetadata());
    $anotar('metadata inbound_intent', ($crudo['inbound_intent'] ?? null) === $m->getInboundIntent());
    $vars = $crudo['variables_plantilla'] ?? [];
    $anotar('metadata variables_plantilla', (is_array($vars) ? $vars : []) === $m->getVariablesPlantilla());
}

$pdo->exec('ROLLBACK');

// ═════════════════════════════════════════════════════════════════════════════════════════════

foreach ($cuenta as $que => $n) {
    printf("  %-70s %6d\n", $que, $n);
}
echo "\n";

if ($diferencias === []) {
    echo "✅ Idénticos: lo que se sube, lo que se guarda al sincronizar, las lecturas del listado y los getters.\n";
    exit(0);
}

foreach ($diferencias as $que => $casos) {
    printf("❌ %s: %d diferencia(s)%s\n", $que, count($casos), $casos[0] !== '' ? ' — p. ej. ' . implode(', ', array_slice(array_unique($casos), 0, 5)) : '');
}
exit(1);
