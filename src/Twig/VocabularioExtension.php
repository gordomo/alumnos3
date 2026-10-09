<?php

namespace App\Twig;

use App\Service\Vocabulario;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Expone el glosario a las plantillas.
 *
 * Se llama term() y no t(), que es el nombre que usa el componente de traducción de Symfony: dos
 * cosas distintas con el mismo nombre terminan en un error raro el día que alguien use el otro.
 */
class VocabularioExtension extends AbstractExtension
{
    public function __construct(private Vocabulario $vocabulario)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('term', [$this->vocabulario, 'termino']),
            new TwigFunction('Term', [$this->vocabulario, 'terminoMayuscula']),
        ];
    }
}
