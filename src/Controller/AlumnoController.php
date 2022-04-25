<?php

namespace App\Controller;

use App\Entity\Alumno;
use App\Form\AlumnoType;
use App\Repository\AlumnoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/admin/alumno")
 */
class AlumnoController extends AbstractController
{
    /**
     * @Route("/", name="app_alumno_index", methods={"GET"})
     */
    public function index(AlumnoRepository $alumnoRepository): Response
    {
        return $this->render('alumno/index.html.twig', [
            'alumnos' => $alumnoRepository->findAll(),
        ]);
    }

    /**
     * @Route("/new", name="app_alumno_new", methods={"GET", "POST"})
     */
    public function new(Request $request, AlumnoRepository $alumnoRepository): Response
    {
        $alumno = new Alumno();
        $form = $this->createForm(AlumnoType::class, $alumno);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

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
        $form = $this->createForm(AlumnoType::class, $alumno);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $alumnoRepository->add($alumno);
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

    private function setearHermandad($request, $alumno, $alumnoRepository) {
        $hermanosForm = $request->request->get('alumno')['hermanos'] ?? [];
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
