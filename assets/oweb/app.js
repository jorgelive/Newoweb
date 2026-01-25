/* Entrypoint para Oweb Admin */

// 1. CARGA DE ESTILOS
// AssetMapper leerá esto, extraerá el CSS e inyectará <link> en el HTML.
import './styles/admin.css';

// 2. CARGA DE SCRIPTS
import './scripts/base_sonata.js';

// 3. CARGA DEL MOTOR (Stimulus)
// Esto ejecuta el código de assets/bootstrap.js
import '../bootstrap.js';

console.log('Oweb: Entrypoint cargado correctamente (JS + CSS)');