<?php

namespace App\Admin;

use App\Entity\ReservaUnitcaracteristica;
use App\Entity\ReservaUnitCaracteristicaLink;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Form\FormMapper;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;

class ReservaUnitCaracteristicaLinkAdmin extends AbstractAdmin
{
    protected function configureFormFields(FormMapper $form): void
    {
        $form
            ->add('caracteristica', EntityType::class, [
                'class' => ReservaUnitcaracteristica::class,
                'choice_label' => fn (ReservaUnitcaracteristica $c) => (string) $c,
                'label' => 'Característica',
                'placeholder' => 'Selecciona...',
                'required' => true,
            ])
            ->add('prioridad', IntegerType::class, [
                'label' => 'Prioridad',
                'required' => false,
                'empty_data' => null,
                'attr' => [
                    'min' => 0, // ajusta si necesitas negativos o empezar en 1
                    'step' => 1,
                ],
            ]);
    }

    public function prePersist(object $object): void
    {
        // Si se crea embebido, garantizamos que el vínculo apunte al Unit padre
        if ($this->isChild() && $object instanceof ReservaUnitCaracteristicaLink) {
            if (null === $object->getUnit() && $this->getParent()?->getSubject()) {
                $object->setUnit($this->getParent()->getSubject());
            }
        }
    }

    public function preUpdate(object $object): void
    {
        // Igual que arriba, por si acaso en edición embebida
        if ($this->isChild() && $object instanceof ReservaUnitCaracteristicaLink) {
            if (null === $object->getUnit() && $this->getParent()?->getSubject()) {
                $object->setUnit($this->getParent()->getSubject());
            }
        }
    }
}
