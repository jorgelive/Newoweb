<?php

declare(strict_types=1);

namespace App\Pms\Guia;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsGuiaItem;
use App\Pms\Entity\PmsReserva;
use App\Pms\Enum\PmsGuiaVisibilidad;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * El enlace con el que el EQUIPO le abre a un huésped un ítem `PorEnlace` de la guía: cómo dejar
 * un pago en la caja de arriba, cómo sacar la llave de emergencia.
 *
 * ── Por qué un enlace y no un ítem oculto de la guía ─────────────────────────
 * La guía se abre con el localizador. Esconder el ítem del menú no lo protege: quien tenga el
 * localizador —cualquier huésped— podría llegar a él, y lo que lleva es el código de la caja del
 * DINERO. Aquí la puerta es la decisión de una persona, cada vez: el enlace sólo existe cuando
 * alguien manda la plantilla que lo lleva (`{{ acceso_<codigo> }}`, ver
 * `PmsMessageDataResolver::enlacesDelEquipo()`).
 *
 * ── Por qué firmado y sin fila en la base ───────────────────────────────────
 * Las variables de plantilla no se calculan sólo al enviar: también en la vista previa
 * (`msg:plantilla:ver`) y en dos skills que leen la reserva. Con una fila por enlace, cada lectura
 * fabricaría un enlace válido guardado. Firmado (HMAC con `kernel.secret`), emitir no escribe
 * nada: el token lleva dentro reserva, ítem y caducidad, y sólo vale lo que firmó este servidor.
 * El precio es no poder revocar uno suelto; lo acota la caducidad y que al abrirlo se vuelve a
 * comprobar que la estancia sigue viva.
 *
 * ── Cuánto dura ───────────────────────────────────────────────────────────────
 * Hasta el final del día de salida de la estancia, como mínimo `HORAS_MINIMAS` y como máximo
 * `DIAS_MAXIMOS` desde que se emite. Una reserva cuya última estancia acabó hace más de
 * `DIAS_TRAS_LA_SALIDA` días no da enlace. El dato que enseña (el código, la foto, el vídeo) se resuelve AL ABRIR, no al emitir:
 * si la caja cambia de código, el enlace ya enviado enseña el nuevo.
 *
 * Ver `docs/PmsGuiaHuesped.md` §7 sexies.
 */
