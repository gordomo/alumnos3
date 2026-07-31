<?php

namespace App\Controller;

use App\Entity\AlumnoCursoHistorico;
use App\Entity\Curso;
use App\Entity\Evaluacion;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Calificaciones para el profesor: solo los cursos que tiene asignados.
 *
 * El prefijo /profesor/... ya está cubierto por la regla ^/profesor de access_control, así
 * que no hace falta tocar security.yaml. Que el profesor solo alcance sus cursos lo
 * garantiza CursoVoter, no el listado.
 *
 * @Route("/profesor/calificaciones")
 */
#[IsGranted('ROLE_PROFESOR')]
class CalificacionProfesorController extends AbstractCalificacionController
{
    protected function rutaBase(): string
    {
        return 'app_profesor_calificaciones';
    }

    /**
     * El profesor también puede crear y editar las evaluaciones de sus cursos: es quien
     * las toma. Borrar queda igualmente cubierto por el Voter.
     */
    protected function puedeAdministrarEvaluaciones(): bool
    {
        return true;
    }

    /**
     * @return Curso[]
     */
    protected function cursosVisibles(): array
    {
        $user = $this->getUser();
        $profesor = $user->getProfesor();
        if (!$profesor) {
            return [];
        }

        $instituto = $user->getInstituto();

        // CursoRepository::findByProfesor() no filtra por instituto, así que se filtra acá.
        return array_values(array_filter(
            $this->cursoRepository->findByProfesor($profesor),
            fn (Curso $curso) => $curso->getInstituto() && $instituto
                && $curso->getInstituto()->getId() === $instituto->getId()
        ));
    }

    /**
     * @Route("/", name="app_profesor_calificaciones_index", methods={"GET"})
     */
    public function index(): Response
    {
        return $this->pantallaIndex();
    }

    /**
     * @Route("/curso/{id}", name="app_profesor_calificaciones_curso", methods={"GET"})
     */
    public function curso(Curso $curso): Response
    {
        return $this->pantallaCurso($curso);
    }

    /**
     * @Route("/curso/{id}/nueva", name="app_profesor_calificaciones_evaluacion_nueva", methods={"GET", "POST"})
     */
    public function nueva(Request $request, Curso $curso): Response
    {
        return $this->pantallaFormEvaluacion($request, $curso, null);
    }

    /**
     * @Route("/{id}/editar", name="app_profesor_calificaciones_evaluacion_editar", methods={"GET", "POST"})
     */
    public function editar(Request $request, Evaluacion $evaluacion): Response
    {
        return $this->pantallaFormEvaluacion($request, $evaluacion->getCurso(), $evaluacion);
    }

    /**
     * @Route("/{id}/eliminar", name="app_profesor_calificaciones_evaluacion_eliminar", methods={"POST"})
     */
    public function eliminar(Request $request, Evaluacion $evaluacion): Response
    {
        return $this->accionEliminarEvaluacion($request, $evaluacion);
    }

    /**
     * @Route("/{id}", name="app_profesor_calificaciones_grilla", methods={"GET"})
     */
    public function grilla(Evaluacion $evaluacion): Response
    {
        return $this->pantallaGrilla($evaluacion);
    }

    /**
     * @Route("/{id}/guardar", name="app_profesor_calificaciones_guardar", methods={"POST"})
     */
    public function guardar(Request $request, Evaluacion $evaluacion): Response
    {
        return $this->accionGuardarGrilla($request, $evaluacion);
    }
    /**
     * @Route("/boletin/{id}", name="app_profesor_calificaciones_boletin", methods={"GET"})
     */
    public function boletin(AlumnoCursoHistorico $historico): Response
    {
        return $this->pantallaBoletin($historico);
    }

    /**
     * @Route("/boletin/{id}/email", name="app_profesor_calificaciones_boletin_email", methods={"POST"})
     */
    public function boletinEmail(Request $request, AlumnoCursoHistorico $historico): Response
    {
        return $this->accionEnviarBoletin($request, $historico);
    }
}
