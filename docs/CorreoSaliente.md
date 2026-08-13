# Correo saliente — de SMTP básico a Microsoft Graph

**Propósito.** Cómo envía correo Newoweb, por qué se abandonó SMTP y los pasos exactos
para completar la migración. **Alcance:** `config/packages/mailer.yaml`, las variables
`MAILER_*` de `.env`/`.env.local`, y la configuración del tenant de Microsoft 365 (fuera
del repositorio).

---

## 1. Por qué se migra

Hasta agosto de 2026 el envío era:

```
MAILER_DSN=smtp://jorge@openperu.pe:<contraseña>@smtp.office365.com:587?...&auth_mode=login
```

Tres problemas, en orden de gravedad:

1. **`jorge@openperu.pe` es Global Admin del tenant.** Esa contraseña, en texto plano en
   `.env.local` del servidor, no sólo mandaba correo: abría Microsoft 365 entero —
   buzones de todos, configuración de la organización, concesión de roles.
2. **SMTP AUTH está deshabilitado a nivel de organización.** Funcionaba únicamente por
   una excepción explícita sobre ese buzón. Una excepción es algo que alguien retira.
3. `auth_mode=login` es autenticación básica, el mecanismo que Microsoft lleva años
   retirando.

Con Graph la aplicación tiene **identidad propia**, con un permiso (`Mail.Send`) y un
alcance (un solo buzón). Comprometer el servidor deja de implicar comprometer el tenant.

⚠️ El puente oficial de Symfony **sólo soporta client secret, no certificado**. Sigue
habiendo un valor secreto en `.env.local` — la diferencia es lo que ese secreto puede
hacer: enviar correo desde un buzón, en vez de administrar la organización.

---

## 2. Cómo queda

```
Newoweb (Symfony 7.4)
   │  symfony/microsoft-graph-mailer v7.4.14
   │  MAILER_DSN=microsoftgraph+api://APP_ID:SECRET@default?tenantId=…
   ▼
Microsoft Graph  POST /users/{From}/sendMail
   │
   │  ← Application Access Policy limita a qué buzones puede escribir
   ▼
Buzón remitente (compartido, sin licencia)  ──►  las respuestas llegan aquí
```

**El buzón remitente lo decide `MAILER_SENDER_EMAIL`**, no el DSN: Graph envía contra
`/users/{From}/sendMail`. Ese buzón tiene que estar dentro de la política de acceso.

**No uses `noreply@`.** El correo de reservas y órdenes de servicio es correo que la
gente responde. Un buzón compartido de M365 no consume licencia y el equipo lo atiende.

---

## 3. Pasos pendientes (requieren autenticación interactiva)

Requisito: los módulos ya están instalados en el Mac
(`Microsoft.Graph.*` 2.39.0 y `ExchangeOnlineManagement` 3.10.1).

### 3.1 Registrar la aplicación

```powershell
Connect-MgGraph -Scopes "Application.ReadWrite.All","AppRoleAssignment.ReadWrite.All" -UseDeviceCode

$app = New-MgApplication -DisplayName "openperu-mailer" -SignInAudience AzureADMyOrg
$sp  = New-MgServicePrincipal -AppId $app.AppId

# Permiso de APLICACIÓN Mail.Send, y ningún otro
$graph = Get-MgServicePrincipal -Filter "appId eq '00000003-0000-0000-c000-000000000000'"
$rol   = $graph.AppRoles | Where-Object { $_.Value -eq 'Mail.Send' -and $_.AllowedMemberTypes -contains 'Application' }

New-MgServicePrincipalAppRoleAssignment -ServicePrincipalId $sp.Id `
    -PrincipalId $sp.Id -ResourceId $graph.Id -AppRoleId $rol.Id

