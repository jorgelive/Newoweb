<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * La zona horaria de la operación la decide el REPOSITORIO, no el `php.ini` del servidor.
     *
     * ── Por qué está aquí y no en la configuración de la máquina ────────────
     *
     * Todo este proyecto guarda **hora de pared**: `2026-08-31T14:00:00` significa las 14:00 en el
     * alojamiento, y no lleva huso dentro. Eso funciona porque cada `new DateTimeImmutable()` nace
     * en la misma zona en que se leen las columnas — y hasta el 08/09/2026 esa zona salía de
     * `date.timezone` del `php.ini`: MAMP en el portátil de quien desarrolla,
     * `/etc/php/8.4/fpm/php.ini` en el servidor. **Ni una línea del repositorio la fijaba.**
     *
     * ⚠️ Las dos decían `America/Lima`, así que nunca dio problemas. Pero un contenedor nuevo, un
     * runner de CI o una reinstalación de PHP arrancan en **UTC**: a partir de ese momento cada
     * fecha escrita entra cinco horas movida y se mezcla con las que ya había, sin un solo error en
     * ninguna parte. Es el fallo de `fecha_reserva_canal` —que costó una migración de 412 filas—
     * pero en todas las tablas a la vez.
     *
     * ── Lo que arregla ponerlo aquí ─────────────────────────────────────────
     *
     * `boot()` corre en TODAS las entradas: web, consola, workers y sondas. Después de esto,
     * `date_default_timezone_get()` deja de significar «lo que diga el sistema» y pasa a significar
     * «lo que decidimos», que es lo que hace legítimo usarlo como respaldo en
     * `PmsEstablecimiento::zonaHoraria()` y compañía.
     *
     * ⚠️ **Esto NO es la zona de un establecimiento.** Es el respaldo de la operación, para lo que
     * no cuelga de ninguno —una zona común, una conversación sin reserva— y para el arranque. La
     * zona de un alojamiento sale de `PmsEstablecimiento::zonaHoraria()`, y ésa es la que manda
     * siempre que exista.
     */
    /**
     * La zona de la operación, decidida aquí.
     *
     * ⚠️ **Ésta es LA decisión, no el `.env`.** En producción existe `.env.local.php` —el entorno
     * compilado por `composer dump-env`— y con él Symfony **deja de leer `.env`**, así que
     * `APP_ZONA_HORARIA` de ese archivo no llega nunca. Cambiar la variable allí no surte efecto
     * hasta volver a compilar el entorno, y el `post-merge` del servidor no lo hace.
     *
     * Por eso el valor vive en esta constante, que sí está en el repositorio y sí se despliega. La
     * variable de entorno queda como anulación para quien la necesite (un desarrollador probando
     * otro huso, un despliegue futuro en otro país).
     */
    private const ZONA_POR_DEFECTO = 'America/Lima';

    public function boot(): void
    {
        // Se lee del entorno directamente y no del contenedor: `boot()` es justo lo que construye
        // el contenedor, así que aquí todavía no hay parámetros que pedir.
        $zona = $_ENV['APP_ZONA_HORARIA'] ?? $_SERVER['APP_ZONA_HORARIA'] ?? self::ZONA_POR_DEFECTO;

        if (is_string($zona) && $zona !== '') {
            // Si el identificador es inválido, `date_default_timezone_set()` avisa y deja el
            // anterior. Vale más que reventar el arranque entero por una variable mal escrita.
            @date_default_timezone_set($zona);
        }

        parent::boot();
    }
}
