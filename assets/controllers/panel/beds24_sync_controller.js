import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        console.log("Controlador de sincronización conectado al Panel");
    }

    update() {
        console.log("Iniciando sincronización con Beds24...");
        // Aquí irá tu fetch a la API
    }
}