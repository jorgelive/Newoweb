import { Controller } from '@hotwired/stimulus';
import Swal from 'sweetalert2';

/*
 * Controlador: panel--pms-tarifa-rango--masiva
 * Ubicación: assets/controllers/panel/pms_tarifa_rango/masiva_controller.js
 */
export default class extends Controller {
    static targets = ['form', 'startDate', 'endDate'];

    connect() {
        // Opcional: Verificar conexión
        // console.log('Controlador Masivo conectado');
    }

    /**
     * Calcula automáticamente la fecha fin (Inicio + 3 días)
     */
    updateEndDate() {
        const startValue = this.startDateTarget.value;
        if (!startValue) return;

        // Creamos la fecha asegurando que se interprete como local para evitar problemas de zona horaria
        // Al usar inputs type="date", el valor viene como "YYYY-MM-DD"
        const date = new Date(startValue + 'T00:00:00');

        if (!isNaN(date.getTime())) {
            // Sumar 3 días
            date.setDate(date.getDate() + 3);

            // Formatear a YYYY-MM-DD
            const yyyy = date.getFullYear();
            const mm = String(date.getMonth() + 1).padStart(2, '0');
            const dd = String(date.getDate()).padStart(2, '0');

            this.endDateTarget.value = `${yyyy}-${mm}-${dd}`;
        }
    }

    /**
     * Muestra confirmación con SweetAlert2 antes de enviar
     */
    confirm(event) {
        event.preventDefault(); // Detener el envío normal

        Swal.fire({
            title: '¿Generar Tarifas?',
            html: `Se crearán tarifas para <b>todas las unidades activas</b>.<br>
                   Esta acción enviará actualizaciones a Beds24.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#0d6efd', // Azul Bootstrap
            cancelButtonColor: '#6c757d',  // Gris Bootstrap
            confirmButtonText: 'Sí, ejecutar',
            cancelButtonText: 'Cancelar',
            reverseButtons: true
        }).then((result) => {
            if (result.isConfirmed) {
                this.showLoading();
                this.formTarget.submit(); // Enviar formulario manualmente
            }
        });
    }

    showLoading() {
        Swal.fire({
            title: 'Procesando...',
            text: 'Calculando tarifas y generando colas de envío.',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
    }
}