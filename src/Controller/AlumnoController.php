<?php

namespace App\Controller;

use App\Entity\Alumno;
use App\Form\AlumnoType;
use App\Repository\AlumnoRepository;
use App\Repository\CursoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Knp\Component\Pager\PaginatorInterface;

/**
 * @Route("/admin/alumno")
 */
class AlumnoController extends AbstractController
{
    /**
     * @Route("/", name="app_alumno_index", methods={"GET", "POST"})
     */
    public function index(Request $request, AlumnoRepository $alumnoRepository, CursoRepository $cursoRepository, PaginatorInterface $paginator): Response
    {
        $limit = $request->get('limit', 20);
        $currentPage = $request->get('page', 1);
        $busqueda = $request->get('busqueda', '');
        $activo = $request->get('activo', 'todos');
        $cursoSelected = $request->get('cursoSelected', '0');
        $totalAgregados = $request->get('totalAgregados', '');
        $alumnosQueNoGuardadamos = $request->get('alumnosQueNoGuardadamos', []);

        if ($cursoSelected != 0) {
            $limit = 10000000000000000;
        }

        $instituto = $this->getUser()->getInstituto();
        $alumnos = $alumnoRepository->getAlumnoByNombreEstadoYcursoQuery($busqueda, $activo, $cursoSelected, $instituto);

        $alumnosPagination = $paginator->paginate(
            $alumnos, 
            $currentPage, 
            $limit
        );

        return $this->render('alumno/index.html.twig', [
            'alumnos' => $alumnosPagination,
            'busqueda' => $busqueda,
            'total' => $alumnosPagination->getTotalItemCount(),
            'activo' => $activo,
            'totalAgregados' => $totalAgregados,
            'alumnosQueNoGuardadamos' => $alumnosQueNoGuardadamos,
            'cursos' => $cursoRepository->findBy(['instituto' => $instituto]),
            'cursoSelected' => $cursoSelected
        ]);
    }

    /**
     * @Route("/new", name="app_alumno_new", methods={"GET", "POST"})
     */
    public function new(Request $request, AlumnoRepository $alumnoRepository): Response
    {
        $alumno = new Alumno();
        $instituto = $this->getUser()->getInstituto();
        $alumno->setInstituto($instituto);

        $form = $this->createForm(AlumnoType::class, $alumno, ['is_edit' => false, 'instituto' => $instituto]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            if ( count($form->get('curso')->getData()) < 1 ) {
                $this->addFlash('danger', 'necesita seleccionar al menos un curso');
                return $this->renderForm('alumno/new.html.twig', [
                    'alumno' => $alumno,
                    'form' => $form,
                    'hermanos' => $alumno->getHermanos()
                ]);
            }

            $alumnoRepository->add($alumno);

            $this->setearHermandad($request, $alumno, $alumnoRepository);

            return $this->redirectToRoute('app_alumno_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->renderForm('alumno/new.html.twig', [
            'alumno' => $alumno,
            'form' => $form,
            'hermanos' => $alumno->getHermanos()
        ]);
    }

    /**
     * @Route("/{id}", name="app_alumno_show", methods={"GET"})
     */
    public function show(Alumno $alumno, AlumnoRepository $alumnoRepository): Response
    {
        $hermanos = [];
        foreach ($alumno->getHermanos() as $hermanoId) {
            $hermanos[] = $alumnoRepository->find($hermanoId);
        }

        return $this->render('alumno/show.html.twig', [
            'alumno' => $alumno,
            'hermanos' => $hermanos
        ]);
    }

