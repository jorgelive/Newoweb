<?php

declare(strict_types=1);

namespace App\Agent\Command;

use Doctrine\ORM\EntityManagerInterface;
use App\Service\Ensayo\ModoEnsayo;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use App\Message\Entity\MessageConversation;
use App\Agent\Access\AsuntosDelHiloParaElAgente;
use App\Agent\Access\GuardiaDeSkills;
use App\Agent\Access\AgentActor;
use App\Agent\Access\AgentActorFactory;
use App\Agent\Provider\Dto\LlamadaAHerramienta;
use App\Agent\Skill\SkillRegistry;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Roles;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Ejecuta UNA skill directamente, sin modelo y sin gastar un céntimo.
 *
 * ```
 * php bin/console app:agent:skill listar_entradas_salidas '{"tipo":"salidas","dias":5}'
 * php bin/console app:agent:skill buscar_reserva '{"busqueda":"Acuña"}'
 * php bin/console app:agent:skill consultar_mi_reserva '{}' --contexto=<uuid-reserva>
 * ```
 *
 * Separa dos preguntas que conviene no mezclar cuando algo va mal: **¿falla la skill o falla
 * el modelo?** Si aquí devuelve lo que debe, el problema está en el prompt o en la
 * descripción de la skill, no en los datos.
 *
 * Es además la forma de ver lo que el modelo ve de verdad: la salida es literalmente el JSON
 * que recibe como resultado de la herramienta.
 */
