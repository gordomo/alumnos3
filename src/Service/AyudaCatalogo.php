<?php

namespace App\Service;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/** Catálogo y permisos únicos para el manual, su búsqueda y el futuro asistente. */
class AyudaCatalogo
{
    public function __construct(
        private Vocabulario $vocabulario,
        private AuthorizationCheckerInterface $authorization
    ) {
    }

    /**
     * Registro de temas. El orden es el del índice.
     *
     * roles: quién puede ver el tema. Un tema con varios roles lo ven todos ellos.
     */
    private const TEMAS = [
        'primeros-pasos' => [
            'titulo' => 'Primeros pasos',
            'icono' => 'bi-compass',
            'resumen' => 'Cómo está organizado el sistema y en qué orden conviene cargar las cosas.',
            'roles' => ['ROLE_ADMIN_INSTITUTO'],
        ],
        'configuracion' => [
            'titulo' => 'Configuración %del_instituto%',
            'icono' => 'bi-gear',
            'resumen' => 'Datos %del_instituto%, vencimientos e intereses, descuentos, métodos de pago y criterios de aprobación.',
            'roles' => ['ROLE_ADMIN_INSTITUTO'],
        ],
        'agenda' => [
            'titulo' => 'Agenda',
            'icono' => 'bi-calendar3',
            'resumen' => 'El calendario con %los_cursos%, las evaluaciones, las entregas, los vencimientos y los eventos %del_instituto%.',
            'roles' => ['ROLE_ADMIN_INSTITUTO', 'ROLE_PROFESOR', 'ROLE_ALUMNO'],
        ],
        'cursos' => [
            'titulo' => '%Cursos%',
            'icono' => 'bi-journal-bookmark',
            'resumen' => 'Crear %un_curso%, asignarle horarios y %profesores%, y cerrarlo al final del período.',
            'roles' => ['ROLE_ADMIN_INSTITUTO'],
        ],
        'diario' => [
            'titulo' => '%Clases% y materiales',
            'icono' => 'bi-journal-text',
            'resumen' => 'Anotar qué se dio en cada %clase% y dejar los materiales de consulta %del_curso%.',
            'roles' => ['ROLE_ADMIN_INSTITUTO', 'ROLE_PROFESOR'],
        ],
        'alumnos' => [
            'titulo' => '%Alumnos% e inscripciones',
            'icono' => 'bi-people',
            'resumen' => 'Dar de alta %un_alumno%, inscribirlo a %cursos% y entender desde cuándo se le genera deuda.',
            'roles' => ['ROLE_ADMIN_INSTITUTO'],
        ],
        'profesores' => [
            'titulo' => '%Profesores% y su liquidación',
            'icono' => 'bi-person-workspace',
            'resumen' => 'Cómo se le paga a cada %profesor%: por hora, fijo, porcentaje o distinto en cada %curso%.',
            'roles' => ['ROLE_ADMIN_INSTITUTO'],
        ],
        'pagos' => [
            'titulo' => 'Pagos y deudas',
            'icono' => 'bi-cash-coin',
            'resumen' => 'Registrar un pago, cobrar meses adelantados, cómo se calculan los recargos y qué son los saldos a favor.',
            'roles' => ['ROLE_ADMIN_INSTITUTO'],
        ],
        'comunicaciones' => [
            'titulo' => 'Comunicaciones',
            'icono' => 'bi-megaphone',
            'resumen' => 'Mandar un aviso por email a todo %el_instituto%, a %un_curso% o a los que deben.',
            'roles' => ['ROLE_ADMIN_INSTITUTO'],
        ],
        'calificaciones' => [
            'titulo' => 'Calificaciones',
            'icono' => 'bi-mortarboard',
            'resumen' => 'Elegir la escala, crear evaluaciones, cargar notas y enviar el boletín.',
            'roles' => ['ROLE_ADMIN_INSTITUTO', 'ROLE_PROFESOR'],
        ],
        'tareas' => [
            'titulo' => 'Tareas',
            'icono' => 'bi-journal-text',
            'resumen' => 'Pedir tareas a %un_curso%, marcar quién las entregó y verlo en la libreta.',
            'roles' => ['ROLE_ADMIN_INSTITUTO', 'ROLE_PROFESOR'],
        ],
        'asistencias' => [
            'titulo' => 'Asistencias',
            'icono' => 'bi-clipboard-check',
            'resumen' => 'Tomar asistencia de %la_clase%, corregirla después y ver los informes.',
            'roles' => ['ROLE_ADMIN_INSTITUTO', 'ROLE_PROFESOR'],
        ],
        'suscripcion' => [
            'titulo' => 'Suscripción %del_instituto%',
            'icono' => 'bi-patch-check',
            'resumen' => 'Cuánto se paga por el uso del sistema, cómo pagarlo y qué pasa si una factura queda impaga.',
            'roles' => ['ROLE_ADMIN_INSTITUTO'],
        ],
        'mis-pagos' => [
            'titulo' => 'Mis pagos',
            'icono' => 'bi-cash-coin',
            'resumen' => 'Lo que te corresponde por mes, lo que ya te pagaron y tus recibos.',
            'roles' => ['ROLE_PROFESOR'],
        ],
        'mi-panel' => [
            'titulo' => 'Mi panel',
            'icono' => 'bi-person-circle',
            'resumen' => 'Dónde ver tus %cursos%, tus asistencias, tus pagos y tus notas.',
            'roles' => ['ROLE_ALUMNO'],
        ],
    ];

    /**
     * Temas que el usuario actual puede ver, en el orden del registro.
     *
     * @return array<string, array<string, mixed>>
     */
    public function temasVisibles(): array
    {
        $visibles = [];
        foreach (self::TEMAS as $slug => $datos) {
            foreach ($datos['roles'] as $rol) {
                if ($this->authorization->isGranted($rol)) {
                    $datos['titulo'] = $this->conVocabulario($datos['titulo']);
                    $datos['resumen'] = $this->conVocabulario($datos['resumen']);
                    $visibles[$slug] = $datos;
                    break;
                }
            }
        }

        return $visibles;
    }

    /** Permisos efectivos de todos los roles utilizados por el manual, sin datos de cuenta. */
    public function contextoPermisos(): array
    {
        $roles = [];
        foreach (self::TEMAS as $tema) {
            foreach ($tema['roles'] as $rol) {
                if (!array_key_exists($rol, $roles)) {
                    $roles[$rol] = $this->authorization->isGranted($rol);
                }
            }
        }
        ksort($roles);

        return $roles;
    }

    /**
     * Resuelve las marcas %clave% del índice con el glosario del producto.
     *
     * Los títulos y resúmenes de la ayuda viven en PHP y no en plantillas, así que no pueden
     * usar term(). Con mayúscula inicial en la clave se pide la palabra capitalizada, que es lo
     * que necesitan los títulos.
     */
    private function conVocabulario(string $texto): string
    {
        return preg_replace_callback('/%([A-Za-z_]+)%/', function (array $coincidencia) {
            $clave = $coincidencia[1];

            return ctype_upper($clave[0])
                ? $this->vocabulario->terminoMayuscula(lcfirst($clave))
                : $this->vocabulario->termino($clave);
        }, $texto);
    }
}
