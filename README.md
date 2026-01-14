# Auto Blog API

Plugin de WordPress que importa de forma automatizada entradas desde otra instalación de WordPress utilizando el endpoint REST `wp-json/wp/v2/posts`. Gestiona categorías, etiquetas, imágenes destacadas y evita duplicados mediante el GUID remoto y el título.

## Requisitos

- WordPress 5.6 o superior.
- PHP 7.4 o superior.
- Acceso a `wp-cron` (automático) o a `WP-CLI` para ejecutar eventos programados manualmente.
- La constante `AUTO_BLOG_API_URL` definida apuntando al sitio origen.

## Instalación

1. Copie la carpeta `autoblogapi` dentro de `wp-content/plugins/`.
2. Defina la constante en `wp-config.php` **antes** de la línea que incluye `wp-settings.php`:

   ```php
   define('AUTO_BLOG_API_URL', 'https://dominio-origen.com/wp-json/wp/v2/posts');
   ```

3. Active el plugin desde el panel de administración o con `wp plugin activate autoblogapi`.

Al activarse, el plugin registra un evento cron (`autoblogapi_import_event`) que se ejecuta cada 15 minutos.

## Flujo de importación

1. Se consulta la URL remota añadiendo el parámetro `_embed=1` para obtener términos e imagen destacada.
2. Para cada entrada remota se toman el GUID y el título; si ya existen en el sitio local, se omite la importación.
3. Se crean categorías y etiquetas faltantes antes de insertar la entrada local.
4. La entrada se guarda asignando autor administrador, términos preparados, fecha original (si está disponible) y el meta `_autoblogapi_guid`.
5. Se descarga y asigna la imagen destacada.
6. Se registra en el log un resumen de la importación con título, categorías, etiquetas e imágenes.

## Ejecuciones manuales

### REST API

El plugin expone un endpoint:

```
POST /wp-json/autoblogapi/v1/import
```

Ejemplo usando `curl`

```bash
curl -X GET \
  https://tu-sitio.com/wp-json/autoblogapi/v1/import
```

### WP-CLI

Para forzar la ejecución del cron sin esperar 15 minutos:

```bash
wp cron event run autoblogapi_import_event
```

## Logs

Cada importación genera mensajes en el log de PHP con este formato:

```
AutoBlogAPI rastreo -> titulo: "Ejemplo" | categorias: ["Noticias"] | tags: ["Actualidad"] | imagenes: ["https://..."]
```

Si no se asigna imagen o hay errores de descarga, también se documenta en el log.

## Eliminación

Al desactivar el plugin se desprograma el evento cron, pero **no** se eliminan las entradas importadas ni los metadatos.

## Ideas adicionales

- Añadir soporte para campos personalizados específicos del sitio origen.
- Guardar la última fecha importada para traer solo entradas nuevas usando parámetros `after`.
- Integrar notificaciones (correo o Slack) cuando la importación genere errores.

---

© 2026 MFerro. Distribuido como parte del proyecto Auto Blog API.
