<?php

namespace App\Service;

use App\Entity\Alumno;
use App\Entity\Instituto;
use App\Repository\AlumnoRepository;
use App\Repository\CursoRepository;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Importación de alumnos desde una planilla.
 *
 * La lista de columnas de acá es la única definición que existe: de ella salen la plantilla que
 * se descarga, el mapeo al leer el archivo y lo que dice la ayuda. Así no puede pasar que la
 * plantilla tenga un orden y el lector espere otro.
 *
 * Las columnas se reconocen por el nombre del encabezado, no por su posición, así que un archivo
 * con las columnas en otro orden o con columnas de más igual se importa. Lo que no puede faltar
 * es el encabezado de las obligatorias.
 *
 * Se analiza primero y se importa después: la pantalla muestra fila por fila qué va a pasar antes
 * de escribir nada.
 */
class ImportarAlumnosService
{
    /**
     * Columnas de la planilla, en el orden en que se generan.
     *
     * `sinonimos` son otros encabezados que se aceptan para la misma columna: es lo que permite
     * importar la planilla que el instituto ya venía usando sin renombrarle nada.
     */
    public const COLUMNAS = [
        'apellido' => [
            'titulo' => 'Apellido', 'requerida' => true, 'ejemplo' => 'Pérez',
            'sinonimos' => ['apellidos'],
        ],
        'nombre' => [
            'titulo' => 'Nombre', 'requerida' => true, 'ejemplo' => 'Ana',
            'sinonimos' => ['nombres'],
        ],
        'dni' => [
            'titulo' => 'DNI', 'requerida' => true, 'ejemplo' => '45123456',
            'sinonimos' => ['documento', 'nro documento', 'numero de documento'],
        ],
        'email' => [
            'titulo' => 'Email', 'requerida' => true, 'ejemplo' => 'ana.perez@ejemplo.com',
            'sinonimos' => ['mail', 'correo', 'correo electronico', 'e-mail'],
        ],
        'fecha_nacimiento' => [
            'titulo' => 'Fecha de nacimiento', 'requerida' => false, 'ejemplo' => '15/03/2010',
            'sinonimos' => ['fecha nac', 'f nac', 'nacimiento', 'fecha de nac'],
        ],
        'lugar_nacimiento' => [
            'titulo' => 'Lugar de nacimiento', 'requerida' => false, 'ejemplo' => 'Rosario',
            'sinonimos' => ['lugar nac', 'l nac'],
        ],
        'telefono_fijo' => [
            'titulo' => 'Teléfono fijo', 'requerida' => false, 'ejemplo' => '3414567890',
            'sinonimos' => ['telefono', 'tel fijo', 'fijo'],
        ],
        'celular' => [
            'titulo' => 'Celular', 'requerida' => false, 'ejemplo' => '3415551234',
            'sinonimos' => ['cel', 'movil', 'telefono celular'],
        ],
        'contacto_emergencia' => [
            'titulo' => 'Contacto de emergencia', 'requerida' => false, 'ejemplo' => 'María Pérez 3415550000',
            'sinonimos' => ['emergencia', 'contacto emergencia'],
        ],
        'tutor_nombre' => [
            'titulo' => 'Tutor: nombre', 'requerida' => false, 'ejemplo' => 'María Pérez',
            'sinonimos' => ['tutor', 'padre madre tutor', 'nombre del tutor', 'responsable'],
        ],
        'tutor_telefono' => [
            'titulo' => 'Tutor: teléfono', 'requerida' => false, 'ejemplo' => '3415550000',
            'sinonimos' => ['telefono del tutor', 'tel tutor', 'telefono padre madre tutor'],
        ],
        'tutor_email' => [
            'titulo' => 'Tutor: email', 'requerida' => false, 'ejemplo' => 'maria.perez@ejemplo.com',
            'sinonimos' => ['mail del tutor', 'correo del tutor', 'mail padre madre tutor', 'email tutor'],
        ],
        'tutor_dni' => [
            'titulo' => 'Tutor: DNI', 'requerida' => false, 'ejemplo' => '28123456',
            'sinonimos' => ['dni del tutor', 'documento del tutor', 'dni padre madre tutor'],
        ],
        'escuela' => [
            'titulo' => 'Escuela', 'requerida' => false, 'ejemplo' => 'Escuela N° 12',
            'sinonimos' => ['colegio', 'establecimiento'],
        ],
        'actividades' => [
            'titulo' => 'Otras actividades', 'requerida' => false, 'ejemplo' => 'Fútbol los sábados',
            'sinonimos' => ['actividades', 'extras'],
        ],
        'grupo_sanguineo' => [
            'titulo' => 'Grupo sanguíneo', 'requerida' => false, 'ejemplo' => '0+',
            'sinonimos' => ['grupo sanguineo', 'sangre'],
        ],
        'enfermedad' => [
            'titulo' => 'Enfermedades', 'requerida' => false, 'ejemplo' => 'Asma',
            'sinonimos' => ['enfermedad', 'enfermedades preexistentes'],
        ],
        'alergias' => [
            'titulo' => 'Alergias', 'requerida' => false, 'ejemplo' => 'Polen',
            'sinonimos' => ['alergia', 'alergico'],
        ],
        'medicacion' => [
            'titulo' => 'Medicación', 'requerida' => false, 'ejemplo' => 'Ninguna',
            'sinonimos' => ['medicacion', 'medicamentos'],
        ],
        'curso' => [
            'titulo' => 'Curso', 'requerida' => false, 'ejemplo' => 'Guitarra Inicial',
            'sinonimos' => ['cursos', 'materia', 'taller'],
        ],
        'como_conocio' => [
            'titulo' => 'Cómo nos conoció', 'requerida' => false, 'ejemplo' => 'Redes sociales',
            'sinonimos' => ['como conocio', 'como nos conocio', 'como llego'],
        ],
    ];

