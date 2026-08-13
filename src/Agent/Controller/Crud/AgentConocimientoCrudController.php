<?php

declare(strict_types=1);

namespace App\Agent\Controller\Crud;

use App\Agent\Conversation\PerfilConversacion;
use App\Agent\Entity\AgentConocimiento;
use App\Panel\Controller\Crud\BaseCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use App\Agent\Service\ValidadorDeConocimiento;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Las respuestas escritas a lo que preguntan todo el rato.
 *
 * Esta pantalla es la que hace que el agente escale menos: cada fila que se añade aquí es una
 * interrupción menos a una persona para repetir lo de siempre.
 */
class AgentConocimientoCrudController extends BaseCrudController
{

    /**
     * ⚠️ `BaseCrudController` tiene su propio constructor con dependencias, así que hay que
     * llamarlo. Sin el `parent::__construct()` el panel devolvía un 500 al entrar —«Typed
     * property BaseCrudController::$requestStack must not be accessed before initialization»—
     * porque `configureActions()` lo usa en la primera línea.
     */
    public function __construct(
        AdminUrlGenerator $adminUrlGenerator,
        RequestStack $requestStack,
        private readonly ValidadorDeConocimiento $validador,
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    /**
     * Al guardar, se comprueba si la guía ya contesta esto — y se dice, sin impedirlo.
     *
     * ⚠️ **Avisa, no bloquea**, y es deliberado: duplicar un tema aquí puede ser lo correcto. La
     * guía exige una casita, así que quien pregunta antes de reservar no llega a ella; para ese
     * público la copia genérica es la respuesta buena, y basta con **declararla** acotándola a
     * prospecto e interesado. El aviso explica las dos salidas.
     */
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->avisarSiDuplica($entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->avisarSiDuplica($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
    }

    private function avisarSiDuplica(mixed $entidad): void
    {
        if (!$entidad instanceof AgentConocimiento) {
            return;
        }

        $aviso = $this->validador->avisoPara($entidad);

        if ($aviso === null) {
            return;
        }

        // `warning` cuando pisa a la guía, `info` cuando la duplicación ya está declarada: lo
        // segundo es una confirmación, no un problema, y teñirlo de amarillo enseña a ignorarlo.
        $this->addFlash(
            $this->validador->duplicaSinDeclarar($entidad) ? 'warning' : 'info',
            $aviso
        );
    }

    public static function getEntityFqcn(): string
    {
        return AgentConocimiento::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Respuesta del agente')
            ->setEntityLabelInPlural('Conocimiento del agente')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setHelp('index', 'Esto es lo ÚLTIMO que mira el agente antes de escalar por no '
                . 'saber. Cada respuesta que añadas aquí es una interrupción menos al equipo. '
                . 'No sirve para lo que cambia —fechas, saldos, disponibilidad—: eso lo consultan '
                . 'las herramientas y siempre estará más al día que un texto escrito a mano.')
            ->setHelp('new', self::AYUDA_GUIA)
            ->setHelp('edit', self::AYUDA_GUIA);
    }

    /**
     * Lo que hay que entender antes de escribir una ficha aquí: en qué se diferencia de la guía.
     */
    private const string AYUDA_GUIA = <<<'HTML'
        <strong>Esto y la guía del huésped no son lo mismo, y a veces conviene que digan lo mismo.</strong>
        <ul>
          <li><strong>La guía</strong> contesta «cómo funciona X <em>en tu casita</em>». Está atada a
              una reserva: resuelve la hora de entrada, el código de la puerta, el grifo de esa ducha.
              <strong>Quien no tiene reserva no llega a ella</strong> — si pregunta antes de reservar,
              el agente le repregunta de qué casita le habla en vez de contestarle.</li>
          <li><strong>Esto</strong> contesta «este sitio tiene X». No depende de la reserva ni del día,
              y <strong>sí puede ser público</strong>: es lo único que el agente puede decirle a alguien
              que todavía está decidiendo si reserva.</li>
        </ul>
        <strong>Por eso duplicar a veces es correcto.</strong> «¿Hay estacionamiento?» ya está en la
        guía, pero el que lo pregunta suele no tener reserva todavía. La copia genérica aquí es la
        respuesta buena para él — siempre que la <strong>declares</strong>: en «A quién se le puede
        contar», marca todos <strong>menos Huésped</strong>. Así él sigue recibiendo la versión de
        su casita, que es mejor, y todos los demás —incluido el equipo, que es quien prueba el
        bot— reciben ésta.
        <br><br>
        Al guardar te avisamos si la guía ya lo contesta: no te lo impide, te dice qué tema es para
        que decidas. <strong>Una copia sin acotar sí es un error</strong> —el huésped se llevaría esta
        versión genérica en vez de la suya, sin sus horas ni sus códigos—.
        HTML;

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('categoria', 'Tema')
            ->setHelp('El agente elige primero el tema y sólo después mira las respuestas de '
                . 'dentro. Si no encuentras uno que encaje, crea el tema antes.')
            ->setColumns(4);

        yield TextField::new('nombreInterno', 'Nombre interno')
            ->setHelp('Para encontrarlo tú en esta lista. El agente NO lo ve.')
            ->setColumns(8);

        yield TextareaField::new('etiquetas', 'Cómo lo pregunta la gente')
            ->setHelp('Las palabras con las que el agente lo reconoce, separadas por comas: '
                . '<code>estacionamiento, parking, dónde dejo el auto, cochera</code>. '
                . '⚠️ Escríbelas como pregunta el HUÉSPED, no como lo llamamos nosotros: es lo '
                . 'único de esta ficha que el agente usa para elegir, y si sólo pones nuestro '
                . 'vocabulario no lo encontrará cuando más falta hace.')
            ->setNumOfRows(3);

        yield TextareaField::new('contenido', 'Lo que se dirá')
            ->setHelp('Ya redactado y corto. El agente lo traduce y ajusta el tono, pero no '
                . 'debería tener que inventar nada: si hace falta inventar, es que falta texto.')
            ->setNumOfRows(6);

        yield ChoiceField::new('dominios', 'Negocios')
            ->setChoices(['Alojamiento' => 'hotelero', 'Turismo' => 'turistico'])
            ->allowMultipleChoices()
            ->setHelp('<strong>Vacío = vale para los dos.</strong> Marca sólo si de verdad es de '
                . 'uno: el horario de la oficina o los medios de pago son de los dos.')
            ->setColumns(6);

        yield ChoiceField::new('perfiles', 'A quién se le puede contar')
            ->setChoices(array_combine(
                array_map(static fn (PerfilConversacion $p): string => ucfirst($p->value), PerfilConversacion::cases()),
                array_map(static fn (PerfilConversacion $p): string => $p->value, PerfilConversacion::cases())
            ))
            ->allowMultipleChoices()
            ->setHelp('<strong>Vacío = a todos</strong>, incluido el huésped. Marca a quién SÍ '
                . 'en dos casos:<br>'
                . '· <strong>Es interno</strong> —un procedimiento, un código—: marca sólo Personal '
                . 'y Colaborador.<br>'
                . '· <strong>Duplica un tema de la guía a propósito</strong>: marca todos '
                . '<strong>menos Huésped</strong>. Eso ES la declaración de «versión pública»; sin '
                . 'ella el huésped recibiría la genérica en lugar de la de su casita, y acotándola '
                . 'de más dejas al equipo sin ella.')
            ->setColumns(6);

        yield BooleanField::new('requiereHumano', 'Además, avisar al equipo')
            ->setHelp('Para lo que se puede explicar pero lo resuelve una persona: el agente '
                . 'contesta esto Y escala, en vez de elegir entre las dos cosas.')
            ->renderAsSwitch(true)
            ->setColumns(6);

        yield BooleanField::new('activo', 'Activo')
            ->setHelp('Apagarlo lo saca para todos sin borrarlo. Úsalo cuando una respuesta deje '
                . 'de ser cierta y todavía no sepas con qué sustituirla: es preferible que el '
                . 'agente escale a que repita algo que ya no vale.')
            ->renderAsSwitch(true)
            ->setColumns(6);
    }
}
