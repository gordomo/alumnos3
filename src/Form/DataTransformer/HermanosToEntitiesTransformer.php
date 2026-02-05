<?php

namespace App\Form\DataTransformer;

use App\Entity\Alumno;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

class HermanosToEntitiesTransformer implements DataTransformerInterface
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Transforma un array de IDs (desde la base de datos) a un array de entidades Alumno (para el formulario)
     */
    public function transform($value): array
    {
        if (null === $value || empty($value)) {
            return [];
        }

        // Si ya es un array de entidades, devolverlo tal cual
        if (is_array($value) && !empty($value) && $value[0] instanceof Alumno) {
            return $value;
        }

        // Si es un array de IDs, convertir a entidades
        if (is_array($value)) {
            $hermanos = [];
            foreach ($value as $id) {
                if (is_numeric($id)) {
                    $hermano = $this->entityManager->getRepository(Alumno::class)->find($id);
                    if ($hermano) {
                        $hermanos[] = $hermano;
                    }
                } elseif ($id instanceof Alumno) {
                    $hermanos[] = $id;
                }
            }
            return $hermanos;
        }

        return [];
    }

    /**
     * Transforma un array de entidades Alumno (desde el formulario) a un array de IDs (para guardar en la base de datos)
     */
    public function reverseTransform($value): array
    {
        if (null === $value || empty($value)) {
            return [];
        }

        // Si es un array de entidades, extraer los IDs
        if (is_array($value)) {
            $ids = [];
            foreach ($value as $item) {
                if ($item instanceof Alumno) {
                    $ids[] = $item->getId();
                } elseif (is_numeric($item)) {
                    $ids[] = (int)$item;
                }
            }
            return array_values(array_unique($ids));
        }

        return [];
    }
}
