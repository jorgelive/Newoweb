<?php

declare(strict_types=1);

namespace App\Message\Controller\Crud;

use App\Message\Entity\GupshupEndpoint;
use App\Panel\Controller\Crud\BaseCrudController;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class GupshupEndpointCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return GupshupEndpoint::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)
            ->setPermission(Action::INDEX, Roles::MAESTROS_SHOW);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('nombre', 'Nombre')->setColumns(6);
        yield TextField::new('accion', 'Alias (Acción)')->setHelp('Ej: send_template, send_text')->setColumns(6);
        yield TextField::new('endpoint', 'Path del Endpoint')->setHelp('Ej: /wa/api/v1/msg')->setColumns(8);
        yield ChoiceField::new('metodo', 'Método HTTP')->setChoices(['POST' => 'POST', 'GET' => 'GET'])->setColumns(4);
        yield BooleanField::new('activo')->renderAsSwitch(true);
    }
}