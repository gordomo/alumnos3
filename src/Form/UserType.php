<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isEdit = $options['is_edit'];
        $builder
            ->add('email', TextType::class, ['label' => 'Email', 'attr' => ['class' => 'form-control'], 'required' => !$isEdit])
            ->add('roles', ChoiceType::class, ['choices'  => [
                    'Administrador' => "ROLE_ADMIN",
                    'Operador' => "ROLE_USER",
                ],
                'choice_attr' => function($choice, $key, $value) {
                    return ['class' => 'form-check-input'];
                },
                'multiple'=>true,
                'expanded'=>true,
                'required' => !$isEdit
            ])
            ->add('password', PasswordType::class, ['label' => 'Password', 'attr' => ['class' => 'form-control'], 'required' => !$isEdit, 'mapped' => false])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_edit' => false, // Valor por defecto
        ]);
    }
}
