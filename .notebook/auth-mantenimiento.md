# Auth del módulo Mantenimiento

Único módulo con login/escritura. Implementado en `lib/Auth.php` (sesión PHP, sin framework).

## Roles
- **tecnico** — acceso completo (CRUD, exportar). Usuario por defecto en `.env`: `MANT_TECNICO_USER=Ricardo`.
- **operario** — sólo registra la fecha de una tarea preventiva (`mant_marcar_hecha`); el resto es solo lectura.

## Credenciales y seguridad
- Passwords como **hash bcrypt** en `.env` (`MANT_*_PASS_HASH`). Fallback a credenciales por defecto SÓLO si `APP_ENV !== 'production'`.
- Cookie de sesión HttpOnly + SameSite=Lax + Secure si HTTPS. `session_regenerate_id()` tras login (anti-fixation).
- Rate-limit: 5 fallos por IP → bloqueo 15 min (JSON en sys temp).
- Token **CSRF** por sesión para los POST de mantenimiento.

## Flujo
`views/mantenimiento.php` (y demás `mant_*`) → si no hay sesión, redirige a `views/mant_login.php?next=<vista>`.
En el front, `apiFetch` (`common.js:37`) detecta `401` y hace la misma redirección automáticamente.
Endpoints JSON de login/logout: `api/mant_login(_json/_movil).php`, `api/mant_logout(_json).php`, `api/mant_session.php`.

## Login móvil / QR (en desarrollo, sin commit)
`api/mant_login_movil.php`, `appmovil.php`, `lib/QrToken.php` (tokens firmados, secreto en `config/qr_secret.dat`)
para marcado de revisiones por QR desde la app del operario.