#[AsCommand(
    name: 'app:agent:skill',
    description: 'Ejecuta una skill del agente directamente, sin pasar por el modelo.',
)]
final class AgentSkillCommand extends Command
{
    public function __construct(
        private readonly SkillRegistry $registro,
        private readonly UserRepository $usuarios,
        private readonly AgentActorFactory $actores,
        private readonly GuardiaDeSkills $guardia,
        private readonly EntityManagerInterface $em,
        private readonly ModoEnsayo $ensayo,
        private readonly AsuntosDelHiloParaElAgente $asuntosDelHilo,
        private readonly EnlacesDeConversacion $enlaces,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Nombre de la skill', name: 'skill')]
        string $nombre,
        #[Argument('Parámetros en JSON', name: 'entrada')]
        string $json = '{}',
        #[Option('UUID de reserva, para las skills acotadas al contexto')]
        ?string $contexto = null,
        #[Option('Ejecutar con ROLE_HUESPED en vez de SUPER_ADMIN')]
        bool $comoHuesped = false,
        #[Option('Ejecutar con ROLE_PROSPECTO: quien pregunta sin reserva ninguna')]
        bool $comoProspecto = false,
        #[Option('UUID del HILO en curso. No es el contexto: no autoriza a leer nada, solo dice en qué conversación se está. Es lo que necesita escalar_al_equipo')]
        ?string $conversacion = null,
        #[Option('Username de un usuario REAL: ejecuta con su identidad y sus roles', name: 'usuario')]
        ?string $username = null,
        #[Option('Simular: todo lo que escriba la skill se DESHACE al terminar, y ni push ni Mercure (ModoEnsayo)')]
        bool $ensayo = false,
    ): int {
        $skill = $this->registro->buscar($nombre);

        if ($skill === null) {
            $io->error(sprintf('No existe la skill "%s".', $nombre));
            $io->listing(array_map(static fn ($s) => $s->nombre(), $this->registro->todas()));

            return Command::INVALID;
        }

        $decodificada = json_decode($json, true);
        if (!is_array($decodificada)) {
            $io->error('Los parámetros deben ser un objeto JSON válido.');
            return Command::INVALID;
        }

        // Como la manda el modelo: un objeto, y por nombre. Ver LlamadaAHerramienta::objetoJson().
        $entrada = LlamadaAHerramienta::objetoJson($decodificada);

        $conversacion = $conversacion !== null ? trim($conversacion) : null;
        $conversacion = $conversacion === '' ? null : $conversacion;

        if ($username !== null) {
            $usuario = $this->usuarios->findOneBy(['username' => $username]);

            if ($usuario === null) {
                $io->error(sprintf('No existe el usuario "%s".', $username));
                return Command::INVALID;
            }

            // Por la factoría: con los roles literales, un usuario real con ROLE_*_DELETE no
            // pasaría un control que pida ROLE_*_WRITE y la prueba mentiría.
            $actor = $contexto !== null
                ? $this->actores->delEquipoPorChat($usuario, 'cli', 'pms_reserva', $contexto)
                : $this->actores->delPanel($usuario);
        } elseif ($comoProspecto) {
            // Sin contexto ni aunque se pase `--contexto`: un prospecto sin contexto es la
            // definición, no una limitación de la prueba. El HILO sí se le pasa: es lo único
            // que le permite escalar, y sin él esta prueba no podía reproducir el camino real
            // —que es justo donde estaba el bug—.
            // Por la factoría: es la que puebla `dominios()`, y probar una skill con un actor
            // que no los lleva mide un catálogo que en producción no existe.
            $actor = $this->actores->prospecto('cli', $conversacion);
        } elseif ($comoHuesped) {
            // 🗂️ Con el HILO, el huésped se arma como en el chat: la reserva de por defecto es la en
            // curso del hilo y conoce los demás asuntos por clave (`AsuntosDelHiloParaElAgente`, la
            // misma pieza que `AiConversationProcessor`). Sin hilo, el de siempre.
            $hilo = $conversacion !== null ? $this->em->getRepository(MessageConversation::class)->find($conversacion) : null;
            $contexto ??= $hilo !== null ? $this->enlaces->asuntoEnCurso($hilo)?->getContextId() : null;
            $actor = $this->actores->huesped('cli', 'pms_reserva', $contexto, $conversacion);

            if ($hilo !== null) {
                $actor = $actor->conAsuntosDelHilo($this->asuntosDelHilo->de($hilo));
                $io->comment(sprintf('Asuntos del hilo: %s', implode(' · ', array_map(
                    static fn ($a): string => sprintf('%s=%s%s', $a->clave, $a->etiqueta, $a->porDefecto ? ' (por defecto)' : ''),
                    $actor->asuntosDelHilo(),
                )) ?: 'uno solo'));
            }
        } else {
            $actor = $this->actorAdmin($contexto);
        }

        // Por el guardián y no comprobando los roles aquí: era la misma política escrita dos
        // veces, y la copia de la CLI no sabía nada del PIN. Ver GuardiaDeSkills.
        $bloqueo = $this->guardia->motivoDeBloqueo($skill, $actor);

        if ($bloqueo !== null) {
            $io->error($bloqueo);

            return Command::FAILURE;
        }

        $inicio = microtime(true);

        // 🧪 Ensayo: la misma red que `app:agent:replay` (transacción con savepoints que se deshace,
        // y ModoEnsayo para lo que sale de la base en el momento). Las colas de envío y Messenger
        // viven en esta misma base, así que un aviso o una plantilla encolados se van con el rollback.
        $conexion = $this->em->getConnection();
        $desde = (new \DateTimeImmutable('-1 second'))->format('Y-m-d H:i:s');
        if ($ensayo) {
            $conexion->setNestTransactionsWithSavepoints(true);
            $conexion->beginTransaction();
            $this->ensayo->activar();
            $io->comment('ENSAYO: nada de lo que haga la skill quedará escrito.');
        }

        try {
            // También por el guardia: una escritura confirmada desde la consola tiene que haberse
            // previsualizado antes con los mismos datos, igual que en el chat.
            $resultado = $this->guardia->ejecutar($skill, $entrada, $actor);
        } catch (Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        } finally {
            if ($ensayo && $conexion->isTransactionActive()) {
                $this->huella($io, $desde);
                $conexion->rollBack();
            }
        }

        // Sin formatear: es exactamente lo que llega al modelo.
        $io->writeln(json_encode(
            json_decode($resultado->aJson(), true),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '(la respuesta no se pudo formatear como JSON)');

        $io->comment(sprintf(
            '%s · %.0f ms · %d caracteres',
            $resultado->esError() ? 'ERROR de negocio' : 'ok',
            (microtime(true) - $inicio) * 1000,
            strlen($resultado->aJson())
        ));

        return Command::SUCCESS;
    }

    /**
     * Lo que la skill dejó escrito, leído DENTRO de la transacción del ensayo, justo antes de
     * deshacerla: con varias reservas en el hilo, lo que hay que ver es a CUÁL fue cada escritura.
     */
    private function huella(SymfonyStyle $io, string $desde): void
    {
        $conexion = $this->em->getConnection();
        $filas = [
            ...array_map(static fn (array $f): array => ['mensaje', ...array_values($f)], $conexion->fetchAllAssociative(
                "SELECT CONCAT(COALESCE(t.code, LEFT(m.content_local, 50)), COALESCE(CONCAT(' · ', REGEXP_SUBSTR(m.content_local, '[0-9]{2}/[0-9]{2} → [0-9]{2}/[0-9]{2}')), '')),
                        r.localizador, m.status
                   FROM msg_message m LEFT JOIN msg_template t ON t.id = m.template_id
                   LEFT JOIN pms_reserva r ON r.id = UNHEX(REPLACE(m.asunto_id, '-', ''))
                  WHERE m.created_at >= ?",
                [$desde],
            )),
            ...array_map(static fn (array $f): array => ['petición', ...array_values($f)], $conexion->fetchAllAssociative(
                "SELECT LEFT(p.texto, 50), r.localizador, '' FROM pms_peticion p
                   JOIN pms_evento_calendario e ON e.id = p.evento_id JOIN pms_reserva r ON r.id = e.reserva_id
                  WHERE p.created_at >= ?",
                [$desde],
            )),
            ...array_map(static fn (array $f): array => ['estancia', ...array_values($f)], $conexion->fetchAllAssociative(
                "SELECT CONCAT(DATE_FORMAT(e.inicio, '%d/%m %H:%i'), ' → ', DATE_FORMAT(e.fin, '%d/%m %H:%i')), r.localizador, '' FROM pms_evento_calendario e
                   JOIN pms_reserva r ON r.id = e.reserva_id WHERE e.updated_at >= ?",
                [$desde],
            )),
        ];

        $io->section('Lo que habría quedado escrito (se deshace ahora)');
        $filas === []
            ? $io->writeln('  nada')
            : $io->table(['qué', 'detalle', 'reserva', 'estado'], $filas);
    }

    private function actorAdmin(?string $contexto): AgentActor
    {
        $admin = new User();
        $admin->setEmail('cli@local');
        $admin->setRoles([Roles::SUPER_ADMIN]);

        return $contexto !== null
            ? AgentActor::delEquipoPorChat($admin, 'cli', 'pms_reserva', $contexto)
            : AgentActor::delPanel($admin);
    }
}
