<?php
namespace App\Service;

use App\Entity\{Instituto, InstitutoPaginaPublica};
use App\Repository\InstitutoPaginaPublicaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

class InstitutoPaginaPublicaService
{
    public function __construct(
        private InstitutoPaginaPublicaRepository $repository,
        private EntityManagerInterface $em,
        private SluggerInterface $slugger,
        private RouterInterface $router,
        private string $baseUrl,
        private string $vertical
    ) {}

    public function reservados(): array
    {
        $reservados = ['assets', 'uploads', 'images', 'css', 'js', 'fonts', 'build', 'bundles', 'admin', 'instituto', 'profesor', 'alumno', 'api'];
        foreach ($this->router->getRouteCollection() as $route) {
            $primero = explode('/', trim($route->getPath(), '/'))[0];
            if ($primero !== '' && !str_contains($primero, '{')) { $reservados[] = mb_strtolower($primero); }
        }
        return array_values(array_unique($reservados));
    }

    public function obtenerOCrear(Instituto $instituto): InstitutoPaginaPublica
    {
        $existente = $this->repository->findOneBy(['instituto' => $instituto]);
        if ($existente) { return $existente; }
        if (!$instituto->getId()) { throw new \LogicException('El instituto debe estar guardado.'); }
        $base = mb_strtolower((string) $this->slugger->slug($instituto->getNombre() ?? 'instituto'));
        $base = trim(substr(preg_replace('/[^a-z0-9-]/', '', $base), 0, 72), '-');
        if ($base === '') { $base = 'instituto'; }
        $slug = $base;
        $reservados = $this->reservados();
        if (in_array($slug, $reservados, true) || $this->repository->findOneBy(['slug' => $slug])) { $slug = $base . '-' . $instituto->getId(); }
        $n = 2;
        while (in_array($slug, $reservados, true) || $this->repository->findOneBy(['slug' => $slug])) { $slug = $base . '-' . $instituto->getId() . '-' . $n++; }
        $pagina = (new InstitutoPaginaPublica())->setInstituto($instituto)->setSlug($slug);
        if ($this->vertical === 'club') { $pagina->setColorPrincipal('#9a3412')->setColorAcento('#15803d'); }
        $this->em->persist($pagina); $this->em->flush();
        return $pagina;
    }

    public function url(InstitutoPaginaPublica $pagina): string
    {
        return rtrim($this->baseUrl, '/') . $this->router->generate('app_instituto_landing_publica', ['slug' => $pagina->getSlug()]);
    }
}
