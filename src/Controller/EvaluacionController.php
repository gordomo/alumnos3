<?php

namespace App\Controller;

use App\Entity\Curso;
use App\Entity\Evaluacion;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Calificaciones para el administrador del instituto: ve todos los cursos y puede
 * administrar evaluaciones.
 *
 * El prefijo /instituto/... ya está cubierto por la regla ^/instituto de access_control,
 * así que no hace falta agregar ninguna línea nueva en security.yaml. El aislamiento por
 * curso lo resuelve CursoVoter.
 *
 * @Route("/instituto/evaluaciones")
 */
#[IsGranted('ROLE_ADMIN_INSTITUTO')]
class EvaluacionController extends AbstractCalificacionController
{
    protected function rutaBase(): string
    {
        return 'app_evaluacion';
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
     * @Route("/", name="app_evaluacion_index", methods={"GET"})
     */
    public function index(): Response
    {
        return $this->pantallaIndex();
    }

    /**
     * @Route("/curso/{id}", name="app_evaluacion_curso", methods={"GET"})
     */
    public function curso(Curso $curso): Response
    {
        return $this->pantallaCurso($curso);
    }

    /**
     * @Route("/curso/{id}/nueva", name="app_evaluacion_evaluacion_nueva", methods={"GET", "POST"})
     */
    public function nueva(Request $request, Curso $curso): Response
    {
        return $this->pantallaFormEvaluacion($request, $curso, null);
    }

    /**
     * @Route("/{id}/editar", name="app_evaluacion_evaluacion_editar", methods={"GET", "POST"})
     */
    public function editar(Request $request, Evaluacion $evaluacion): Response
    {
        return $this->pantallaFormEvaluacion($request, $evaluacion->getCurso(), $evaluacion);
    }

    /**
     * @Route("/{id}/eliminar", name="app_evaluacion_evaluacion_eliminar", methods={"POST"})
     */
    public function eliminar(Request $request, Evaluacion $evaluacion): Response
    {
        return $this->accionEliminarEvaluacion($request, $evaluacion);
    }

    /**
     * @Route("/{id}", name="app_evaluacion_grilla", methods={"GET"})
     */
    public function grilla(Evaluacion $evaluacion): Response
    {
        return $this->pantallaGrilla($evaluacion);
    }

    /**
     * @Route("/{id}/guardar", name="app_evaluacion_guardar", methods={"POST"})
     */
    public function guardar(Request $request, Evaluacion $evaluacion): Response
    {
        return $this->accionGuardarGrilla($request, $evaluacion);
    }
}
