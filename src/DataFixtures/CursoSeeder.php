<?php

namespace App\DataFixtures;

use App\Entity\Curso;
use App\Entity\Instituto;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class CursoSeeder extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        /** @var Instituto $instituto */
        $instituto = $this->getReference('instituto', Instituto::class);

        $cursos = [
            [
                'nombre' => 'Danza Contemporánea Inicial',
                'dias' => ['Lunes', 'Miércoles'],
                'horario_inicio' => '18:00',
                'horario_fin' => '19:00',
                'fecha_inicio' => new \DateTime('2024-03-01'),
                'fecha_fin' => new \DateTime('2024-12-20'),
                'duracion' => 1,
                'precio' => 8000,
                'cupo' => 20
            ],
            [
                'nombre' => 'Ballet Clásico Avanzado',
                'dias' => ['Martes', 'Jueves'],
                'horario_inicio' => '19:30',
                'horario_fin' => '21:30',
                'fecha_inicio' => new \DateTime('2024-03-05'),
                'fecha_fin' => new \DateTime('2024-12-15'),
                'duracion' => 2,
                'precio' => 10000,
                'cupo' => 15
            ],
            [
                'nombre' => 'Jazz Dance Intermedio',
                'dias' => ['Miércoles', 'Viernes'],
                'horario_inicio' => '17:00',
                'horario_fin' => '18:30',
                'fecha_inicio' => new \DateTime('2024-03-10'),
                'fecha_fin' => new \DateTime('2024-12-10'),
                'duracion' => 1.5,
                'precio' => 9000,
                'cupo' => 18
            ],
            [
                'nombre' => 'Danza Folklórica',
                'dias' => ['Sábado'],
                'horario_inicio' => '10:00',
                'horario_fin' => '12:00',
                'fecha_inicio' => new \DateTime('2024-03-15'),
                'fecha_fin' => new \DateTime('2024-12-05'),
                'duracion' => 2,
                'precio' => 7000,
                'cupo' => 25
            ],
            [
                'nombre' => 'Hip Hop Principiantes',
                'dias' => ['Lunes', 'Viernes'],
                'horario_inicio' => '16:00',
                'horario_fin' => '17:00',
                'fecha_inicio' => new \DateTime('2024-03-20'),
                'fecha_fin' => new \DateTime('2024-12-25'),
                'duracion' => 1,
                'precio' => 7500,
                'cupo' => 20
            ],
            [
                'nombre' => 'Danza Contemporánea Avanzado',
                'dias' => ['Martes', 'Jueves'],
                'horario_inicio' => '20:00',
                'horario_fin' => '22:00',
                'fecha_inicio' => new \DateTime('2024-03-25'),
                'fecha_fin' => new \DateTime('2024-12-30'),
                'duracion' => 2,
                'precio' => 11000,
                'cupo' => 15
            ]
        ];

        foreach ($cursos as $index => $cursoData) {
            $curso = new Curso();
            $curso->setNombre($cursoData['nombre']);
            $curso->setDias($cursoData['dias']);
            $curso->setHorarioInicio(new \DateTime($cursoData['horario_inicio']));
            $curso->setHorarioFin(new \DateTime($cursoData['horario_fin']));
            $curso->setFechaInicio($cursoData['fecha_inicio']);
            $curso->setFechaFin($cursoData['fecha_fin']);
            $curso->setDuracion($cursoData['duracion']);
            $curso->setPrecio($cursoData['precio']);
            $curso->setInstituto($instituto);

            $manager->persist($curso);
            
            // Agregar referencia al curso
            $this->addReference('curso_' . $index, $curso);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            InstitutoSeeder::class
        ];
    }
} 