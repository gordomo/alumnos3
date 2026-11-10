<?php
/** php tests/regression_ayuda.php — no usa base de datos ni proveedores externos. */
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Service\AyudaBusqueda;
use App\Service\AyudaCatalogo;
use App\Service\AyudaFuente;
use App\Service\Vocabulario;
use App\Twig\VocabularioExtension;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

function verificar(bool $condicion, string $mensaje): void
{
    if (!$condicion) { throw new RuntimeException($mensaje); }
    echo "OK: $mensaje\n";
}

$auth = new class implements AuthorizationCheckerInterface {
    public array $roles = [];
    public function isGranted($attribute, $subject = null): bool
    {
        return in_array($attribute, $this->roles, true);
    }
};
$rutas = new RouteCollection();
$rutas->add('app_ayuda_tema', new Route('/ayuda/{tema}'));
$router = new UrlGenerator($rutas, new RequestContext());
function preparar(string $vertical, $auth, $router, $cache = null, $loader = null): array
{
    $vocabulario = new Vocabulario($vertical);
    $twig = new Environment($loader ?? new FilesystemLoader(dirname(__DIR__) . '/templates'), ['strict_variables' => true]);
    $twig->addExtension(new VocabularioExtension($vocabulario));
    $twig->addFunction(new TwigFunction('is_granted', [$auth, 'isGranted']));
    $twig->addFunction(new TwigFunction('asset', static fn ($archivo) => '/assets/' . $archivo));
    $twig->addGlobal('vertical', $vertical);
    $catalogo = new AyudaCatalogo($vocabulario, $auth);
    $fuente = new AyudaFuente($catalogo, $twig, $router);

    return [$catalogo, $fuente, new AyudaBusqueda($fuente, $cache)];
}

