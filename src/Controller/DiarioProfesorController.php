<?php

namespace App\Controller;

use App\Entity\ClaseDictada;
use App\Entity\Curso;
use App\Entity\MaterialCurso;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Diario de clases y materiales para el profesor: solo los cursos que tiene asignados.
 *
 * El prefijo /profesor ya exige ROLE_PROFESOR en access_control. El aislamiento por curso lo
 * resuelve CursoVoter.
 *
 * @Route("/profesor/diario")
 */
#[IsGranted('ROLE_PROFESOR')]
class DiarioProfesorController extends AbstractDiarioController
{
    protected function rutaBase(): string
    {
        return 'app_diario_profesor';
    }

    protected function cursosVisibles(): array
    {
        $usuario = $this->getUser();
        $profesor = $usuario->getProfesor();

        if (!$profesor) {
            return [];
        }

        // findByProfesor() no filtra por instituto, así que se filtra acá: el Voter igual
        // vuelve a validarlo por curso, pero el listado no debe mostrar cursos ajenos.
        $instituto = $usuario->getInstituto();

        return array_values(array_filter(
            $this->cursoRepository->findByProfesor($profesor),
            static fn(\App\Entity\Curso $curso) => $curso->getInstituto() === $instituto
        ));
    }

    /**
     * @Route("/", name="app_diario_profesor_index", methods={"GET"})
     */
    public function index(): Response
    {
        return $this->pantallaIndex();
    }

    /**
     * @Route("/curso/{id}", name="app_diario_profesor_curso", methods={"GET"})
     */
    public function curso(Curso $curso): Response
    {
        return $this->pantallaCurso($curso);
    }

    /**
     * @Route("/curso/{id}/clase", name="app_diario_profesor_clase_guardar", methods={"POST"})
     */
    public function guardarClase(Request $request, Curso $curso): Response
    {
        return $this->accionGuardarClase($request, $curso);
    }

    /**
     * @Route("/clase/{id}/eliminar", name="app_diario_profesor_clase_eliminar", methods={"POST"})
     */
    public function eliminarClase(Request $request, ClaseDictada $clase): Response
    {
        return $this->accionEliminarClase($request, $clase);
    }

    /**
     * @Route("/curso/{id}/material", name="app_diario_profesor_material_guardar", methods={"POST"})
     */
    public function guardarMaterial(Request $request, Curso $curso): Response
    {
        return $this->accionGuardarMaterial($request, $curso);
    }

    /**
     * @Route("/material/{id}/eliminar", name="app_diario_profesor_material_eliminar", methods={"POST"})
     */
    public function eliminarMaterial(Request $request, MaterialCurso $material): Response
    {
        return $this->accionEliminarMaterial($request, $material);
    }
}
