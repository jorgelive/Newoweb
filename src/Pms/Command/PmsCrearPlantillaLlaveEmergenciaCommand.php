<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Message\Entity\MessageTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * La plantilla con las instrucciones de la LLAVE DE EMERGENCIA, para no escribirlas a mano cada vez.
 *
 * ── Quién la manda, y por qué no el agente (decisión de Jorge, 21/10/2026) ──────
 * Las llaves de emergencia están en la caja fuerte de ARRIBA —la misma donde los huéspedes dejan
 * los pagos en efectivo—, en una cartuchera celeste, una por casita («emergencia 1», «emergencia
 * 2»…). Darla obliga a dar el código de la caja del DINERO, así que lo decide una persona: el
 * agente escala («no estaba su llave en el check-in» / «perdió su llave, cobrar») y el operador
 * manda esta plantilla. Por eso `envioManual` y un `agenteUso` que le dice al agente que no la
 * mande por su cuenta, igual que `pago_efectivo_caja`, que es su hermana.
 *
 * ── La misma estructura que `pago_efectivo_caja` ────────────────────────────────────
 * Texto para Beds24 y para WhatsApp dentro de la ventana. (Desde el 21/10/2026 también oficial de Meta, por
 * `PmsPlantillasCajaAMetaCommand`: el equipo puede pedir que se mande fuera de ventana. Lo de abajo
 * era el motivo original para no hacerlo.) WhatsApp NO como plantilla oficial de
 * Meta (llevaría el código de la caja del dinero dentro de un texto revisado por Meta, y se manda
 * con el huésped escribiendo, o sea con ventana). Los códigos, la foto y el vídeo de la caja son
 * variables (`PmsMessageDataResolver::CLAVES_DE_ACCESO`): se resuelven al enviar, nunca tecleados.
 *
 * Se escribe en español y `AutoTranslationEventListener` rellena los otros seis idiomas al
 * guardar: por eso entra por el ORM y no por SQL. Idempotente por el código, con `--dry-run`.
 * Nace `hidden` porque es de una vez.
 *
 *   php bin/console app:pms:crear-plantilla-llave-emergencia --dry-run
 *
 * @phpstan-import-type BloqueDeCanal from MessageTemplate
 */
#[AsCommand(
    name: 'app:pms:crear-plantilla-llave-emergencia',
    description: 'Crea la plantilla «llave_emergencia» (instrucciones de la llave de emergencia). Idempotente.',
    hidden: true,
)]
final class PmsCrearPlantillaLlaveEmergenciaCommand extends Command
{
    public const string CODIGO = 'llave_emergencia';

    /**
     * El cuerpo. La casita va por su nombre (`{{ room_name }}`, «Casita 3»): el número de la
     * cartuchera es el de la casita, y no hay una variable de plantilla sólo con el número.
     */
    private const string CUERPO = <<<'TXT'
        Hola {{ guest_name }}, para entrar a {{ room_name }} usa la llave de emergencia:

        1️⃣ En el pasadizo hay dos cajas fuertes digitales. La de la llave de emergencia es la de ARRIBA — la de abajo es la de las llaves de siempre.
        2️⃣ Marca el código {{ codigo_caja_dinero }} y gira la perilla hacia la derecha para abrir.
        3️⃣ Dentro hay una cartuchera celeste. Toma la llave con la etiqueta «emergencia» y el número de tu casita ({{ room_name }}).
        4️⃣ Cierra la caja girando la perilla hacia la izquierda y asegúrate de que quedó bien cerrada.

        📷 Así es la caja: {{ foto_caja_dinero }}
        🎥 Cómo se abre: {{ video_caja_dinero }}
        TXT;

    /**
     * La etiqueta FÍSICA de la llave, que está escrita en español y no se traduce.
     *
     * ⚠️ La traducción automática la tradujo: en inglés salió «the key marked "emergency"», y un
     * huésped buscaría una etiqueta que no existe. Se restaura tras traducir, en cada idioma, en la
     * línea 3️⃣ —la de la cartuchera—, sustituyendo lo que quedó entre comillas.
     */
    private const string ETIQUETA = '«emergencia»';

