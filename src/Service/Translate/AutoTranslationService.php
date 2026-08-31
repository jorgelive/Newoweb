<?php

declare(strict_types=1);

namespace App\Service\Translate;

use App\Attribute\AutoTranslate;
use App\Entity\Maestro\MaestroIdioma;
use Doctrine\ORM\EntityManagerInterface;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Psr\Log\LoggerInterface;

/**
 * Servicio encargado de procesar la autotraducción de entidades.
 * Centraliza la lógica para poder ser invocado tanto por Listeners de Doctrine
 * como por Comandos de consola de forma directa.
 *
 * ── Las tres formas que se mueven por aquí ──────────────────────────────────
 * `Fila` es a propósito `array<string, mixed>` y no `array{language: string, content: string}`:
 * el contenido llega de EasyAdmin y de columnas JSON, donde `content` puede venir `null` —por
 * eso existe la normalización de `listToMapRows()`— y donde una fila puede traer claves de más
 * que hay que conservar (ver el `array_merge()` de `translateAndCloneRows()`). Un tipo más
 * estrecho aquí sería mentira, y un tipo mentiroso es peor que ninguno: le haría creer al
 * analizador que las comprobaciones en runtime sobran.
 *
 * `Estructura` es el JSON anidado tal cual: puede ser lista de objetos o mapa, según el campo.
 *
 * @phpstan-type Fila array<string, mixed>
 * @phpstan-type MapaPorIdioma array<string, Fila>
 * @phpstan-type Estructura array<array-key, mixed>
 */
class AutoTranslationService
{
    /** @var list<string> Códigos de idioma en minúsculas (ej: 'en', 'pt', 'es') */
    private array $validLanguageCodes = [];

    private const string OVERWRITE_FLAG_KEY = 'sobreescribirTraduccion';

    /**
     * La clave que cada fila traducida lleva dentro del JSON con la huella del texto del que
     * salió. Es lo que permite saber si una traducción quedó desfasada **sin** preguntarle nada
     * al changeset de Doctrine.
     *
     * ⚠️ Va en la FILA y no indexada por campo a propósito. Una sola columna puede contener
     * varias unidades traducibles —`whatsappMetaTmpl` tiene `body`, `header`, `footer` y un
     * `button_text` por botón—, así que cualquier esquema con clave necesitaría componer
     * `propiedad + ruta JSON + índice de botón`. Y ese índice es **posicional**: reordenar los
     * botones emparejaría textos que no se corresponden. En la fila, el hash viaja pegado a su
     * texto y el problema no existe.
     *
     * Que una fila i18n lleve claves de más no es nuevo: las de `MessageTemplate::$body` ya
     * viajan con `status` desde siempre.
     */
    private const string SOURCE_HASH_KEY = 'origenHash';

    /**
     * Centinela para una traducción curada a mano que **no debe rehacerse nunca** por el camino
     * automático, ni siquiera cuando cambie el español.
     *
     * El flag explícito de sobrescritura sí la pisa: es una acción humana deliberada sobre un
     * campo concreto, y el centinela está para que nadie la retraduzca sin querer, no para
     * blindarla contra su dueño.
     */
    public const string HASH_MANUAL = 'manual';

    public function __construct(
        private readonly GoogleTranslateService $translator,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProtectorDeMarcadores $marcadores = new ProtectorDeMarcadores(),
        private readonly ?LoggerInterface $logger = null
    ) {}

