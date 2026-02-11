import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static values = {
        text: String
    };

    async copy(event) {
        event.preventDefault();

        const btn = event.currentTarget;
        const originalHtml = btn.innerHTML;

        try {
            await navigator.clipboard.writeText(this.textValue);

            btn.classList.remove("btn-outline-primary");
            btn.classList.add("btn-success");
            btn.innerHTML = '<i class="fas fa-check me-1"></i> Copiado';

            setTimeout(() => {
                btn.classList.remove("btn-success");
                btn.classList.add("btn-outline-primary");
                btn.innerHTML = originalHtml;
            }, 1500);
        } catch (e) {
            btn.classList.remove("btn-outline-primary");
            btn.classList.add("btn-danger");
            btn.innerHTML = '<i class="fas fa-times me-1"></i> Error';

            setTimeout(() => {
                btn.classList.remove("btn-danger");
                btn.classList.add("btn-outline-primary");
                btn.innerHTML = originalHtml;
            }, 1500);
        }
    }
}