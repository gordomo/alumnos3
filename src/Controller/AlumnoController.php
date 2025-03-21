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
        $limit = $request->get('limit', 10);
        $currentPage = $request->get('page', 1);
        $busqueda = $request->get('busqueda', '');
        $order = $request->get('order', 'asc');
        $sort = $request->get('sort', 'apellido');
        $activo = $request->get('activo', 'todos');
        $cursoSelected = $request->get('cursoSelected', '0');
        $totalAgregados = $request->get('totalAgregados', '');
        $alumnosQueNoGuardadamos = $request->get('alumnosQueNoGuardadamos', []);

        if ($cursoSelected != 0) {
            $limit = 10000000000000000;
        }

        $instituto = $this->getUser()->getInstituto();
        
        $alumnosQuery = $this->createQuery($alumnoRepository, $instituto, $sort, $order, $busqueda, $activo);

        $alumnos = $paginator->paginate(
            $alumnosQuery, 
            $currentPage, 
            $limit
        );

        return $this->render('alumno/index.html.twig', [
            'alumnos' => $alumnos,
            'busqueda' => $busqueda,
            'total' => $alumnos->getTotalItemCount(),
            'order' => $order,
            'sort' => $sort,
            'activo' => $activo,
            'totalAgregados' => $totalAgregados,
            'alumnosQueNoGuardadamos' => $alumnosQueNoGuardadamos,
            'cursos' => $cursoRepository->findBy(['instituto' => $instituto]),
            'cursoSelected' => $cursoSelected
        ]);
    }

    /**
     * Crea una consulta para obtener alumnos con los filtros especificados
     */
    private function createQuery(
        AlumnoRepository $alumnoRepository,
        $instituto,
        string $sort,
        string $order,
        ?string $busqueda = null,
        ?string $activo = null
    ) {
        $qb = $alumnoRepository->createQueryBuilder('a')
            ->where('a.instituto = :instituto')
            ->setParameter('instituto', $instituto);

        if ($busqueda) {
            $qb->andWhere('a.apellido LIKE :busqueda OR a.nombre LIKE :busqueda')
               ->setParameter('busqueda', '%' . $busqueda . '%');
        }

        if ($activo != 'todos') {
            $qb->andWhere('a.activo = :activo')
                ->setParameter('activo', $activo);
        }

        // Ordenamiento por nombre o apellido
        if ($sort === 'nombre') {
            $qb->orderBy('a.nombre', $order);
        } else {
            $qb->orderBy('a.apellido', $order);
        }

        return $qb;
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
            // Remover relaciones con cursos
            foreach ($alumno->getCurso() as $curso) {
                $alumno->removeCurso($curso);
            }

            // Desarmar relaciones de hermanos
            $hermanosActuales = $alumno->getHermanos();
            foreach ($hermanosActuales as $hermanoId) {
                $hermano = $alumnoRepository->find($hermanoId);
                if ($hermano) {
                    $hermanosDelHermano = $hermano->getHermanos();
                    if (($key = array_search($alumno->getId(), $hermanosDelHermano)) !== false) {
                        unset($hermanosDelHermano[$key]);
                        $hermano->setHermanos(array_values($hermanosDelHermano));
                        $alumnoRepository->add($hermano);
                    }
                }
            }
            $alumno->setHermanos([]);
            $alumnoRepository->remove($alumno);
        }

        return $this->redirectToRoute('app_alumno_index', [], Response::HTTP_SEE_OTHER);
    }

    private function setearHermandad($request, Alumno $alumno, $alumnoRepository) {
        // Obtener los hermanos del formulario y convertirlos a enteros
        $hermanosForm = array_map('intval', $request->request->get('alumno')['hermanos'] ?? []);
        
        // Eliminar duplicados
        $hermanosForm = array_unique($hermanosForm);
        
        // Limpiar todas las relaciones anteriores
        $hermanosActuales = $alumno->getHermanos();
        foreach ($hermanosActuales as $hermanoId) {
            $hermano = $alumnoRepository->find($hermanoId);
            if ($hermano) {
                $hermanosDelHermano = $hermano->getHermanos();
                if (($key = array_search($alumno->getId(), $hermanosDelHermano)) !== false) {
                    unset($hermanosDelHermano[$key]);
                    $hermano->setHermanos(array_values($hermanosDelHermano));
                    $alumnoRepository->add($hermano);
                }
            }
        }
        
        // Limpiar los hermanos del alumno actual
        $alumno->setHermanos([]);
        $alumnoRepository->add($alumno);
        
        // Establecer las nuevas relaciones
        foreach ($hermanosForm as $hermanoId) {
            $hermano = $alumnoRepository->find($hermanoId);
            if ($hermano && $hermano->getId() !== $alumno->getId()) {
                // Agregar el hermano al alumno actual
                $hermanosActuales = $alumno->getHermanos();
                if (!in_array($hermanoId, $hermanosActuales)) {
                    $hermanosActuales[] = $hermanoId;
                    $alumno->setHermanos($hermanosActuales);
                }
                
                // Agregar el alumno actual como hermano
                $hermanosDelHermano = $hermano->getHermanos();
                if (!in_array($alumno->getId(), $hermanosDelHermano)) {
                    $hermanosDelHermano[] = $alumno->getId();
                    $hermano->setHermanos($hermanosDelHermano);
                }
                
                $alumnoRepository->add($alumno);
                $alumnoRepository->add($hermano);
            }
        }
    }
}
