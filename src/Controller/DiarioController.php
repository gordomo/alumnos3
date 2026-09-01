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
 * Diario de clases y materiales, para el administrador del instituto: todos los cursos.
 *
 * El prefijo /instituto ya exige ROLE_ADMIN_INSTITUTO en access_control.
 *
 * @Route("/instituto/diario")
 */
#[IsGranted('ROLE_ADMIN_INSTITUTO')]
class DiarioController extends AbstractDiarioController
{
    protected function rutaBase(): string
    {
        return 'app_diario';
    }

    protected function cursosVisibles(): array
    {
        return $this->cursoRepository->findByInstituto($this->getUser()->getInstituto());
    }

    /**
     * @Route("/", name="app_diario_index", methods={"GET"})
     */
    public function index(): Response
    {
        return $this->pantallaIndex();
    }

    /**
     * @Route("/curso/{id}", name="app_diario_curso", methods={"GET"})
     */
    public function curso(Curso $curso): Response
    {
        return $this->pantallaCurso($curso);
    }

    /**
     * @Route("/curso/{id}/clase", name="app_diario_clase_guardar", methods={"POST"})
     */
    public function guardarClase(Request $request, Curso $curso): Response
    {
        return $this->accionGuardarClase($request, $curso);
    }

    /**
     * @Route("/clase/{id}/eliminar", name="app_diario_clase_eliminar", methods={"POST"})
     */
    public function eliminarClase(Request $request, ClaseDictada $clase): Response
    {
        return $this->accionEliminarClase($request, $clase);
    }

    /**
     * @Route("/curso/{id}/material", name="app_diario_material_guardar", methods={"POST"})
     */
    public function guardarMaterial(Request $request, Curso $curso): Response
    {
        return $this->accionGuardarMaterial($request, $curso);
    }

    /**
     * @Route("/material/{id}/eliminar", name="app_diario_material_eliminar", methods={"POST"})
     */
    public function eliminarMaterial(Request $request, MaterialCurso $material): Response
    {
        return $this->accionEliminarMaterial($request, $material);
    }
}
