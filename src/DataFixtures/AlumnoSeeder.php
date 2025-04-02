<?php

namespace App\DataFixtures;

use App\Entity\Instituto;
use App\Entity\Curso;
use App\Entity\Alumno;
use App\Entity\AlumnoCursoHistorico;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AlumnoSeeder extends Fixture implements DependentFixtureInterface
{
    private $passwordHasher;

    public function __construct(UserPasswordHasherInterface $passwordHasher)
    {
        $this->passwordHasher = $passwordHasher;
    }

    public function load(ObjectManager $manager): void
    {
        $instituto = $this->getReference('instituto', Instituto::class);

        $alumnos = [
            [
                'nombre' => 'Sofía',
                'apellido' => 'López',
                'email' => 'sofia.lopez@email.com',
                'telefono' => '1234567894',
                'dni' => '22334455',
                'fecha_nacimiento' => '2010-01-15',
                'direccion' => 'Calle del Sol 123',
                'cursos' => [0, 3] // Danza Contemporánea y Hip Hop Kids
            ],
            [
                'nombre' => 'Lucas',
                'apellido' => 'Martínez',
                'email' => 'lucas.martinez@email.com',
                'telefono' => '1234567895',
                'dni' => '33445566',
                'fecha_nacimiento' => '2012-03-20',
                'direccion' => 'Avenida Luna 456',
                'cursos' => [5] // Danza Clásica
            ],
            // ... Agregar más alumnos aquí ...
        ];

        // Generar 28 alumnos más con datos aleatorios
        for ($i = 2; $i < 30; $i++) {
            $alumnos[] = [
                'nombre' => $this->getRandomName(),
                'apellido' => $this->getRandomLastName(),
                'email' => "alumno{$i}@email.com",
                'telefono' => '1234567' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'dni' => str_pad($i, 8, '0', STR_PAD_LEFT),
                'fecha_nacimiento' => $this->getRandomDate(),
                'direccion' => "Calle {$i} #{$i}",
                'cursos' => $this->getRandomCourses()
            ];
        }

        foreach ($alumnos as $alumnoData) {
            // Crear usuario
            $user = new User();
            $user->setEmail($alumnoData['email']);
            $user->setRoles(['ROLE_ALUMNO']);
            $user->setInstituto($instituto);

            $hashedPassword = $this->passwordHasher->hashPassword($user, 'Alumno123');
            $user->setPassword($hashedPassword);

            $manager->persist($user);

            // Crear alumno
            $alumno = new Alumno();
            $alumno->setNombre($alumnoData['nombre']);
            $alumno->setApellido($alumnoData['apellido']);
            $alumno->setEmail($alumnoData['email']);
            $alumno->setCelular($alumnoData['telefono']);
            $alumno->setDni($alumnoData['dni']);
            $alumno->setFNac(new \DateTime($alumnoData['fecha_nacimiento']));
            $alumno->setInstituto($instituto);
            $alumno->setActivo(1);

            // Asignar cursos y crear historial
            foreach ($alumnoData['cursos'] as $cursoIndex) {
                $curso = $this->getReference('curso_' . $cursoIndex, Curso::class);
                $alumno->addCurso($curso);

                // Crear historial del curso
                $historico = new AlumnoCursoHistorico();
                $historico->setAlumno($alumno);
                $historico->setCurso($curso);
                $historico->setFechaInicio($curso->getFechaInicio());
                $historico->setFechaFin($curso->getFechaFin());
                $historico->setPrecioMensual($curso->getPrecio());
                
                // Generar algunos meses adeudados aleatorios
                $mesesAdeudados = $this->getRandomMonths($curso->getFechaInicio(), $curso->getFechaFin());
                $historico->setMesesAdeudados($mesesAdeudados);
                
                // Generar algunos meses pagados aleatorios
                $mesesPagados = $this->getRandomMonths($curso->getFechaInicio(), $curso->getFechaFin());
                $historico->setMesesPagados($mesesPagados);

                $manager->persist($historico);
            }

            $manager->persist($alumno);
        }

        $manager->flush();
    }

    private function getRandomName()
    {
        $nombres = ['Ana', 'María', 'Sofía', 'Lucía', 'Valentina', 'Lucas', 'Juan', 'Pedro', 'Carlos', 'Diego'];
        return $nombres[array_rand($nombres)];
    }

    private function getRandomLastName()
    {
        $apellidos = ['González', 'Rodríguez', 'Martínez', 'López', 'Pérez', 'Sánchez', 'Fernández', 'García', 'Silva', 'Torres'];
        return $apellidos[array_rand($apellidos)];
    }

    private function getRandomDate()
    {
        $start = strtotime('2000-01-01');
        $end = strtotime('2015-12-31');
        return date('Y-m-d', rand($start, $end));
    }

    private function getRandomCourses()
    {
        $numCursos = rand(1, 3);
        $cursos = range(0, 5);
        shuffle($cursos);
        return array_slice($cursos, 0, $numCursos);
    }

    private function getRandomMonths($fechaInicio, $fechaFin)
    {
        $meses = [];
        $fecha = clone $fechaInicio;
        
        while ($fecha <= $fechaFin) {
            if (rand(0, 1)) { // 50% de probabilidad de adeudar el mes
                $meses[] = $fecha->format('Y-m');
            }
            $fecha->modify('+1 month');
        }
        
        return $meses;
    }

    public function getDependencies(): array
    {
        return [
            InstitutoSeeder::class,
            CursoSeeder::class,
        ];
    }
} 