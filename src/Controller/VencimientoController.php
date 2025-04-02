<?php

namespace App\Controller;

use App\Entity\Vencimiento;
use App\Repository\VencimientoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @Route("/instituto/vencimientos")
 */
class VencimientoController extends AbstractController
{
    /**
     * @Route("/", name="vencimiento_index", methods={"GET"})
     */
    public function index(VencimientoRepository $vencimientoRepository): Response
    {
        $instituto = $this->getUser()->getInstituto();
        $vencimientos = $vencimientoRepository->findByInstitutoOrdered($instituto);
        
        return $this->render('vencimiento/index.html.twig', [
            'vencimientos' => $vencimientos,
            'instituto' => $instituto
        ]);
    }

    /**
     * @Route("/new", name="vencimiento_new", methods={"GET", "POST"})
     */
    public function new(Request $request, EntityManagerInterface $entityManager, ValidatorInterface $validator): Response
    {
        $vencimiento = new Vencimiento();
        $instituto = $this->getUser()->getInstituto();
        $vencimiento->setInstituto($instituto);

        if ($request->isMethod('POST')) {
            $diaVencimiento = $request->request->get('diaVencimiento');
            $porcentajeInteres = $request->request->get('porcentajeInteres');
            
            // Obtener el último orden
            $ultimoOrden = $entityManager->getRepository(Vencimiento::class)
                ->createQueryBuilder('v')
                ->select('MAX(v.orden)')
                ->where('v.instituto = :instituto')
                ->setParameter('instituto', $instituto)
                ->getQuery()
                ->getSingleScalarResult();

            $vencimiento->setDiaVencimiento((int)$diaVencimiento);
            $vencimiento->setPorcentajeInteres((float)$porcentajeInteres);
            $vencimiento->setOrden($ultimoOrden ? $ultimoOrden + 1 : 1);

            $errors = $validator->validate($vencimiento);
            if (count($errors) === 0) {
                $entityManager->persist($vencimiento);
                $entityManager->flush();
                $this->addFlash('success', 'Vencimiento creado correctamente.');
                return $this->redirectToRoute('vencimiento_index');
            }
        }

        return $this->render('vencimiento/new.html.twig', [
            'vencimiento' => $vencimiento,
            'instituto' => $instituto
        ]);
    }

    /**
     * @Route("/{id}/edit", name="vencimiento_edit", methods={"GET", "POST"})
     */
    public function edit(Request $request, Vencimiento $vencimiento, EntityManagerInterface $entityManager, ValidatorInterface $validator): Response
    {
        if ($request->isMethod('POST')) {
            $diaVencimiento = $request->request->get('diaVencimiento');
            $porcentajeInteres = $request->request->get('porcentajeInteres');
            $orden = $request->request->get('orden');

            $vencimiento->setDiaVencimiento((int)$diaVencimiento);
            $vencimiento->setPorcentajeInteres((float)$porcentajeInteres);
            $vencimiento->setOrden((int)$orden);

            $errors = $validator->validate($vencimiento);
            if (count($errors) === 0) {
                $entityManager->flush();
                $this->addFlash('success', 'Vencimiento actualizado correctamente.');
                return $this->redirectToRoute('vencimiento_index');
            }
        }

        return $this->render('vencimiento/edit.html.twig', [
            'vencimiento' => $vencimiento,
            'instituto' => $vencimiento->getInstituto()
        ]);
    }

    /**
     * @Route("/{id}", name="vencimiento_delete", methods={"POST"})
     */
    public function delete(Request $request, Vencimiento $vencimiento, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$vencimiento->getId(), $request->request->get('_token'))) {
            $entityManager->remove($vencimiento);
            $entityManager->flush();
            $this->addFlash('success', 'Vencimiento eliminado correctamente.');
        }

        return $this->redirectToRoute('vencimiento_index');
    }
} 