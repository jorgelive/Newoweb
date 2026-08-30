<?php

declare(strict_types=1);

namespace App\Message\Controller\Crud;

use App\Message\Entity\MessageTemplate;
use App\Message\Form\Type\Beds24TemplateType;
use App\Message\Form\Type\EmailTemplateType;
use App\Message\Form\Type\WhatsappLinkTemplateType;
use App\Message\Form\Type\WhatsappMetaTemplateType;
use App\Message\Service\MessageSegmentationAggregator;
use App\Message\Service\Meta\Template\WhatsappMetaTemplateInventario;
use App\Message\Service\Meta\Template\WhatsappMetaTemplatePushService;
use App\Message\Service\Meta\Template\WhatsappMetaTemplateSyncService;
use App\Panel\Controller\Crud\BaseCrudController;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * @extends BaseCrudController<MessageTemplate>
 */
class MessageTemplateCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack,
        private readonly MessageSegmentationAggregator $segmentationAggregator
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return MessageTemplate::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        $crud = parent::configureCrud($crud);

        return $crud
            ->setEntityLabelInSingular('Plantilla')
            ->setEntityLabelInPlural('Plantillas de Mensaje')
            ->setPageTitle(Crud::PAGE_INDEX, 'Gestión de Plantillas')
            ->showEntityActionsInlined();
    }

    public function configureAssets(Assets $assets): Assets
    {
        return $assets
            ->addCssFile('panel/styles/message/message_template/flat-collection.css');
    }

    public function configureActions(Actions $actions): Actions
    {
        // 1. Botón global para REVISAR EL ESTADO (PULL desde Meta).
        //
        // Es el mismo servicio que corre el cron de las 03:15 (`app:whatsapp:sync-templates`):
        // baja de Meta el estado de aprobación de cada idioma y lo escribe en local. Se
        // llama «Revisar estado» y no «Sincronizar» porque eso es lo que se viene a hacer
        // aquí —ver si Meta ya aprobó— y «sincronizar» se confundía con el Push.
        $syncMetaAction = Action::new('syncMetaTemplates', 'Revisar estado en Meta', 'fa fa-rotate')
            ->linkToCrudAction('executeMetaSync')
            ->createAsGlobalAction()
            ->setCssClass('btn btn-info');

        // 2. Botón individual para hacer PUSH a Meta (Bypass interfaz web)
        $pushMetaAction = Action::new('pushMetaTemplate', 'Push a Meta', 'fa fa-cloud-upload-alt')
            ->linkToCrudAction('executePushToMeta')
            ->setCssClass('btn btn-warning text-dark') // Diferenciado visualmente
            ->displayIf(static function (MessageTemplate $entity) {
                // Solo mostrar si tiene datos de Meta
                return !empty($entity->getWhatsappMetaTmpl());
            });

        // 3. Botón global para VER LO QUE META TIENE DE VERDAD.
        //
        // No toca nada: es un GET a la Graph API. Existe porque el estado que enseña este
        // listado sale del JSON local, y ese JSON se escribe a mano igual que lo escribe el
        // sincronizador: cuando no coinciden, aquí se ve un «PENDING» que en Meta no existe.
        $inventarioMetaAction = Action::new('inventarioMeta', 'Ver plantillas en Meta', 'fa fa-list-check')
            ->linkToCrudAction('executeInventarioMeta')
            ->createAsGlobalAction()
            ->setCssClass('btn btn-secondary');

        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $syncMetaAction)
            ->add(Crud::PAGE_INDEX, $inventarioMetaAction)
            ->add(Crud::PAGE_DETAIL, $inventarioMetaAction)
            // También en el detalle: se entra a mirar una plantilla concreta para ver si
            // Meta ya la aprobó, y tener que volver al listado para refrescar es absurdo.
            ->add(Crud::PAGE_DETAIL, $syncMetaAction)
            // Añadimos el nuevo botón de Push en lista, vista detalle y edición
            ->add(Crud::PAGE_INDEX, $pushMetaAction)
            ->add(Crud::PAGE_DETAIL, $pushMetaAction)
            ->add(Crud::PAGE_EDIT, $pushMetaAction);

        $actions = parent::configureActions($actions);

        $actions
            ->update(Crud::PAGE_INDEX, Action::NEW, fn (Action $action) => $action->setIcon('fa fa-plus')->setLabel('Crear Plantilla'))
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $action) => $action->setIcon('fa fa-edit')->setLabel('Editar'))
            ->update(Crud::PAGE_INDEX, Action::DETAIL, fn (Action $action) => $action->setIcon('fa fa-eye')->setLabel('Ver'))
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn (Action $action) => $action->setIcon('fa fa-trash-alt')->setLabel('Eliminar'))
            ->update(Crud::PAGE_NEW, Action::SAVE_AND_RETURN, fn (Action $action) => $action->setLabel('Guardar Plantilla'))
            ->update(Crud::PAGE_EDIT, Action::SAVE_AND_RETURN, fn (Action $action) => $action->setLabel('Guardar Cambios'));

        return $actions
            ->setPermission(Action::INDEX, Roles::MENSAJES_SHOW)
            ->setPermission(Action::DETAIL, Roles::MENSAJES_SHOW)
            ->setPermission(Action::NEW, Roles::MENSAJES_WRITE)
            ->setPermission(Action::EDIT, Roles::MENSAJES_WRITE)
            ->setPermission(Action::DELETE, Roles::MENSAJES_DELETE)
            ->setPermission('pushMetaTemplate', Roles::MENSAJES_WRITE) // Requiere permisos de escritura
            // Revisar estado solo LEE de Meta y actualiza el estado local: basta con poder
            // ver plantillas. Se declara explícitamente para que no dependa del defecto.
            ->setPermission('syncMetaTemplates', Roles::MENSAJES_SHOW)
            // Solo lee de Meta y no escribe ni en local ni allí.
            ->setPermission('inventarioMeta', Roles::MENSAJES_SHOW);
    }

    /**
     * Estilo de los badges de las columnas virtuales.
     *
     * EasyAdmin le pone a `.badge` un `margin-inline-start: 4px` —y 8px a `.badge-danger`—,
     * o sea margen a la IZQUIERDA. En una columna estrecha eso descuadra el arranque de cada
     * fila y, sumado a un `gap` de flex, empuja los badges a la línea siguiente sin
     * necesidad. Se anula y se usa margen derecho, que es el que separa sin desalinear.
     */
    private const string ESTILO_BADGE = 'margin-inline-start:0;margin-inline-end:4px;';

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')
            ->setMaxLength(40)
            ->onlyOnDetail();

        // --- PANEL 1: GENERAL ---
        yield FormField::addPanel('Información General')
            ->setIcon('fa fa-info-circle');

        yield TextField::new('code', 'Código Interno')
            ->setColumns(12)
            ->setHelp('Llave única para el sistema. <b>No usar espacios, tildes ni mayúsculas</b> (ej: <code>booking_confirmation</code>).');

        yield TextField::new('name', 'Nombre Comercial')
            ->setColumns(12)
            ->setHelp('Nombre descriptivo y amigable para el equipo.');

        // Canales: en cuáles está redactada y en cuáles está encendida.
        //
        // Son dos cosas distintas y la lista tiene que distinguirlas: una plantilla escrita
        // pero apagada se ve perfectamente al editar y sin embargo no sale por ese canal.
        // Verde = redactado y encendido · Gris tachado = redactado pero apagado ·
        // ausente = ni siquiera hay texto.
        yield TextField::new('virtualCanales', 'Canales')
            ->onlyOnIndex()
            ->setSortable(false)
            ->formatValue(static function ($value, ?MessageTemplate $entity): string {
                $canales = $entity?->getCanales() ?? [];
                $badges = [];

                foreach ($canales as $etiqueta => $estado) {
                    if (!$estado['creado']) {
                        continue;
                    }

                    $badges[] = $estado['habilitado']
                        ? sprintf('<span class="badge badge-success" style="%s" title="Redactado y activo">%s</span>', self::ESTILO_BADGE, htmlspecialchars($etiqueta, ENT_QUOTES))
                        : sprintf('<span class="badge badge-secondary" style="%s" title="Redactado pero APAGADO: no se envía por este canal"><s>%s</s></span>', self::ESTILO_BADGE, htmlspecialchars($etiqueta, ENT_QUOTES));
                }

                return $badges === []
                    ? '<span class="text-muted small">sin redactar</span>'
                    : implode('', $badges);
            })
            ->renderAsHtml();

        // Estado de aprobación en Meta, por idioma.
        //
        // Meta aprueba CADA IDIOMA por separado, así que no hay una sola etiqueta que
        // resuma la plantilla: puede tener el español aprobado y el italiano en revisión.
        // Se pintan los recuentos, con lo que bloquea primero.
        //
        // Campo virtual: se ancla a `virtualEstadoMeta` (un stub que devuelve cadena) y no
        // a `estadoMetaPorIdioma`, porque TextField valida el valor CRUDO antes de
        // formatearlo y un array lo hace reventar. Mismo motivo que en PmsCatalogo.
        // Sin ordenación: no hay columna en la base de datos por la que ordenar.
        yield TextField::new('virtualEstadoMeta', 'Estado Meta')
            ->onlyOnIndex()
            ->setSortable(false)
            ->formatValue(static function ($value, ?MessageTemplate $entity): string {
                $estados = $entity?->getEstadoMetaPorIdioma() ?? [];

                if ($estados === []) {
                    return '<span class="badge badge-secondary">sin WhatsApp</span>';
                }

                $color = [
                    'APPROVED' => 'badge-success',
                    // Borrada en Meta: ni aprobada ni pendiente. Existe el texto aquí, pero
                    // allí no hay nada — y hasta 4 semanas después no se puede volver a crear.
                    'DELETED' => 'badge-dark',
                    'PENDING' => 'badge-warning',
                    'REJECTED' => 'badge-danger',
                    'SIN ENVIAR' => 'badge-secondary',
                ];

                $badges = [];
                foreach ($estados as $estado => $cuantos) {
                    $badges[] = sprintf(
                        '<span class="badge %s" style="%s" title="%d idioma(s)">%s %d</span>',
                        $color[$estado] ?? 'badge-secondary',
                        self::ESTILO_BADGE,
                        $cuantos,
                        htmlspecialchars(ucfirst(strtolower($estado)), ENT_QUOTES),
                        $cuantos
                    );
                }

                return implode('', $badges);
            })
            ->renderAsHtml();

        yield BooleanField::new('ejecutarTraduccion', 'Traducir Auto')->onlyOnForms()->setColumns(6);
        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir')->onlyOnForms()->setColumns(6);

        // --- 🔥 PANEL 2: ALCANCE Y SEGREGACIÓN ---
        yield FormField::addPanel('Alcance y Segregación (Scope)')
            ->setIcon('fa fa-filter')
            ->setHelp('Define dónde se permite usar esta plantilla. Si dejas los filtros vacíos, será una plantilla <b>Global</b>.');

        yield ChoiceField::new('contextType', 'Módulo Exclusivo')
            ->setChoices([
                'Solo Reservas (PMS)' => 'pms_reserva',
                'Registro Manual / Walk-in' => 'manual',
            ])
            ->setRequired(false)
            ->setColumns(4);

        yield ChoiceField::new('allowedSources', 'Solo para estas Fuentes (OTAs)')
            ->setChoices($this->segmentationAggregator->getSourceChoices())
            ->allowMultipleChoices()
            ->setRequired(false)
            ->setColumns(4);

        yield ChoiceField::new('allowedAgencies', 'Solo para Agencias (B2B)')
            ->setChoices($this->segmentationAggregator->getAgencyChoices())
            ->allowMultipleChoices()
            ->setRequired(false)
            ->setColumns(4);

        // --- ASISTENTE DE IA ---
        // El asistente puede mandar cualquier plantilla CON «Cuándo usarla» escrito (el
        // operador confirma y la correspondencia de OTA guarda). El interruptor controla lo
        // otro: el AUTOENVÍO, que el huésped se la pida él solo, sin operador que confirme.
        yield FormField::addPanel('Asistente de IA')
            ->setIcon('fa fa-robot')
            ->collapsible()
            ->renderCollapsed()
            ->setHelp('El <b>asistente interno</b> puede mandar cualquier plantilla que tenga '
                . '«Cuándo usarla» rellenado — el operador confirma antes de cada envío. El '
                . 'interruptor de <b>autoenvío</b> es otra cosa: permite que el <b>huésped</b> '
                . 'se la pida él mismo por el chat («mándame mi guía»), sin nadie que confirme. '
                . 'Actívalo sólo en plantillas inocuas de pedir dos veces.');

        yield BooleanField::new('autoenvioHabilitada', 'El huésped puede pedirla (autoenvío)')
            ->setHelp('Sólo surte efecto si además rellenas «Cuándo usarla».')
            ->setColumns(4);

        yield TextareaField::new('agenteUso', 'Cuándo usarla (para la IA)')
            ->setRequired(false)
            ->setNumOfRows(3)
            ->setHelp('Escrito <b>para el modelo</b>, no para el equipo: en qué situación se manda '
                . 'y en cuál no. Es lo único que lee para elegir, así que el nombre de la '
                . 'plantilla no sirve aquí. Ej.: <i>«El huésped pide su guía, o hay que '
                . 'reenviársela porque no la encuentra.»</i>')
            ->setColumns(8);

        // --- PANEL 3: WHATSAPP / META ---
        yield FormField::addPanel('Configuración WhatsApp / Meta')
            ->setIcon('fab fa-whatsapp')
            ->collapsible()
            ->renderCollapsed()
            ->setHelp('💡 <b>¡Importante!</b> Utiliza llaves dobles y nombres descriptivos para tus variables (ej. <code>{{guest_name}}</code> o <code>{{url_checkin}}</code>). El sistema las detectará y convertirá automáticamente al formato posicional que exige Meta.');
        yield Field::new('whatsappMetaTmpl', '')
            ->setFormType(WhatsappMetaTemplateType::class)
            ->onlyOnForms()
            ->setColumns(12);

        yield CodeEditorField::new('whatsappMetaTmpl', 'JSON Generado WhatsApp')
            ->setLanguage('js')->onlyOnDetail()
            ->formatValue(fn($val) => empty($val) ? '' : (is_array($val) ? json_encode($val, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) : $val));

        // --- PANEL 4: BEDS24 ---
        yield FormField::addPanel('Configuración Beds24 / OTAs')
            ->setIcon('fa fa-bed')
            ->collapsible()
            ->renderCollapsed();

        yield Field::new('beds24Tmpl', '')
            ->setFormType(Beds24TemplateType::class)
            ->onlyOnForms()
            ->setColumns(12);

        yield CodeEditorField::new('beds24Tmpl', 'JSON Generado Beds24')
            ->setLanguage('js')->onlyOnDetail()
            ->formatValue(fn($val) => empty($val) ? '' : (is_array($val) ? json_encode($val, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) : $val));

        // --- PANEL 5: WHATSAPP LINK MANUAL ---
        yield FormField::addPanel('Configuración Enlace WhatsApp (Manual)')
            ->setIcon('fa fa-external-link-alt')
            ->collapsible()
            ->renderCollapsed();

        yield Field::new('whatsappLinkTmpl', '')
            ->setFormType(WhatsappLinkTemplateType::class)
            ->onlyOnForms()
            ->setColumns(12);

        yield CodeEditorField::new('whatsappLinkTmpl', 'JSON Generado Link Manual')
            ->setLanguage('js')->onlyOnDetail()
            ->formatValue(fn($val) => empty($val) ? '' : (is_array($val) ? json_encode($val, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) : $val));

        // --- PANEL 6: EMAIL ---
        yield FormField::addPanel('Configuración Correo Electrónico')
            ->setIcon('fa fa-envelope')
            ->collapsible()
            ->renderCollapsed();

        yield Field::new('emailTmpl', '')
            ->setFormType(EmailTemplateType::class)
            ->onlyOnForms()
            ->setColumns(12);

        yield CodeEditorField::new('emailTmpl', 'JSON Generado Email')
            ->setLanguage('js')->onlyOnDetail()
            ->formatValue(fn($val) => empty($val) ? '' : (is_array($val) ? json_encode($val, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) : $val));

        // --- PANEL 7: AUDITORÍA ---
        yield FormField::addPanel('Auditoría')
            ->setIcon('fa fa-shield-alt')
            ->collapsible()
            ->renderCollapsed()
            ->onlyOnDetail();

        yield DateTimeField::new('createdAt', 'Creado')->onlyOnDetail();
        yield DateTimeField::new('updatedAt', 'Actualizado')->onlyOnDetail();
    }

    /**
     * Enseña lo que Meta tiene de verdad, sin tocar nada.
     *
     * ¿Por qué existe, habiendo un «Revisar estado»? Porque ése **escribe**: baja lo que Meta
     * devuelve y lo guarda en local, así que después de correrlo ya no puedes comparar — y lo
     * que no encaja, en vez de verse, desaparece o se convierte en una fila `*_META` nueva.
     * Esta pantalla es un `GET` y se queda mirando:
     *
     * - una plantilla local que dice estar `PENDING` y **no existe en Meta** sale señalada
     *   arriba (fue el caso de `menu_tours` durante cinco meses);
     * - una plantilla de Meta que **aquí no reclama nadie** sale marcada «sin dueño»: es la
     *   antesala de las gemelas `*_META` que fabrica el cron.
     *
     * El resultado va en una tabla con scroll propio; la página no crece con el listado.
     *
     * @param AdminContext<MessageTemplate> $context
     */
    public function executeInventarioMeta(
        AdminContext $context,
        WhatsappMetaTemplateInventario $inventario,
        AdminUrlGenerator $adminUrlGenerator
    ): Response {
        $urlVolver = $adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->generateUrl();

        try {
            $datos = $inventario->listar();
        } catch (Throwable $e) {
            // Se pinta dentro de la propia pantalla, no como flash: quien pulsó el botón vino a
            // ver una lista, y un aviso rojo en el listado de plantillas se confunde con un
            // problema de las plantillas.
            return $this->render('panel/message/message_template/inventario_meta.html.twig', [
                'error' => $e->getMessage(),
                'urlVolver' => $urlVolver,
            ]);
        }

        return $this->render('panel/message/message_template/inventario_meta.html.twig', [
            'enMeta' => $datos['enMeta'],
            'soloLocales' => $datos['soloLocales'],
            'total' => $datos['total'],
            'urlVolver' => $urlVolver,
        ]);
    }

    /**
     * Ejecuta la sincronización manual de plantillas oficiales desde WhatsApp Meta Cloud API.
     * * ¿Por qué existe? Permite al operador forzar la actualización de plantillas (nuevas o cambios de estado)
     * directamente desde la interfaz de EasyAdmin sin esperar a procesos en segundo plano.
     * Delega toda la responsabilidad de conexión, extracción de credenciales y mapeo de componentes
     * (Header, Body, Footer, Buttons) al servicio especializado.
     *
     * @param AdminContext $context Contexto actual de la petición en EasyAdmin.
     * @param WhatsappMetaTemplateSyncService $syncService Orquestador unificado de sincronización de Meta.
     * @param AdminUrlGenerator $adminUrlGenerator Generador de URLs para la redirección post-ejecución.
     * @return Response Redirección al listado principal del CRUD.
     *
     * @param AdminContext<MessageTemplate> $context
     */
    public function executeMetaSync(
        AdminContext $context,
        WhatsappMetaTemplateSyncService $syncService,
        AdminUrlGenerator $adminUrlGenerator
    ): Response {
        try {
            // El servicio ahora encapsula toda la lógica HTTP y de base de datos internamente.
            $result = $syncService->sync();

            $created = $result['created'] ?? 0;
            $updated = $result['updated'] ?? 0;
            $total = $created + $updated;

            if ($total > 0) {
                $this->addFlash(
                    'success',
                    sprintf('Sincronización exitosa. Se crearon %d y se actualizaron %d plantillas oficiales de Meta.', $created, $updated)
                );
            } else {
                $this->addFlash('info', 'Plantillas verificadas. Todo se encuentra sincronizado y al día con Meta.');
            }

        } catch (Throwable $e) {
            $this->addFlash('danger', 'Error crítico al sincronizar plantillas con Meta: ' . $e->getMessage());
        }

        // Redirigimos de vuelta a la lista del CRUD después de ejecutar la acción
        $targetUrl = $adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl();

        return $this->redirect($targetUrl);
    }

    /**
     * Fuerz el envío (Push) de la estructura JSON de esta plantilla hacia Meta
     * para su creación o actualización en múltiples idiomas, bypaseando la web de Facebook.
     *
     * @param AdminContext $context Contexto actual de la petición en EasyAdmin.
     * @param WhatsappMetaTemplatePushService $pushService Servicio encargado de formatear y subir la plantilla a Meta.
     * @param AdminUrlGenerator $adminUrlGenerator Generador de URLs.
     * @return Response Redirección a la vista previa.
     *
     */
    /**
     * Borra en Meta los idiomas marcados. **No los vuelve a crear, y no puede.**
     *
     * Meta reserva el par nombre+idioma **cuatro semanas** tras un borrado: el POST siguiente
     * se rechaza con «no es posible añadir contenido nuevo mientras se está eliminando el
     * existente». Por eso esto no promete una recreación — pasado el plazo, volver a crearlas
     * es el push normal, que al no encontrarlas en Meta entra por el camino de creación.
     *
     * @param list<string>                 $idiomas
     * @param AdminContext<MessageTemplate> $context
     */
    private function borrarIdiomasDeMeta(
        MessageTemplate $template,
        array $idiomas,
        WhatsappMetaTemplatePushService $pushService,
        AdminUrlGenerator $adminUrlGenerator,
        AdminContext $context
    ): Response {
        try {
            $resultados = $pushService->borrarIdiomasEnMeta($template, $idiomas);
            $ok = [];
            $fallos = [];

            foreach ($resultados as $lang => $r) {
                if ($r['status'] === 'success') {
                    $ok[] = strtoupper($lang);
                } else {
                    $fallos[] = strtoupper($lang) . ': ' . ($r['message'] ?? '');
                }
            }

            if ($ok !== []) {
                // El plazo se repite AQUÍ, no sólo en el cuadro previo: es lo que hay que
                // recordar dentro de un mes, y para entonces nadie se acuerda del aviso.
                $this->addFlash('warning', sprintf(
                    '🗑️ Borrados en Meta y marcados como inexistentes: %s. ⚠️ Meta no deja volver a '
                    . 'crearlos hasta dentro de 4 SEMANAS; pasado el plazo, vuelve a darle a «Push a Meta».',
                    implode(', ', $ok)
                ));
            }

            if ($fallos !== []) {
                $this->addFlash('danger', '❌ No se pudieron borrar: <br>' . implode('<br>', $fallos));
            }
        } catch (Throwable $e) {
            $this->addFlash('danger', 'Error borrando en Meta: ' . $e->getMessage());
        }

        return $this->redirect($context->getReferrer()
            ?? $adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->generateUrl());
    }

    /** @param AdminContext<MessageTemplate> $context */
    public function executePushToMeta(
        AdminContext $context,
        WhatsappMetaTemplatePushService $pushService,
        AdminUrlGenerator $adminUrlGenerator
    ): Response {
        $template = $context->getEntity()->getInstance();

        if (!$template instanceof MessageTemplate) {
            $this->addFlash('danger', 'Error interno: No se pudo obtener la entidad de la plantilla.');
            return $this->redirect($context->getReferrer() ?? $adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->generateUrl());
        }

        // PASO 1 — elegir idiomas. Sin selección, se enseña el cuadro en vez de subir.
        //
        // Subir un idioma REABRE su revisión en Meta: reenviar los siete porque uno fue
        // rechazado devuelve a PENDING seis que ya estaban aprobadas y no se pueden usar
        // fuera de la ventana de 24 h hasta que Meta las mire otra vez.
        $peticion = $context->getRequest();
        $idiomas = (array) $peticion->request->all('idiomas');

        if (!$peticion->isMethod('POST')) {
            $cuerpos = $template->getWhatsappMetaTmpl()['body'] ?? [];

            return $this->render('panel/message/message_template/push_meta_idiomas.html.twig', [
                'plantilla' => $template,
                'idiomas' => array_map(static fn (array $b): array => [
                    'codigo' => (string) ($b['language'] ?? '?'),
                    'estado' => (string) ($b['status'] ?? 'SIN ENVIAR'),
                ], is_array($cuerpos) ? $cuerpos : []),
                'urlVolver' => $peticion->headers->get('referer')
                    ?? $adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->generateUrl(),
            ]);
        }

        if ($idiomas === []) {
            $this->addFlash('warning', 'No marcaste ningún idioma: no se subió nada.');

            return $this->redirect($adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->generateUrl());
        }

        // BORRAR es su propia acción, no un modo del push, porque «borrar y volver a crear»
        // no es una operación: Meta reserva el par nombre+idioma CUATRO SEMANAS. Presentarlo
        // como «recrear» prometía algo que la API no puede cumplir — y el día que se intentó
        // dejó cinco idiomas borrados y ninguno recreado.
        if ($peticion->request->get('accion') === 'borrar') {
            return $this->borrarIdiomasDeMeta($template, $idiomas, $pushService, $adminUrlGenerator, $context);
        }

        try {
            // Solo los idiomas marcados; el resto conserva su estado en Meta.
            $results = $pushService->pushTemplateToMeta($template, $idiomas);

            if (empty($results)) {
                $this->addFlash('warning', 'La plantilla local no tiene un JSON de WhatsApp Meta válido o no contiene idiomas configurados.');
            } else {
                $successCount = 0;
                $errorMessages = [];

                // Analizamos los resultados por idioma
                foreach ($results as $lang => $result) {
                    if ($result['status'] === 'success') {
                        $successCount++;
                    } else {
                        $errorMessages[] = strtoupper($lang) . ': ' . $result['message'];
                    }
                }

                // Generamos el feedback al usuario
                if ($successCount > 0) {
                    $this->addFlash('success', sprintf('✅ Se enviaron a revisión en Meta %d idiomas exitosamente.', $successCount));
                }

                if (!empty($errorMessages)) {
                    $this->addFlash('danger', '❌ Ocurrieron errores en algunos idiomas: <br>' . implode('<br>', $errorMessages));
                }
            }

        } catch (Throwable $e) {
            $this->addFlash('danger', 'Error crítico al hacer Push a Meta: ' . $e->getMessage());
        }

        // Retornamos a la misma vista donde el usuario hizo clic (Index, Detail o Edit)
        $referrer = $context->getReferrer();
        if ($referrer) {
            return $this->redirect($referrer);
        }

        return $this->redirect($adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->generateUrl());
    }
}