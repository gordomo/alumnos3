<?php

namespace App\Controller;

use App\Entity\Curso;
use App\Entity\Tarea;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tareas para el administrador del instituto: ve todos los cursos.
 *
 * El prefijo /instituto ya está cubierto por la regla ^/instituto de access_control, así que
 * security.yaml no se toca. El aislamiento por curso lo resuelve CursoVoter.
 *
 * @Route("/instituto/tareas")
 */
#[IsGranted('ROLE_ADMIN_INSTITUTO')]
class TareaController extends AbstractTareaController
{
    protected function rutaBase(): string
    {
        return 'app_tarea';
    }

    /**
     * @return Curso[]
     */
    protected function cursosVisibles(): array
    {
        return $this->cursoRepository->findBy(
            ['instituto' => $this->getUser()->getInstituto()],
            ['nombre' => 'ASC']
        );
    }

    /**
     * @Route("/", name="app_tarea_index", methods={"GET"})
     */
    public function index(): Response
    {
        return $this->pantallaIndex();
    }

    /**
     * @Route("/curso/{id}", name="app_tarea_curso", methods={"GET"})
     */
    public function curso(Curso $curso): Response
    {
        return $this->pantallaCurso($curso);
    }

    /**
     * @Route("/curso/{id}/nueva", name="app_tarea_nueva", methods={"GET", "POST"})
     */
    public function nueva(Request $request, Curso $curso): Response
    {
        return $this->pantallaFormTarea($request, $curso, null);
    }

    /**
     * @Route("/{id}/editar", name="app_tarea_editar", methods={"GET", "POST"})
     */
    public function editar(Request $request, Tarea $tarea): Response
    {
        return $this->pantallaFormTarea($request, $tarea->getCurso(), $tarea);
    }

    /**
     * @Route("/{id}/eliminar", name="app_tarea_eliminar", methods={"POST"})
     */
    public function eliminar(Request $request, Tarea $tarea): Response
    {
        return $this->accionEliminarTarea($request, $tarea);
    }

    /**
     * @Route("/{id}", name="app_tarea_grilla", methods={"GET"})
     */
    public function grilla(Tarea $tarea): Response
    {
        return $this->pantallaGrilla($tarea);
    }

    /**
     * @Route("/{id}/guardar", name="app_tarea_guardar", methods={"POST"})
     */
    public function guardar(Request $request, Tarea $tarea): Response
    {
        return $this->accionGuardarGrilla($request, $tarea);
    }
}
