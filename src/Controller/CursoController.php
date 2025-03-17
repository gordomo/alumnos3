<?php

namespace App\Controller;

use App\Entity\Curso;
use App\Form\CursoType;
use App\Repository\CursoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Helpers;

/**
 * @Route("/admin/curso")
 */
class CursoController extends AbstractController
{
    /**
     * @Route("/", name="app_curso_index", methods={"GET"})
     */
    public function index(CursoRepository $cursoRepository, Request $request): Response
    {
        // Get search parameter from request
        $busqueda = $request->get('busqueda');
        $order = $request->get('order', 'desc');
        $sort = $request->get('sort', 'nombre');

        // Get current user's institute
        $instituto = $this->getUser()->getInstituto();

        // Obtener cursos activos y deshabilitados
        $cursos = $this->createQuery($cursoRepository, $instituto, $sort, $order, false, $busqueda);
        $cursosDesabilitados = $this->createQuery($cursoRepository, $instituto, $sort, $order, true, $busqueda);

        return $this->render('curso/index.html.twig', [
            'cursos' => $cursos,
            'order' => $order,
            'sort' => $sort,
            'totalCursos' => count($cursos),
            'cursosDesabilitados' => $cursosDesabilitados,
            'totalCursosDesabilitados' => count($cursosDesabilitados),
            'busqueda' => $busqueda,
        ]);
    }

    /**
     * Crea una consulta para obtener cursos con los filtros especificados
     */
    private function createQuery(
        CursoRepository $cursoRepository,
        $instituto,
        string $sort,
        string $order,
        bool $disabled,
        ?string $busqueda = null
    ): array {
        $qb = $cursoRepository->createQueryBuilder('c')
            ->where('c.instituto = :instituto')
            ->andWhere('c.disabled = :disabled')
            ->setParameter('instituto', $instituto)
            ->setParameter('disabled', $disabled);

        if ($busqueda) {
            $qb->andWhere('c.nombre LIKE :nombre')
               ->setParameter('nombre', '%' . $busqueda . '%');
        }

        $qb->orderBy($sort === 'precio' ? 'c.precio + 0' : 'c.'.$sort, $order);

        return $qb->getQuery()->getResult();
    }

