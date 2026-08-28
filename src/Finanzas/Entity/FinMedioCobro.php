<?php

declare(strict_types=1);

namespace App\Finanzas\Entity;

use App\Attribute\AutoTranslate;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Finanzas\Enum\FinAudienciaCobro;
use App\Finanzas\Enum\FinMedioCobroTipo;
use App\Finanzas\Repository\FinMedioCobroRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Una vía por la que el cliente nos puede mandar dinero: una cuenta, un Yape, un giro.
 *
 * ### Por qué es un catálogo GLOBAL y no una columna por establecimiento
 *
 * La primera versión de esto vivía en `PmsEstablecimientoVirtual::$mediosCobro`, un JSON por
 * listing. Duró un día: las cuentas son **las mismas para todos** —el dinero entra a la misma
 * empresa—, así que aquel diseño obligaba a corregir el mismo número de cuenta en cada
 * establecimiento virtual, y a que el que se olvidara quedara mintiendo.
 *
 * Aquí hay una sola fila por cuenta. Cambia el número una vez y cambia en todas partes.
 *
 * ### Por qué en `Finanzas` y no en `Pms`
 *
 * Porque no lo usa sólo el PMS. Hoy lo lee {@see \App\Agent\Skill\Pms\ConsultarMediosPagoSkill}
 * para decirle a un huésped por dónde pagar; el mismo catálogo alimenta las **condiciones de
 * pago de una cotización**, donde el cliente no es un huésped ni hay reserva de por medio.
 *
 * `src/Finanzas/` no importa de `App\Pms` en ningún archivo, y esta entidad mantiene esa regla:
 * las flechas van Pms → Finanzas y Cotizacion → Finanzas, nunca al revés. Es lo que permite
 * que el segundo consumidor no herede el PMS entero. Ver docs/FinanzasEnlacesPago.md.
 *
 * ⚠️ **Lo que se escribe aquí se le da al cliente tal cual y el cliente manda dinero.** No hay
 * una segunda revisión antes de que el agente lo cite en un chat: un dígito de más en una
 * cuenta es una transferencia perdida.
 */
