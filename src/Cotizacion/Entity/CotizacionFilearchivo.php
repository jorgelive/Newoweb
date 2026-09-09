<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Attribute\AutoTranslate;
use App\Cotizacion\Enum\ArchivoTipoEnum;
use App\Cotizacion\Enum\ValidacionDocumentoEnum;
use App\Cotizacion\State\CotizacionFilearchivoMultipartProcessor;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Panel\Contract\RequiereAltaFidelidadInterface;
use App\Panel\Entity\Trait\MediaTrait;
use App\Security\Roles;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Serializer\Annotation\Groups;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[ApiResource(
    shortName: 'CotizacionFilearchivo',
    operations: [
        new Post(
            inputFormats: [
                'jsonld' => ['application/ld+json'],
                'multipart' => ['multipart/form-data']
            ],
            denormalizationContext: [
                'groups' => ['file:write'],
                'disable_type_enforcement' => true,   // 🔑 multipart manda todo como string
            ],
            securityPostDenormalize: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para subir documentos.',
            processor: CotizacionFilearchivoMultipartProcessor::class
        ),
        new Patch(
            denormalizationContext: ['groups' => ['file:write']],
            security: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar documentos.'
        ),
        new Delete(
            security: "is_granted('" . Roles::RESERVAS_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar documentos.'
        )
    ],
    routePrefix: '/sales'
)]
#[ORM\Entity]
#[ORM\Table(name: 'cotizacion_file_archivo')]
#[ORM\HasLifecycleCallbacks]
#[Vich\Uploadable]
class CotizacionFilearchivo implements RequiereAltaFidelidadInterface
{
    use IdTrait;
    use TimestampTrait;
    use MediaTrait;
    use AutoTranslateControlTrait;

    /**
     * Qué CLASE DE ARCHIVO es: boleto, factura, confirmación de reserva.
     *
     * ⚠️ **Se llamaba `tipodocumento`, y ese nombre hacía creer que aquí se guardaba el NÚMERO
     * del DNI.** Eso es lo que no cabe: los datos de identidad —tipo, número, vencimiento, país—
     * son {@see CotizacionPasajeroIdentificacion}, que se consulta y se compara.
     *
     * Aquí caben **archivos, cualquier archivo**: un boleto, una factura, una confirmación… y
     * también el escaneo de un pasaporte, que es un archivo como otro cualquiera. Lo que separa a
     * las dos entidades no es el asunto del documento, es su naturaleza: **allí un dato, aquí un
     * fichero**.
     *
     * ⚠️ La redacción anterior decía «esto no es un documento de identidad», y se leía como que un
     * pasaporte escaneado no tenía sitio aquí. No es eso: el escaneo sí, el número no.
     */
    #[Groups(['file:item:read', 'file:write', 'pax_file:read'])]
    #[ORM\Column(name: 'tipo_archivo', type: 'string', length: 20, enumType: ArchivoTipoEnum::class)]
    private ?ArchivoTipoEnum $tipoArchivo = null;

    #[Groups(['file:item:read', 'file:write'])]
    #[ORM\ManyToOne(targetEntity: CotizacionFile::class, inversedBy: 'filearchivos')]
    #[ORM\JoinColumn(name: 'file_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?CotizacionFile $file = null;

    /**
     * De quién es este archivo. **Las dos nulables, y las CUATRO combinaciones significan algo:**
     *
     * | `pasajero` | `vuelo` | `grupo` | qué es |
     * |---|---|---|---|
     * | ✓ | ✓ | — | **su boarding pass de ese vuelo**: Ana, DM6771, LIM→PUJ del 18 |
     * | ✓ | — | — | suyo y nada más: el escaneo de su pasaporte, su autorización |
     * | — | — | ✓ | del grupo: el namelist que manda la aerolínea con el PNR |
     * | — | — | — | del expediente: la factura, la confirmación |
     *
     * ⚠️ **La primera fila se añadió el 07/09/2026** y en dos intentos: primero con el subgrupo, que
     * no servía —su clave es el PNR y un PNR cubre ida y vuelta—, y después con el vuelo, que es
     * de lo que de verdad es un boarding pass.
     *
     * Un solo mecanismo para los cuatro alcances. Sin esto harían falta cuatro modelos.
     */
    #[Groups(['file:item:read', 'file:write'])]
    #[ORM\ManyToOne(targetEntity: CotizacionFilepasajero::class)]
    #[ORM\JoinColumn(name: 'pasajero_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?CotizacionFilepasajero $pasajero = null;

