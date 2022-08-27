<?php

namespace App\Controller;

use App\Entity\AlumnosPagos;
use App\Form\AlumnosPagosType;
use App\Repository\AlumnoRepository;
use App\Repository\AlumnosPagosRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/alumnos/pagos")
 */
class AlumnosPagosController extends AbstractController
{
    /**
     * @Route("/", name="app_alumnos_pagos_index", methods={"GET"})
     */
    public function index(Request $request, AlumnosPagosRepository $alumnosPagosRepository, AlumnoRepository $alumnoRepository): Response
    {
        $alumnoId = $request->get('alumno', 0);
        $alumno = $alumnoRepository->find($alumnoId);
        $nombreApellido = $alumno ? $alumno->getNombreApellido() : '';
        $pagos = $alumnosPagosRepository->findAll();

        if ($alumnoId) {
            $pagos = $alumnosPagosRepository->findBy(['alumno' => $alumnoId]);
        }


        return $this->render('alumnos_pagos/index.html.twig', [
            'alumnos_pagos' => $pagos,
            'alumno' => $nombreApellido,
            'alumnoId' => $alumnoId
        ]);
    }

    /**
     * @Route("/new", name="app_alumnos_pagos_new", methods={"GET", "POST"})
     */
    public function new(Request $request, AlumnosPagosRepository $alumnosPagosRepository, AlumnoRepository $alumnoRepository): Response
    {
        $alumnosPago = new AlumnosPagos();
        $alumnoId = $request->get('alumno', 0);
        $alumno = $alumnoRepository->find($alumnoId);

        //$alumno->getDebeMes();
        $hoy = new \DateTime();
        $alumnosPago->setAlumno($alumno);
        if (!empty($alumno->getCurso())) {
            $alumnosPago->setCurso($alumno->getCurso()[0]);
        }

        $alumnosPago->setFecha($hoy);
        $alumnosPago->setAno($hoy->format('Y'));
        $mes = $hoy->format('m');
        if($mes[0] == 0){
            $mes = $mes[1];
        }
        $alumnosPago->setMes($mes);

        $curso = $alumno->getCurso()->getValues();
        $precio = isset($curso[0]) ? $curso[0]->getPrecio() : 0;
        $bonificacionHermanos = false;
        $recargo = false;

        if (!empty($alumno->getHermanos())) {
            $precio = $precio * 0.80;
            $bonificacionHermanos = true;
        }

        if ($hoy->format('d') > 20) {
            $precio = $precio * 1.10;
            $recargo = true;
        }

        $alumnosPago->setMonto($precio);

        $form = $this->createForm(AlumnosPagosType::class, $alumnosPago);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $alumnosPagosRepository->add($alumnosPago);
            return $this->redirectToRoute('app_alumnos_pagos_index', ['alumno' => $alumnoId], Response::HTTP_SEE_OTHER);
        }

        return $this->renderForm('alumnos_pagos/new.html.twig', [
            'alumnos_pago' => $alumnosPago,
            'form' => $form,
            'alumno' => $alumno->getNombreApellido(),
            'alumnoId' => $alumnoId,
            'bonificacionHermanos' => $bonificacionHermanos,
            'recargo' => $recargo
        ]);
    }

    /**
     * @Route("/{id}", name="app_alumnos_pagos_show", methods={"GET"})
     */
    public function show(AlumnosPagos $alumnosPago): Response
    {
        return $this->render('alumnos_pagos/show.html.twig', [
            'alumnos_pago' => $alumnosPago,
        ]);
    }

    /**
     * @Route("/{id}/edit", name="app_alumnos_pagos_edit", methods={"GET", "POST"})
     */
    public function edit(Request $request, AlumnosPagos $alumnosPago, AlumnosPagosRepository $alumnosPagosRepository): Response
    {
        $form = $this->createForm(AlumnosPagosType::class, $alumnosPago);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $alumnosPagosRepository->add($alumnosPago);
            return $this->redirectToRoute('app_alumnos_pagos_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->renderForm('alumnos_pagos/edit.html.twig', [
            'alumnos_pago' => $alumnosPago,
            'form' => $form,
            'bonificacionHermanos' => false,
            'recargo' => false,
        ]);
    }

    /**
     * @Route("/{id}", name="app_alumnos_pagos_delete", methods={"POST"})
     */
    public function delete(Request $request, AlumnosPagos $alumnosPago, AlumnosPagosRepository $alumnosPagosRepository): Response
    {
        if ($this->isCsrfTokenValid('delete'.$alumnosPago->getId(), $request->request->get('_token'))) {
            $alumnosPagosRepository->remove($alumnosPago);
        }

        return $this->redirectToRoute('app_alumnos_pagos_index', [], Response::HTTP_SEE_OTHER);
    }
}