#[ORM\Entity(repositoryClass: FinMedioCobroRepository::class)]
#[ORM\Table(name: 'fin_medio_cobro')]
#[ORM\HasLifecycleCallbacks]
class FinMedioCobro
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    #[ORM\Column(name: 'tipo', type: 'string', length: 30, enumType: FinMedioCobroTipo::class)]
    private FinMedioCobroTipo $tipo = FinMedioCobroTipo::TRANSFERENCIA_BANCARIA;

    /**
     * A nombre de quién está la cuenta, tal como el cliente lo va a ver al pagar.
     *
     * No es siempre el mismo: las cuentas bancarias están a nombre de una persona y el Western
     * Union a nombre de otra. Que no cuadre el titular es el motivo número uno de que alguien
     * cancele una transferencia a medias.
     */
    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $titular = null;

    /**
     * Cómo aparece el titular cuando el sistema del banco no admite tildes ni «ñ».
     *
     * Existe porque «Susan Acuña Romero» sale como «Susan Acuna Romero» en parte de la banca
     * peruana, y el cliente que ve un nombre distinto al que le dimos se detiene y pregunta.
     * Es un campo y no una nota repetida en las ocho cuentas: es el mismo dato para todas.
     */
    #[ORM\Column(name: 'titular_alterno', type: 'string', length: 180, nullable: true)]
    private ?string $titularAlterno = null;

    /** El número de cuenta, o el teléfono en Yape y Plin. Sin esto el medio no se ofrece. */
    #[ORM\Column(type: 'string', length: 60, nullable: true)]
    private ?string $numero = null;

    /** Sólo en transferencias: BCP, BBVA, Interbank, Scotiabank. */
    #[ORM\Column(type: 'string', length: 60, nullable: true)]
    private ?string $banco = null;

    /**
     * Código interbancario, para transferencias entre bancos distintos.
     *
     * Se deja vacío a propósito hasta que alguien lo teclee: el CCI y el SWIFT se piden, no se
     * publican, y un CCI inventado o copiado de otra cuenta es dinero que se va a un tercero.
     */
    #[ORM\Column(type: 'string', length: 40, nullable: true)]
    private ?string $cci = null;

    /** `PEN`, `USD`… `null` cuando no aplica, como en el efectivo. */
    #[ORM\Column(type: 'string', length: 3, nullable: true)]
    private ?string $moneda = null;

    /**
     * A quién se le ofrece. No es un permiso: es dinero. Ver {@see FinAudienciaCobro}.
     */
    #[ORM\Column(name: 'audiencia', type: 'string', length: 20, enumType: FinAudienciaCobro::class, options: ['default' => 'todos'])]
    private FinAudienciaCobro $audiencia = FinAudienciaCobro::TODOS;

    /**
     * Días MÍNIMOS que tienen que faltar para la llegada para poder ofrecer este medio.
     *
     * `null` = sirve siempre, que es el caso de casi todos.
     *
     * Existe por **Western Union**: tarda, así que ofrecérselo a alguien que llega mañana —o
     * que ya está en la puerta— es darle una salida que no existe. Y ése es el peor tipo de
     * respuesta: parece útil y se descubre inútil cuando ya no hay tiempo.
     *
     * La audiencia dice **a quién** se le ofrece; esto, **cuándo**. Son dos ejes distintos y
     * por eso son dos campos: Western Union es `internacional` Y necesita margen; el efectivo
     * es de `todos` y no necesita ninguno.
     *
     * ⚠️ Va aquí, en el medio, y no en las skills: el catálogo lo consultan el agente, la app
     * del huésped y las cotizaciones. Puesto en un solo sitio, los tres se corrigen a la vez.
     */
    #[ORM\Column(name: 'dias_minimos', type: 'smallint', nullable: true)]
    private ?int $diasMinimos = null;

    /**
     * La aclaración que acompaña al número: «cobro en tienda», «mándanos la captura».
     *
     * Va traducida —y no en español a secas como el resto de textos que ve el modelo— porque
     * este mismo campo lo pinta la app del cliente, que no pasa por ningún modelo que lo
     * redacte. Estructura `[{"language": "es", "content": "…"}]`, como el resto de campos i18n.
     */
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $nota = [];

    /** Apagarlo lo retira de todas partes sin perder el número. */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $activo = true;

    /** En qué orden se le enseñan al cliente. Lo primero de la lista es lo que más se usa. */
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $orden = 0;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getTipo(): FinMedioCobroTipo { return $this->tipo; }
    public function setTipo(FinMedioCobroTipo $tipo): self { $this->tipo = $tipo; return $this; }

    public function getTitular(): ?string { return $this->titular; }
    public function setTitular(?string $titular): self { $this->titular = $titular; return $this; }

    public function getTitularAlterno(): ?string { return $this->titularAlterno; }
    public function setTitularAlterno(?string $titularAlterno): self { $this->titularAlterno = $titularAlterno; return $this; }

    public function getNumero(): ?string { return $this->numero; }
    public function setNumero(?string $numero): self { $this->numero = $numero !== null ? trim($numero) : null; return $this; }

    public function getBanco(): ?string { return $this->banco; }
    public function setBanco(?string $banco): self { $this->banco = $banco; return $this; }

    public function getCci(): ?string { return $this->cci; }
    public function setCci(?string $cci): self { $this->cci = $cci; return $this; }

    public function getMoneda(): ?string { return $this->moneda; }
    public function setMoneda(?string $moneda): self { $this->moneda = $moneda !== null ? strtoupper(trim($moneda)) : null; return $this; }

    public function getDiasMinimos(): ?int { return $this->diasMinimos; }
    public function setDiasMinimos(?int $dias): self { $this->diasMinimos = $dias; return $this; }

    /**
     * ¿Llega a tiempo este medio?
     *
     * `$dias` a `null` significa «no se sabe cuándo llega» —una cotización sin fechas, un chat
     * sin reserva—: entonces pasan todos, igual que hace la audiencia cuando no sabe de dónde
     * paga. Esconder una opción por no saber es peor que ofrecerla de más.
     */
    public function llegaATiempo(?int $dias): bool
    {
        return $this->diasMinimos === null || $dias === null || $dias >= $this->diasMinimos;
    }

    public function getAudiencia(): FinAudienciaCobro { return $this->audiencia; }
    public function setAudiencia(FinAudienciaCobro $audiencia): self { $this->audiencia = $audiencia; return $this; }

    /** @return array<int, array{language: string, content: string}> */
    public function getNota(): array { return $this->nota ?? []; }

    public function setNota(?array $nota): self { $this->nota = $nota ?? []; return $this; }

    public function isActivo(): bool { return $this->activo; }
    public function setActivo(bool $activo): self { $this->activo = $activo; return $this; }

    public function getOrden(): int { return $this->orden; }
    public function setOrden(int $orden): self { $this->orden = $orden; return $this; }

    /**
     * La nota en un idioma, con caída al español.
     *
     * La caída existe porque la traducción es asíncrona: un medio recién creado tiene sólo el
     * español hasta que el listener pasa por él, y en ese hueco es mejor una nota en español
     * que ninguna.
     */
    public function getNotaEn(string $idioma): ?string
    {
        $porIdioma = [];

        foreach ($this->getNota() as $entrada) {
            $clave = strtolower((string) ($entrada['language'] ?? ''));
            $texto = trim((string) ($entrada['content'] ?? ''));

            if ($clave !== '' && $texto !== '') {
                $porIdioma[$clave] = $texto;
            }
        }

        return $porIdioma[strtolower($idioma)] ?? $porIdioma['es'] ?? null;
    }

    /**
     * Getter señuelo para que el listado del panel pueda pintar la nota en español.
     *
     * Devuelve vacío porque quien rellena la celda es el `formatValue()` del CRUD; esto sólo
     * existe para que EasyAdmin encuentre la propiedad. Mismo truco que
     * `PmsGuiaItem::getVirtualDescripcion()`.
     */
    public function getNotaEsVisual(): string
    {
        return '';
    }

    /** ¿Está listo para enseñárselo a alguien? Sin número no se ofrece, salvo el efectivo. */
    public function esOfrecible(): bool
    {
        return $this->activo
            && (!$this->tipo->exigeNumero() || ($this->numero !== null && $this->numero !== ''));
    }

    public function __toString(): string
    {
        $partes = array_filter([$this->tipo->label(), $this->banco, $this->moneda, $this->numero]);

        return implode(' · ', $partes);
    }
}
