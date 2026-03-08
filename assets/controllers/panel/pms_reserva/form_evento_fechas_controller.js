import { Controller } from '@hotwired/stimulus';

export default class extends Controller {

    connect() {
        this.startInput = this.element;

        // 1. Buscamos dinámicamente el input 'fin' asociado a esta fila
        const endInputId = this.startInput.id.replace('_inicio', '_fin');
        this.endInput = document.getElementById(endInputId);

        if (!this.endInput) {
            return;
        }

        // 2. Calculamos la duración inicial (noches) y la hora de salida
        this.recalculateDuration();

        // 3. Aplicamos la restricción inicial (Min = Inicio + 1 día a las 00:00)
        this.updateMinAttribute();

        // 4. Listeners
        // Si cambia el FIN manualmente, recalculamos las noches base
        this.endInput.addEventListener('change', () => this.recalculateDuration());
    }

    /**
     * Acción disparada cuando el usuario cambia la Fecha de Inicio.
     * (Configurada en el PHP con data-action="change->...#updateEnd")
     */
    updateEnd() {
        if (!this.endInput || !this.startInput.value) return;

        const startDate = new Date(this.startInput.value);

        // A. Sincronización inteligente por NOCHES (no por milisegundos)
        const newEndDate = new Date(startDate);

        // Sumamos la cantidad de noches que tenía la reserva
        newEndDate.setDate(newEndDate.getDate() + (this.dayOffset || 1));

        // Restauramos la hora y minutos exactos que tenía el check-out original
        // (Si por algún motivo no existen, usamos 11:00 por defecto)
        newEndDate.setHours(this.endHours !== undefined ? this.endHours : 11);
        newEndDate.setMinutes(this.endMinutes !== undefined ? this.endMinutes : 0);

        this.endInput.value = this.toLocalISOString(newEndDate);

        // B. Actualizamos el bloqueo del calendario para que no permita errores
        this.updateMinAttribute();
    }

    /**
     * Bloqueo de fechas inválidas
     * Establece el atributo 'min' del campo Fin para que sea (Inicio + 1 día a las 00:00).
     */
    updateMinAttribute() {
        if (!this.startInput.value || !this.endInput) return;

        const startDate = new Date(this.startInput.value);
        const minDate = new Date(startDate);

        // Regla de Negocio: La salida debe ser al menos al día siguiente
        minDate.setDate(minDate.getDate() + 1);

        // Reseteamos la hora a 00:00 para permitir salidas a primera hora de la madrugada
        minDate.setHours(0, 0, 0, 0);

        this.endInput.min = this.toLocalISOString(minDate);
    }

    /**
     * Calcula cuántas noches de diferencia hay entre el inicio y el fin,
     * e identifica la hora a la que el huésped hará el check-out.
     */
    recalculateDuration() {
        if (!this.startInput.value || !this.endInput.value) return;

        const start = new Date(this.startInput.value);
        const end = new Date(this.endInput.value);

        // Guardamos explícitamente la hora y los minutos de salida
        this.endHours = end.getHours();
        this.endMinutes = end.getMinutes();

        // Comparamos solo los días (ignorando las horas) para saber el número de noches
        const startDay = new Date(start).setHours(0, 0, 0, 0);
        const endDay = new Date(end).setHours(0, 0, 0, 0);

        // 86400000 son los milisegundos que tiene un día
        this.dayOffset = Math.round((endDay - startDay) / 86400000);

        // Seguridad: Si la fecha está invertida o es el mismo día, forzamos mínimo 1 noche
        if (this.dayOffset <= 0) {
            this.dayOffset = 1;
        }
    }

    toLocalISOString(date) {
        // Función auxiliar para mantener la zona horaria local y el formato 'YYYY-MM-DDTHH:mm'
        const pad = (num) => num.toString().padStart(2, '0');
        const year = date.getFullYear();
        const month = pad(date.getMonth() + 1);
        const day = pad(date.getDate());
        const hours = pad(date.getHours());
        const minutes = pad(date.getMinutes());

        return `${year}-${month}-${day}T${hours}:${minutes}`;
    }
}