$app.AppId   # ← APP_ID del DSN
```

⚠️ **No concedas `Mail.ReadWrite`.** Permite leer y modificar buzones; para enviar no
hace falta.

### 3.2 Crear el secreto

```powershell
$cred = Add-MgApplicationPassword -ApplicationId $app.Id -PasswordCredential @{
    DisplayName = "newoweb-prod"
    EndDateTime = (Get-Date).AddYears(2)
}
$cred.SecretText   # ← APP_SECRET. Se muestra UNA sola vez.
```

**Anota la fecha de caducidad.** Cuando expire, el envío se cae sin aviso previo.

### 3.3 Buzón remitente y política de alcance

```powershell
Connect-ExchangeOnline -Device

New-Mailbox -Shared -Name "Reservas OpenPeru" -PrimarySmtpAddress reservas@openperu.pe

# Grupo, no buzón suelto: añadir remitentes mañana es cambiar una membresía
New-DistributionGroup -Name "sg-app-mailsenders" -Type Security `
    -PrimarySmtpAddress sg-app-mailsenders@openperu.pe -Members reservas@openperu.pe

New-ApplicationAccessPolicy -AppId <APP_ID> `
    -PolicyScopeGroupId sg-app-mailsenders@openperu.pe `
    -AccessRight RestrictAccess -Description "Solo buzones de envio automatico"
```

🔥 **Sin esta política, `Mail.Send` de aplicación puede enviar como CUALQUIER buzón del
tenant** — incluido el de dirección. Es el paso que convierte un permiso peligroso en uno
acotado. No lo dejes para después.

Nota: Microsoft está sustituyendo `ApplicationAccessPolicy` por *RBAC for Applications*.
Ambos funcionan hoy; si el cmdlet falla, ésa es la vía nueva.

### 3.4 Configurar Newoweb

En `.env.local` (nunca en `.env`, que sí se versiona):

```
MAILER_DSN=microsoftgraph+api://APP_ID:APP_SECRET@default?tenantId=521d7551-30a8-4c63-ba93-8869d8d03360
MAILER_SENDER_EMAIL=reservas@openperu.pe
```

### 3.5 Probar ANTES de cerrar nada

Enviar una reserva real y confirmar que llega. Sólo entonces:

### 3.6 Cerrar SMTP AUTH

```powershell
Set-CASMailbox -Identity jorge@openperu.pe -SmtpClientAuthenticationDisabled $true
Get-CASMailbox jorge@openperu.pe | Select SmtpClientAuthenticationDisabled   # debe dar True
```

Y **rotar la contraseña de `jorge@openperu.pe`**: estuvo en texto plano en el servidor,
hay que considerarla comprometida.

Opcional, misma sesión: revisar si POP e IMAP hacen falta — hoy están activos en los tres
buzones.

---

## 4. Orden, y por qué importa

```
registrar app → secreto → buzón → POLÍTICA → configurar → PROBAR → cerrar SMTP → rotar
```

Cerrar SMTP antes de probar Graph deja a Newoweb sin ninguna vía de envío: las reservas
y órdenes de servicio dejan de salir y nadie se entera hasta que un cliente reclama.

**Vuelta atrás:** restaurar el `MAILER_DSN` de SMTP en `.env.local` y volver a poner
`SmtpClientAuthenticationDisabled $false`. Funciona sólo mientras no se haya rotado la
contraseña — por eso rotar es el último paso.

---

## 5. Dónde tocar para cambiar X

| Necesidad | Archivo | Clave / cmdlet |
|---|---|---|
| Cambiar el buzón remitente | `.env.local` | `MAILER_SENDER_EMAIL` — y añadirlo al grupo `sg-app-mailsenders` |
| Añadir otro remitente autorizado | Exchange Online | `Add-DistributionGroupMember -Identity sg-app-mailsenders` |
| Rotar el secreto | Entra ID + `.env.local` | `Add-MgApplicationPassword`, luego el DSN |
| Ver si la app sigue acotada | Exchange Online | `Get-ApplicationAccessPolicy` |
| Diagnosticar un envío fallido | `var/log/error.log` | canal `mailer` |
| Volver a SMTP (emergencia) | `.env.local` | `MAILER_DSN=smtp://…` + reabrir SMTP AUTH |