    /**
     * De qué VUELO es, cuando es un boarding pass.
     *
     * ⚠️ **Y no basta con el subgrupo, que es lo que había.** La `clave` de un subgrupo de reserva
     * aérea es el **PNR** —`54X6ZM`—, y un PNR cubre ida y vuelta: `DM6771` y `DM6770` caen en el
     * mismo, así que con `grupo` solo se vuelve a no distinguir cuál es cuál. Y quien vuela
     * Cusco–Lima, Lima–Panamá y Panamá–Punta Cana ida y vuelta tiene ocho.
     *
     * Un boarding pass **es de un vuelo**, no de una reserva. Los vuelos ya existían como dato
     * —número, fecha, aerolínea y ruta— y ya colgaban de los subgrupos; sólo faltaba que el
     * archivo pudiera apuntar a uno.
     *
     * ⚠️ **El camino pasajero → vuelos ya existe** y no hace falta guardarlo:
     * `pasajero → pasajero_grupo → grupo(reserva_aerea) → grupo_vuelo → vuelo`. Por eso la carga
     * masiva puede **validar** que esa persona vuela de verdad ese vuelo —un renombrado mal hecho
     * se marca en vez de guardarse torcido— y el formulario ofrece sus ocho, no los veinticuatro
     * del expediente.
     */
    #[Groups(['file:item:read', 'file:write'])]
    #[ORM\ManyToOne(targetEntity: CotizacionVuelo::class)]
    #[ORM\JoinColumn(name: 'vuelo_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?CotizacionVuelo $vuelo = null;

    #[Groups(['file:item:read', 'file:write'])]
    #[ORM\ManyToOne(targetEntity: CotizacionFileGrupo::class)]
    #[ORM\JoinColumn(name: 'grupo_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?CotizacionFileGrupo $grupo = null;

    /**
     * En qué punto del control está este escaneo. Ver {@see ValidacionDocumentoEnum}.
     *
     * ⚠️ **Vive en el ARCHIVO y no en la identificación del pasajero**, aunque valide sus datos.
     * Lo que se valida es *esta imagen contra el manifiesto*: la misma persona puede tener el
     * pasaporte comprobado y el DNI observado, y un estado en la persona no sabría decir cuál de
     * los dos hay que volver a pedir.
     */
    #[Groups(['file:item:read'])]
    #[ORM\Column(name: 'estado_validacion', type: 'string', length: 20, enumType: ValidacionDocumentoEnum::class, options: ['default' => 'no_validado'])]
    private ValidacionDocumentoEnum $estadoValidacion = ValidacionDocumentoEnum::NO_VALIDADO;

    /**
     * Qué no encajó, en frases que se leen tal cual en la cola de trabajo.
     *
     * ⚠️ Se guarda el TEXTO y no un código de motivo: son para que una persona decida, no para
     * que el programa ramifique. Un catálogo de códigos aquí envejecería mal —cada documento raro
     * añade el suyo— y obligaría a mantener sus traducciones para nada.
     *
     * @var list<string>
     */
    #[Groups(['file:item:read'])]
    #[ORM\Column(name: 'observaciones_validacion', type: 'json')]
    private array $observacionesValidacion = [];

