<?php

namespace App\Controller;

use App\Entity\ClaseDictada;
use App\Entity\Curso;
use App\Entity\MaterialCurso;
use App\Repository\ClaseDictadaRepository;
use App\Repository\CursoRepository;
use App\Repository\MaterialCursoRepository;
use App\Security\Voter\CursoVoter;
use App\Service\InstitutoTimezoneService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Diario de clases y materiales de un curso, compartido entre el administrador y el profesor.
 *
 * Las dos cosas viven en la misma pantalla porque se cargan en el mismo momento: al terminar la
 * clase se anota qué se dio y se deja el apunte. Separarlas eran dos pantallas y dos menús para
 * un solo gesto.
 *
 * Mismo patrón que las tareas: las subclases declaran su ruta base y qué cursos listan, y el
 * aislamiento por curso lo resuelve CursoVoter (mismo instituto y, para el profesor, estar
 * asignado al curso).
 */
abstract class AbstractDiarioController extends AbstractController
{
    public function __construct(
        protected ClaseDictadaRepository $claseRepository,
        protected MaterialCursoRepository $materialRepository,
        protected CursoRepository $cursoRepository,
        protected InstitutoTimezoneService $institutoTimezoneService,
        protected EntityManagerInterface $entityManager,
        protected ValidatorInterface $validator
    ) {
    }

    /**
     * @return Curso[]
     */
    abstract protected function cursosVisibles(): array;

    abstract protected function rutaBase(): string;

    protected function rutas(): array
    {
        $base = $this->rutaBase();

        return [
            'index' => $base . '_index',
            'curso' => $base . '_curso',
            'guardarClase' => $base . '_clase_guardar',
            'eliminarClase' => $base . '_clase_eliminar',
            'guardarMaterial' => $base . '_material_guardar',
            'eliminarMaterial' => $base . '_material_eliminar',
        ];
    }

    protected function pantallaIndex(): Response
    {
        $instituto = $this->getUser()->getInstituto();

        return $this->render('diario/index.html.twig', [
            'cursos' => $this->cursosVisibles(),
            'clases' => $this->claseRepository->contarPorCurso($instituto),
            'materiales' => $this->materialRepository->contarPorCurso($instituto),
            'rutas' => $this->rutas(),
        ]);
    }

    protected function pantallaCurso(Curso $curso): Response
    {
        $this->denyAccessUnlessGranted(CursoVoter::CALIFICAR, $curso);

        $hoy = $this->institutoTimezoneService->getNowForInstituto($curso->getInstituto());

        return $this->render('diario/curso.html.twig', [
            'curso' => $curso,
            'clases' => $this->claseRepository->findByCurso($curso),
            'materiales' => $this->materialRepository->findByCurso($curso),
            'tiposMaterial' => MaterialCurso::TIPOS,
            'hoy' => $hoy,
            'rutas' => $this->rutas(),
        ]);
    }

    protected function accionGuardarClase(Request $request, Curso $curso): Response
    {
        $this->denyAccessUnlessGranted(CursoVoter::CALIFICAR, $curso);

        if (!$this->isCsrfTokenValid('diario_clase_' . $curso->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'El formulario expiró. Volvé a intentarlo.');

            return $this->redirectToRoute($this->rutas()['curso'], ['id' => $curso->getId()]);
        }

        $fecha = $this->fecha($request->request->get('fecha'));
        $tema = trim((string) $request->request->get('tema'));

        if (!$fecha || $tema === '') {
            $this->addFlash('danger', 'Hacen falta la fecha y el tema de la clase.');

            return $this->redirectToRoute($this->rutas()['curso'], ['id' => $curso->getId()]);
        }

        // Una clase por curso y por fecha: si ya existe la de ese día, se actualiza en lugar de
        // fallar contra el índice único.
        $clase = $this->claseRepository->findUnaPorFecha($curso, $fecha) ?? new ClaseDictada();

        if (!$clase->getId()) {
            $clase->setInstituto($curso->getInstituto());
            $clase->setCurso($curso);
            $clase->setCargadoPor($this->getUser());
            $this->entityManager->persist($clase);
        }

        $clase->setFecha($fecha);
        $clase->setTema($tema);
        $clase->setDetalle(trim((string) $request->request->get('detalle')) ?: null);

        $errores = $this->validator->validate($clase);
        if (count($errores) > 0) {
            foreach ($errores as $error) {
                $this->addFlash('danger', $error->getMessage());
            }

            return $this->redirectToRoute($this->rutas()['curso'], ['id' => $curso->getId()]);
        }

        $this->entityManager->flush();
        $this->addFlash('success', sprintf('Clase del %s guardada.', $fecha->format('d/m/Y')));

        return $this->redirectToRoute($this->rutas()['curso'], ['id' => $curso->getId()]);
    }

    protected function accionEliminarClase(Request $request, ClaseDictada $clase): Response
    {
        $curso = $clase->getCurso();
        $this->denyAccessUnlessGranted(CursoVoter::CALIFICAR, $curso);

        if ($this->isCsrfTokenValid('eliminar_clase_' . $clase->getId(), (string) $request->request->get('_token'))) {
            $this->entityManager->remove($clase);
            $this->entityManager->flush();
            $this->addFlash('success', 'Clase eliminada del diario.');
        }

        return $this->redirectToRoute($this->rutas()['curso'], ['id' => $curso->getId()]);
    }

    protected function accionGuardarMaterial(Request $request, Curso $curso): Response
    {
        $this->denyAccessUnlessGranted(CursoVoter::CALIFICAR, $curso);

        if (!$this->isCsrfTokenValid('diario_material_' . $curso->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'El formulario expiró. Volvé a intentarlo.');

            return $this->redirectToRoute($this->rutas()['curso'], ['id' => $curso->getId()]);
        }

        $material = new MaterialCurso();
        $material->setInstituto($curso->getInstituto());
        $material->setCurso($curso);
        $material->setCargadoPor($this->getUser());
        $material->setTitulo(trim((string) $request->request->get('titulo')));
        $material->setUrl(trim((string) $request->request->get('url')));
        $material->setTipo($request->request->get('tipo'));
        $material->setDescripcion(trim((string) $request->request->get('descripcion')) ?: null);
        $material->setVisible($request->request->has('visible'));

        $errores = $this->validator->validate($material);
        if (count($errores) > 0) {
            foreach ($errores as $error) {
                $this->addFlash('danger', $error->getMessage());
            }

            return $this->redirectToRoute($this->rutas()['curso'], ['id' => $curso->getId()]);
        }

        $this->entityManager->persist($material);
        $this->entityManager->flush();
        $this->addFlash('success', sprintf('Se agregó "%s" a los materiales.', $material->getTitulo()));

        return $this->redirectToRoute($this->rutas()['curso'], ['id' => $curso->getId()]);
    }

    protected function accionEliminarMaterial(Request $request, MaterialCurso $material): Response
    {
        $curso = $material->getCurso();
        $this->denyAccessUnlessGranted(CursoVoter::CALIFICAR, $curso);

        if ($this->isCsrfTokenValid('eliminar_material_' . $material->getId(), (string) $request->request->get('_token'))) {
            $this->entityManager->remove($material);
            $this->entityManager->flush();
            $this->addFlash('success', 'Material eliminado.');
        }

        return $this->redirectToRoute($this->rutas()['curso'], ['id' => $curso->getId()]);
    }

    private function fecha($valor): ?\DateTime
    {
        if (!is_string($valor) || trim($valor) === '') {
            return null;
        }

        try {
            return new \DateTime(trim($valor));
        } catch (\Exception $e) {
            return null;
        }
    }
}
