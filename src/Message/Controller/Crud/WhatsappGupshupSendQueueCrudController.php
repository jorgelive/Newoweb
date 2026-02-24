<?php

declare(strict_types=1);

namespace App\Message\Controller\Crud;

use App\Message\Entity\WhatsappGupshupSendQueue;
use App\Panel\Controller\Crud\BaseCrudController;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class WhatsappGupshupSendQueueCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return WhatsappGupshupSendQueue::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)
            ->disable(Action::NEW, Action::EDIT)
            ->setPermission(Action::INDEX, Roles::MENSAJES_SHOW);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnDetail();
        yield TextField::new('destinationPhone', 'Destinatario');

        yield ChoiceField::new('status', 'Estado Worker')
            ->renderAsBadges([
                'pending' => 'warning',
                'success' => 'success',
                'failed' => 'danger'
            ]);

        yield ChoiceField::new('deliveryStatus', 'WhatsApp Status')
            ->renderAsBadges([
                'read' => 'info',
                'delivered' => 'primary',
                'submitted' => 'secondary'
            ]);

        yield DateTimeField::new('runAt', 'Programado para');
        yield TextField::new('failedReason', 'Error')->onlyOnDetail();
    }
}