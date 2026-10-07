<?php

declare(strict_types=1);

namespace App\Front\Comun\Service;

use App\Front\Comun\Entity\LibroReclamacion;
use App\Dto\Lee;
use App\Service\Config\Parametro;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Registra una hoja del Libro de Reclamaciones y manda las dos copias.
 *
 * El orden importa: **primero se guarda, después se avisa.** La hoja es el registro legal; un
 * correo que no sale se puede reenviar, una hoja que no se guardó porque el correo falló es una
 * reclamación perdida. Por eso un fallo de envío se registra en el log y no se propaga.
 */
final class LibroReclamacionesService
{
    /** Candado de MySQL para numerar: dos hojas a la vez no pueden llevarse el mismo número. */
    private const CANDADO = 'front_libro_reclamacion_correlativo';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire('%mailer_sender_email%')]
        private readonly mixed $remitente,
        #[Autowire('%front.correo%')]
        private readonly mixed $buzonOperador,
        #[Autowire('%front.marca%')]
        private readonly mixed $marca,
    ) {
    }

    public function registrar(LibroReclamacion $hoja, string $sitio, ?string $ip): void
    {
        $hoja->setSitio($sitio)->setIp($ip);

        $conexion = $this->em->getConnection();
        // Sin el candado, dos envíos simultáneos leen el mismo último número y el segundo
        // revienta contra el UNIQUE con el EntityManager ya cerrado: no habría reintento posible.
        // `GET_LOCK` devuelve 1 si lo consigue y 0 si vence la espera: seguir sin él sería numerar
        // a ciegas y reventar contra el UNIQUE con el EntityManager cerrado.
        if (Lee::entero($conexion->fetchOne('SELECT GET_LOCK(?, 10)', [self::CANDADO])) !== 1) {
            throw new \RuntimeException('No se pudo numerar la hoja de reclamación (candado ocupado); inténtalo de nuevo.');
        }
        try {
            $hoja->setCorrelativo(self::siguiente($hoja->getFecha(), $this->ultimoDelAnio($hoja->getFecha())));
            $this->em->persist($hoja);
            $this->em->flush();
        } finally {
            $conexion->fetchOne('SELECT RELEASE_LOCK(?)', [self::CANDADO]);
        }

        $this->avisar($hoja);
    }

    /**
     * «2026-00007» → «2026-00008»; cambio de año → «2027-00001».
     * Pública y estática para poder probarla sin base de datos.
     */
    public static function siguiente(\DateTimeImmutable $fecha, ?string $ultimo): string
    {
        $anio = $fecha->format('Y');
        $n = 0;
        if ($ultimo !== null && str_starts_with($ultimo, $anio . '-')) {
            $n = (int) substr($ultimo, strlen($anio) + 1);
        }
        return sprintf('%s-%05d', $anio, $n + 1);
    }

    private function ultimoDelAnio(\DateTimeImmutable $fecha): ?string
    {
        $ultimo = $this->em->getConnection()->fetchOne(
            'SELECT correlativo FROM front_libro_reclamacion WHERE correlativo LIKE ? ORDER BY correlativo DESC LIMIT 1',
            [$fecha->format('Y') . '-%'],
        );
        return is_string($ultimo) ? $ultimo : null;
    }

    /** @return array<string, string|bool|null> */
    private static function datosParaCorreo(LibroReclamacion $hoja): array
    {
        return [
            'correlativo' => $hoja->getCorrelativo(),
            'fecha' => $hoja->getFecha()->format('d/m/Y H:i'),
            'vence' => $hoja->getVenceRespuesta()->format('d/m/Y'),
            'sitio' => $hoja->getSitio(),
            'nombre' => $hoja->getConsumidorNombre(),
            'documento' => $hoja->getConsumidorDocumentoTipo() . ' ' . $hoja->getConsumidorDocumentoNumero(),
            'domicilio' => $hoja->getConsumidorDomicilio(),
            'telefono' => $hoja->getConsumidorTelefono(),
            'email' => $hoja->getConsumidorEmail(),
            'menor' => $hoja->isConsumidorEsMenor(),
            'apoderado' => $hoja->getApoderadoNombre(),
            'bien_tipo' => $hoja->getBienTipo()->value,
            'bien_descripcion' => $hoja->getBienDescripcion(),
            'monto' => $hoja->getBienMonto(),
            'tipo' => $hoja->getTipo()->value,
            'detalle' => $hoja->getDetalle(),
            'pedido' => $hoja->getPedido(),
        ];
    }

    private function avisar(LibroReclamacion $hoja): void
    {
        $remitente = new Address(
            Parametro::texto($this->remitente, 'mailer_sender_email'),
            Parametro::texto($this->marca, 'front.marca'),
        );
        $operador = Parametro::texto($this->buzonOperador, 'front.correo');
        // Sólo textos en el contexto: el correo va a la cola de Messenger serializado, y una
        // entidad de Doctrine dentro del contexto no sobrevive al viaje.
        $datos = self::datosParaCorreo($hoja);

        $envios = [
            // Copia al consumidor: la norma exige que reciba la hoja que presentó.
            (new TemplatedEmail())
                ->from($remitente)
                ->to($hoja->getConsumidorEmail())
                ->replyTo($operador)
                ->subject(sprintf('Libro de Reclamaciones — Hoja N.º %s', $hoja->getCorrelativo()))
                ->htmlTemplate('front/comun/libro/correo.html.twig')
                ->context(['hoja' => $datos, 'para_operador' => false]),
            (new TemplatedEmail())
                ->from($remitente)
                ->to($operador)
                ->replyTo($hoja->getConsumidorEmail())
                ->subject(sprintf('[Reclamación %s] %s — responder antes del %s', $hoja->getCorrelativo(), $hoja->getConsumidorNombre(), $hoja->getVenceRespuesta()->format('d/m/Y')))
                ->htmlTemplate('front/comun/libro/correo.html.twig')
                ->context(['hoja' => $datos, 'para_operador' => true]),
        ];

        foreach ($envios as $correo) {
            try {
                $this->mailer->send($correo);
            } catch (TransportExceptionInterface $e) {
                $this->logger->error('[libro-reclamaciones] No salió un correo de la hoja {correlativo}: {error}', [
                    'correlativo' => $hoja->getCorrelativo(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
