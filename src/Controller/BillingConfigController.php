<?php

namespace App\Controller;

use App\Entity\BillingConfig;
use App\Repository\BillingConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @Route("/admin/billing-config")
 */
class BillingConfigController extends AbstractController
{
    /**
     * Verifica que el usuario sea SUPER_ADMIN
     */
    private function checkSuperAdmin(): ?Response
    {
        $usuarioActual = $this->getUser();
        
        if (!$usuarioActual || !in_array('ROLE_SUPER_ADMIN', $usuarioActual->getRoles())) {
            $this->addFlash('danger', 'No tienes permisos para acceder a esta sección.');
            return $this->redirectToRoute('admin_instituto_index');
        }
        
        return null;
    }

    /**
     * @Route("/", name="admin_billing_config_index", methods={"GET"})
     */
    public function index(BillingConfigRepository $billingConfigRepository): Response
    {
        if ($response = $this->checkSuperAdmin()) {
            return $response;
        }

        $configs = $billingConfigRepository->findAll();

        return $this->render('admin/billing_config/index.html.twig', [
            'configs' => $configs,
        ]);
    }

    /**
     * @Route("/new", name="admin_billing_config_new", methods={"GET", "POST"})
     */
    public function new(Request $request, EntityManagerInterface $entityManager, ValidatorInterface $validator): Response
    {
        if ($response = $this->checkSuperAdmin()) {
            return $response;
        }

        $config = new BillingConfig();
        $config->setConfigKey('price_per_student_monthly');
        $config->setPricePerStudentMonthly(50.0);

        if ($request->isMethod('POST')) {
            $price = $request->request->get('price_per_student_monthly');
            $description = $request->request->get('description');

            if ($price === null || $price === '') {
                $this->addFlash('danger', 'El precio es obligatorio.');
                return $this->render('admin/billing_config/new.html.twig', [
                    'config' => $config,
                ]);
            }

            $price = (float) $price;
            
            if ($price <= 0) {
                $this->addFlash('danger', 'El precio debe ser mayor a 0.');
                return $this->render('admin/billing_config/new.html.twig', [
                    'config' => $config,
                ]);
            }

            // Verificar si ya existe una configuración con esta clave
            $existingConfig = $entityManager->getRepository(BillingConfig::class)
                ->findOneBy(['configKey' => 'price_per_student_monthly']);
            
            if ($existingConfig) {
                $this->addFlash('danger', 'Ya existe una configuración de precio. Por favor, edítala en lugar de crear una nueva.');
                return $this->redirectToRoute('admin_billing_config_edit', ['id' => $existingConfig->getId()]);
            }

            $config->setPricePerStudentMonthly($price);
            $config->setDescription($description);

            $errors = $validator->validate($config);
            if (count($errors) === 0) {
                $entityManager->persist($config);
                $entityManager->flush();

                $this->addFlash('success', 'Configuración de facturación creada exitosamente.');
                return $this->redirectToRoute('admin_billing_config_index');
            }
        }

        return $this->render('admin/billing_config/new.html.twig', [
            'config' => $config,
        ]);
    }

    /**
     * @Route("/{id}", name="admin_billing_config_show", methods={"GET"})
     */
    public function show(BillingConfig $billingConfig): Response
    {
        if ($response = $this->checkSuperAdmin()) {
            return $response;
        }

        return $this->render('admin/billing_config/show.html.twig', [
            'config' => $billingConfig,
        ]);
    }

    /**
     * @Route("/{id}/edit", name="admin_billing_config_edit", methods={"GET", "POST"})
     */
    public function edit(Request $request, BillingConfig $billingConfig, EntityManagerInterface $entityManager, ValidatorInterface $validator): Response
    {
        if ($response = $this->checkSuperAdmin()) {
            return $response;
        }

        if ($request->isMethod('POST')) {
            $price = $request->request->get('price_per_student_monthly');
            $description = $request->request->get('description');

            if ($price === null || $price === '') {
                $this->addFlash('danger', 'El precio es obligatorio.');
                return $this->render('admin/billing_config/edit.html.twig', [
                    'config' => $billingConfig,
                ]);
            }

            $price = (float) $price;
            
            if ($price <= 0) {
                $this->addFlash('danger', 'El precio debe ser mayor a 0.');
                return $this->render('admin/billing_config/edit.html.twig', [
                    'config' => $billingConfig,
                ]);
            }

            $billingConfig->setPricePerStudentMonthly($price);
            $billingConfig->setDescription($description);

            $errors = $validator->validate($billingConfig);
            if (count($errors) === 0) {
                $entityManager->flush();

                $this->addFlash('success', 'Configuración de facturación actualizada exitosamente.');
                return $this->redirectToRoute('admin_billing_config_index');
            }
        }

        return $this->render('admin/billing_config/edit.html.twig', [
            'config' => $billingConfig,
        ]);
    }

    /**
     * @Route("/{id}", name="admin_billing_config_delete", methods={"POST"})
     */
    public function delete(Request $request, BillingConfig $billingConfig, EntityManagerInterface $entityManager): Response
    {
        if ($response = $this->checkSuperAdmin()) {
            return $response;
        }

        if ($this->isCsrfTokenValid('delete'.$billingConfig->getId(), $request->request->get('_token'))) {
            $entityManager->remove($billingConfig);
            $entityManager->flush();
            
            $this->addFlash('success', 'Configuración de facturación eliminada exitosamente.');
        }

        return $this->redirectToRoute('admin_billing_config_index');
    }
}
