<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Manual de uso dentro del propio sistema.
 *
 * El índice se filtra por rol: cada usuario ve solo los temas de las pantallas a las que
 * realmente puede entrar, así el profesor no lee cómo se configura la facturación.
 *
 * El contenido vive en templates (templates/ayuda/temas/) para que quede versionado junto
 * al código y no dependa de la base.
 *
 * La ruta no necesita una linea propia en access_control: /ayuda cae en la regla catch-all
 * ^/ que exige IS_AUTHENTICATED_FULLY, o sea cualquier usuario logueado.
 *
 * @Route("/ayuda")
 */
class AyudaController extends AbstractController
{
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
            'titulo' => 'Configuración del instituto',
            'icono' => 'bi-gear',
            'resumen' => 'Datos del instituto, vencimientos e intereses, descuentos, métodos de pago y criterios de aprobación.',
            'roles' => ['ROLE_ADMIN_INSTITUTO'],
        ],
        'cursos' => [
            'titulo' => 'Cursos',
            'icono' => 'bi-journal-bookmark',
            'resumen' => 'Crear un curso, asignarle horarios y profesores, y cerrarlo al final del período.',
            'roles' => ['ROLE_ADMIN_INSTITUTO'],
        ],
        'alumnos' => [
            'titulo' => 'Alumnos e inscripciones',
            'icono' => 'bi-people',
            'resumen' => 'Dar de alta un alumno, inscribirlo a cursos y entender desde cuándo se le genera deuda.',
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
            'resumen' => 'Mandar un aviso por email a todo el instituto, a un curso o a los que deben.',
            'roles' => ['ROLE_ADMIN_INSTITUTO'],
        ],
        'calificaciones' => [
            'titulo' => 'Calificaciones',
            'icono' => 'bi-mortarboard',
            'resumen' => 'Elegir la escala, crear evaluaciones, cargar notas y enviar el boletín.',
            'roles' => ['ROLE_ADMIN_INSTITUTO', 'ROLE_PROFESOR'],
        ],
        'asistencias' => [
            'titulo' => 'Asistencias',
            'icono' => 'bi-clipboard-check',
            'resumen' => 'Tomar asistencia de una clase, corregirla después y ver los informes.',
            'roles' => ['ROLE_ADMIN_INSTITUTO', 'ROLE_PROFESOR'],
        ],
        'mi-panel' => [
            'titulo' => 'Mi panel',
            'icono' => 'bi-person-circle',
            'resumen' => 'Dónde ver tus cursos, tus asistencias, tus pagos y tus notas.',
            'roles' => ['ROLE_ALUMNO'],
        ],
    ];

    /**
     * @Route("", name="app_ayuda_index", methods={"GET"})
     */
    public function index(): Response
    {
        return $this->render('ayuda/index.html.twig', [
            'temas' => $this->temasVisibles(),
        ]);
    }

    /**
     * @Route("/{tema}", name="app_ayuda_tema", methods={"GET"}, requirements={"tema"="[a-z0-9\-]+"})
     */
    public function tema(string $tema): Response
    {
        $visibles = $this->temasVisibles();

        // Un tema que no corresponde al rol se trata como inexistente, para no revelar
        // qué secciones existen para otros roles.
        if (!isset($visibles[$tema])) {
            throw $this->createNotFoundException('El tema de ayuda no existe.');
        }

        return $this->render('ayuda/temas/' . $tema . '.html.twig', [
            'tema' => $visibles[$tema] + ['slug' => $tema],
            'temas' => $visibles,
        ]);
    }

    /**
     * Temas que el usuario actual puede ver, en el orden del registro.
     *
     * @return array<string, array<string, mixed>>
     */
    private function temasVisibles(): array
    {
        $visibles = [];
        foreach (self::TEMAS as $slug => $datos) {
            foreach ($datos['roles'] as $rol) {
                if ($this->isGranted($rol)) {
                    $visibles[$slug] = $datos;
                    break;
                }
            }
        }

        return $visibles;
    }
}