    /**
     * @Route("/new", name="app_curso_new", methods={"GET", "POST"})
     */
    public function new(Request $request, CursoRepository $cursoRepository): Response
    {
        // Obtener el instituto del usuario actual
        $instituto = $this->getUser()->getInstituto();
        $curso = new Curso();
        $curso->setInstituto($instituto);
        $form = $this->createForm(CursoType::class, $curso, [
            'allow_extra_fields' => true,
            'instituto' => $instituto
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            
            if (empty($form->get('dias')->getData())) {
                $this->addFlash('danger', 'necesita seleccionar al menos un día');
                return $this->renderForm('curso/new.html.twig', [
                    'curso' => $curso,
                    'form' => $form,
                ]);
            }

            if (empty($form->get('horarioInicio')->getData()) || empty($form->get('horarioFin')->getData())) {
                $this->addFlash('danger', 'necesita especificar el horario de inicio y fin');
                return $this->renderForm('curso/new.html.twig', [
                    'curso' => $curso,
                    'form' => $form,
                ]);
            }

            // Validar que la hora de fin sea posterior a la hora de inicio
            $horaInicio = $form->get('horarioInicio')->getData();
            $horaFin = $form->get('horarioFin')->getData();
            
            // Crear objetos DateTime para comparar
            $inicio = new \DateTime($horaInicio);
            $fin = new \DateTime($horaFin);
            
            if ($fin <= $inicio) {
                $this->addFlash('danger', 'El horario de fin debe ser posterior al horario de inicio');
                return $this->renderForm('curso/new.html.twig', [
                    'curso' => $curso,
                    'form' => $form,
                ]);
            }

            // Establecer los horarios en el curso
            $curso->setHorarioInicio($inicio);
            $curso->setHorarioFin($fin);

            // Validar fechas
            $fechaInicio = $form->get('fechaInicio')->getData();
            $fechaFin = $form->get('fechaFin')->getData();

            if (!$fechaInicio || !$fechaFin) {
                $this->addFlash('danger', 'Debe especificar las fechas de inicio y fin del curso');
                return $this->renderForm('curso/new.html.twig', [
                    'curso' => $curso,
                    'form' => $form,
                ]);
            }

            if ($fechaFin <= $fechaInicio) {
                $this->addFlash('danger', 'La fecha de fin debe ser posterior a la fecha de inicio');
                return $this->renderForm('curso/new.html.twig', [
                    'curso' => $curso,
                    'form' => $form,
                ]);
            }

            // Calcular la duración basada en los horarios
            $duracion = $this->calcularDuracion($inicio, $fin);
            $curso->setDuracion($duracion);

            $cursoRepository->add($curso);
            return $this->redirectToRoute('app_curso_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->renderForm('curso/new.html.twig', [
            'curso' => $curso,
            'form' => $form,
        ]);
    }

    /**
     * @Route("/calendario", name="app_curso_calendario", methods={"GET"})
     */
    public function calendario(CursoRepository $cursoRepository): Response
    {
        $instituto = $this->getUser()->getInstituto();
        $cursos = $cursoRepository->findBy(['instituto' => $instituto]);
        $eventos = [];
        $cursosSinHorario = [];

        foreach ($cursos as $curso) {
            if (!$curso->getHorarioInicio() || !$curso->getHorarioFin() || !$curso->getFechaInicio() || !$curso->getFechaFin()) {
                $cursosSinHorario[] = $curso;
                continue;
            }

            try {
                $fechaInicio = $curso->getFechaInicio();
                $fechaFin = $curso->getFechaFin();
                $horaInicio = $curso->getHorarioInicio();
                $horaFin = $curso->getHorarioFin();
                $dias = $curso->getDias();

                // Crear un array de días de la semana (0 = Domingo, 1 = Lunes, etc.)
                $diasSemana = [
                    'Domingo' => 0,
                    'Lunes' => 1,
                    'Martes' => 2,
                    'Miercoles' => 3,
                    'Jueves' => 4,
                    'Viernes' => 5,
                    'Sabado' => 6
                ];

                // Convertir los días seleccionados a números
                $diasSeleccionados = array_map(function($dia) use ($diasSemana) {
                    return $diasSemana[$dia];
                }, $dias);

                // Generar eventos para cada día seleccionado en el rango de fechas
                $fechaActual = clone $fechaInicio;
                while ($fechaActual <= $fechaFin) {
                    $diaSemana = (int)$fechaActual->format('w');
                    
                    if (in_array($diaSemana, $diasSeleccionados)) {
                        $evento = [
                            'title' => $curso->getNombre(),
                            'start' => $fechaActual->format('Y-m-d') . 'T' . $horaInicio->format('H:i:s'),
                            'end' => $fechaActual->format('Y-m-d') . 'T' . $horaFin->format('H:i:s'),
                            'extendedProps' => [
                                'profesor' => $curso->getProfesores()->first() ? $curso->getProfesores()->first()->getNombre() . ' ' . $curso->getProfesores()->first()->getApellido() : 'Sin profesor',
                                'duracion' => $curso->getDuracion(),
                                'precio' => $curso->getPrecio()
                            ]
                        ];
                        $eventos[] = $evento;
                    }
                    
                    $fechaActual->modify('+1 day');
                }
            } catch (\Exception $e) {
                // Si hay algún error al procesar el curso, lo agregamos a la lista de cursos sin horario
                $cursosSinHorario[] = $curso;
            }
        }

        return $this->render('curso/calendario.html.twig', [
            'eventos' => json_encode($eventos),
            'cursosSinHorario' => $cursosSinHorario
        ]);
    }

    /**
     * @Route("/{id}", name="app_curso_show", methods={"GET"})
     */
    public function show(Curso $curso): Response
    {
        // Obtener el instituto del usuario actual
        $instituto = $this->getUser()->getInstituto();
        if ($curso->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'El curso no pertenece al instituto del usuario.');
            return $this->redirectToRoute('app_curso_index');
        }
        return $this->render('curso/show.html.twig', [
            'curso' => $curso,
        ]);
    }

    /**
     * @Route("/{id}/edit", name="app_curso_edit", methods={"GET", "POST"})
     */
    public function edit(Request $request, Curso $curso, CursoRepository $cursoRepository): Response
    {
        // Obtener el instituto del usuario actual
        $instituto = $this->getUser()->getInstituto();
        if ($curso->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'El curso no pertenece al instituto del usuario.');
            return $this->redirectToRoute('app_curso_index');
        }
        $form = $this->createForm(CursoType::class, $curso, [
            'allow_extra_fields' => true,
            'instituto' => $instituto
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            if (empty($form->get('dias')->getData())) {
                $this->addFlash('danger', 'necesita seleccionar al menos un día');
                return $this->renderForm('curso/edit.html.twig', [
                    'curso' => $curso,
                    'form' => $form,
                ]);
            }

            if (empty($form->get('horarioInicio')->getData()) || empty($form->get('horarioFin')->getData())) {
                $this->addFlash('danger', 'necesita especificar el horario de inicio y fin');
                return $this->renderForm('curso/edit.html.twig', [
                    'curso' => $curso,
                    'form' => $form,
                ]);
            }

            // Validar que la hora de fin sea posterior a la hora de inicio
            $horaInicio = $form->get('horarioInicio')->getData();
            $horaFin = $form->get('horarioFin')->getData();
            
            // Crear objetos DateTime para comparar
            $inicio = new \DateTime($horaInicio);
            $fin = new \DateTime($horaFin);
            
            if ($fin <= $inicio) {
                $this->addFlash('danger', 'El horario de fin debe ser posterior al horario de inicio');
                return $this->renderForm('curso/edit.html.twig', [
                    'curso' => $curso,
                    'form' => $form,
                ]);
            }

            // Establecer los horarios en el curso
            $curso->setHorarioInicio($inicio);
            $curso->setHorarioFin($fin);

            // Validar fechas
            $fechaInicio = $form->get('fechaInicio')->getData();
            $fechaFin = $form->get('fechaFin')->getData();

            if (!$fechaInicio || !$fechaFin) {
                $this->addFlash('danger', 'Debe especificar las fechas de inicio y fin del curso');
                return $this->renderForm('curso/edit.html.twig', [
                    'curso' => $curso,
                    'form' => $form,
                ]);
            }

            if ($fechaFin <= $fechaInicio) {
                $this->addFlash('danger', 'La fecha de fin debe ser posterior a la fecha de inicio');
                return $this->renderForm('curso/edit.html.twig', [
                    'curso' => $curso,
                    'form' => $form,
                ]);
            }

            // Calcular la duración basada en los horarios
            $duracion = $this->calcularDuracion($inicio, $fin);
            $curso->setDuracion($duracion);

            $cursoRepository->add($curso);
            return $this->redirectToRoute('app_curso_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->renderForm('curso/edit.html.twig', [
            'curso' => $curso,
            'form' => $form,
        ]);
    }

