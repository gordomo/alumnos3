<?php
namespace App\Service;

use App\Entity\{Curso, InstitutoPaginaPublica};
use Doctrine\ORM\EntityManagerInterface;

/** Lista explícita de datos públicos: nunca se entrega el grafo de entidades a Twig. */
class InstitutoLandingPresenter
{
    public function __construct(private EntityManagerInterface $em, private InstitutoTimezoneService $timezone) {}

    public function datos(InstitutoPaginaPublica $pagina): array
    {
        $instituto = $pagina->getInstituto();
        $hoy = $this->timezone->getCurrentDateForInstituto($instituto);
        $cursos = $this->em->getRepository(Curso::class)->createQueryBuilder('c')
            ->leftJoin('c.horarios', 'h')->addSelect('h')
            ->where('c.instituto = :instituto AND c.disabled = false AND c.cerrado = false')
            ->setParameter('instituto', $instituto)->orderBy('c.nombre', 'ASC')->getQuery()->getResult();
        $email = filter_var($instituto->getEmail(), FILTER_VALIDATE_EMAIL) ?: null;
        $telefono = trim($instituto->getTel() ?? '');
        $principal = self::color($pagina->getColorPrincipal(), '#1b4f9c');
        $acento = self::color($pagina->getColorAcento(), '#0f766e');
        $logo = $instituto->getLogo();
        $datos = [
            'nombre' => $instituto->getNombre(), 'direccion' => $instituto->getDir(), 'telefono' => $telefono,
            'telLink' => preg_replace('/[^0-9+]/', '', $telefono), 'email' => $email,
            'logo' => $logo && basename($logo) === $logo ? $logo : null,
            'presentacion' => $pagina->getPresentacion(), 'principal' => $principal, 'acento' => $acento,
            'contraste' => self::contraste($principal), 'contrasteAcento' => self::contraste($acento),
            'mostrarPrecios' => $pagina->isMostrarPrecios(), 'cursos' => [],
        ];
        foreach ($cursos as $curso) {
            if ($curso->getInstituto()->getId() !== $instituto->getId()) { continue; }
            $horarios = [];
            foreach ($curso->getHorarios() as $horario) {
                $horarios[] = ['dia' => $horario->getDia(), 'inicio' => $horario->getHorarioInicio()?->format('H:i'), 'fin' => $horario->getHorarioFin()?->format('H:i')];
            }
            if (!$horarios) {
                foreach ($curso->getDias() ?? [] as $dia) {
                    $horarios[] = ['dia' => $dia, 'inicio' => $curso->getHorarioInicio()?->format('H:i'), 'fin' => $curso->getHorarioFin()?->format('H:i')];
                }
            }
            $ordenDias = ['lunes'=>1,'martes'=>2,'miercoles'=>3,'jueves'=>4,'viernes'=>5,'sabado'=>6,'domingo'=>7];
            usort($horarios, static function (array $a, array $b) use ($ordenDias): int {
                $diaA = strtr(mb_strtolower($a['dia'] ?? ''), ['é'=>'e','á'=>'a']);
                $diaB = strtr(mb_strtolower($b['dia'] ?? ''), ['é'=>'e','á'=>'a']);
                return (($ordenDias[$diaA] ?? 8) <=> ($ordenDias[$diaB] ?? 8)) ?: strcmp($a['inicio'] ?? '', $b['inicio'] ?? '');
            });
            $item = ['nombre' => $curso->getNombre(), 'descripcion' => $curso->getDescripcionPublica(), 'finalizado' => $curso->getFechaFin() !== null && $curso->getFechaFin() < $hoy, 'inicio' => $curso->getFechaInicio()?->format('d/m/Y'), 'fin' => $curso->getFechaFin()?->format('d/m/Y'), 'proximo' => $curso->getFechaInicio() > $hoy, 'horarios' => $horarios];
            if ($pagina->isMostrarPrecios()) { $item['precio'] = is_numeric($curso->getPrecio()) && (float) $curso->getPrecio() >= 0 ? $curso->getPrecio() : null; }
            $datos['cursos'][] = $item;
        }
        return $datos;
    }

    public static function color(string $color, string $fallback): string { return preg_match('/^#[0-9a-fA-F]{6}$/D', $color) ? $color : $fallback; }
    public static function contraste(string $color): string
    {
        $l = 0;
        foreach ([0.2126, 0.7152, 0.0722] as $i => $peso) {
            $v = hexdec(substr($color, 1 + $i * 2, 2)) / 255;
            $l += $peso * ($v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4);
        }
        return $l > 0.179 ? '#111827' : '#ffffff';
    }
}
