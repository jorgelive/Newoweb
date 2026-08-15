// ============================================================================
// ESLint (flat config, v9)
//
// Cubre lo que `vue-tsc` NO ve: variables e imports muertos, `console.log`
// olvidados, promesas sin await, `v-for` sin `:key`. El typecheck comprueba que los
// tipos encajen; esto comprueba que el código esté limpio.
//
// Arranca con las recomendadas y sin comprobaciones que exijan información de tipos
// (`projectService`): en un proyecto de este tamaño y sin historial de lint, eso
// dispararía cientos de avisos de golpe y el informe dejaría de leerse. Cuando la
// base esté limpia se puede subir el listón.
// ============================================================================

import js from '@eslint/js';
import pluginVue from 'eslint-plugin-vue';
import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript';

export default defineConfigWithVueTs(
    {
        name: 'ignorados',
        ignores: [
            'dist/**',
            'node_modules/**',
            // Lo genera Vite (`vite/client`): se regenera entero, no se corrige a mano.
            'src/vite-env.d.ts',
            // Generado por openapi-typescript: se regenera entero, no se corrige.
            'src/types/api.d.ts',
            // Scripts de build en Node puro, con otra forma de módulos.
            'scripts/**',
            'public/**',
        ],
    },

    js.configs.recommended,
    ...pluginVue.configs['flat/recommended'],
    vueTsConfigs.recommended,

    {
        name: 'ajustes-del-proyecto',
        rules: {
            // El código ya usa `_` para lo que se ignora a propósito (destructuring,
            // parámetros de firma que no se usan). Sin esto serían falsos positivos.
            '@typescript-eslint/no-unused-vars': ['warn', {
                argsIgnorePattern: '^_',
                varsIgnorePattern: '^_',
                caughtErrorsIgnorePattern: '^_',
            }],

            // `console.error` y `console.warn` son la bitácora real de la SPA —los
            // stores los usan para los fallos de red— así que sólo se avisa del `log`
            // y del `debug`, que son los que se dejan olvidados al depurar.
            'no-console': ['warn', { allow: ['error', 'warn', 'info'] }],
            'no-debugger': 'error',

            // Vue: nombres de componente de una palabra están por todo el proyecto
            // (HomeView, ChatView…) y renombrarlos ahora no aporta nada.
            'vue/multi-word-component-names': 'off',

            // ── FORMATO: TODO APAGADO, Y A PROPÓSITO ────────────────────────────
            //
            // Con estas activadas el informe salía con 1.344 avisos, de los que 1.251
            // eran saltos de línea y posición de corchetes en las plantillas. Un
            // informe así no se lee, y los ~80 hallazgos de verdad —variables muertas,
            // `any`, `v-html`— quedaban enterrados. Además arreglarlas reescribiría
            // todos los templates de golpe, convirtiendo cualquier diff futuro en algo
            // imposible de revisar.
            //
            // Si algún día se adopta un formateador (Prettier), esto se borra y lo
            // resuelve él en una pasada única y aparte.
            'vue/max-attributes-per-line': 'off',
            'vue/singleline-html-element-content-newline': 'off',
            'vue/multiline-html-element-content-newline': 'off',
            'vue/html-indent': 'off',
            'vue/html-self-closing': 'off',
            'vue/attributes-order': 'off',
            'vue/first-attribute-linebreak': 'off',
            'vue/html-closing-bracket-newline': 'off',
            'vue/html-closing-bracket-spacing': 'off',
            'vue/no-multi-spaces': 'off',
            'vue/attribute-hyphenation': 'off',
        },
    },
);
