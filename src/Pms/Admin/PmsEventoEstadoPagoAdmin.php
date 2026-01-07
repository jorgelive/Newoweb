<?php

namespace App\Pms\Admin;

use App\Pms\Entity\PmsEventoEstadoPago;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Regex;

class PmsEventoEstadoPagoAdmin extends AbstractAdmin
{
    protected function configureFormFields(FormMapper $form): void
    {
        $form
            ->add('codigo', TextType::class, [
                'label' => 'Código interno',
            ])
            ->add('nombre', TextType::class, [
                'label' => 'Nombre visible',
            ])
            ->add('color', TextType::class, [
                'required' => false,
                'label' => 'Color (HEX)',
                'attr' => [
                    'maxlength' => 7,
                    'placeholder' => '#RRGGBB',
                ],
                'help' => 'Formato: #RRGGBB (7 caracteres).',
                'constraints' => [
                    new Length([
                        'max' => 7,
                        'maxMessage' => 'El color debe tener como máximo {{ limit }} caracteres (ej: #1A2B3C).',
                    ]),
                    new Regex([
                        'pattern' => '/^#?[0-9A-Fa-f]{6}$/',
                        'message' => 'El color debe ser HEX válido (ej: #1A2B3C).',
                    ]),
                ],
            ])
            ->add('orden', IntegerType::class, [
                'required' => false,
                'label' => 'Orden',
            ]);
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('codigo')
            ->add('nombre');
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('codigo')
            ->add('nombre', null, [
                'editable' => true,
            ])
            ->add('orden', null, [
                'editable' => true,
            ])
            ->add('color', null, [
                'editable' => true,
                'edit' => 'inline',
                'form_type' => TextType::class,
                'form_type_options' => [
                    'required' => false,
                    'attr' => [
                        'maxlength' => 7,
                        'placeholder' => '#RRGGBB',
                    ],
                    'constraints' => [
                        new Length([
                            'max' => 7,
                            'maxMessage' => 'El color debe tener como máximo {{ limit }} caracteres (ej: #1A2B3C).',
                        ]),
                        new Regex([
                            'pattern' => '/^#?[0-9A-Fa-f]{6}$/',
                            'message' => 'El color debe ser HEX válido (ej: #1A2B3C).',
                        ]),
                    ],
                ],
            ])
            ->add('created', null, [
                'format' => 'Y-m-d H:i',
            ])
            ->add('updated', null, [
                'format' => 'Y-m-d H:i',
            ]);
    }

    protected function configureShowFields(ShowMapper $show): void
    {
        $show
            ->add('id')
            ->add('codigo')
            ->add('nombre')
            ->add('color')
            ->add('orden')
            ->add('created', null, [
                'format' => 'Y-m-d H:i',
            ])
            ->add('updated', null, [
                'format' => 'Y-m-d H:i',
            ]);
    }
}