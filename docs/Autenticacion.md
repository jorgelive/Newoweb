# Autenticación: sesión, «Recordarme» y niveles

Cómo entra un usuario a `util` y al panel, qué garantiza cada nivel de autenticación y por qué
un solo atributo mal elegido puede dejar «Recordarme» sin efecto en toda la aplicación.

Todo lo de este documento vive en `config/packages/security.yaml`, `src/Controller/SecurityController.php`
y el modal de login de la SPA.

## 1. Las dos puertas de entrada

El firewall `main` acepta login por dos vías, y ambas terminan en la misma sesión:

```
  Formulario HTML (EasyAdmin, panel)          JSON desde la PWA (util)
   POST /login  ──── form_login ────┐    ┌──── json_login ──── POST /ajax_login
   enable_csrf: true                │    │    _username / _password / _remember_me
                                    ▼    ▼
                          firewall main (lazy, con sesión)
                                      │
                              provider: app_user_provider
```

- `SecurityController::login()` pinta el formulario; `SecurityController::jsonLogin()` responde
  al POST de la SPA; `SecurityController::logout()` lo intercepta el firewall.
- La SPA manda el login desde `util/src/components/GlobalLoginModal.vue`, que llama a
  `renewSession()` en `util/src/services/sessionAuth.ts`.
- `checkSession()` **no** tiene endpoint propio: sondea `/message/mercure/auth` y da la sesión por
  viva si la respuesta no es `text/html` (un redirect a `/login` sí lo sería).

## 2. «Recordarme»: qué es y qué NO es

Configurado en el bloque `remember_me` del firewall: cookie `REMEMBERME`, `lifetime: 2629746`
(≈30 días), `always_remember_me: false` — o sea, **sólo** si la petición trae `_remember_me`.

El modal de la SPA lo manda con `loginRemember = ref(true)`: la casilla nace marcada.

⚠️ **`json_login` sí soporta remember-me, aunque no lo parezca.** `JsonLoginAuthenticator` añade
`new RememberMeBadge((array) $data)` con el cuerpo JSON entero, y
`CheckRememberMeConditionsListener` lo habilita con `filter_var($parametro, FILTER_VALIDATE_BOOL)`.
Por eso un `_remember_me: true` de JavaScript vale igual que la casilla del formulario HTML.

## 3. ⚠️ Recordado ≠ autenticado — el eje de todo

Symfony distingue **tres** niveles, y la diferencia entre los dos últimos es la que muerde:

| Nivel | Qué significa | Lo cumple una cookie REMEMBERME |
|---|---|---|
| `PUBLIC_ACCESS` | cualquiera | — |
| `IS_AUTHENTICATED_REMEMBERED` / `ROLE_USER` | sabemos quién eres | **sí** |
| `IS_AUTHENTICATED_FULLY` | metiste la contraseña **en esta sesión** | **nunca** |

`access_control` no usa `FULLY` en ninguna regla: los hosts de `util`, panel y oweb piden
`ROLE_USER`. Un usuario recordado tiene sus roles reales, así que pasa.

⚠️ **Pero `getUser()` no distingue REMEMBERED de FULLY.** Devuelve el usuario en ambos casos,
porque remember-me **sí autentica**. Comprobar `getUser()` donde importa el nivel es el fallo de
esta familia, y no da ningún error: da una decisión equivocada.

## 4. El caso real: bucle infinito de redirecciones (16/08 → 02/09/2026)

`PermisoAjaxController` —la ruta `/tipo/user/enum/permisos`, que la SPA pide en **cada
arranque** para pintar botones deshabilitados— llevaba `#[IsGranted('IS_AUTHENTICATED_FULLY')]`.
Con la sesión caducada y la cookie REMEMBERME viva:

```
  SPA arranca
      │
      ▼
  GET /tipo/user/enum/permisos      ── pide FULLY, el usuario sólo está RECORDADO
      │                                 Symfony guarda el target y redirige
      ▼
  GET /login                        ── comprobaba getUser(), que SÍ devuelve usuario
      │                                 → redirige al target guardado
      └──────────────► vuelve al paso 1 ───► ERR_TOO_MANY_REDIRECTS
```

⚠️ **No dejaba rastro en el log**: son 302 perfectamente válidos. Y sólo le pasaba a quien había
marcado «Recordarme», así que parecía intermitente.

Se corrigió en dos pasos, y **hacen falta los dos**:

1. `SecurityController::login()` comprueba `isGranted('IS_AUTHENTICATED_FULLY')` en vez de
   `getUser()`. Corta el bucle: un usuario sólo recordado ve el formulario. Pero eso **no
   devuelve** el efecto de «Recordarme» — sólo cambia el cuelgue por una petición de contraseña.
2. `PermisoAjaxController` baja a `#[IsGranted('ROLE_USER')]`. Es lo que devuelve el sentido a la
   casilla: el endpoint es de sólo lectura y devuelve los permisos del propio usuario, así que no
   hay nada que unas credenciales frescas protejan y `ROLE_USER` no proteja ya.

**La regla que queda:** `IS_AUTHENTICATED_FULLY` se reserva para lo que de verdad exige
credenciales recientes —cambiar contraseña, cobrar, borrar—. Ponerlo en cualquier ruta del
arranque de la SPA equivale a **apagar «Recordarme» para toda la aplicación**, y el síntoma no
se parece a la causa.

## 5. Dónde tocar para cambiar X

| Necesidad | Archivo | Símbolo |
|---|---|---|
| Duración o nombre de la cookie «Recordarme» | `config/packages/security.yaml` | bloque `remember_me` del firewall `main` |
| Que la casilla nazca desmarcada | `util/src/components/GlobalLoginModal.vue` | `loginRemember` |
| Qué manda la SPA al autenticarse | `util/src/services/sessionAuth.ts` | `CredencialesLogin`, `renewSession()` |
| Cómo se detecta que la sesión murió | `util/src/services/sessionAuth.ts` | `checkSession()` |
| A dónde va un usuario ya logueado que pide `/login` | `src/Controller/SecurityController.php` | `login()` |
| Qué rutas exigen credenciales frescas | los `#[IsGranted]` de cada controlador | buscar `IS_AUTHENTICATED_FULLY` |
| Qué permisos ve el frontend | `src/Api/Controller/Tipo/PermisoAjaxController.php` | `ROLES_UI` |