    /**
     * @Route("/{id}/edit", name="app_alumno_edit", methods={"GET", "POST"})
     */
    public function edit(Request $request, Alumno $alumno, AlumnoRepository $alumnoRepository): Response
    {
        $instituto = $this->getUser()->getInstituto();
        $form = $this->createForm(AlumnoType::class, $alumno, ['is_edit' => true, 'instituto' => $instituto]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            
            if ( count($form->get('curso')->getData()) < 1 ) {
                $this->addFlash('danger', 'necesita seleccionar al menos un curso');
                return $this->renderForm('alumno/edit.html.twig', [
                    'alumno' => $alumno,
                    'form' => $form,
                    'hermanos' => $alumno->getHermanos()
                ]);
            }

            $alumnoRepository->add($alumno);
            $this->setearHermandad($request, $alumno, $alumnoRepository);

            return $this->redirectToRoute('app_alumno_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->renderForm('alumno/edit.html.twig', [
            'alumno' => $alumno,
            'form' => $form,
            'hermanos' => $alumno->getHermanos()
        ]);
    }

    /**
     * @Route("/{id}", name="app_alumno_delete", methods={"POST"})
     */
    public function delete(Request $request, Alumno $alumno, AlumnoRepository $alumnoRepository): Response
    {
        if ($this->isCsrfTokenValid('delete'.$alumno->getId(), $request->request->get('_token'))) {
            //TODO, ver si quieren borrar la relación con los hermanos
            $alumnoRepository->remove($alumno);
        }

        return $this->redirectToRoute('app_alumno_index', [], Response::HTTP_SEE_OTHER);
    }

    private function setearHermandad($request, Alumno $alumno, $alumnoRepository) {
        $hermanosForm = $request->request->get('alumno')['hermanos'] ?? [];

        $hermanosActuales = $alumno->getHermanos();

        $todosLosHermanosActualesDelAlumno = [];
        foreach ($hermanosActuales as $hermanoActual) {
            $hermanoActualObj = $alumnoRepository->find($hermanoActual);
            $todosLosHermanosActualesDelAlumno[] = $hermanoActualObj;
            $otrosHermanosActuales = $hermanoActualObj->getHermanos();

            foreach ($otrosHermanosActuales as $otroHermanoActual) {
                $todosLosHermanosActualesDelAlumno[] = $otroHermanoActual;
                $otroHermanoActualDelAlumno = $alumnoRepository->find($otroHermanoActual);
                $otrosHermanosActualesMas = $otroHermanoActualDelAlumno->getHermanos();
                if(in_array($alumno->getId(), $otrosHermanosActualesMas)) {
                    $key = array_search($alumno->getId(), $otrosHermanosActualesMas);
                    unset($otrosHermanosActualesMas[$key]);
                    $otroHermanoActualDelAlumno->setHermanos($otrosHermanosActualesMas);
                    $alumnoRepository->add($otroHermanoActualDelAlumno);
                }
            }
        }

        $alumno->setHermanos([]);
        $alumnoRepository->add($alumno);

        $todosLosHermanosDelAlumno = [];

        foreach ($hermanosForm as $hermano) {
            $todosLosHermanosDelAlumno[] = $hermano;
            $hermanoDelAlumno = $alumnoRepository->find($hermano);
            $otrosHermanos = $hermanoDelAlumno->getHermanos();

            foreach ($otrosHermanos as $otroHermano) {
                $todosLosHermanosDelAlumno[] = $otroHermano;
                $otroHermanoDelAlumno = $alumnoRepository->find($otroHermano);
                $otrosHermanosMas = $otroHermanoDelAlumno->getHermanos();
                if(!in_array($alumno->getId(), $otrosHermanosMas)) {
                    array_push($otrosHermanosMas, $alumno->getId());
                    $otroHermanoDelAlumno->setHermanos($otrosHermanosMas);
                    $alumnoRepository->add($otroHermanoDelAlumno);
                }
            }

            if(!in_array($alumno->getId(), $otrosHermanos)) {
                array_push($otrosHermanos, $alumno->getId());
                $hermanoDelAlumno->setHermanos($otrosHermanos);
                $alumnoRepository->add($hermanoDelAlumno);
            }

            $alumno->setHermanos($todosLosHermanosDelAlumno);
            $alumnoRepository->add($alumno);
        }
    }
}
