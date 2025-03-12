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
    public function index(CursoRepository $cursoRepository): Response
    {
        // Obtener el instituto del usuario actual
        $instituto = $this->getUser()->getInstituto();
        $cursos = $cursoRepository->findBy(['disabled' => false, 'instituto' => $instituto]);
        $cursosDesabilitados = $cursoRepository->findBy(['disabled' => true, 'instituto' => $instituto]);
        return $this->render('curso/index.html.twig', [
            'cursos' => $cursos,
            'totalCursos' => count($cursos),
            'cursosDesabilitados' => $cursosDesabilitados,
            'totalCursosDesabilitados' => count($cursosDesabilitados),
        ]);
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
        $form = $this->createForm(CursoType::class, $curso, ['allow_extra_fields' =>true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $cursoRepository->add($curso);
            return $this->redirectToRoute('app_curso_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->renderForm('curso/new.html.twig', [
            'curso' => $curso,
            'form' => $form,
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
        $form = $this->createForm(CursoType::class, $curso, ['allow_extra_fields' =>true, 'is_edit' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
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
}
