//src/sttores/attachmentStore.ts
import { defineStore } from 'pinia';
import { ref, computed } from 'vue';

export const useAttachmentStore = defineStore('attachmentStore', () => {
    // ============================================================================
    // ESTADO
    // ============================================================================
    const file = ref<File | null>(null);
    const previewUrl = ref<string | null>(null);
    const error = ref<string | null>(null);

    // Límite de seguridad. Al usar multipart PHP aguanta más,
    // pero 10MB es un buen límite para fotos/documentos de un PMS.
    const MAX_SIZE_MB = 10;

    // ============================================================================
    // GETTERS COMPUTADOS
    // ============================================================================
    const isImage = computed(() => file.value?.type.startsWith('image/') || false);
    const fileSizeKB = computed(() => file.value ? (file.value.size / 1024).toFixed(1) : '0');
    const fileName = computed(() => file.value?.name || '');
    const mimeType = computed(() => file.value?.type || '');

    // ============================================================================
    // ACCIONES
    // ============================================================================
    const setFile = (newFile: File): boolean => {
        clear(); // Limpiamos cualquier rastro anterior para evitar fugas de memoria

        if (newFile.size > MAX_SIZE_MB * 1024 * 1024) {
            error.value = `El archivo pesa ${(newFile.size / 1024 / 1024).toFixed(1)}MB. El máximo permitido es ${MAX_SIZE_MB}MB.`;
            return false;
        }

        file.value = newFile;

        // Si es imagen, creamos un enlace local súper rápido (Blob URL) para la vista previa
        // Esto no sube nada al servidor, solo le dice al navegador que renderice el archivo en RAM.
        if (newFile.type.startsWith('image/')) {
            previewUrl.value = URL.createObjectURL(newFile);
        }

        return true;
    };

    const clear = () => {
        file.value = null;
        error.value = null;

        if (previewUrl.value) {
            URL.revokeObjectURL(previewUrl.value); // Liberamos la memoria RAM del navegador
            previewUrl.value = null;
        }
    };

    return {
        file, previewUrl, error, isImage, fileSizeKB, fileName, mimeType,
        setFile, clear
    };
});