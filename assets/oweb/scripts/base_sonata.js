document.addEventListener('DOMContentLoaded', function () {
    // Lightbox
    $(document).on('click', '[data-toggle="lightbox"]', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).ekkoLightbox();
    });

    // Select2 focus seguro
    $(document).on("select2:open", () => {
        const el = document.querySelector(".select2-container--open .select2-search__field");
        if (el) el.focus();
    });

    // Clipboard con API moderna
    $('.clipboard-trigger').on('click', async function () {
        const text = $(this).data('text');
        const tip  = $(this).data('tooltiptext') || 'Copiado ✔';
        if (!text) return console.warn('Falta data-text');

        try {
            await navigator.clipboard.writeText(String(text));
            const $tooltip = $('<div id="tooltipdiv">'+ tip +'</div>');
            $(".content-wrapper").prepend($tooltip);
            setTimeout(() => { $tooltip.fadeOut(300, function(){ $(this).remove(); }); }, 800);
        } catch (err) {
            console.error('Clipboard error', err);
        }
    });
});