final readonly class PmsGuiaEnlaceDelEquipo
{
    /** Lo mínimo que vale un enlace, aunque la estancia acabe antes. */
    public const int HORAS_MINIMAS = 48;

    /**
     * Lo máximo, aunque la estancia acabe después. Sin tope, un `pago_efectivo_caja` mandado al reservar
     * valía hasta el check-out —meses— y no se puede revocar uno suelto.
     */
    public const int DIAS_MAXIMOS = 14;

    /** Pasados estos días desde la última salida, la reserva ya no da enlace: no hay estancia viva. */
    public const int DIAS_TRAS_LA_SALIDA = 2;

    /** Prefijo de la variable de plantilla: `{{ acceso_llave_emergencia }}`. */
    public const string PREFIJO_VARIABLE = 'acceso_';

    /**
     * La variable de plantilla de un ítem: `llave-emergencia` → `acceso_llave_emergencia`.
     *
     * El código de ficha va con guiones (`PmsGuiaItem::$codigo`) y una variable no puede: Meta
     * sólo admite `[a-z0-9_]` en un parámetro con nombre.
     */
    public static function variable(string $codigo): string
    {
        return self::PREFIJO_VARIABLE . str_replace('-', '_', $codigo);
    }

    /** Separa el HMAC de este uso de cualquier otro que firme con el mismo secreto. */
    private const string DOMINIO_DE_FIRMA = 'pms-guia-enlace-del-equipo|v1|';

    public function __construct(
        private EntityManagerInterface $em,
        #[Autowire('%kernel.secret%')]
        private string $secreto,
        #[Autowire('%pax_host_url%')]
        private string $paxHostUrl,
    ) {
    }

    /**
     * Los ítems que se abren con enlace del equipo, por código. Sin código no hay variable que
     * escribir en una plantilla, así que no cuentan.
     *
     * @return array<string, PmsGuiaItem>
     */
    public function items(): array
    {
        /** @var list<PmsGuiaItem> $items */
        $items = $this->em->getRepository(PmsGuiaItem::class)
            ->findBy(['visibilidad' => PmsGuiaVisibilidad::PorEnlace], ['codigo' => 'ASC']);

        $porCodigo = [];

        foreach ($items as $item) {
            $codigo = $item->getCodigo();

            if ($codigo !== null && $codigo !== '') {
                $porCodigo[$codigo] = $item;
            }
        }

        return $porCodigo;
    }

    /**
     * El token para esta reserva y este ítem, o `null` si no procede: el ítem no es `PorEnlace`,
     * o la reserva no tiene ninguna estancia que se pueda servir.
     */
    public function emitir(PmsReserva $reserva, PmsGuiaItem $item, ?\DateTimeImmutable $ahora = null): ?string
    {
        $codigo = $item->getCodigo();
        $reservaId = $reserva->getId();

        if ($item->getVisibilidad() !== PmsGuiaVisibilidad::PorEnlace
            || $codigo === null || $codigo === ''
            || $reservaId === null
        ) {
            return null;
        }

        $evento = self::estanciaDe($reserva, $ahora);

        if ($evento === null) {
            return null;
        }

        $carga = sprintf('%s|%s|%d', $reservaId->toRfc4122(), $codigo, $this->caducidad($evento, $ahora)->getTimestamp());

        return self::b64($carga) . '.' . self::b64($this->firma($carga));
    }

    /**
     * Si el token lo firmó este servidor, valga aún o no. Para el freno: un enlace legítimo que
     * caducó no es alguien probando tokens, y contarlo como fallo dejaba sin guía a quien lo
     * reabría (el contador es el mismo que el de la guía, `PmsGuiaThrottle`).
     */
    public function firmaValida(string $token): bool
    {
        $partes = explode('.', $token);

        if (count($partes) !== 2) {
            return false;
        }

        $carga = self::desdeB64($partes[0]);
        $firma = self::desdeB64($partes[1]);

        return $carga !== null && $firma !== null && hash_equals($this->firma($carga), $firma);
    }

    /** La URL que se le manda al huésped. */
    public function url(string $token): string
    {
        return rtrim($this->paxHostUrl, '/') . '/' . self::ruta($token);
    }

    /** La ruta sin dominio (`acceso/<token>`): el final variable del botón de Meta. */
    public static function ruta(string $token): string
    {
        return 'acceso/' . $token;
    }

    /**
     * Lo que abre un token, o `null` si no vale. Comprueba, en este orden: la firma, la
     * caducidad, que el ítem siga siendo `PorEnlace` y que la reserva siga teniendo una estancia
     * viva. Cualquier fallo es el mismo `null`: a quien prueba tokens no se le dice cuál falló.
     */
    public function leer(string $token, ?\DateTimeImmutable $ahora = null): ?EnlaceDelEquipoAbierto
    {
        $partes = explode('.', $token);

        if (count($partes) !== 2) {
            return null;
        }

        $carga = self::desdeB64($partes[0]);
        $firma = self::desdeB64($partes[1]);

        if ($carga === null || $firma === null || !hash_equals($this->firma($carga), $firma)) {
            return null;
        }

        $campos = explode('|', $carga);

        if (count($campos) !== 3 || !ctype_digit($campos[2])) {
            return null;
        }

        [$reservaId, $codigo, $expira] = $campos;
        $expiraEn = (new \DateTimeImmutable())->setTimestamp((int) $expira);

        if ($expiraEn < ($ahora ?? new \DateTimeImmutable())) {
            return null;
        }

        $item = $this->em->getRepository(PmsGuiaItem::class)->findOneBy(['codigo' => $codigo]);

        // Si el ítem dejó de ser `PorEnlace` —alguien lo publicó en la guía, o lo bajó a otro
        // nivel—, el enlace muere: lo que firmó era «este contenido reservado», no «este código».
        if (!$item instanceof PmsGuiaItem || $item->getVisibilidad() !== PmsGuiaVisibilidad::PorEnlace) {
            return null;
        }

        $reserva = $this->em->getRepository(PmsReserva::class)->find($reservaId);

        if (!$reserva instanceof PmsReserva || $reserva->isCancelada()) {
            return null;
        }

        $evento = self::estanciaDe($reserva, $ahora);

        if ($evento === null) {
            return null;
        }

        return new EnlaceDelEquipoAbierto($reserva, $evento, $item, $expiraEn);
    }

    /**
     * La estancia que da contexto (casita, fechas): la que está en curso, si no la próxima, si no
     * la última. Sólo entre las que la guía sirve (`getEventosActivosGuia()`): una cancelada o
     * con la guía apagada no da enlace.
     */
    private static function estanciaDe(PmsReserva $reserva, ?\DateTimeImmutable $ahora): ?PmsEventoCalendario
    {
        $eventos = array_values(array_filter(
            $reserva->getEventosActivosGuia(),
            static fn (PmsEventoCalendario $e): bool => $e->getPmsUnidad() !== null
                && $e->getInicio() !== null && $e->getFin() !== null,
        ));

        if ($eventos === []) {
            return null;
        }

        foreach ($eventos as $evento) {
            // Hora de pared del establecimiento contra «ahora» en su zona (docs/ZonasHorarias.md).
            $zona = $evento->zonaHoraria();
            $ya = $ahora ?? new \DateTimeImmutable('now', $zona);
            $fin = new \DateTimeImmutable($evento->getFinOrFail()->format('Y-m-d') . ' 23:59:59', $zona);

            if ($ya <= $fin) {
                return $evento;
            }
        }

        // Todas terminadas: la última, sólo si acabó hace poco (un pago que se deja al irse).
        // Una reserva de hace meses no da enlace, ni al emitir ni al abrir.
        $ultima = $eventos[count($eventos) - 1];
        $zona = $ultima->zonaHoraria();
        $limite = (new \DateTimeImmutable($ultima->getFinOrFail()->format('Y-m-d') . ' 23:59:59', $zona))
            ->modify(sprintf('+%d days', self::DIAS_TRAS_LA_SALIDA));

        return ($ahora ?? new \DateTimeImmutable('now', $zona)) <= $limite ? $ultima : null;
    }

    private function caducidad(PmsEventoCalendario $evento, ?\DateTimeImmutable $ahora): \DateTimeImmutable
    {
        $zona = $evento->zonaHoraria();
        $ya = $ahora ?? new \DateTimeImmutable('now', $zona);
        $finDeEstancia = new \DateTimeImmutable($evento->getFinOrFail()->format('Y-m-d') . ' 23:59:59', $zona);
        $minimo = $ya->modify(sprintf('+%d hours', self::HORAS_MINIMAS));
        $maximo = $ya->modify(sprintf('+%d days', self::DIAS_MAXIMOS));

        return max(min($finDeEstancia, $maximo), $minimo);
    }

    private function firma(string $carga): string
    {
        return hash_hmac('sha256', self::DOMINIO_DE_FIRMA . $carga, $this->secreto, true);
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function desdeB64(string $texto): ?string
    {
        if ($texto === '' || preg_match('/^[A-Za-z0-9_-]+$/', $texto) !== 1) {
            return null;
        }

        $bytes = base64_decode(strtr($texto, '-_', '+/'), true);

        return $bytes === false ? null : $bytes;
    }
}