    /** Lo que lee el agente del panel: es instrucción, no se traduce. */
    private const string AGENTE_USO = 'Instrucciones para sacar la LLAVE DE EMERGENCIA de la caja fuerte de arriba (la '
        . 'del dinero). La manda un operador cuando el huésped no puede entrar porque su llave no estaba en la caja '
        . 'en el check-in o porque la perdió. EL HUÉSPED NO PUEDE PEDIRLA por su cuenta: si la pide él, no la '
        . 'mandes y escala al equipo. Si la perdió, el equipo además hace la cobranza.';

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Sólo dice qué crearía.')] bool $dryRun = false,
    ): int {
        $existente = $this->em->getRepository(MessageTemplate::class)->findOneBy(['code' => self::CODIGO]);

        if ($existente !== null) {
            $fijadas = $dryRun ? 0 : $this->restaurarEtiqueta($existente);
            $io->success(sprintf('«%s» ya existe; etiqueta restaurada en %d idioma(s).', self::CODIGO, $fijadas));

            return Command::SUCCESS;
        }

        $io->section('Se creará «' . self::CODIGO . '»');
        $io->writeln(self::CUERPO);

        if ($dryRun) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        $cuerpo = [['language' => 'es', 'content' => self::CUERPO]];

        $plantilla = (new MessageTemplate())
            ->setCode(self::CODIGO)
            ->setName('Instrucciones llave de emergencia')
            ->setContextType('pms_reserva')
            ->setEnvioManual(true)
            ->setAgenteUso(self::AGENTE_USO)
            ->setAutoenvioHabilitada(false)
            ->setBeds24Tmpl(['is_active' => true, 'body' => $cuerpo])
            ->setWhatsappLinkTmpl(['is_active' => true, 'body' => $cuerpo])
            // Activa para elegirla en el chat, sin cuerpo propio ni oficial en Meta: igual que
            // `pago_efectivo_caja`. Sale como texto dentro de la ventana.
            ->setWhatsappMetaTmpl(['is_active' => true, 'is_official_meta' => false, 'body' => []])
            ->setEmailTmpl(['is_active' => false, 'subject' => [], 'body' => []]);

        $this->em->persist($plantilla);
        // Primero se crea y el listener traduce; después se restaura la etiqueta física.
        $this->em->flush();
        $fijadas = $this->restaurarEtiqueta($plantilla);

        $io->success(sprintf('«%s» creada, con sus siete idiomas; etiqueta restaurada en %d.', self::CODIGO, $fijadas));

        return Command::SUCCESS;
    }

    /**
     * Vuelve a poner `ETIQUETA` en la línea de la cartuchera de cada idioma. Hace flush.
     *
     * Conserva el `origenHash` de cada fila y apaga la traducción para este guardado: así el
     * listener no traduce otra vez encima (mismo método que `PaxCrearTextosGuiaCommand::fijarAMano()`).
     */
    private function restaurarEtiqueta(MessageTemplate $plantilla): int
    {
        $fijadas = 0;
        $plantilla->setBeds24Tmpl(self::conEtiqueta($plantilla->getBeds24Tmpl(), $fijadas));
        $plantilla->setWhatsappLinkTmpl(self::conEtiqueta($plantilla->getWhatsappLinkTmpl(), $fijadas));
        // Sin esto, el listener volvería a traducir encima de lo que acabamos de fijar.
        $plantilla->setEjecutarTraduccion(false);
        $this->em->flush();

        return $fijadas;
    }

    /**
     * @param BloqueDeCanal|null $canal
     *
     * @return BloqueDeCanal|null
     */
    public static function conEtiqueta(?array $canal, int &$fijadas): ?array
    {
        if ($canal === null || !isset($canal['body'])) {
            return $canal;
        }

        foreach ($canal['body'] as $i => $fila) {
            $texto = $fila['content'] ?? null;

            if ($texto === null) {
                continue;
            }

            $nuevo = (string) preg_replace_callback(
                '/^(3️⃣[^\n]*?)(["«„“\'][^"»“”\'\n]{3,30}["»“”\'])/mu',
                static fn (array $m): string => $m[1] . self::ETIQUETA,
                $texto,
                1,
            );

            if ($nuevo !== $texto) {
                $canal['body'][$i]['content'] = $nuevo;
                ++$fijadas;
            }
        }

        return $canal;
    }
}
