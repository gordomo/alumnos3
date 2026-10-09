<?php

namespace App\Service;

/**
 * Cómo se llaman las cosas en cada producto.
 *
 * El sistema es el mismo para un instituto educativo y para un club deportivo, pero las palabras
 * no: lo que en uno es un curso con alumnos, en el otro es una disciplina con socios. Tener el
 * glosario en un solo archivo permite corregir una palabra en todo el sistema de una vez, y deja
 * a la vista qué dice cada versión sin recorrer 119 plantillas.
 *
 * Las claves incluyen el artículo cuando hace falta ('el_curso'), porque el género cambia: "el
 * curso" pero "la disciplina". Sin eso, cada frase tendría que resolverlo por su cuenta y la
 * mitad quedarían mal escritas.
 *
 * Lo que NO está acá: los nombres de las clases, las rutas y las columnas de la base. Un Curso
 * sigue siendo un Curso en el código. Cambiar eso sería un refactor enorme y sin ninguna ganancia
 * para quien usa el sistema.
 */
class Vocabulario
{
    /**
     * Cada entrada: clave => [instituto, club].
     */
    private const GLOSARIO = [
        // El cliente del sistema
        'instituto' => ['instituto', 'club'],
        'instituto_plural' => ['institutos', 'clubes'],
        'el_instituto' => ['el instituto', 'el club'],
        'del_instituto' => ['del instituto', 'del club'],
        'tu_instituto' => ['tu instituto', 'tu club'],

        // Quien asiste y paga
        'alumno' => ['alumn@', 'soci@'],
        'alumnos' => ['alumn@s', 'soci@s'],
        'el_alumno' => ['el alumn@', 'el soci@'],
        'del_alumno' => ['del alumn@', 'del soci@'],
        'alumno_formal' => ['alumno', 'socio'],
        'alumnos_formal' => ['alumnos', 'socios'],

        // Lo que se dicta
        'curso' => ['curso', 'disciplina'],
        'cursos' => ['cursos', 'disciplinas'],
        'el_curso' => ['el curso', 'la disciplina'],
        'los_cursos' => ['los cursos', 'las disciplinas'],
        'del_curso' => ['del curso', 'de la disciplina'],
        'al_curso' => ['al curso', 'a la disciplina'],
        'un_curso' => ['un curso', 'una disciplina'],
        'este_curso' => ['este curso', 'esta disciplina'],

        // El encuentro concreto
        'clase' => ['clase', 'entrenamiento'],
        'clases' => ['clases', 'entrenamientos'],
        'la_clase' => ['la clase', 'el entrenamiento'],

        // Quien la dicta
        'profesor' => ['profesor', 'profe'],
        'profesores' => ['profesores', 'profes'],
        'el_profesor' => ['el profesor', 'el profe'],

        // La inscripción y lo que se cobra
        'inscripcion' => ['inscripción', 'inscripción'],
        'cuota' => ['cuota', 'cuota'],
    ];

    public function __construct(private string $vertical = 'instituto')
    {
    }

    /**
     * La palabra que corresponde a esta instalación.
     *
     * Si la clave no está en el glosario se devuelve tal cual: es preferible que una pantalla
     * muestre "alumno_raro" y se note, a que muestre un espacio en blanco y nadie lo vea.
     */
    public function termino(string $clave): string
    {
        if (!isset(self::GLOSARIO[$clave])) {
            return $clave;
        }

        [$instituto, $club] = self::GLOSARIO[$clave];

        return $this->vertical === 'club' ? $club : $instituto;
    }

    /**
     * La misma palabra con la primera letra en mayúscula, para títulos y comienzos de frase.
     * No se usa ucfirst: con acentos y multibyte devuelve cualquier cosa.
     */
    public function terminoMayuscula(string $clave): string
    {
        $palabra = $this->termino($clave);

        return mb_strtoupper(mb_substr($palabra, 0, 1)) . mb_substr($palabra, 1);
    }

    public function esClub(): bool
    {
        return $this->vertical === 'club';
    }
}
