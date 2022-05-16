<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;

class MainArchivocamposType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('operacion',HiddenType::class)
        ;
    }
    
    /**
     * @return string
     */
    public function getBlockPrefix()
    {
        return 'archivo';//suplantando
    }
}