    /**
     * Procesa una entidad buscando atributos #[AutoTranslate] y traduce sus contenidos.
     *
     * @param object $entity La entidad a procesar.
     * @param bool $forceExecution Si es true, ignora el flag del Trait y ejecuta la traducción obligatoriamente.
     * @param bool|null $overrideOverwrite Si se define, sobreescribe el comportamiento de sobrescritura de la entidad.
     * @param bool $soloSellar Estampa el `origenHash` de lo que ya está traducido y NO llama al
     *                          traductor. Lo usa `app:traduccion:sellar-hash` para declarar que el
     *                          contenido existente corresponde a su español actual, y evitar así
     *                          que el despliegue del hash retraduzca todo el histórico de golpe.
     * @param EntityManagerInterface|null $emToRecompute Si se envía (desde un preUpdate), recalcula el ChangeSet de Doctrine.
     *                                                   Es el `EntityManagerInterface` y no el `ObjectManager` de la
     *                                                   interfaz genérica porque aquí hace falta `getUnitOfWork()`, que
     *                                                   sólo existe en el del ORM. Los eventos de `Doctrine\ORM\Event`
     *                                                   ya lo entregan así.
     *
     * @return void
     */
    public function processEntity(object $entity, bool $forceExecution = false, ?bool $overrideOverwrite = null, ?EntityManagerInterface $emToRecompute = null, bool $soloSellar = false): void
    {
        // 1. Decidir si ejecutamos o no el proceso
        $execute = $forceExecution;
        if (!$execute && method_exists($entity, 'getEjecutarTraduccion')) {
            $execute = (bool) $entity->getEjecutarTraduccion();
        }

        if (!$execute) {
            return;
        }

        if (empty($this->validLanguageCodes)) {
            $this->loadValidLanguages();
        }

        // 2. Determinar si vamos a sobrescribir (leyendo parámetro o el flag físico de la entidad)
        $globalOverwrite = $overrideOverwrite ?? (method_exists($entity, 'getSobreescribirTraduccion') ? (bool) $entity->getSobreescribirTraduccion() : false);

        // Sellar y sobrescribir son incompatibles por definición: sellar declara que lo que hay
        // es correcto, sobrescribir lo tira. Si llegaran juntos, manda sellar.
        if ($soloSellar) {
            $globalOverwrite = false;
        }

        $reflection = new ReflectionClass($entity);
        $hasEntityChanges = false;

        // =========================================================================
        // ✨ AUTO-APAGADO DEL FLAG DE SOBRESCRITURA
        // =========================================================================
        // Si el usuario activó la sobrescritura desde EasyAdmin (true), la regresamos a false
        // inmediatamente en la memoria del objeto para que MySQL lo guarde apagado.
        // Esto previene que el flag se quede pegado y obligue a traducir en futuras ediciones.
        if ($globalOverwrite && method_exists($entity, 'setSobreescribirTraduccion')) {
            $entity->setSobreescribirTraduccion(false);
            $hasEntityChanges = true; // Forzamos a Doctrine a registrar el 'false' en la BD
        }

        foreach ($reflection->getProperties() as $property) {
            $attr = $this->getAutoTranslateAttribute($property);
            if ($attr === null) continue;

            $propertyName = $property->getName();
            $getter = 'get' . ucfirst($propertyName);
            $setter = 'set' . ucfirst($propertyName);

            if (!method_exists($entity, $getter) || !method_exists($entity, $setter)) continue;

            $originalValue = $entity->$getter();

            if (empty($originalValue)) {
                continue;
            }

            $sourceLang = strtolower($attr->sourceLanguage);
            $nestedFields = $attr->nestedFields;
            $mimeType = $attr->getFormat();

            // =========================================================================
            // 🛡️ BARRERA DE SEGURIDAD DINÁMICA (El Veto Declarativo)
            // =========================================================================
            $currentOverwrite = $globalOverwrite;
            $vetado = false;

            // ⚠️ El veto ya NO cuelga de `$currentOverwrite`, y ése es el cambio que importa.
            // Antes sólo frenaba la sobrescritura MANUAL; con el hash, una plantilla aprobada por
            // Meta se habría retraducido sola en cuanto alguien tocara el español, quedándose
            // desincronizada del texto que Meta aprobó — y sin que nadie pulsara nada.
            //
            // Ahora veta las dos vías. Lo que NO veta es rellenar un idioma vacío: eso no pisa
            // ninguna traducción, y es como se traduce por primera vez una plantilla nueva.
            if ($attr->preventOverwriteIf !== null) {
                $vetoMethod = $attr->preventOverwriteIf;
                if (method_exists($entity, $vetoMethod) && $entity->$vetoMethod() === true) {
                    $vetado = true;
                    $currentOverwrite = false;
                }
            }

            // CASO 1: CON NESTED FIELDS (Estructuras complejas anidadas)
            if (!empty($nestedFields)) {
                if (!is_array($originalValue)) {
                    throw new RuntimeException(sprintf('El campo "%s" tiene nestedFields, por lo que debe ser un array (lista o mapa).', $propertyName));
                }

                $newValue = $this->processNestedStructure($originalValue, $nestedFields, $sourceLang, $mimeType, $currentOverwrite, $propertyName, $vetado, $soloSellar);

                if ($newValue !== $originalValue) {
                    $entity->$setter($newValue);
                    $hasEntityChanges = true;
                }
                continue;
            }

            // CASO 2: SIN NESTED FIELDS (Lista plana de traducciones)
            if (!is_array($originalValue) || !array_is_list($originalValue)) {
                throw new RuntimeException(sprintf(
                    'El campo "%s" sin nestedFields debe ser una lista plana de traducciones [{language, content}]. Tipo encontrado: %s',
                    $propertyName, gettype($originalValue)
                ));
            }

            $valuesMap = $this->listToMapRows($originalValue, $propertyName);
            $translatedMap = $this->translateAndCloneRows($valuesMap, $sourceLang, $mimeType, $currentOverwrite, $vetado, $soloSellar);
            $finalValue = $this->mapRowsToList($translatedMap);

            // Solo seteamos la propiedad si el mapa traducido es distinto al original
            if ($finalValue !== $originalValue) {
                $entity->$setter($finalValue);
                $hasEntityChanges = true;
            }
        }

        // 3. Notificar a Doctrine si hubo cambios estructurales en un evento de Update
        if ($hasEntityChanges && $emToRecompute !== null) {
            $meta = $emToRecompute->getClassMetadata($entity::class);
            $emToRecompute->getUnitOfWork()->recomputeSingleEntityChangeSet($meta, $entity);
        }
    }

