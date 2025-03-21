<?php

namespace App\Controller;

use App\Entity\Instituto;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

/**
 * @Route("/instituto/config")
 */
class InstitutoConfigController extends AbstractController
{
    /**
     * @Route("/", name="instituto_config_index", methods={"GET"})
     */
    public function index(): Response
    {
        $instituto = $this->getUser()->getInstituto();
        return $this->render('instituto_config/index.html.twig', [
            'instituto' => $instituto,
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
                            $this->getParameter('institutos_directory'),
                            $newFilename
                        );
                    } catch (FileException $e) {
                        $this->addFlash('error', 'No se pudo subir el logo.');
                        return $this->redirectToRoute('instituto_config_edit');
                    }
                    
                    // Eliminar el logo anterior si existe
                    if ($instituto->getLogo()) {
                        $oldLogoPath = $this->getParameter('institutos_directory').'/'.$instituto->getLogo();
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
} 