    /**
     * @Route("/{id}", name="app_curso_delete", methods={"POST"})
     */
    public function delete(Request $request, Curso $curso, CursoRepository $cursoRepository): Response
    {
        // Obtener el instituto del usuario actual
        $instituto = $this->getUser()->getInstituto();
        if ($curso->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'El curso no pertenece al instituto del usuario.');
            return $this->redirectToRoute('app_curso_index');
        }
        if ($curso->getAlumnos()->count() > 0) {
            $this->addFlash('danger', 'El curso tiene alumnos asociados y no puede ser eliminado.');
            return $this->redirectToRoute('app_curso_index');
        }

        if ($this->isCsrfTokenValid('delete'.$curso->getId(), $request->request->get('_token'))) {
            $cursoRepository->remove($curso);
        }

        return $this->redirectToRoute('app_curso_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * @Route("/habilitar/{id}", name="app_curso_habilitar", methods={"GET"})
     */
    public function habilitar(Request $request, Curso $curso, CursoRepository $cursoRepository): Response
    {
        // Obtener el instituto del usuario actual
        $instituto = $this->getUser()->getInstituto();
        if ($curso->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'El curso no pertenece al instituto del usuario.');
            return $this->redirectToRoute('app_curso_index');
        }
        $cursoRepository->habilitar($curso);

        return $this->redirectToRoute('app_curso_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Obtiene la fecha y hora de inicio del curso para un día específico
     */
    private function getHoraInicio($curso, $dia): string
    {
        $horaInicio = $curso->getHorarioInicio();
        if (!$horaInicio) {
            throw new \Exception('Horario de inicio no establecido');
        }

        $diaSemana = $this->getDiaSemana($dia);
        
        // Obtener la fecha del próximo día de la semana
        $fecha = new \DateTime();
        
        $fecha->modify('next ' . $diaSemana);
        
        // Combinar la fecha con la hora de inicio
        $fecha->setTime($horaInicio->format('H'), $horaInicio->format('i'));
        
        return $fecha->format('Y-m-d\TH:i:s');
    }

    /**
     * Obtiene la fecha y hora de fin del curso para un día específico
     */
    private function getHoraFin($curso, $dia): string
    {
        $horaFin = $curso->getHorarioFin();
        if (!$horaFin) {
            throw new \Exception('Horario de fin no establecido');
        }

        $diaSemana = $this->getDiaSemana($dia);
        
        // Obtener la fecha del próximo día de la semana
        $fecha = new \DateTime();
        $fecha->modify('next ' . $diaSemana);
        
        // Combinar la fecha con la hora de fin
        $fecha->setTime($horaFin->format('H'), $horaFin->format('i'));
        
        return $fecha->format('Y-m-d\TH:i:s');
    }

    /**
     * Convierte el nombre del día en español al formato de PHP
     */
    private function getDiaSemana($dia): string
    {
        $dias = [
            'Lunes' => 'monday',
            'Martes' => 'tuesday',
            'Miercoles' => 'wednesday',
            'Jueves' => 'thursday',
            'Viernes' => 'friday',
            'Sábado' => 'saturday',
            'Domingo' => 'sunday'
        ];
        
        return $dias[$dia];
    }

    /**
     * Calcula la duración en horas entre dos horarios
     * @return float Duración en formato decimal (ej: 1.5 para 1 hora y 30 minutos)
     */
    private function calcularDuracion(\DateTime $inicio, \DateTime $fin): float
    {
        $intervalo = $inicio->diff($fin);
        $horas = $intervalo->h;
        $minutos = $intervalo->i;
        
        // Convertir a formato decimal (ej: 1:30 -> 1.5)
        return $horas + ($minutos / 60);
    }
}