    /**
     * Recorre recursivamente toda la estructura y apaga (true -> false) cualquier
     * flag de sobrescritura encontrado. Se ejecuta una sola vez, después de haber
     * procesado TODOS los targetKeys de la propiedad, para que un mismo contenedor
     * con varios campos traducibles vea el flag activo durante todo el proceso
     * y no se "gaste" en el primer campo que lo consulta.
     *
     * @param Estructura $data Estructura ya traducida.
     * @return Estructura Estructura con los flags apagados.
     */
    private function resetOverwriteFlags(array $data): array
    {
        if (($data[self::OVERWRITE_FLAG_KEY] ?? null) === true) {
            $data[self::OVERWRITE_FLAG_KEY] = false;
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->resetOverwriteFlags($value);
            }
        }

        return $data;
    }

    /**
     * Inicia la travesía de los campos anidados leyendo la notación de flecha (->).
     *
     * @param Estructura $data Los datos estructurados a procesar.
     * @param list<string> $targetKeys Claves objetivo en notación de flechas.
     * @param string $sourceLang Idioma origen (ej. 'es').
     * @param string $mimeType Tipo mime para el traductor (ej. 'text/html').
     * @param bool $overwrite Si debe sobrescribir o no.
     * @param string $propName Nombre de la propiedad original para mensajes de error.
     *
     * @return Estructura Los datos procesados.
     */
    private function processNestedStructure(array $data, array $targetKeys, string $sourceLang, string $mimeType, bool $overwrite, string $propName, bool $vetado = false, bool $soloSellar = false): array
    {
        foreach ($targetKeys as $keyPath) {
            $pathParts = explode('->', $keyPath);
            $data = $this->traverseAndTranslate($data, $pathParts, $sourceLang, $mimeType, $overwrite, $propName, $vetado, $soloSellar);
        }

        return $this->resetOverwriteFlags($data);
    }

    /**
     * Lee (sin mutar) el flag de sobrescritura de un contenedor específico.
     * Si el contenedor no trae su propia llave, se hereda el flag del nivel padre.
     *
     * @param Estructura $container Contenedor a inspeccionar.
     * @param bool  $inheritedOverwrite Flag heredado del nivel superior.
     *
     * @return bool Flag efectivo para procesar este contenedor.
     */
    private function peekLocalOverwrite(array $container, bool $inheritedOverwrite): bool
    {
        if (!array_key_exists(self::OVERWRITE_FLAG_KEY, $container)) {
            return $inheritedOverwrite;
        }

        return (bool) $container[self::OVERWRITE_FLAG_KEY];
    }

    /**
     * Función recursiva que navega en el array hasta encontrar la llave final a traducir.
     *
     * @param Estructura $data Los datos del nivel actual.
     * @param list<string> $pathParts Las claves restantes por navegar.
     * @param string $sourceLang Idioma de origen.
     * @param string $mimeType Tipo Mime.
     * @param bool $overwrite Bandera de sobrescritura.
     * @param string $fullPath Path completo para contexto de depuración.
     *
     * @return Estructura
     */
    private function traverseAndTranslate(array $data, array $pathParts, string $sourceLang, string $mimeType, bool $overwrite, string $fullPath, bool $vetado = false, bool $soloSellar = false): array
    {
        if (empty($pathParts)) return $data;

        $currentKey = array_shift($pathParts);

        // CASO A: Lista de Objetos (Iterar)
        if (array_is_list($data) && !empty($data)) {
            foreach ($data as $index => $item) {
                if (is_array($item) && !empty($item[$currentKey])) {
                    $itemOverwrite = $this->peekLocalOverwrite($item, $overwrite);

                    if (empty($pathParts)) {
                        $fieldMap = $this->normalizeNestedFieldToRowMap($item[$currentKey], $sourceLang, $fullPath . '.' . $currentKey);
                        $translatedMap = $this->translateAndCloneRows($fieldMap, $sourceLang, $mimeType, $itemOverwrite, $vetado, $soloSellar);
                        $item[$currentKey] = $this->mapRowsToList($translatedMap);
                    } else {
                        $item[$currentKey] = $this->traverseAndTranslate($item[$currentKey], $pathParts, $sourceLang, $mimeType, $itemOverwrite, $fullPath . '.' . $currentKey, $vetado, $soloSellar);
                    }

                    $data[$index] = $item;
                }
            }
            return $data;
        }

        // CASO B: Objeto Simple
        if (isset($data[$currentKey]) && !empty($data[$currentKey])) {
            $localOverwrite = $this->peekLocalOverwrite($data, $overwrite);

            if (empty($pathParts)) {
                $fieldMap = $this->normalizeNestedFieldToRowMap($data[$currentKey], $sourceLang, $fullPath . '.' . $currentKey);
                $translatedMap = $this->translateAndCloneRows($fieldMap, $sourceLang, $mimeType, $localOverwrite, $vetado, $soloSellar);
                $data[$currentKey] = $this->mapRowsToList($translatedMap);
            } else {
                $data[$currentKey] = $this->traverseAndTranslate($data[$currentKey], $pathParts, $sourceLang, $mimeType, $localOverwrite, $fullPath . '.' . $currentKey, $vetado, $soloSellar);
            }
        }

        return $data;
    }

    /**
     * Traduce y clona las filas iterando sobre los idiomas soportados.
     * Genera las filas vacantes inyectando nuevas llaves cuando es necesario.
     *
     * @param MapaPorIdioma $valuesMap Mapa asociativo indexado por idioma (ej. ['es' => [...]]).
     * @param string $sourceLang Idioma de origen.
     * @param string $mimeType Tipo de contenido.
     * @param bool $overwrite Si es verdadero, fuerza la traducción en elementos no vacíos.
     *
     * @return MapaPorIdioma
     */
    private function translateAndCloneRows(array $valuesMap, string $sourceLang, string $mimeType, bool $overwrite, bool $vetado = false, bool $soloSellar = false): array
    {
        $sourceLangNorm = strtolower($sourceLang);
        $normalizado = [];

        // ⚠️ Antes esto DESCARTABA las filas de idiomas fuera de `validLanguageCodes`, y lo hacía
        // **antes de mirar `$overwrite`**: bajar la prioridad de un idioma a 0 en `maestro_idioma`
        // —una decisión de negocio normal, «ya no vendemos ahí»— borraba su contenido de las 25
        // entidades según se iban guardando. Sin error y sin log, y con el flag de sobrescritura
        // APAGADO, que es justo el modo que promete respetar lo existente.
        //
        // Retirar un idioma del catálogo significa «deja de traducir a él», no «tira lo traducido».
        // Las filas desconocidas se conservan intactas: no se traducen —el bucle de abajo sólo
        // recorre `validLanguageCodes`— pero tampoco se pierden, y vuelven a mantenerse solas el
        // día que ese idioma se reactive.
        foreach ($valuesMap as $lang => $row) {
            $normalizado[strtolower((string) $lang)] = $row;
        }
        $valuesMap = $normalizado;

        $sourceRow = $valuesMap[$sourceLangNorm] ?? null;
        $sourceIsUsable = is_array($sourceRow) && !empty($sourceRow['content']) && is_string($sourceRow['content']);

        if (!$sourceIsUsable) {
            // 🗑️ Solo limpiamos las traducciones huérfanas cuando el usuario pidió
            // explícitamente sobrescribir (overwrite=true) para este campo/rama.
            // Si overwrite es false, puede que este campo simplemente nunca tuvo
            // base cargada todavía, y no hay que tocar lo que ya existe.
            if ($overwrite) {
                return $sourceRow !== null ? [$sourceLangNorm => $sourceRow] : [];
            }

            return $valuesMap;
        }

        $sourceText = $sourceRow['content'];
        $hashActual = self::hashDeOrigen($sourceText, $mimeType);

        foreach ($this->validLanguageCodes as $targetCode) {
            if ($targetCode === $sourceLangNorm) continue;

            $existingRow = $valuesMap[$targetCode] ?? null;
            $isContentEmpty = $existingRow === null || trim((string) $existingRow['content']) === '';
            $hashGuardado = is_array($existingRow) ? ($existingRow[self::SOURCE_HASH_KEY] ?? null) : null;

            // Propiedad vetada (`preventOverwriteIf`): nunca se pisa una traducción existente,
            // ni por hash ni por el flag. Un hueco vacío sí se rellena — no pisa nada.
            if ($vetado && !$isContentEmpty) {
                continue;
            }

            // Modo sellar: estampa la huella de lo que ya está traducido y no llama a nadie. Una
            // fila vacía se queda vacía —no hay nada que declarar correcto— y se traducirá el día
            // que la entidad se guarde de verdad.
            if ($soloSellar) {
                if (!$isContentEmpty && $hashGuardado === null) {
                    $valuesMap[$targetCode] = array_merge($existingRow, [
                        self::SOURCE_HASH_KEY => $hashActual,
                    ]);
                }

                continue;
            }

            // Una traducción curada a mano no la toca el camino automático. El flag explícito sí.
            if (!$overwrite && $hashGuardado === self::HASH_MANUAL) {
                continue;
            }

            // Traducida y el origen no se ha movido: no hay nada que hacer.
            //
            // ⚠️ Nótese lo que NO hay aquí: una rama que selle el contenido sin hash y lo dé por
            // bueno. Se probó y se retiró el 31/08/2026, porque volvía **decorativo** el
            // `--clase` del comando de sellado: si no sellar acaba sellando igual, sólo que más
            // tarde y sin que nadie lo mire, entonces no hay decisión que tomar.
            //
            // Una fila sin hash es una fila de la que no sabemos si corresponde a su español, y
            // la única forma honesta de averiguarlo es rehacerla. Quien SÍ lo sabe es la persona
            // que corre `app:traduccion:sellar-hash --clase=`, y por eso ésa es la vía para
            // declararlo — explícita, por módulo y con `--dry-run` delante.
            //
            // Lo que esta rama protegía —las plantillas aprobadas por Meta, que
            // `WhatsappMetaTemplateSyncService` reconstruye sin hash desde la respuesta de
            // ellos— lo protege ya el veto de `preventOverwriteIf`, que se evalúa más arriba.
            if (!$overwrite && !$isContentEmpty && $hashGuardado === $hashActual) {
                continue;
            }

            try {
                // 🛡️ Los `{{ marcadores }}` van enmascarados: Google no los deja quietos, les
                // TRADUCE el nombre de dentro —`{{ medios_pago }}` vuelve como
                // `{{ payment_methods }}`— y entonces el interpolador ya no los reconoce. El
                // huésped ve la llave en crudo en su guía. Ver ProtectorDeMarcadores.
                [$textoSeguro, $marcadores] = $this->marcadores->enmascarar($sourceText);

                $res = $this->translator->translate($textoSeguro, $targetCode, $sourceLangNorm, $mimeType);

                // Sin `is_string($res[0])`: `translate()` devuelve `list<string>` desde que
                // está tipado. El `!empty()` sí hace falta — una traducción vacía no se guarda.
                if (!empty($res[0])) {
                    // Si el traductor se comió un centinela, esta traducción NO se guarda: un
                    // texto al que le falta el widget de medios de pago se publica igual de
                    // callado que uno bueno, y nadie lo nota hasta que alguien pregunta cómo
                    // pagar. Mejor quedarse con la traducción anterior, o sin ninguna.
                    if (!$this->marcadores->estaIntacto($res[0], $marcadores)) {
                        $this->logger?->warning(sprintf(
                            '[AutoTranslate] %s → %s: el traductor perdió un marcador; no se guarda.',
                            $sourceLangNorm,
                            $targetCode
                        ));

                        continue;
                    }

                    $baseRow = $existingRow !== null ? $existingRow : $sourceRow;
                    $valuesMap[$targetCode] = array_merge($baseRow, [
                        'language'            => $targetCode,
                        'content'             => $this->marcadores->restaurar($res[0], $marcadores),
                        // La huella del texto del que ACABA de salir. Mientras cuadre, esta fila
                        // está al día; en cuanto el español cambie, dejará de cuadrar y se rehará.
                        self::SOURCE_HASH_KEY => $hashActual,
                    ]);
                }
            } catch (\Throwable $e) {
                // ⚠️ Este `catch` estuvo MUDO, y es el que avisa de que el sistema entero está
                // caído: credenciales caducadas, cuota agotada o red cortada dejaban las siete
                // traducciones sin hacer y la entidad se guardaba tan contenta con sólo el
                // español. Ni un error, ni una línea de log — el fallo que no se ve.
                $this->logger?->error(sprintf(
                    '[AutoTranslate] %s → %s: falló la traducción (%s): %s',
                    $sourceLangNorm,
                    $targetCode,
                    $e::class,
                    $e->getMessage()
                ));

                continue;
            }
        }

        return $valuesMap;
    }

    /**
     * La huella del texto origen del que debe salir una traducción.
     *
     * Tres decisiones, y las tres importan:
     *
     * - **Sobre el texto CRUDO**, antes de que `ProtectorDeMarcadores` lo enmascare. El
     *   enmascarado genera centinelas que dependen del orden de aparición; hashear eso ataría la
     *   huella a un detalle interno del protector.
     * - **Con los espacios normalizados.** `sha1()` distingue un salto de línea de más, y los
     *   editores de texto enriquecido reescriben el HTML al guardar aunque nadie lo haya tocado.
     *   Sin esto, cada guardado retraduciría los seis idiomas por un espacio.
     * - **Con el `$mimeType` dentro.** Cambiar un campo de `text` a `html` obliga a rehacer las
     *   traducciones, y el texto es el mismo: sin el formato en la huella, el hash cuadraría y no
     *   se rehacía nada.
     */
    private static function hashDeOrigen(string $sourceText, string $mimeType): string
    {
        $normalizado = trim((string) preg_replace('/\s+/u', ' ', $sourceText));

        return sha1($mimeType . '|' . $normalizado);
    }

    /**
     * Convierte una lista JSON en un mapa asociativo por idioma.
     *
     * @param mixed $values Lista de arrays.
     * @param string $propName Nombre de la propiedad original.
     *
     * @throws RuntimeException Si el formato es inválido.
     * @return MapaPorIdioma
     */
    private function listToMapRows(mixed $values, string $propName): array
    {
        if (!is_array($values)) {
            throw new RuntimeException(sprintf('El valor de "%s" debe ser un array de traducciones.', $propName));
        }

        $out = [];
        foreach ($values as $index => $row) {
            // Se usa array_key_exists en lugar de isset para permitir contenidos "null" de EasyAdmin
            if (!is_array($row) || !isset($row['language']) || !array_key_exists('content', $row)) {
                throw new RuntimeException(sprintf(
                    'Estructura inválida en "%s" (índice %s). Se requiere un objeto con las claves "language" y "content"',
                    $propName, $index
                ));
            }

            $row['content'] = $row['content'] ?? '';
            $out[strtolower((string) $row['language'])] = $row;
        }
        return $out;
    }

    /**
     * Convierte el mapa asociativo nuevamente en una lista para guardarse en BD.
     *
     * @param MapaPorIdioma $map
     * @return list<Fila>
     */
    private function mapRowsToList(array $map): array
    {
        return array_values($map);
    }

    /**
     * Normaliza un campo anidado preparándolo para ser traducido.
     * Si viene como texto plano, lo encapsula. Si viene como lista, lo indexa.
     *
     * @param mixed $value
     * @param string $sourceLang
     * @param string $propName
     *
     * @throws RuntimeException
     * @return MapaPorIdioma
     */
    private function normalizeNestedFieldToRowMap(mixed $value, string $sourceLang, string $propName): array
    {
        $sourceLangNorm = strtolower($sourceLang);

        if (is_string($value)) {
            return [$sourceLangNorm => ['language' => $sourceLangNorm, 'content'  => $value]];
        }

        if (is_array($value) && array_is_list($value)) {
            return $this->listToMapRows($value, $propName);
        }

        throw new RuntimeException(sprintf(
            'El valor en "%s" tiene un formato no válido. Debe ser texto plano o una lista de traducciones [{language, content}].',
            $propName
        ));
    }

    /**
     * Carga de la BD los idiomas soportados con prioridad mayor a 0.
     */
    private function loadValidLanguages(): void
    {
        // `@var` obligatorio sobre todo `getResult()`: sin él la lista es `mixed` y el
        // `->getId()` de abajo es una zona ciega que ni el nivel 7 de PHPStan mira.
        /** @var list<MaestroIdioma> $idiomas */
        $idiomas = $this->entityManager->getRepository(MaestroIdioma::class)
            ->createQueryBuilder('i')
            ->where('i.prioridad > 0')
            ->orderBy('i.prioridad', 'DESC')
            ->getQuery()
            ->getResult();

        foreach ($idiomas as $idioma) {
            $this->validLanguageCodes[] = strtolower((string) $idioma->getId());
        }

        $this->validLanguageCodes = array_values(array_unique($this->validLanguageCodes));

        // Medida de seguridad: Garantizar que el español siempre sea base obligatoria
        if (!in_array('es', $this->validLanguageCodes, true)) {
            $this->validLanguageCodes[] = 'es';
        }
    }

    /**
     * Obtiene y procesa el atributo AutoTranslate de una propiedad vía Reflexión.
     *
     * @param ReflectionProperty $property
     * @return AutoTranslate|null
     */
    private function getAutoTranslateAttribute(ReflectionProperty $property): ?AutoTranslate
    {
        $attributes = $property->getAttributes(AutoTranslate::class);
        if (!isset($attributes[0])) {
            return null;
        }

        /** @var AutoTranslate $instance */
        $instance = $attributes[0]->newInstance();
        return $instance;
    }
}