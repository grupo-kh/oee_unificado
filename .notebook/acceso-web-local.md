# Gotcha: abrir la app de producción en Chrome para estudiarla

## El problema
- Producción vive en `https://10.0.5.67/PLAN_ATTAINMENT/` con **certificado self-signed** de XAMPP.
- Chrome muestra el interstitial "Your connection is not private" en una página `chrome-error://`
  **privilegiada**: la extensión de automatización NO puede hacer click ni teclear `thisisunsafe` ahí
  (devuelve `Cannot attach to this target`).
- Sólo escucha por **443** (HTTP/80 da connection-refused). La raíz `/` redirige a `/dashboard/` (welcome de XAMPP);
  la app cuelga de `/PLAN_ATTAINMENT/`.

## La solución que funcionó: proxy local HTTP→HTTPS
Script en `/tmp/pa_proxy.py` (Python stdlib). Termina el TLS self-signed (verify off, **deliberado, sólo
intranet**) y expone la app en `http://127.0.0.1:8911` SIN advertencia de cert. Reescribe `Location:` y quita
`Secure` de las cookies para que la sesión viaje por http local.
```
python3 /tmp/pa_proxy.py &        # escucha 127.0.0.1:8911 -> https://10.0.5.67
# Chrome → http://127.0.0.1:8911/PLAN_ATTAINMENT/
```

## Gotcha dentro del gotcha: `urllib` cuelga, usar `http.client`
El servidor (¿mod_security?) **deja colgada** la petición cuando el `User-Agent` es `Python-urllib/x`
(timeout). `curl` y `http.client.HTTPSConnection` con UA normal responden en ~50 ms. El proxy usa
`http.client`, no `urllib`. También: `protocol_version = "HTTP/1.0"` (sin keep-alive) evita resets.

## Alternativa para estudio puramente visual (sin datos de red)
`php -S 127.0.0.1:PORT` desde la raíz del repo local renderiza home y vistas (el `.htaccess` no aplica con el
server embebido, da igual para mirar). Los datos requieren alcanzar MAPEX/SAGE/PG, que están en otra red.