$cacheCompartida = new ArrayAdapter();
$idsPorVertical = [];
foreach (['instituto', 'club'] as $vertical) {
    [$catalogo, $fuente, $busqueda] = preparar($vertical, $auth, $router, $cacheCompartida);
    $auth->roles = ['ROLE_ADMIN_INSTITUTO'];
    verificar(count($catalogo->temasVisibles()) === 13, "$vertical: catálogo del administrador");
    $secciones = $fuente->seccionesVisibles();
    $porId = array_column($secciones, null, 'id');
    verificar(count($porId) === count($secciones), "$vertical: secciones sin identificadores duplicados");
    $idsPorVertical[$vertical] = array_keys($porId);
    foreach ($secciones as $seccion) {
        if (str_contains($seccion['texto'], '{{') || str_contains($seccion['texto'], '<script') || str_contains($seccion['texto'], '<img')) {
            throw new RuntimeException('La fuente contiene código de plantilla o elementos excluidos.');
        }
    }
    $preguntas = [
        'cómo registrar un pago' => 'pagos#registrar-un-pago',
        'saldo a favor' => 'pagos#saldos-a-favor',
        'RECUPERAR CLAVE' => 'primeros-pasos#entrar-por-primera-vez-o-recuperar-la-contrasena',
        'cobrar meses adelantados' => 'pagos#cobrar-meses-adelantados',
        'tomar asistencia' => 'asistencias#tomar-asistencia-de-una-clase',
        'cerrar curso' => 'cursos#cerrar-un-curso',
        'crear tarea' => 'tareas#crear-la-tarea',
        'enviar boletín' => 'calificaciones#el-boletin',
    ];
    foreach ($preguntas as $pregunta => $esperado) {
        $resultados = $busqueda->buscar($pregunta);
        verificar(($resultados[0]['id'] ?? null) === $esperado, "$vertical: $pregunta");
        verificar(count($resultados) <= 5, "$vertical: límite de resultados");
    }
    verificar($busqueda->buscar('capital de Francia') === [], "$vertical: sin coincidencias no inventa resultados");
    verificar($busqueda->buscar('como de la el') === [], "$vertical: consulta vacía de términos útiles");
    verificar($busqueda->buscar('   ') === [], "$vertical: consulta vacía");
    if ($vertical === 'club') {
        verificar($porId['cursos#crear-un-curso']['titulo'] === 'Crear un deporte', 'Club: vocabulario resuelto sin cambiar el identificador');
    }
    // Mismas instancias, distinto rol: no debe reutilizarse un índice del administrador.
    $auth->roles = ['ROLE_PROFESOR'];
    verificar(count($catalogo->temasVisibles()) === 6, "$vertical: catálogo del profesor");
    $docente = array_column($fuente->seccionesVisibles(), null, 'id');
    verificar(!isset($docente['pagos#registrar-un-pago']), "$vertical: profesor sin ayuda de cobranza administrativa");
    verificar(!isset($docente['calificaciones#como-se-usan-las-notas-al-cerrar-el-curso']), "$vertical: rama administrativa excluida del texto");
    verificar(str_contains($docente['calificaciones#antes-de-empezar-la-escala']['texto'], 'pedile al administrador'), "$vertical: instrucciones del profesor renderizadas");
    verificar($busqueda->buscar('saldo a favor') === [], "$vertical: sin coincidencias parciales engañosas");
    $auth->roles = ['ROLE_ALUMNO'];
    verificar(count($catalogo->temasVisibles()) === 2, "$vertical: catálogo del alumno");
    foreach ($fuente->seccionesVisibles() as $seccion) {
        if (!in_array($seccion['tema'], ['agenda', 'mi-panel'], true)) {
            throw new RuntimeException('El alumno recibió contenido de otro rol.');
        }
    }
    verificar(true, "$vertical: alumno solo recibe sus temas");
    verificar($busqueda->buscar('registrar pago') === [], "$vertical: alumno sin procedimiento para cobrar");
    $auth->roles = [];
    verificar($fuente->seccionesVisibles() === [] && $busqueda->buscar('pago') === [], "$vertical: sin rol no se entrega contenido");
    $auth->roles = ['ROLE_SUPER_ADMIN'];
    verificar($catalogo->temasVisibles() === [], "$vertical: no se inventa ayuda de plataforma ausente");
    try {
        $busqueda->buscar(str_repeat('a', 301));
        throw new RuntimeException('Se aceptó una consulta demasiado larga.');
    } catch (InvalidArgumentException $e) {
        verificar(true, "$vertical: límite de longitud");
    }
}
verificar($idsPorVertical['instituto'] === $idsPorVertical['club'], 'Identificadores estables entre ambas verticales');

// Una actualización real del contenido debe invalidar el índice sin esperar al TTL.
$auth->roles = ['ROLE_ADMIN_INSTITUTO'];
[, , $original] = preparar('instituto', $auth, $router, $cacheCompartida);
verificar($original->buscar('marcadorcacheversion') === [], 'Índice original sin marcador de la versión nueva');
$plantilla = file_get_contents(dirname(__DIR__) . '/templates/ayuda/temas/pagos.html.twig');
$modificada = str_replace('{% endblock %}', '<h2 id="version-nueva">Versión nueva</h2><p>marcadorcacheversion</p>{% endblock %}', $plantilla);
$loader = new ChainLoader([
    new ArrayLoader(['ayuda/temas/pagos.html.twig' => $modificada]),
    new FilesystemLoader(dirname(__DIR__) . '/templates'),
]);
[, , $actualizada] = preparar('instituto', $auth, $router, $cacheCompartida, $loader);
verificar(($actualizada->buscar('marcadorcacheversion')[0]['id'] ?? null) === 'pagos#version-nueva', 'Cambio de manual invalida automáticamente el índice');
verificar($original->buscar('marcadorcacheversion') === [], 'La versión anterior no reutiliza contenido de otra versión');
$caliente = $original->buscar('saldo a favor');
verificar($caliente === $original->buscar('saldo a favor'), 'Índice reutilizado conserva resultados y orden');