    public const ESTADO_OK = 'ok';
    public const ESTADO_AVISO = 'aviso';
    public const ESTADO_ERROR = 'error';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private AlumnoRepository $alumnoRepository,
        private CursoRepository $cursoRepository,
        private HistorialCursosService $historialCursosService,
        private InstitutoTimezoneService $institutoTimezoneService
    ) {
    }

    /**
     * Lee la planilla y dice qué va a pasar con cada fila, sin escribir nada.
     *
     * @return array{
     *     ok: bool,
     *     error: ?string,
     *     columnasReconocidas: array<string, string>,
     *     columnasIgnoradas: string[],
     *     filas: array<int, array{numero: int, datos: array, estado: string, motivos: string[]}>,
     *     resumen: array{importables: int, con_aviso: int, rechazadas: int}
     * }
     */
    public function analizar(string $ruta, Instituto $instituto): array
    {
        try {
            $hoja = IOFactory::load($ruta)->getActiveSheet();
        } catch (\Throwable $e) {
            return $this->fallo('No se pudo leer la planilla. Verificá que sea un archivo .xlsx, .xls o .csv válido.');
        }

        $filas = $hoja->toArray(null, true, true, true);
        if (!$filas) {
            return $this->fallo('La planilla está vacía.');
        }

        $encabezado = array_shift($filas);
        [$mapa, $ignoradas] = $this->mapearColumnas($encabezado);

        $faltantes = [];
        foreach (self::COLUMNAS as $clave => $columna) {
            if ($columna['requerida'] && !isset($mapa[$clave])) {
                $faltantes[] = $columna['titulo'];
            }
        }

        if ($faltantes) {
            return $this->fallo(sprintf(
                'Faltan columnas obligatorias en la primera fila: %s. Descargá la plantilla para ver los nombres exactos.',
                implode(', ', $faltantes)
            ));
        }

        // DNI y email ya usados, para detectar el choque contra la base y también entre dos
        // filas del mismo archivo.
        $dnisEnArchivo = [];
        $numeroFila = 1;
        $resultado = [];

        foreach ($filas as $fila) {
            $numeroFila++;

            if (!array_filter($fila, static fn($valor) => trim((string) $valor) !== '')) {
                continue; // Fila totalmente vacía: no se informa, es relleno de la planilla.
            }

            $datos = [];
            foreach ($mapa as $clave => $letra) {
                $datos[$clave] = trim((string) ($fila[$letra] ?? ''));
            }

            $motivos = [];
            $estado = self::ESTADO_OK;

            foreach (self::COLUMNAS as $clave => $columna) {
                if ($columna['requerida'] && ($datos[$clave] ?? '') === '') {
                    $motivos[] = sprintf('Falta %s', $columna['titulo']);
                    $estado = self::ESTADO_ERROR;
                }
            }

            $dni = $datos['dni'] ?? '';
            if ($dni !== '') {
                if (!preg_match('/^\d{6,8}$/', $dni)) {
                    $motivos[] = 'El DNI tiene que ser de 6 a 8 números, sin puntos ni espacios';
                    $estado = self::ESTADO_ERROR;
                } elseif (isset($dnisEnArchivo[$dni])) {
                    $motivos[] = sprintf('El DNI está repetido en la fila %d de esta misma planilla', $dnisEnArchivo[$dni]);
                    $estado = self::ESTADO_ERROR;
                } elseif ($this->dniYaExiste($dni)) {
                    // No se dice de quién es: puede ser de otro instituto y no corresponde
                    // mostrar datos ajenos.
                    $motivos[] = 'Ya hay un alumn@ con ese DNI en el sistema';
                    $estado = self::ESTADO_ERROR;
                } else {
                    $dnisEnArchivo[$dni] = $numeroFila;
                }
            }

            $email = $datos['email'] ?? '';
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $motivos[] = sprintf('El email "%s" no es válido', $email);
                $estado = self::ESTADO_ERROR;
            }

            // La fecha se normaliza acá y se guarda ya parseada, para no volver a interpretarla
            // al importar y que las dos pasadas puedan diferir.
            $datos['fecha_nacimiento_parseada'] = null;
            if (($datos['fecha_nacimiento'] ?? '') !== '') {
                $fecha = $this->interpretarFecha($fila[$mapa['fecha_nacimiento']] ?? null);

                if ($fecha) {
                    $datos['fecha_nacimiento_parseada'] = $fecha;
                    $datos['fecha_nacimiento'] = $fecha->format('d/m/Y');
                } else {
                    $motivos[] = sprintf('No se entiende la fecha de nacimiento "%s". Usá 15/03/2010', $datos['fecha_nacimiento']);
                    $estado = $estado === self::ESTADO_ERROR ? self::ESTADO_ERROR : self::ESTADO_AVISO;
                }
            }

            $datos['curso_encontrado'] = null;
            if (($datos['curso'] ?? '') !== '') {
                $curso = $this->cursoRepository->findOneBy(['nombre' => $datos['curso'], 'instituto' => $instituto]);

                if ($curso) {
                    $datos['curso_encontrado'] = $curso;
                } else {
                    $motivos[] = sprintf('No existe un curso llamado "%s": se va a importar sin inscribirlo', $datos['curso']);
                    $estado = $estado === self::ESTADO_ERROR ? self::ESTADO_ERROR : self::ESTADO_AVISO;
                }
            }

            $resultado[] = [
                'numero' => $numeroFila,
                'datos' => $datos,
                'estado' => $estado,
                'motivos' => $motivos,
            ];
        }

        $resumen = ['importables' => 0, 'con_aviso' => 0, 'rechazadas' => 0];
        foreach ($resultado as $fila) {
            if ($fila['estado'] === self::ESTADO_ERROR) {
                $resumen['rechazadas']++;
            } else {
                $resumen['importables']++;
                if ($fila['estado'] === self::ESTADO_AVISO) {
                    $resumen['con_aviso']++;
                }
            }
        }

        return [
            'ok' => true,
            'error' => null,
            'columnasReconocidas' => $mapa,
            'columnasIgnoradas' => $ignoradas,
            'filas' => $resultado,
            'resumen' => $resumen,
        ];
    }

    /**
     * Importa las filas que el análisis dio por importables.
     *
     * Cada alumno se guarda por separado: una fila que falle no se lleva puesta a las demás.
     *
     * @return array{importados: int, rechazados: int, fallidos: array<int, string>}
     */
    public function importar(string $ruta, Instituto $instituto): array
    {
        $analisis = $this->analizar($ruta, $instituto);

        if (!$analisis['ok']) {
            return ['importados' => 0, 'rechazados' => 0, 'fallidos' => [], 'error' => $analisis['error']];
        }

        $importados = 0;
        $fallidos = [];

        foreach ($analisis['filas'] as $fila) {
            if ($fila['estado'] === self::ESTADO_ERROR) {
                continue;
            }

            try {
                $alumno = $this->armarAlumno($fila['datos'], $instituto);
                $this->entityManager->persist($alumno);
                $this->entityManager->flush();

                // La inscripción se hace después de tener el alumno con id, y por el servicio
                // de siempre: es lo que crea el histórico del que dependen las cuotas, las
                // notas y la libreta. Sin eso el alumno quedaba "en el curso" pero sin
                // inscripción real.
                $curso = $fila['datos']['curso_encontrado'] ?? null;
                if ($curso) {
                    // Las dos cosas, igual que el alta manual: el histórico es la inscripción de
                    // verdad, y la relación alumno-curso es la que leen algunas pantallas.
                    $alumno->addCurso($curso);
                    $this->historialCursosService->inscribirAlumnoEnCurso($alumno, $curso);
                    $this->entityManager->flush();
                }

                $importados++;
            } catch (\Throwable $e) {
                $fallidos[$fila['numero']] = sprintf(
                    'Fila %d (%s %s): no se pudo guardar.',
                    $fila['numero'],
                    $fila['datos']['apellido'] ?? '',
                    $fila['datos']['nombre'] ?? ''
                );
            }
        }

        return [
            'importados' => $importados,
            'rechazados' => $analisis['resumen']['rechazadas'],
            'fallidos' => $fallidos,
            'error' => null,
        ];
    }

    /**
     * La plantilla que se descarga: los encabezados exactos que espera el lector, una fila de
     * ejemplo y una hoja con las instrucciones.
     */
    public function generarPlantilla(Instituto $instituto): Spreadsheet
    {
        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Alumnos');

        $letra = 'A';
        $ultima = 'A';
        foreach (self::COLUMNAS as $columna) {
            $titulo = $columna['titulo'] . ($columna['requerida'] ? ' *' : '');
            $hoja->setCellValue($letra . '1', $titulo);
            $hoja->setCellValueExplicit(
                $letra . '2',
                $columna['ejemplo'],
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
            );
            $hoja->getColumnDimension($letra)->setWidth(max(14, min(34, strlen($titulo) + 6)));
            $ultima = $letra;
            $letra++;
        }

        $rango = 'A1:' . $ultima . '1';
        $hoja->getStyle($rango)->getFont()->setBold(true);
        $hoja->getStyle($rango)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('DDEBF7');
        $hoja->getStyle($rango)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $hoja->freezePane('A2');

        $instrucciones = $libro->createSheet();
        $instrucciones->setTitle('Instrucciones');

        $lineas = [
            ['Cómo usar esta plantilla'],
            [''],
            ['1. La primera fila son los encabezados: no la borres ni le cambies los nombres.'],
            ['2. La fila 2 es un ejemplo. Reemplazala por tus datos o borrala.'],
            ['3. Una fila por alumn@. Las filas vacías se ignoran.'],
            ['4. Las columnas marcadas con * son obligatorias.'],
            ['5. El orden de las columnas no importa: se reconocen por el nombre del encabezado.'],
            ['6. Podés dejar columnas vacías o borrar las que no uses, salvo las obligatorias.'],
            [''],
            ['Datos que conviene saber'],
            [''],
            ['DNI: de 6 a 8 números, sin puntos ni espacios. No puede repetirse.'],
            ['Fecha de nacimiento: 15/03/2010. También se acepta la fecha de Excel.'],
            ['Curso: tiene que coincidir exactamente con el nombre del curso en el sistema.'],
            ['      Si no coincide, el alumn@ se importa igual pero sin inscribirlo.'],
            [''],
            ['Antes de importar nada, el sistema te muestra fila por fila qué va a pasar.'],
            [''],
            ['Cursos cargados hoy en ' . $instituto->getNombre() . ':'],
        ];

        foreach ($this->cursoRepository->findByInstituto($instituto) as $curso) {
            $lineas[] = ['      ' . $curso->getNombre()];
        }

        $numero = 1;
        foreach ($lineas as $linea) {
            $instrucciones->setCellValue('A' . $numero, $linea[0]);
            $numero++;
        }

        $instrucciones->getColumnDimension('A')->setWidth(95);
        $instrucciones->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $instrucciones->getStyle('A10')->getFont()->setBold(true);

        $libro->setActiveSheetIndex(0);

        return $libro;
    }

    private function armarAlumno(array $datos, Instituto $instituto): Alumno
    {
        $alumno = new Alumno();
        $alumno->setInstituto($instituto);
        $alumno->setNombre($datos['nombre']);
        $alumno->setApellido($datos['apellido']);
        $alumno->setDni($datos['dni']);
        $alumno->setEmail($datos['email']);
        $alumno->setActivo(true);

        // Sin fecha de nacimiento se deja en null y no en hoy: una fecha inventada después no se
        // distingue de una cargada de verdad.
        $alumno->setFNac($datos['fecha_nacimiento_parseada'] ?? null);

        $alumno->setLNac($this->oNull($datos['lugar_nacimiento'] ?? ''));
        $alumno->setTelefonoFijo($this->oNull($datos['telefono_fijo'] ?? ''));
        $alumno->setCelular($this->oNull($datos['celular'] ?? ''));
        $alumno->setContactoEmergencia($this->oNull($datos['contacto_emergencia'] ?? ''));
        $alumno->setNTutor($this->oNull($datos['tutor_nombre'] ?? ''));
        $alumno->setTTutor($this->oNull($datos['tutor_telefono'] ?? ''));
        $alumno->setCorreTutor($this->oNull($datos['tutor_email'] ?? ''));
        $alumno->setDniTutor($this->oNull($datos['tutor_dni'] ?? ''));
        $alumno->setEscuela($this->oNull($datos['escuela'] ?? ''));
        $alumno->setExtras($this->oNull($datos['actividades'] ?? ''));
        $alumno->setGSanguineo($this->oNull($datos['grupo_sanguineo'] ?? ''));
        $alumno->setEnfermedad($this->oNull($datos['enfermedad'] ?? ''));
        $alumno->setAlergico($this->oNull($datos['alergias'] ?? ''));
        $alumno->setMedicacion($this->oNull($datos['medicacion'] ?? ''));
        $alumno->setComoConociste($this->oNull($datos['como_conocio'] ?? ''));

        return $alumno;
    }

    /**
     * Encabezados de la planilla a claves de columna.
     *
     * @return array{0: array<string, string>, 1: string[]}
     */
    private function mapearColumnas(array $encabezado): array
    {
        $porNombre = [];
        foreach (self::COLUMNAS as $clave => $columna) {
            $porNombre[$this->normalizar($columna['titulo'])] = $clave;
            foreach ($columna['sinonimos'] as $sinonimo) {
                $porNombre[$this->normalizar($sinonimo)] = $clave;
            }
        }

        $mapa = [];
        $ignoradas = [];

        foreach ($encabezado as $letra => $titulo) {
            $texto = trim((string) $titulo);
            if ($texto === '') {
                continue;
            }

            // El asterisco de "obligatoria" de la plantilla no es parte del nombre.
            $normalizado = $this->normalizar(str_replace('*', '', $texto));
            $clave = $porNombre[$normalizado] ?? null;

            if ($clave !== null && !isset($mapa[$clave])) {
                $mapa[$clave] = $letra;
            } else {
                $ignoradas[] = $texto;
            }
        }

        return [$mapa, $ignoradas];
    }

    /**
     * Compara encabezados sin que molesten las tildes, las mayúsculas ni los espacios de más.
     */
    private function normalizar(string $texto): string
    {
        $sinTildes = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ñ' => 'n', 'Ü' => 'u',
        ]);

        $limpio = preg_replace('/[^a-z0-9]+/', ' ', strtolower($sinTildes));

        return trim(preg_replace('/\s+/', ' ', $limpio));
    }

    /**
     * Interpreta la fecha venga como texto o como número de serie de Excel.
     *
     * El número de serie es el caso importante: una celda con formato de fecha en Excel llega
     * como 40252, y pasarlo a DateTime directamente daba una excepción que terminaba guardando
     * la fecha de hoy como fecha de nacimiento.
     */
    private function interpretarFecha($valor): ?\DateTime
    {
        if ($valor === null || trim((string) $valor) === '') {
            return null;
        }

        if (is_numeric($valor) && (float) $valor > 0 && (float) $valor < 100000) {
            try {
                return \DateTime::createFromFormat(
                    'Y-m-d',
                    ExcelDate::excelToDateTimeObject((float) $valor)->format('Y-m-d')
                ) ?: null;
            } catch (\Throwable $e) {
                return null;
            }
        }

        $texto = trim((string) $valor);

        // d/m/Y primero: en una planilla local 03/04/2010 es el 3 de abril, no el 4 de marzo.
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y'] as $formato) {
            $fecha = \DateTime::createFromFormat($formato . '|', $texto);
            if ($fecha && $fecha->format($formato) === $texto) {
                return $fecha;
            }
        }

        return null;
    }

    private function dniYaExiste(string $dni): bool
    {
        // Sin filtrar por instituto a propósito: el DNI es único en toda la base, así que el
        // choque puede ser contra un alumno de otro instituto y hay que detectarlo igual.
        return $this->alumnoRepository->count(['dni' => $dni]) > 0;
    }

    private function oNull(string $valor): ?string
    {
        return $valor === '' ? null : $valor;
    }

    private function fallo(string $mensaje): array
    {
        return [
            'ok' => false,
            'error' => $mensaje,
            'columnasReconocidas' => [],
            'columnasIgnoradas' => [],
            'filas' => [],
            'resumen' => ['importables' => 0, 'con_aviso' => 0, 'rechazadas' => 0],
        ];
    }
}
