<?php

namespace App\Controller;

use App\Entity\Instituto;
use App\Entity\Vencimiento;
use App\Repository\VencimientoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @Route("/instituto/config")
 */
class InstitutoConfigController extends AbstractController
{
    /**
     * @Route("/", name="instituto_config_index", methods={"GET"})
     */
    public function index(VencimientoRepository $vencimientoRepository): Response
    {
        $instituto = $this->getUser()->getInstituto();
        $vencimientos = $vencimientoRepository->findByInstitutoOrdered($instituto);
        
        return $this->render('instituto_config/index.html.twig', [
            'instituto' => $instituto,
            'vencimientos' => $vencimientos
        ]);
    }

    /**
     * @Route("/edit", name="instituto_config_edit", methods={"GET", "POST"})
     */
    public function edit(Request $request, EntityManagerInterface $entityManager, SluggerInterface $slugger): Response
    {
        $instituto = $this->getUser()->getInstituto();
        
        if ($request->isMethod('POST')) {
            $instituto->setNombre($request->request->get('nombre'));
            $instituto->setEmail($request->request->get('email'));
            $instituto->setTel($request->request->get('tel'));
            $instituto->setDir($request->request->get('dir'));

            // Manejo del logo
            if ($request->files->has('logo')) {
                $logoFile = $request->files->get('logo');
                if ($logoFile) {
                    $originalFilename = pathinfo($logoFile->getClientOriginalName(), PATHINFO_FILENAME);
                    $safeFilename = $slugger->slug($originalFilename);
                    $newFilename = $safeFilename.'-'.uniqid().'.'.$logoFile->guessExtension();
                    
                    try {
                        $logoFile->move(
                            $this->getParameter('logos_directory'),
                            $newFilename
                        );
                    } catch (FileException $e) {
                        $this->addFlash('error', 'No se pudo subir el logo.');
                        return $this->redirectToRoute('instituto_config_edit');
                    }
                    
                    // Eliminar el logo anterior si existe
                    if ($instituto->getLogo()) {
                        $oldLogoPath = $this->getParameter('logos_directory').'/'.$instituto->getLogo();
                        if (file_exists($oldLogoPath)) {
                            unlink($oldLogoPath);
                        }
                    }
                    
                    $instituto->setLogo($newFilename);
                }
            }

            $entityManager->flush();
            $this->addFlash('success', 'La configuración se ha actualizado correctamente.');
            return $this->redirectToRoute('instituto_config_index');
        }

        return $this->render('instituto_config/edit.html.twig', [
            'instituto' => $instituto,
        ]);
    }

    /**
     * @Route("/vencimiento/new", name="instituto_config_vencimiento_new", methods={"GET", "POST"})
     */
    public function newVencimiento(Request $request, EntityManagerInterface $entityManager, ValidatorInterface $validator): Response
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
                return $this->redirectToRoute('instituto_config_index');
            } else {
                foreach ($errors as $error) {
                    $this->addFlash('danger', $error->getMessage());
                }
            }
        }

        return $this->render('instituto_config/vencimiento_new.html.twig', [
            'vencimiento' => $vencimiento,
            'instituto' => $instituto
        ]);
    }

    /**
     * @Route("/vencimiento/{id}/edit", name="instituto_config_vencimiento_edit", methods={"GET", "POST"})
     */
    public function editVencimiento(Request $request, Vencimiento $vencimiento, EntityManagerInterface $entityManager, ValidatorInterface $validator): Response
    {
        if ($request->isMethod('POST')) {
            $diaVencimiento = $request->request->get('diaVencimiento');
            $porcentajeInteres = $request->request->get('porcentajeInteres');

            $vencimiento->setDiaVencimiento((int)$diaVencimiento);
            $vencimiento->setPorcentajeInteres((float)$porcentajeInteres);

            
            $errors = $validator->validate($vencimiento);
            
            if (count($errors) === 0) {
                $entityManager->persist($vencimiento);
                $entityManager->flush();
                $this->addFlash('success', 'Vencimiento actualizado correctamente.');
                return $this->redirectToRoute('instituto_config_index');
            } else {
                foreach ($errors as $error) {
                    $this->addFlash('danger', $error->getMessage());
                }
            }
        }

        return $this->render('instituto_config/vencimiento_edit.html.twig', [
            'vencimiento' => $vencimiento,
            'instituto' => $vencimiento->getInstituto()
        ]);
    }

    /**
     * @Route("/vencimiento/{id}", name="instituto_config_vencimiento_delete", methods={"POST"})
     */
    public function deleteVencimiento(Request $request, Vencimiento $vencimiento, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$vencimiento->getId(), $request->request->get('_token'))) {
            $entityManager->remove($vencimiento);
            $entityManager->flush();
            $this->addFlash('success', 'Vencimiento eliminado correctamente.');
        }

        return $this->redirectToRoute('instituto_config_index');
    }
} 