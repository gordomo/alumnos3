<?php

namespace App\Controller;

use App\Service\ImportarAlumnosService;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Importar alumnos desde una planilla.
 *
 * Son dos pasos a propósito: primero se sube el archivo y se muestra fila por fila qué va a
 * pasar, y solo después se escribe. Importar cien alumnos es difícil de deshacer, así que
 * conviene ver los rechazos antes y no después.
 *
 * El prefijo es propio y no /instituto/alumno para no competir con la ruta /{id} de
 * AlumnoController, que tomaría "importar" como si fuera un id.
 *
 * @Route("/instituto/importar-alumnos")
 */
#[IsGranted('ROLE_ADMIN_INSTITUTO')]
class ImportarAlumnosController extends AbstractController
{
    private const EXTENSIONES_PERMITIDAS = ['xlsx', 'xls', 'csv'];
    private const TAMANO_MAXIMO = 10 * 1024 * 1024;

    /** Clave de sesión donde queda el archivo subido entre el análisis y la confirmación. */
    private const SESION_ARCHIVO = 'importacion_alumnos_archivo';

    public function __construct(private ImportarAlumnosService $importarService)
    {
    }

    /**
     * @Route("", name="app_importar_alumnos", methods={"GET"})
     */
    public function index(): Response
    {
        return $this->render('importar_alumnos/index.html.twig', [
            'columnas' => ImportarAlumnosService::COLUMNAS,
        ]);
    }

    /**
     * Sube la planilla y muestra el análisis. No escribe nada.
     *
     * @Route("/analizar", name="app_importar_alumnos_analizar", methods={"POST"})
     */
    public function analizar(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('importar_alumnos', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'El formulario expiró. Volvé a intentarlo.');

            return $this->redirectToRoute('app_importar_alumnos');
        }

        $instituto = $this->getUser()->getInstituto();
        $archivo = $request->files->get('archivo');

        $ruta = $this->guardarTemporal($archivo);
        if (!$ruta) {
            return $this->redirectToRoute('app_importar_alumnos');
        }

        $analisis = $this->importarService->analizar($ruta, $instituto);

        if (!$analisis['ok']) {
            @unlink($ruta);
            $this->addFlash('danger', $analisis['error']);

            return $this->redirectToRoute('app_importar_alumnos');
        }

        // El archivo queda en var/tmp hasta que se confirme o se cancele. En la sesión va solo
        // el nombre generado por el servidor, nunca una ruta que venga del navegador.
        $request->getSession()->set(self::SESION_ARCHIVO, basename($ruta));

        return $this->render('importar_alumnos/previsualizar.html.twig', [
            'analisis' => $analisis,
            'columnas' => ImportarAlumnosService::COLUMNAS,
            'nombreArchivo' => $archivo->getClientOriginalName(),
        ]);
    }

    /**
     * @Route("/confirmar", name="app_importar_alumnos_confirmar", methods={"POST"})
     */
    public function confirmar(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('importar_alumnos_confirmar', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'El formulario expiró. Volvé a subir la planilla.');

            return $this->redirectToRoute('app_importar_alumnos');
        }

        $ruta = $this->rutaEnSesion($request);
        if (!$ruta) {
            $this->addFlash('danger', 'No encontramos la planilla que habías subido. Volvé a subirla.');

            return $this->redirectToRoute('app_importar_alumnos');
        }

        $resultado = $this->importarService->importar($ruta, $this->getUser()->getInstituto());

        @unlink($ruta);
        $request->getSession()->remove(self::SESION_ARCHIVO);

        if (!empty($resultado['error'])) {
            $this->addFlash('danger', $resultado['error']);

            return $this->redirectToRoute('app_importar_alumnos');
        }

        $this->addFlash('success', sprintf(
            'Se importaron %d alumn@s.',
            $resultado['importados']
        ));

        if ($resultado['rechazados'] > 0) {
            $this->addFlash('warning', sprintf(
                '%d fila(s) no se importaron por los problemas que te mostramos antes.',
                $resultado['rechazados']
            ));
        }

        foreach ($resultado['fallidos'] as $mensaje) {
            $this->addFlash('danger', $mensaje);
        }

        return $this->redirectToRoute('app_alumno_index');
    }

    /**
     * @Route("/cancelar", name="app_importar_alumnos_cancelar", methods={"POST"})
     */
    public function cancelar(Request $request): Response
    {
        $ruta = $this->rutaEnSesion($request);
        if ($ruta) {
            @unlink($ruta);
        }

        $request->getSession()->remove(self::SESION_ARCHIVO);

        return $this->redirectToRoute('app_importar_alumnos');
    }

    /**
     * La plantilla se genera en el momento a partir de la definición de columnas del servicio,
     * así que no puede quedar desactualizada respecto de lo que el lector espera.
     *
     * @Route("/plantilla", name="app_importar_alumnos_plantilla", methods={"GET"})
     */
    public function plantilla(): StreamedResponse
    {
        $libro = $this->importarService->generarPlantilla($this->getUser()->getInstituto());

        $respuesta = new StreamedResponse(function () use ($libro) {
            (new Xlsx($libro))->save('php://output');
        });

        $respuesta->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $respuesta->headers->set('Content-Disposition', 'attachment; filename="plantilla-alumnos.xlsx"');
        $respuesta->headers->set('Cache-Control', 'max-age=0');

        return $respuesta;
    }

    /**
     * Guarda la planilla fuera de public/ con un nombre generado por el servidor.
     *
     * El nombre original lo controla quien sube el archivo: se le lee la extensión para
     * validarla, pero nunca se usa como ruta de destino.
     */
    private function guardarTemporal($archivo): ?string
    {
        if (!$archivo instanceof UploadedFile || !$archivo->isValid()) {
            $this->addFlash('danger', 'No recibimos un archivo válido. Elegí la planilla y volvé a intentar.');

            return null;
        }

        $extension = strtolower($archivo->getClientOriginalExtension() ?: (string) $archivo->guessExtension());
        if (!in_array($extension, self::EXTENSIONES_PERMITIDAS, true)) {
            $this->addFlash('danger', sprintf(
                'El archivo tiene que ser %s. Recibimos un ".%s".',
                implode(', ', self::EXTENSIONES_PERMITIDAS),
                $extension
            ));

            return null;
        }

        if ($archivo->getSize() > self::TAMANO_MAXIMO) {
            $this->addFlash('danger', 'El archivo supera los 10 MB.');

            return null;
        }

        $carpeta = $this->carpetaTemporal();
        $nombre = bin2hex(random_bytes(16)) . '.' . $extension;

        try {
            $archivo->move($carpeta, $nombre);
        } catch (FileException $e) {
            $this->addFlash('danger', 'No pudimos procesar el archivo subido.');

            return null;
        }

        return $carpeta . '/' . $nombre;
    }

    /**
     * La ruta del archivo pendiente, validando que el nombre guardado en sesión sea uno de los
     * que genera el servidor y que el archivo siga estando.
     */
    private function rutaEnSesion(Request $request): ?string
    {
        $nombre = $request->getSession()->get(self::SESION_ARCHIVO);

        if (!is_string($nombre) || !preg_match('/^[0-9a-f]{32}\.(xlsx|xls|csv)$/', $nombre)) {
            return null;
        }

        $ruta = $this->carpetaTemporal() . '/' . $nombre;

        return is_file($ruta) ? $ruta : null;
    }

    private function carpetaTemporal(): string
    {
        return $this->getParameter('kernel.project_dir') . '/var/tmp/imports';
    }
}