    /**
     * Cuándo se miró por última vez.
     *
     * ⚠️ Hace falta **además** del estado: un `NO_VALIDADO` con fecha es «se intentó y no se pudo»
     * y uno sin fecha es «nunca le ha tocado». Son dos colas distintas y sin esto se mezclan.
     */
    #[Groups(['file:item:read'])]
    #[ORM\Column(name: 'validado_en', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $validadoEn = null;

    public function getEstadoValidacion(): ValidacionDocumentoEnum { return $this->estadoValidacion; }

    /** @return list<string> */
    public function getObservacionesValidacion(): array { return $this->observacionesValidacion; }

    public function getValidadoEn(): ?DateTimeImmutable { return $this->validadoEn; }

    /**
     * El resultado del control, siempre junto: estado, motivos y fecha se escriben a la vez o se
     * contradicen. Con tres setters sueltos, un día alguien pone el estado y olvida las
     * observaciones, y queda un «observado» que no dice de qué.
     *
     * @param list<string> $observaciones
     */
    public function registrarValidacion(ValidacionDocumentoEnum $estado, array $observaciones): self
    {
        $this->estadoValidacion = $estado;
        $this->observacionesValidacion = $observaciones;
        $this->validadoEn = new DateTimeImmutable();

        return $this;
    }

    /* ======================================================
     * PROPIEDADES DE VICH UPLOADER Y MEDIA TRAIT
     * ====================================================== */
    #[Vich\UploadableField(mapping: 'cotizacion_file_archivos', fileNameProperty: 'imageName', size: 'imageSize')]
    private ?File $imageFile = null;

    #[Groups(['file:item:read', 'file:write'])]
    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $imageName = null;

    #[Groups(['file:item:read', 'file:write'])]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $imageSize = null;

    /**
     * Por dónde se pide este archivo. **Ya no es una URL pública.**
     *
     * ⚠️ Antes la inyectaba un `AssetListener` concatenando la ruta de `public/`, y eso hacía que
     * el enlace **fuera** el permiso. Ahora apunta al controlador que comprueba quién pregunta
     * ({@see \App\Cotizacion\Controller\Publico\ArchivoPrivadoController}); el fichero lo manda
     * nginx, no PHP.
     *
     * Es un getter y no una propiedad inyectada porque se deriva del id: no hay nada que guardar
     * ni que mantener sincronizado.
     */
    #[Groups(['file:item:read', 'file:write', 'pax_file:read'])]
    public function getImageUrl(): ?string
    {
        if (($this->imageName ?? '') === '' || $this->id === null) {
            return null;
        }

        return '/archivo/' . $this->id->toRfc4122();
    }

    /** @var list<array{language?: string, content?: string|null}>|null */
    #[Groups(['file:item:read', 'file:write', 'pax_file:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $nombre = null;

    public function __construct()
    {
        $this->initializeId();
    }

    #[ORM\PrePersist]
    public function setupMediaToken(): void
    {
        $this->initializeToken();
    }

    public function __toString(): string
    {
        return $this->getNombreTraducido('es') ?? $this->imageName ?? 'Archivo sin nombre';
    }

    public function isSobreescribirTraduccion(): bool
    {
        return $this->sobreescribirTraduccion;
    }

    #[Groups(['file:write'])]
    public function setSobreescribirTraduccion(bool|string|int|null $sobreescribirTraduccion): self
    {
        $this->sobreescribirTraduccion = filter_var($sobreescribirTraduccion, FILTER_VALIDATE_BOOLEAN);
        return $this;
    }

    private function getNombreTraducido(string $lang): ?string
    {
        foreach ($this->nombre ?? [] as $item) {
            if (($item['language'] ?? null) === $lang) {
                return $item['content'] ?? null;
            }
        }
        return null;
    }

    /* ======================================================
     * GETTERS Y SETTERS
     * ====================================================== */

    public function getTipoArchivo(): ?ArchivoTipoEnum { return $this->tipoArchivo; }
    public function setTipoArchivo(?ArchivoTipoEnum $tipoArchivo): self { $this->tipoArchivo = $tipoArchivo; return $this; }

