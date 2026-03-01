import { Controller } from '@hotwired/stimulus';

export default class extends Controller {

    connect() {
        this.startInput = this.element;

        // 1. Buscamos dinámicamente el input 'fin' asociado a esta fila
        const endInputId = this.startInput.id.replace('_fechaInicio', '_fechaFin');
        this.endInput = document.getElementById(endInputId);

        if (!this.endInput) {
            return;
        }

        // 2. Calculamos la duración inicial (para mantenerla si se mueve la fecha de inicio)
        this.recalculateDuration();

        // 3. 🔥 NUEVO: Aplicamos la restricción inicial (Min = Inicio + 1 día)
        this.updateMinAttribute();

        // 4. Listeners
        // Si cambia el FIN manualmente, recalculamos la duración base
        this.endInput.addEventListener('change', () => this.recalculateDuration());
    }

    /**
     * Acción disparada cuando el usuario cambia la Fecha de Inicio.
     * (Configurada en el PHP con data-action="change->...#updateEnd")
     */
    updateEnd() {
        if (!this.endInput || !this.startInput.value) return;

        const startDate = new Date(this.startInput.value);

        // A. Sincronización inteligente:
        // Movemos la fecha fin para mantener la misma cantidad de noches que había antes
        const newEndDate = new Date(startDate.getTime() + this.duration);
        this.endInput.value = this.toLocalISOString(newEndDate);

        // B. 🔥 NUEVO: Actualizamos el bloqueo del calendario (atributo min)
        this.updateMinAttribute();
    }

    /**
     * 🔥 NUEVO MÉTODO: Bloqueo de fechas inválidas
     * Establece el atributo 'min' del campo Fin para que sea (Inicio + 1 día).
     * Esto deshabilita visualmente los días anteriores en el selector.
     */
    updateMinAttribute() {
        if (!this.startInput.value || !this.endInput) return;

        const startDate = new Date(this.startInput.value);

        // Clonamos la fecha para no modificar la original
        const minDate = new Date(startDate);

        // Regla de Negocio: La salida debe ser al menos 1 día después de la entrada
        minDate.setDate(minDate.getDate() + 1);

        // Aplicamos el atributo HTML5 'min'
        this.endInput.min = this.toLocalISOString(minDate);
    }

    recalculateDuration() {
        if (!this.startInput.value || !this.endInput.value) return;

        const start = new Date(this.startInput.value);
        const end = new Date(this.endInput.value);

        // Guardamos la diferencia en milisegundos
        this.duration = end - start;

        // Seguridad: Si por alguna razón la duración es negativa o cero (usuario forzó input),
        // reseteamos la duración a 1 día (86400000 ms) por defecto.
        if (this.duration <= 0) {
            this.duration = 86400000;
        }
    }

    toLocalISOString(date) {
        // Función auxiliar para mantener la zona horaria local y el formato 'YYYY-MM-DDTHH:mm'
        // necesario para inputs datetime-local
        const pad = (num) => num.toString().padStart(2, '0');
        const year = date.getFullYear();
        const month = pad(date.getMonth() + 1);
        const day = pad(date.getDate());
        const hours = pad(date.getHours());
        const minutes = pad(date.getMinutes());

        return `${year}-${month}-${day}`;
    }
}