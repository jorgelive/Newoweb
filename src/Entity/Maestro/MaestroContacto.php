<?php

declare(strict_types=1);

namespace App\Entity\Maestro;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Una PERSONA con la que hablamos: su número, su nombre, su idioma.
 *
 * ── Qué problema resuelve ───────────────────────────────────────────────────
 * Hoy los mismos datos de una persona están escritos en tres sitios con nombres distintos:
 *
 * ```
 * PmsReserva        nombreCliente, apellidoCliente, telefono, telefono2, emailCliente, pais, idioma
 * CotizacionFile    nombreGrupo,                    telefono,            email,               idioma
 * MessageConversation  guestName,                   guestPhone,                               idioma
 * ```
 *
 * Es el mismo señor tecleado tres veces. Corregirle el teléfono en la reserva no lo corrige en
 * su expediente de tours ni en su conversación, y nadie se entera hasta que un mensaje sale al
 * número viejo. Con un contacto, se **selecciona** en vez de teclearse, y se corrige una vez.
 *
 * ── Por qué en `Maestro` y no en un módulo ──────────────────────────────────
 * Porque no es de ninguno: la misma persona reserva una casita, contrata un tour y escribe por
 * WhatsApp. Colgarlo de `Pms` obligaría a `Cotizacion` a depender del PMS para saber a quién le
 * está cotizando, que es exactamente la dependencia que el trabajo de dominios está quitando.
 * `App\Entity\Maestro` ya es el sitio de lo transversal —país, moneda, idioma— y no hubo que
 * mover ningún namespace.
 *
 * ── El teléfono NO es único, y es deliberado ────────────────────────────────
 * ⚠️ Lleva índice, no restricción de unicidad. Dos razones, y la segunda es la de peso:
 *
 *  1. Un matrimonio comparte número; una agencia usa el suyo para los grupos que manda. Son
 *     personas distintas con el mismo teléfono, y eso es un dato correcto, no un duplicado.
 *  2. La migración que puebla esta tabla sale de columnas **sucias** —números de las OTA con el
 *     código de país duplicado, espacios, prefijos— que llevan años acumulándose. Una
 *     restricción de unicidad haría fallar la migración entera por un puñado de filas malas, y
 *     el arreglo sería a mano y contra reloj.
 *
 * La deduplicación es responsabilidad de quien resuelve un número entrante, con el MISMO
 * criterio que ya usa `PmsReservaRepository::findVivasByTelefono()` —exacto o los últimos 8
 * dígitos—, no de una restricción de esquema que no sabe de prefijos internacionales.
 */
#[ORM\Entity]
#[ORM\Table(name: 'maestro_contacto')]
#[ORM\Index(columns: ['telefono'], name: 'idx_contacto_telefono')]
#[ORM\Index(columns: ['telefono2'], name: 'idx_contacto_telefono2')]
#[ORM\Index(columns: ['email'], name: 'idx_contacto_email')]
#[ORM\HasLifecycleCallbacks]
class MaestroContacto
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private ?string $nombre = null;

    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private ?string $apellido = null;

    /**
     * En dígitos y normalizado, como se guarda en `pms_reserva.telefono` y en
     * `msg_conversation.guest_phone`: es lo que se compara con el remitente de WhatsApp, y un
     * «+51 987…» con espacios no casa con nada.
     */
    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $telefono = null;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $telefono2 = null;

    /**
     * A cuál de los dos escribimos NOSOTROS. **No dice desde cuál escribe él**: quien llama por
     * teléfono entrante busca en los dos sin mirar este flag. Mismo significado y mismo nombre
     * que `PmsReserva::$telefono2EsPrincipal`, para que migrar sea copiar.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $telefono2EsPrincipal = false;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\ManyToOne(targetEntity: MaestroPais::class)]
    #[ORM\JoinColumn(name: 'pais_id', referencedColumnName: 'id', nullable: true)]
    private ?MaestroPais $pais = null;

    /**
     * En qué idioma se le escribe. Vive aquí y no en cada conversación porque es de la persona:
     * quien habla portugués lo habla en su reserva y en su tour.
     */
    #[ORM\ManyToOne(targetEntity: MaestroIdioma::class)]
    #[ORM\JoinColumn(name: 'idioma_id', referencedColumnName: 'id', nullable: true)]
    private ?MaestroIdioma $idioma = null;

    /**
     * Notas internas sobre la persona, no sobre un asunto suyo: «prefiere que le escriban por
     * la tarde», «es la agencia que manda los grupos de Lima».
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $nota = null;

    public function __construct()
    {
        $this->initializeId();
    }

    /** El nombre completo, o el teléfono cuando no hay nombre: algo con lo que referirse a él. */
    public function __toString(): string
    {
        return $this->nombreCompleto() ?: ($this->telefono ?? 'Contacto sin datos');
    }

    public function nombreCompleto(): string
    {
        return trim(sprintf('%s %s', $this->nombre ?? '', $this->apellido ?? ''));
    }

    /**
     * El número al que se le escribe, según la elección.
     *
     * 🪞 Espejo de `PmsReserva::getTelefonoContacto()`. El flag sólo decide el ORDEN de
     * preferencia: si el preferido está vacío se cae al otro, para que marcar como principal un
     * número que luego se borra no deje al contacto sin forma de contacto.
     */
    public function telefonoPreferido(): ?string
    {
        $preferido = trim((string) ($this->telefono2EsPrincipal ? $this->telefono2 : $this->telefono));
        $alterno = trim((string) ($this->telefono2EsPrincipal ? $this->telefono : $this->telefono2));

        return ($preferido ?: $alterno) ?: null;
    }

    public function getId(): ?Uuid { return $this->id; }

    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(?string $nombre): self { $this->nombre = $nombre; return $this; }

    public function getApellido(): ?string { return $this->apellido; }
    public function setApellido(?string $apellido): self { $this->apellido = $apellido; return $this; }

    public function getTelefono(): ?string { return $this->telefono; }
    public function setTelefono(?string $telefono): self { $this->telefono = $telefono; return $this; }

    public function getTelefono2(): ?string { return $this->telefono2; }
    public function setTelefono2(?string $telefono2): self { $this->telefono2 = $telefono2; return $this; }

    public function isTelefono2EsPrincipal(): bool { return $this->telefono2EsPrincipal; }
    public function setTelefono2EsPrincipal(bool $val): self { $this->telefono2EsPrincipal = $val; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): self { $this->email = $email; return $this; }

    public function getPais(): ?MaestroPais { return $this->pais; }
    public function setPais(?MaestroPais $pais): self { $this->pais = $pais; return $this; }

    public function getIdioma(): ?MaestroIdioma { return $this->idioma; }
    public function setIdioma(?MaestroIdioma $idioma): self { $this->idioma = $idioma; return $this; }

    public function getNota(): ?string { return $this->nota; }
    public function setNota(?string $nota): self { $this->nota = $nota; return $this; }
}