    public function getFile(): ?CotizacionFile { return $this->file; }
    public function setFile(?CotizacionFile $file): self { $this->file = $file; return $this; }

    public function getImageFile(): ?File { return $this->imageFile; }
    public function setImageFile(?File $imageFile = null): void
    {
        $this->imageFile = $imageFile;
        if (null !== $imageFile) {
            // Forzar actualización de la entidad para que Doctrine detecte el cambio y dispare el evento
            $this->updatedAt = new DateTimeImmutable();
        }
    }

    public function getImageName(): ?string { return $this->imageName; }

    /**
     * Qué CLASE de fichero es, para que quien lo pinte no tenga que adivinar.
     *
     * ⚠️ **Las dos aplicaciones pintaban un icono de PDF a fuego**, así que un vídeo, una foto de
     * pasaporte o una hoja de cálculo se anunciaban como PDF. No rompe nada y por eso llevaba ahí
     * desde siempre: sólo hace que la lista mienta, y una bóveda con ~1 500 archivos que miente
     * sobre lo que hay dentro obliga a abrirlos para saberlo.
     *
     * ⚠️ Se decide por la EXTENSIÓN del nombre en disco, que lo pone `MediaTokenNamer` a partir de
     * lo que Symfony dedujo del contenido al subirlo — no del nombre que traía el cliente. Y el
     * escaneo de identidad ya viene convertido a `webp` por
     * {@see \App\Panel\EventListener\Media\VichWebpConversionListener}, así que aquí ya es
     * imagen aunque el operador subiera un HEIC.
     *
     * @return 'pdf'|'imagen'|'video'|'audio'|'hoja'|'otro'
     */
    #[Groups(['file:item:read', 'pax_file:read'])]
    public function getTipoMedio(): string
    {
        $extension = strtolower(pathinfo((string) $this->imageName, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => 'pdf',
            'jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif', 'avif', 'bmp', 'tif', 'tiff' => 'imagen',
            'mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v', '3gp' => 'video',
            'mp3', 'wav', 'ogg', 'm4a', 'aac' => 'audio',
            'xls', 'xlsx', 'csv', 'ods' => 'hoja',
            default => 'otro',
        };
    }
    public function setImageName(?string $imageName): self { $this->imageName = $imageName; return $this; }

    public function getImageSize(): ?int { return $this->imageSize; }
    public function setImageSize(?int $imageSize): self { $this->imageSize = $imageSize; return $this; }



    /**
     * @return list<array{language?: string, content?: string|null}>
     */
    public function getNombre(): array { return $this->nombre; }
    /**
     * @param list<array{language?: string, content?: string|null}> $nombre
     */
    public function setNombre(array $nombre): void { $this->nombre = $nombre; }

    public function getPasajero(): ?CotizacionFilepasajero { return $this->pasajero; }
    public function setPasajero(?CotizacionFilepasajero $v): self { $this->pasajero = $v; return $this; }

    public function getGrupo(): ?CotizacionFileGrupo { return $this->grupo; }
    public function setGrupo(?CotizacionFileGrupo $v): self { $this->grupo = $v; return $this; }

    /**
     * El dueño tiene que ser del MISMO expediente que el archivo.
     *
     * Mismo invariante que en {@see CotizacionPasajeroGrupo}: las dos claves son válidas por
     * separado y nada impide colgarle a un expediente el boarding pass de otro. A mano no pasa;
     * en una importación en lote, sí.
     */
    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function validarDuenoDelMismoExpediente(): void
    {
        $mio = $this->file?->getId();
        if ($mio === null) {
            return;
        }

        foreach ([
            'pasajero' => $this->pasajero?->getFile()?->getId(),
            'grupo' => $this->grupo?->getFile()?->getId(),
            // ⚠️ **El vuelo faltaba en esta lista.** Se escribió cuando el único alcance con
            // riesgo era la importación en lote, y `vuelo` se añadió después (07/09/2026): por la
            // pantalla no se alcanza —el desplegable sale del mismo expediente—, pero por la API
            // sí se podía colgar el vuelo de otro. Un archivo así no es inválido para nadie: sólo
            // enseña en la fila un vuelo que esa persona no tiene.
            'vuelo' => $this->vuelo?->getFile()?->getId(),
        ] as $que => $suyo) {
            if ($suyo !== null && !$mio->equals($suyo)) {
                throw new \DomainException(sprintf('El archivo y su %s son de expedientes distintos.', $que));
            }
        }
    }

    /** ¿Lo ve todo el expediente, o es de alguien? */
    #[Groups(['file:item:read'])]
    public function getAlcance(): string
    {
        return match (true) {
            $this->pasajero !== null => 'pasajero',
            $this->grupo !== null => 'grupo',
            default => 'expediente',
        };
    }

    /**
     * ¿Se le puede devolver al pasajero que se identificó? Lo decide el TIPO.
     *
     * Vive aquí y no sólo en el enum para que el controlador pregunte a la entidad y no tenga que
     * saber que el tipo puede ser nulo: un adjunto sin clasificar no se devuelve.
     */
    public function esDevolvibleAlPasajero(): bool
    {
        return $this->tipoArchivo?->esDevolvibleAlPasajero() ?? false;
    }

    /**
     * Con qué nombre se guarda en el móvil de quien lo descarga.
     *
     * ⚠️ El nombre en disco es un token —`TOKEN_algo.pdf`, cosa del `MediaTokenNamer`— y en la
     * carpeta de descargas de un teléfono eso no le dice nada a nadie. En el gate hay prisa: el
     * fichero tiene que llamarse como lo que es.
     */
    public function nombreParaDescarga(): string
    {
        $extension = pathinfo((string) $this->imageName, PATHINFO_EXTENSION);
        $base = $this->tipoArchivo->value ?? 'archivo';

        // 🔥 **El vuelo delante del tipo.** Ocho tarjetas de embarque descargadas se llamaban
        // `boleto.pdf`, `boleto(1).pdf`, `boleto(2).pdf` — y el pasajero acaba en la puerta
        // abriéndolas de una en una. Con `CUZ-LIM-17sep-JA7018` la carpeta de descargas ya está
        // ordenada, que es donde de verdad se busca.
        $vuelo = $this->vuelo;
        if ($vuelo !== null) {
            $base = trim(sprintf(
                '%s-%s-%s-%s',
                (string) $vuelo->getOrigen(),
                (string) $vuelo->getDestino(),
                // Sin año: el viaje es de este año y hace el nombre más corto de leer.
                ($vuelo->getSalida() ?? $vuelo->getFecha())?->format('d-M') ?? '',
                (string) $vuelo->getNumero(),
            ), '-');
        }

        $pasajero = $this->pasajero;
        if ($pasajero !== null) {
            $base .= '-' . trim(sprintf('%s %s', (string) $pasajero->getNombre(), (string) $pasajero->getApellido()));
        }

        // Sin acentos ni espacios: acaba en un sistema de ficheros que no se sabe cuál es.
        $limpio = preg_replace('/[^A-Za-z0-9._-]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $base) ?: $base) ?? $base;

        return trim((string) $limpio, '-') . ($extension !== '' ? '.' . $extension : '');
    }

    public function getVuelo(): ?CotizacionVuelo { return $this->vuelo; }
    public function setVuelo(?CotizacionVuelo $v): self { $this->vuelo = $v; return $this; }

    /**
     * Un escaneo de identidad se comprime con otro filtro: hay que poder LEERLO.
     *
     * {@see RequiereAltaFidelidadInterface} — y {@see ArchivoTipoEnum::esEscaneoDeIdentidad()} para
     * cuáles son.
     */
    public function requiereAltaFidelidad(): bool
    {
        return $this->tipoArchivo?->esEscaneoDeIdentidad() === true;
    }
}
