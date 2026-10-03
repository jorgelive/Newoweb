<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Agent\Entity\AutoResponderRule;
use App\Message\Entity\MessageTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Los cuatro botones de hora que preguntan en vez de pasar al agente: su respuesta fija y su regla.
 *
 * | botón | payload | respuesta | aviso si no dice la hora en 2 h |
 * |---|---|---|---|
 * | Necesito salir tarde (y el viejo «Necesito más tiempo») | `CMD_SALIDA_MAS_TARDE` | `pedir_hora_salida_tarde` | sí |
 * | Saldré antes | `CMD_SALIDA_ANTES` | `pedir_hora_salida_antes` | no: no hay nada que decidir |
 * | Necesito llegar antes | `CMD_LLEGADA_ANTES` | `pedir_hora_llegada_antes` | sí |
 * | Llegaré más tarde | `CMD_LLEGADA_DESPUES` | `pedir_hora_llegada_tarde` | no |
 *
 * Textos de Jorge (02/10/2026). Las respuestas sólo salen dentro de la ventana —el botón acaba de
 * abrirla—, así que no se suben a Meta. Las reglas ya existían con `pasar_al_agente`: se repuntan a
 * `pedir_hora`. Ver `PedirHoraActionHandler`.
 *
 *   php bin/console msg:plantillas:pedir-hora [--dry-run]
 */
#[AsCommand(
    name: 'msg:plantillas:pedir-hora',
    description: 'Crea las respuestas fijas de los botones de hora y repunta sus reglas a pedir_hora.',
    hidden: true,
)]
final class MessageCrearPedirHoraCommand extends Command
{
    /** @var array<string, array{codigo: string, nombre: string, texto: string, extremo: string, pedido: string, avisar: bool}> */
    private const array BOTONES = [
        'CMD_SALIDA_MAS_TARDE' => [
            'codigo' => 'pedir_hora_salida_tarde',
            'nombre' => 'Respuesta: necesita salir tarde (pide la hora)',
            'texto' => 'Claro, {{guest_name}}. ¿A qué hora necesitarías salir? Lo consulto con el equipo: depende de la disponibilidad.',
            'extremo' => 'salida',
            'pedido' => 'Pide salir más tarde del check-out, sin hora todavía',
            'avisar' => true,
        ],
        'CMD_SALIDA_ANTES' => [
            'codigo' => 'pedir_hora_salida_antes',
            'nombre' => 'Respuesta: saldrá antes (pide la hora)',
            'texto' => '¡Perfecto! ¿A qué hora piensas salir? Así lo dejamos anotado.',
            'extremo' => 'salida',
            'pedido' => 'Pide salir antes, sin hora todavía',
            'avisar' => false,
        ],
        'CMD_LLEGADA_ANTES' => [
            'codigo' => 'pedir_hora_llegada_antes',
            'nombre' => 'Respuesta: necesita llegar antes (pide la hora)',
            'texto' => 'Claro, {{guest_name}}. ¿A qué hora necesitarías llegar? Lo consulto con el equipo: depende de la disponibilidad. Si llegas antes, puedes dejarnos tu equipaje.',
            'extremo' => 'llegada',
            'pedido' => 'Pide entrar antes del check-in, sin hora todavía',
            'avisar' => true,
        ],
        'CMD_LLEGADA_DESPUES' => [
            'codigo' => 'pedir_hora_llegada_tarde',
            'nombre' => 'Respuesta: llegará más tarde (pide la hora)',
            'texto' => '¡Perfecto! ¿A qué hora piensas llegar? Así lo dejamos anotado.',
            'extremo' => 'llegada',
            'pedido' => 'Pide entrar después del check-in, sin hora todavía',
            'avisar' => false,
        ],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo dice qué haría');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simular = (bool) $input->getOption('dry-run');
        $plantillas = $this->em->getRepository(MessageTemplate::class);
        $reglas = $this->em->getRepository(AutoResponderRule::class);

        foreach (self::BOTONES as $payload => $b) {
            if ($plantillas->findOneBy(['code' => $b['codigo']]) === null) {
                $io->text(sprintf('+ plantilla %s', $b['codigo']));
                if (!$simular) {
                    $this->em->persist($this->respuesta($b['codigo'], $b['nombre'], $b['texto']));
                    // `AutoTranslate` corre en `prePersist`: los siete idiomas al guardarla.
                    $this->em->flush();
                }
            } else {
                $io->text(sprintf('· plantilla %s ya existe: no se toca', $b['codigo']));
            }

            $parametros = [
                'extremo' => $b['extremo'],
                'pedido' => $b['pedido'],
                'plantilla_respuesta' => $b['codigo'],
                'avisar_sin_respuesta' => $b['avisar'] ? 'si' : 'no',
            ];

            $regla = $reglas->findOneBy(['triggerValue' => $payload]);
            $io->text(sprintf('%s regla %s → pedir_hora', $regla === null ? '+' : '~', $payload));

            if ($simular) {
                continue;
            }

            if ($regla === null) {
                $regla = (new AutoResponderRule())->setTriggerValue($payload)->setIsActive(true);
                $regla->initializeId();
                $this->em->persist($regla);
            }

            $regla->setActionType('pedir_hora')->setActionParameters($parametros);
        }

        if (!$simular) {
            $this->em->flush();
            $io->success('Botones de hora listos.');
        }

        return Command::SUCCESS;
    }

    private function respuesta(string $codigo, string $nombre, string $texto): MessageTemplate
    {
        $cuerpo = [['language' => 'es', 'content' => $texto]];

        return (new MessageTemplate())
            ->setCode($codigo)
            ->setName($nombre)
            ->setContextType('pms_reserva')
            ->setAllowedSources([])
            ->setAutoenvioHabilitada(false)
            ->setAgenteUso('Interna: la manda SOLA un botón de hora (aviso de salida / guía de llegada). No la envíes tú.')
            ->setBeds24Tmpl(['is_active' => false, 'body' => []])
            ->setWhatsappLinkTmpl(['disable_meta_buttons' => true, 'body' => $cuerpo])
            // Activa para WhatsApp pero NO oficial: sólo dentro de la ventana, que es donde está
            // quien acaba de pulsar el botón. No hay nada que subir a Meta.
            ->setWhatsappMetaTmpl(['is_active' => true, 'is_official_meta' => false, 'header' => [], 'footer' => [], 'body' => $cuerpo, 'buttons_map' => []])
            ->setEmailTmpl(['is_active' => false, 'subject' => [], 'body' => []]);
    }
}
