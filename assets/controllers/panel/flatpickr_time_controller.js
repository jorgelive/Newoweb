import { Controller } from '@hotwired/stimulus';
import flatpickr from 'flatpickr';
// 🔥 IMPORTACIÓN CRÍTICA: Esto garantiza que la ventanita negra aparezca (AssetMapper/Webpack)
import 'flatpickr/dist/flatpickr.min.css';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    connect() {
        this.fp = flatpickr(this.element, {
            enableTime: true,
            noCalendar: true,
            dateFormat: "H:i",
            time_24hr: true,
            disableMobile: true,
            allowInput: true,
            // 🔥 AQUÍ INYECTAS TU ESTILO AL POPUP 🔥
            onReady: function(selectedDates, dateStr, instance) {
            // instance.calendarContainer es el <div> flotante de la imagen
            instance.calendarContainer.style.width = "100px";
            instance.calendarContainer.style.minWidth = "100px"; // Por si Bootstrap intenta sobreescribirlo
        }
        });

        // 🔥 EL HACK VISUAL PARA BOOTSTRAP 🔥
        // Flatpickr es terco y a veces vuelve a poner el readonly.
        // Le forzamos el fondo blanco y le quitamos la cerradura.
        this.element.style.backgroundColor = '#ffffff';
        this.element.removeAttribute('readonly');
    }

    disconnect() {
        if (this.fp) {
            this.fp.destroy();
        }
    }
}