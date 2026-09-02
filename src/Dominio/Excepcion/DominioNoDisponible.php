<?php

declare(strict_types=1);

namespace App\Dominio\Excepcion;

/**
 * El cálculo compartido no pudo responder.
 *
 * ⚠️ **Nunca significa «salió vacío»**: significa que no hay respuesta. Quien la captura decide
 * qué hacer, y la política está escrita una vez en `docs/PlanProcesamientoCompartido.md` §5:
 *
 *   - PDF o correo  → 503 y NINGÚN documento. Uno con la mitad del viaje es peor que ninguno.
 *   - Escritura con dinero → NO se guarda. Nunca un total a medias.
 *
 * Lo que no se hace nunca es seguir con un valor por defecto: eso convierte un fallo ruidoso en
 * uno mudo, que es el que cuesta caro.
 */
final class DominioNoDisponible extends \RuntimeException
{
}
