<?php
namespace App\Form;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{TextType, TextareaType, HiddenType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\{NotBlank, Length, Regex};
class RespuestaSolicitudPublicaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder,array $options): void
    {
        $builder->add('asunto',TextType::class,['label'=>'Asunto','constraints'=>[new NotBlank(),new Length(['max'=>180]),new Regex(['pattern'=>'/^[^\r\n]*$/D','message'=>'El asunto debe ocupar una sola línea.'])],'attr'=>['maxlength'=>180]])
            ->add('mensaje',TextareaType::class,['label'=>'Tu respuesta','constraints'=>[new NotBlank(),new Length(['max'=>5000])],'attr'=>['rows'=>7,'maxlength'=>5000],'help'=>'Hasta 5000 caracteres. Se enviará por email al contacto de esta solicitud.'])
            ->add('envio',HiddenType::class,['constraints'=>[new NotBlank(),new Regex('/^[a-f0-9]{32}$/D')]]);
    }
    public function configureOptions(OptionsResolver $resolver): void { $resolver->setDefaults(['data_class'=>null]); }
}
