<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Entity\Maestro\MaestroIdioma;
use App\Message\Entity\MessageTemplate;
use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsReservaHuesped;
use App\Pms\Factory\PmsEventoCalendarioFactory;
use App\Pms\Form\Type\PmsReservaHuespedType;
use App\Pms\Service\Message\PmsMessageDataResolver;
use App\Security\Roles;
use App\Twig\Extension\PhoneExtension;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use JeroenDesloovere\VCard\VCard;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

final class PmsReservaCrudController extends BaseCrudController
{
    public function __construct(
        private readonly PmsEventoCalendarioFactory $eventoFactory,
        private readonly EntityManagerInterface $entityManager,
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack,
        private readonly PmsMessageDataResolver $messageDataResolver,
        // Inyectamos nuestro Formateador Twig para usarlo en el Listado/Detalle
        private readonly PhoneExtension $phoneExtension
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsReserva::class;
    }

    public function createEntity(string $entityFqcn): PmsReserva
    {
        $reserva = new PmsReserva();
        $idiomaDefault = $this->entityManager->getReference(MaestroIdioma::class, MaestroIdioma::DEFAULT_IDIOMA);
        if ($idiomaDefault) {
            $reserva->setIdioma($idiomaDefault);
        }
        return $reserva;
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof PmsReserva) {
            foreach ($entityInstance->getEventosCalendario() as $evento) {
                if (!$evento instanceof PmsEventoCalendario) continue;
                if ($evento->getReserva() === null) {
                    $evento->setReserva($entityInstance);
                }
                if (!$evento->isOta()) {
                    $this->eventoFactory->hydrateLinksForUi($evento);
                }
            }

            foreach ($entityInstance->getHuespedes() as $huesped) {
                if ($huesped instanceof PmsReservaHuesped && $huesped->getReserva() === null) {
                    $huesped->setReserva($entityInstance);
                }
            }
        }
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof PmsReserva) {
            $uow = $entityManager->getUnitOfWork();
            $metaEvento = $entityManager->getClassMetadata(PmsEventoCalendario::class);

            foreach ($entityInstance->getEventosCalendario() as $evento) {
                if (!$evento instanceof PmsEventoCalendario) continue;
                if ($evento->getReserva() === null) {
                    $evento->setReserva($entityInstance);
                }

                if ($evento->isOta()) continue;

                $isNew = $evento->getId() === null || $uow->getEntityState($evento) === UnitOfWork::STATE_NEW;

                if ($isNew) {
                    $this->eventoFactory->hydrateLinksForUi($evento);
                    continue;
                }

                $uow->computeChangeSet($metaEvento, $evento);
                $changes = $uow->getEntityChangeSet($evento);

                if (array_key_exists('pmsUnidad', $changes)) {
                    $this->eventoFactory->hydrateLinksForUi($evento);
                }
            }

            foreach ($entityInstance->getHuespedes() as $huesped) {
                if ($huesped instanceof PmsReservaHuesped && $huesped->getReserva() === null) {
                    $huesped->setReserva($entityInstance);
                }
            }
        }
        parent::updateEntity($entityManager, $entityInstance);
    }

    public function configureActions(Actions $actions): Actions
    {
        $returnTo = $this->requestStack->getCurrentRequest()?->query->get('returnTo');

        $actions->disable(Action::BATCH_DELETE);

        $crearBloqueo = Action::new('crearBloqueo', 'Crear Bloqueo')
            ->createAsGlobalAction()
            ->setCssClass('btn btn-danger')
            ->linkToUrl(function () use ($returnTo) {
                return $this->adminUrlGenerator
                    ->setController(PmsEventoCalendarioCrudController::class)
                    ->setAction(Action::NEW)
                    ->set('es_bloqueo', 1)
                    ->set('returnTo', $returnTo)
                    ->generateUrl();
            });

        $actions
            ->add(Crud::PAGE_INDEX, $crearBloqueo)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::INDEX);

        $checkBorrado = function (Action $action) {
            return $action->displayIf(static function (PmsReserva $reserva) {
                foreach ($reserva->getEventosCalendario() as $evento) {
                    if ($evento->isOta()) {
                        $estado = $evento->getEstado();
                        if (!$estado || $estado->getId() !== PmsEventoEstado::CODIGO_CANCELADA) {
                            return false;
                        }
                    }
                }
                return true;
            });
        };

        $actions->update(Crud::PAGE_INDEX, Action::DELETE, $checkBorrado);
        $actions->update(Crud::PAGE_DETAIL, Action::DELETE, $checkBorrado);

        return parent::configureActions($actions)
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW)
            ->setPermission(Action::NEW, Roles::RESERVAS_WRITE)
            ->setPermission(Action::EDIT, Roles::RESERVAS_WRITE)
            ->setPermission(Action::DELETE, Roles::RESERVAS_DELETE)
            ->setPermission('crearBloqueo', Roles::RESERVAS_WRITE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Reserva')
            ->setEntityLabelInPlural('Reservas')
            ->setDefaultSort(['fechaLlegada' => 'DESC'])
            ->showEntityActionsInlined()
            ->overrideTemplate('crud/index', 'panel/pms/pms_reserva/index.html.twig')
            ->overrideTemplate('crud/detail', 'panel/pms/pms_reserva/detail.html.twig');
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if ($responseParameters->get('pageName') === Crud::PAGE_DETAIL) {
            $plantillas = $this->entityManager->getRepository(MessageTemplate::class)->findAll();
            $plantillasValidas = array_filter($plantillas, function (MessageTemplate $t) {
                return $t->getWhatsappLinkBody('es') !== null || $t->getWhatsappLinkBody('en') !== null;
            });

            $responseParameters->set('plantillas_whatsapp', $plantillasValidas);
        }

        return parent::configureResponseParameters($responseParameters);
    }

    public function generarWhatsappUrl(AdminContext $context): Response
    {
        $reservaId = $context->getRequest()->query->get('entityId');
        $templateId = $context->getRequest()->query->get('templateId');

        $reserva = $this->entityManager->getRepository(PmsReserva::class)->find($reservaId);
        $template = $this->entityManager->getRepository(MessageTemplate::class)->find($templateId);

        if (!$reserva instanceof PmsReserva || !$template instanceof MessageTemplate) {
            $this->addFlash('danger', 'Faltan datos para generar el mensaje.');
            return $this->redirect($context->getReferrer() ?? $this->adminUrlGenerator->setAction(Action::INDEX)->generateUrl());
        }

        $lang = $reserva->getIdioma() ? (string) $reserva->getIdioma()->getId() : 'es';
        $cuerpoPlantilla = $template->getWhatsappLinkBody($lang);

        if (!$cuerpoPlantilla) {
            $this->addFlash('warning', sprintf('La plantilla "%s" no tiene traducción disponible.', $template->getName()));
            return $this->redirect($context->getReferrer());
        }

        $variables = $this->messageDataResolver->getTemplateVariables((string)$reserva->getId());

        $replacePairs = [];
        foreach ($variables as $key => $value) {
            $replacePairs['{{ ' . $key . ' }}'] = (string) $value;
            $replacePairs['{{' . $key . '}}'] = (string) $value;
        }

        $textoFinal = strtr($cuerpoPlantilla, $replacePairs);

        // Se mantiene la regex cruda aquí porque WhatsApp API quiere SÓLO números
        $telefonoLimpio = preg_replace('/[^0-9]/', '', $reserva->getTelefono() ?? $reserva->getTelefono2() ?? '');

        if (empty($telefonoLimpio)) {
            $this->addFlash('warning', 'Esta reserva no tiene un número de teléfono válido.');
            return $this->redirect($context->getReferrer());
        }

        $whatsappUrl = sprintf(
            'https://api.whatsapp.com/send/?phone=%s&text=%s&type=phone_number&app_absent=0',
            $telefonoLimpio,
            urlencode($textoFinal)
        );

        return new RedirectResponse($whatsappUrl);
    }

    // =========================================================================
    // 🔥 ACCIÓN PARA GENERAR VCARD (MÓVIL / WHATSAPP AGENDA)
    // =========================================================================
    public function generarVcard(AdminContext $context): Response
    {
        $reserva = $context->getEntity()->getInstance();

        if (!$reserva instanceof PmsReserva) {
            throw $this->createNotFoundException('Reserva no encontrada.');
        }

        $vcard = new VCard();

        $localizador = $reserva->getLocalizador() ?? 'Sin-Loc';
        $nombre = $reserva->getNombreApellido() ?? 'Huésped Desconocido';
        $cantidad = $reserva->getPaxTotal();

        $inicio = $reserva->getFechaLlegada();
        $fin = $reserva->getFechaSalida();

        $inicioText = $inicio ? $inicio->format('Y/m/d') : '-';
        $finText = $fin ? $fin->format('Y/m/d') : '-';
        $diaFin = $fin ? $fin->format('d') : '-';

        $fechaReservaText = $reserva->getCreatedAt() ? $reserva->getCreatedAt()->format('Y/m/d') : '-';

        $unidad = $reserva->getNombreHabitacion();
        $canalNombre = $reserva->getChannel() ? (string)$reserva->getChannel()->getId() : 'Directo';
        $inicialCanal = substr(strtoupper($canalNombre), 0, 1);

        // LÓGICA DE FORMATEO (Número sanitizado en BD sin '+')
        $telefonoRaw = trim((string) $reserva->getTelefono());
        $telefonoVcard = '';

        if ($telefonoRaw !== '') {
            // 1. Le devolvemos el '+' que se omitió al guardar en la BD
            $telefonoConPlus = str_starts_with($telefonoRaw, '+') ? $telefonoRaw : '+' . $telefonoRaw;

            try {
                $phoneUtil = PhoneNumberUtil::getInstance();

                // 2. Al tener el '+', libphonenumber ya sabe que es formato internacional.
                $numberProto = $phoneUtil->parse($telefonoConPlus, null);

                if ($phoneUtil->isValidNumber($numberProto)) {
                    // 3. E164 asegura que quede exactamente como "+51999999999" para WhatsApp
                    $telefonoVcard = $phoneUtil->format($numberProto, PhoneNumberFormat::E164);
                } else {
                    $telefonoVcard = $telefonoConPlus; // Fallback si es inválido
                }
            } catch (NumberParseException $e) {
                // Si falla catastróficamente el parseo, guardamos con el '+' de todas formas
                $telefonoVcard = $telefonoConPlus;
            }
        }

        // Formato visual para la agenda: 2024/05/10/12 B x2 (101) Juan Perez
        $campoNombre = sprintf('%s/%s %s x%s (%s) %s', $inicioText, $diaFin, $inicialCanal, $cantidad, $unidad, $nombre);

        $nota = <<<TXT
Localizador: $localizador
Nombre: $nombre
Alojamiento: $unidad
Ingreso: $inicioText
Salida: $finText
Canal: $canalNombre
Fecha de reserva: $fechaReservaText
TXT;

        $vcard->addName('', $campoNombre);

        if ($telefonoVcard !== '') {
            $vcard->addPhoneNumber($telefonoVcard, 'CELL');
        }

        $vcard->addNote($nota);

        // Generar URL absoluta al detalle de la reserva en el panel
        $detailUrl = $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::DETAIL)
            ->setEntityId((string) $reserva->getId())
            ->generateUrl();

        $request = $this->requestStack->getCurrentRequest();
        if ($request) {
            $vcard->addURL($request->getSchemeAndHttpHost() . $detailUrl);
        }

        return new Response(
            $vcard->getOutput(),
            200,
            [
                'Content-Type' => 'text/vcard',
                'Content-Disposition' => 'attachment; filename="' . $localizador . '_contacto.vcf"',
            ]
        );
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('establecimiento')
            ->add('beds24MasterId')
            ->add('referenciaCanalAggregate')
            ->add('canalesAggregate')
            ->add('nombreCliente')
            ->add('fechaLlegada');
    }

    public function configureFields(string $pageName): iterable
    {
        $entity = null;
        if ($pageName === Crud::PAGE_DETAIL) {
            $context = $this->getContext();
            $entity = $context?->getEntity()->getInstance();
        }

        yield TextField::new('localizador', 'Localizador')
            ->setTemplatePath('panel/pms/pms_reserva/fields/localizador.html.twig')
            ->setFormTypeOption('disabled', true)
            ->setColumns(6)
            ->setHelp('Copia el enlace público para el huésped.');

        yield TextField::new('establecimientoVirtualPrincipal', 'Listing Virtual')
            ->onlyOnDetail()
            ->formatValue(function ($virtual) {
                if (!$virtual) return '-';
                return sprintf(
                    '<span class="badge bg-secondary-subtle text-secondary font-monospace" style="padding: 0.5em 0.7em;"><i class="fas fa-building me-1"></i> %s</span>',
                    $virtual->getNombre() ?? $virtual->getCodigo()
                );
            })
            ->renderAsHtml();

        yield TextField::new('syncStatusAggregate', 'Estado Sincro')
            ->setVirtual(true)
            ->formatValue(function ($statusValue) {
                return match ($statusValue) {
                    'synced'  => '<span class="badge badge-success"><i class="fa fa-check"></i> Sync</span>',
                    'error'   => '<span class="badge badge-danger"><i class="fa fa-exclamation"></i> Error</span>',
                    'pending' => '<span class="badge badge-warning"><i class="fa fa-sync fa-spin"></i> Pend.</span>',
                    default   => '',
                };
            })
            ->renderAsHtml()
            ->hideOnForm();

        yield TextField::new('canalesAggregate', 'Canales')
            ->hideOnForm()
            ->formatValue(fn($v) => $v ? sprintf('<span class="badge badge-info">%s</span>', $v) : '-');

        yield TextField::new('referenciaCanalAggregate', 'Referencia OTA')
            ->hideOnForm()
            ->setTemplatePath('panel/pms/pms_reserva/fields/referencia_extranet.html.twig');

        yield FormField::addPanel('Datos del Titular')->setIcon('fa fa-user');

        yield TextField::new('nombreCliente', 'Nombre')->setColumns(6);
        yield TextField::new('apellidoCliente', 'Apellido')->setColumns(6);

        // 🔥 AQUÍ QUEDA LISTO TU CAMPO DE TELÉFONO PARA RENDERIZAR LA PLANTILLA VCARD
        yield TextField::new('telefono', 'Teléfono')
            ->setColumns(6)
            ->formatValue(fn($val) => $val ? $this->phoneExtension->formatPhone($val) : null)
            ->setTemplatePath('panel/pms/pms_reserva/fields/telefono_wa_vcard.html.twig');

        // Ocultar el email en la vista de detalle si está vacío
        if ($pageName !== Crud::PAGE_DETAIL || ($entity && $entity->getEmailCliente())) {
            yield EmailField::new('emailCliente', 'Email')->setColumns(6);
        }

        yield AssociationField::new('pais', 'País')
            ->setColumns(6)
            ->setQueryBuilder(fn($qb) => $qb->orderBy('entity.prioritario', 'DESC')->addOrderBy('entity.nombre', 'ASC'));

        yield AssociationField::new('idioma', 'Idioma')
            ->setColumns(6)
            ->setRequired(true)
            ->setFormTypeOption('attr', ['required' => true])
            ->setQueryBuilder(fn($qb) => $qb->orderBy('entity.prioridad', 'DESC')->addOrderBy('entity.nombre', 'ASC'));

        yield BooleanField::new('datosLocked', 'Bloquear Datos')
            ->setHelp('Protege los datos contra sobrescritura por sincronización.');

        yield FormField::addPanel('Estancias')->setIcon('fa fa-calendar');
        yield AssociationField::new('establecimiento', 'Establecimiento')
            ->setRequired(true)
            ->setFormTypeOption('attr', ['required' => true]);

        yield CollectionField::new('eventosCalendario', 'Gestión de Eventos')
            ->useEntryCrudForm(PmsEventoCalendarioCrudController::class)
            ->setFormTypeOption('prototype_data', $this->eventoFactory->createForUi())
            ->setColumns(12)
            ->addCssClass('field-full-width')
            ->setFormTypeOption('by_reference', false)
            ->allowAdd()
            ->allowDelete()
            ->renderExpanded()
            ->onlyOnForms();

        yield CollectionField::new('eventosCalendario', 'Detalle de Estancias')
            ->setTemplatePath('panel/pms/pms_reserva/fields/detail_eventos.html.twig')
            ->onlyOnDetail();

        yield FormField::addPanel('Huéspedes')->setIcon('fa fa-users');
        yield CollectionField::new('huespedes', 'Lista Namelist')
            ->setEntryType(PmsReservaHuespedType::class)
            ->setFormTypeOption('by_reference', false)
            ->allowAdd()
            ->allowDelete()
            ->onlyOnForms();

        yield CollectionField::new('huespedes', 'Lista Namelist')
            ->setTemplatePath('panel/pms/pms_reserva/fields/detail_huespedes.html.twig')
            ->onlyOnDetail();

        yield FormField::addPanel('Resumen de Reserva (Autocalculado)')->setIcon('fa fa-calculator')->renderCollapsed();

        yield DateField::new('fechaLlegada', 'Check-in Min')->setFormTypeOption('disabled', true)->setColumns(6);
        yield DateField::new('fechaSalida', 'Check-out Max')->setFormTypeOption('disabled', true)->setColumns(6);

        yield IntegerField::new('cantidadAdultos', 'Total Adultos')->setFormTypeOption('disabled', true)->setColumns(6)->hideOnIndex();
        yield IntegerField::new('cantidadNinos', 'Total Niños')->setFormTypeOption('disabled', true)->setColumns(6)->hideOnIndex();

        yield MoneyField::new('montoTotal', 'Total Ingresos')->setCurrency('USD')->setStoredAsCents(false)->setFormTypeOption('disabled', true)->setColumns(6);
        yield MoneyField::new('comisionTotal', 'Total Comisiones')->setCurrency('USD')->setStoredAsCents(false)->setFormTypeOption('disabled', true)->setColumns(6)->hideOnIndex();

        yield FormField::addPanel('Tiempos de Canal')->setIcon('fa fa-clock')->renderCollapsed();
        yield DateTimeField::new('primeraFechaReservaCanal', 'Creación más antigua en OTA')->setFormTypeOption('disabled', true)->hideOnIndex();
        yield DateTimeField::new('ultimaFechaModificacionCanal', 'Modificación más reciente en OTA')->setFormTypeOption('disabled', true)->hideOnIndex();
        yield TextField::new('horaLlegadaCanalAggregate', 'Horas de llegada (ETAs)')->setFormTypeOption('disabled', true)->hideOnIndex();

        yield FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed();

        yield TextField::new('id', 'Query Navicat (Copiar)')
            ->onlyOnDetail()
            ->formatValue(function ($value) {
                $uuid = (string) $value;
                return sprintf(
                    '<code style="user-select: all; padding: 5px; background: #f8f9fa; border: 1px solid #ddd; display: block; margin-bottom: 10px;">SELECT BIN_TO_UUID(id) as id_str, r.* FROM pms_reserva r WHERE id = UUID_TO_BIN(\'%s\');</code>',
                    $uuid
                );
            })
            ->renderAsHtml();

        yield DateTimeField::new('createdAt', 'Creado')->hideOnIndex()->setFormat('dd/MM/yyyy HH:mm')->setFormTypeOption('disabled', true);
        yield DateTimeField::new('updatedAt', 'Actualizado')->hideOnIndex()->setFormat('dd/MM/yyyy HH:mm')->setFormTypeOption('disabled', true);
    }
}