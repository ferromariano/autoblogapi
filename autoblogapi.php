<?php
/**
 * Plugin Name: Auto Blog Api
 * Description: Imports posts from another WordPress instance using its REST API.
 * Author: MFerro
 * Version: 1.0.0
 */

defined('ABSPATH') || exit;

if (!class_exists('Auto_Blog_API')) {
    class Auto_Blog_API {
        private const META_KEY = '_autoblogapi_guid';
        private const FLAG_META_KEY = 'AUTO_BLOG_API';
        private const CRON_HOOK = 'autoblogapi_import_event';

        /** @var Auto_Blog_API|null */
        private static $instance = null;

        /**
         * Inicializa el singleton del plugin y engancha acciones y filtros principales.
         *
         * @return Auto_Blog_API Instancia compartida del plugin.
         */
        public static function get_instance(): Auto_Blog_API {
            if (self::$instance === null) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        /**
         * Constructor: registra hooks en el ciclo de vida de WordPress.
         */
        private function __construct() {
            add_filter('cron_schedules', [$this, 'add_cron_schedule']);
            add_action(self::CRON_HOOK, [$this, 'run_import']);
            add_action('rest_api_init', [$this, 'register_rest_routes']);
        }

        /**
         * Programa el evento cron al activar el plugin.
         */
        public static function activate(): void {
            if (!defined('AUTO_BLOG_API_URL') || empty(AUTO_BLOG_API_URL)) {
                return;
            }

            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time(), 'autoblogapi_quarter_hour', self::CRON_HOOK);
            }
        }

        /**
         * Elimina el evento cron al desactivar el plugin.
         */
        public static function deactivate(): void {
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
            if ($timestamp) {
                wp_unschedule_event($timestamp, self::CRON_HOOK);
            }
        }

        /**
         * Registra un intervalo de cron personalizado de quince minutos.
         *
         * @param array $schedules Lista de intervalos existentes.
         * @return array Lista de intervalos ampliada con la opción del plugin.
         */
        public function add_cron_schedule(array $schedules): array {
            if (!isset($schedules['autoblogapi_quarter_hour'])) {
                $schedules['autoblogapi_quarter_hour'] = [
                    'interval' => 15 * MINUTE_IN_SECONDS,
                    'display' => 'Every Fifteen Minutes',
                ];
            }

            return $schedules;
        }

        /**
         * Registra la ruta REST utilizada para ejecutar la importación bajo demanda.
         */
        public function register_rest_routes(): void {
            register_rest_route(
                'autoblogapi/v1',
                '/import',
                [
                    'methods' => ['GET'],
                    'callback' => [$this, 'run_import'],
                    'permission_callback' => '__return_true'
                ]
            );
        }

        /**
         * Ejecuta la importación de entradas desde el origen remoto.
         *
         * @param WP_REST_Request|mixed $request Solicitud REST o valor nulo cuando se invoca por cron.
         * @return true|WP_Error Resultado de la importación.
         */
        public function run_import($request = null) {
            if (!defined('AUTO_BLOG_API_URL') || empty(AUTO_BLOG_API_URL)) {
                return new WP_Error('autoblogapi_missing_url', 'AUTO_BLOG_API_URL is not defined.');
            }

            $remote_posts = $this->fetch_remote_posts(AUTO_BLOG_API_URL);
            if (is_wp_error($remote_posts)) {
                return $remote_posts;
            }

            if (empty($remote_posts)) {
                return true;
            }

            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $admin_id = $this->get_admin_user_id();
            foreach ($remote_posts as $remote_post) {
                $guid = $this->extract_guid($remote_post);
                $title = $this->extract_title($remote_post);
                $tax_input = $this->prepare_terms($remote_post);

                $existing_id = $this->find_existing_post($guid, $title);
                if ($existing_id > 0) {
                    $this->sync_terms_with_post($existing_id, $tax_input);
                    if ($guid !== '') {
                        update_post_meta($existing_id, self::META_KEY, $guid);
                    }
                    update_post_meta($existing_id, self::FLAG_META_KEY, '1');
                    $this->log_post_details($existing_id, $remote_post);
                    continue;
                }

                $post_id = $this->insert_post($remote_post, $admin_id, $title, $tax_input);
                if (!$post_id || is_wp_error($post_id)) {
                    continue;
                }

                $this->handle_featured_media($post_id, $remote_post);
                add_post_meta($post_id, self::META_KEY, $guid, true);
                add_post_meta($post_id, self::FLAG_META_KEY, '1', true);
                $this->sync_terms_with_post($post_id, $tax_input);
                $this->log_post_details($post_id, $remote_post);
            }

            return true;
        }

        /**
         * Recupera las entradas del endpoint remoto añadiendo _embed para términos e imágenes.
         *
         * @param string $endpoint URL completa del endpoint wp-json/wp/v2/posts.
         * @return array|WP_Error Datos decodificados o error de la petición remota.
         */
        private function fetch_remote_posts(string $endpoint) {
            $url = add_query_arg('_embed', '1', $endpoint);
            $response = wp_remote_get($url, ['timeout' => 20]);

            if (is_wp_error($response)) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);
            if ((int) $code !== 200) {
                return new WP_Error('autoblogapi_bad_status', 'Unexpected status code from remote API.', ['status' => $code]);
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (!is_array($data)) {
                return new WP_Error('autoblogapi_bad_payload', 'Invalid JSON payload from remote API.');
            }

            return $data;
        }

        /**
         * Obtiene el GUID de la entrada remota para usarlo como identificador único.
         *
         * @param array $remote_post Entrada remota proporcionada por la API.
         * @return string GUID limpiado o cadena vacía si no está disponible.
         */
        private function extract_guid(array $remote_post): string {
            if (isset($remote_post['guid']['rendered'])) {
                return trim((string) $remote_post['guid']['rendered']);
            }

            if (isset($remote_post['guid'])) {
                return trim((string) $remote_post['guid']);
            }

            return '';
        }

        /**
         * Obtiene el título de la entrada remota, eliminando etiquetas HTML.
         *
         * @param array $remote_post Entrada remota proporcionada por la API.
         * @return string Título saneado o cadena vacía.
         */
        private function extract_title(array $remote_post): string {
            if (isset($remote_post['title']['rendered'])) {
                return wp_strip_all_tags((string) $remote_post['title']['rendered']);
            }

            if (isset($remote_post['title']) && is_string($remote_post['title'])) {
                return wp_strip_all_tags($remote_post['title']);
            }

            return '';
        }

        /**
         * Devuelve el identificador de una entrada previamente importada buscando por GUID o título.
         *
         * @param string $guid GUID remoto saneado.
         * @param string $title Título remoto saneado.
         * @return int ID de la entrada existente o 0 si no se encuentra.
         */
        private function find_existing_post(string $guid, string $title): int {
            if ($guid !== '') {
                $existing = get_posts([
                    'post_type' => 'post',
                    'post_status' => 'any',
                    'meta_key' => self::META_KEY,
                    'meta_value' => $guid,
                    'fields' => 'ids',
                    'numberposts' => 1,
                ]);

                if (!empty($existing)) {
                    return (int) $existing[0];
                }
            }

            if ($title !== '') {
                if (!function_exists('post_exists')) {
                    require_once ABSPATH . 'wp-admin/includes/post.php';
                }

                $post_id = (int) post_exists($title, '', '', 'post');
                if ($post_id > 0) {
                    return $post_id;
                }
            }

            return 0;
        }

        /**
         * Inserta la entrada local utilizando los datos remotos y las taxonomías preparadas.
         *
         * @param array $remote_post Datos originales de la entrada remota.
         * @param int $author_id Identificador del autor local asignado.
         * @param string $title Título remoto saneado.
         * @param array $tax_input Term IDs de categorías y etiquetas preparados.
         * @return int|WP_Error ID de la nueva entrada o error si falla la inserción.
         */
        private function insert_post(array $remote_post, int $author_id, string $title, array $tax_input) {
            $title_to_use = $title !== '' ? $title : 'Remote Post';
            $content = isset($remote_post['content']['rendered']) ? $remote_post['content']['rendered'] : '';
            $excerpt = isset($remote_post['excerpt']['rendered']) ? wp_strip_all_tags($remote_post['excerpt']['rendered']) : '';
            $slug = isset($remote_post['slug']) ? sanitize_title($remote_post['slug']) : sanitize_title($title_to_use);
            $status = isset($remote_post['status']) ? sanitize_key($remote_post['status']) : 'draft';
            $allowed_status = ['publish', 'draft', 'pending', 'future'];
            if (!in_array($status, $allowed_status, true)) {
                $status = 'draft';
            }

            $postarr = [
                'post_title' => $title_to_use,
                'post_content' => $content,
                'post_excerpt' => $excerpt,
                'post_name' => $slug,
                'post_status' => $status,
                'post_type' => 'post',
                'post_author' => $author_id,
            ];

            if (!empty($tax_input['category'])) {
                $postarr['post_category'] = array_values(array_unique(array_map('intval', $tax_input['category'])));
            }

            if (!empty($tax_input['post_tag'])) {
                $postarr['tags_input'] = array_values(array_unique(array_map('sanitize_text_field', $this->map_terms_to_names($tax_input['post_tag']))));
            }

            if (!empty($remote_post['date_gmt'])) {
                $timestamp_gmt = strtotime($remote_post['date_gmt']);
                if ($timestamp_gmt) {
                    $postarr['post_date_gmt'] = gmdate('Y-m-d H:i:s', $timestamp_gmt);
                    $postarr['post_date'] = get_date_from_gmt($postarr['post_date_gmt']);
                }
            } elseif (!empty($remote_post['date'])) {
                $timestamp = strtotime($remote_post['date']);
                if ($timestamp) {
                    $postarr['post_date'] = gmdate('Y-m-d H:i:s', $timestamp);
                }
            }

            return wp_insert_post($postarr, true);
        }

        /**
         * Prepara las taxonomías remotas para su asignación local creando términos cuando falten.
         *
         * @param array $remote_post Entrada remota con información embebida.
         * @return array Listado de IDs de categorías y etiquetas.
         */
        private function prepare_terms(array $remote_post): array {
            $result = [
                'category' => [],
                'post_tag' => [],
            ];

            if (!isset($remote_post['_embedded']['wp:term']) || !is_array($remote_post['_embedded']['wp:term'])) {
                return $result;
            }

            foreach ($remote_post['_embedded']['wp:term'] as $term_group) {
                if (!is_array($term_group)) {
                    continue;
                }

                foreach ($term_group as $term_data) {
                    if (empty($term_data['taxonomy'])) {
                        continue;
                    }

                    $taxonomy = sanitize_key($term_data['taxonomy']);
                    if (!array_key_exists($taxonomy, $result)) {
                        continue;
                    }

                    $term_name = isset($term_data['name']) ? sanitize_text_field($term_data['name']) : '';
                    if ($term_name === '') {
                        continue;
                    }

                    $slug = isset($term_data['slug']) ? sanitize_title($term_data['slug']) : sanitize_title($term_name);
                    $term = get_term_by('slug', $slug, $taxonomy);
                    if (!$term) {
                        $term = wp_insert_term($term_name, $taxonomy, ['slug' => $slug]);
                        if (is_wp_error($term)) {
                            continue;
                        }
                        $term_id = (int) $term['term_id'];
                    } else {
                        $term_id = (int) $term->term_id;
                    }

                    $result[$taxonomy][] = $term_id;
                }
            }

            return $result;
        }

        /**
         * Convierte IDs de términos de etiquetas en sus nombres sanitizados para usar con tags_input.
         *
         * @param array $term_ids Identificadores numéricos de términos tag.
         * @return array Lista de nombres de etiquetas.
         */
        private function map_terms_to_names(array $term_ids): array {
            $names = [];
            foreach ($term_ids as $term_id) {
                $term = get_term((int) $term_id);
                if ($term && !is_wp_error($term) && !empty($term->name)) {
                    $names[] = $term->name;
                }
            }

            return $names;
        }

        /**
         * Sincroniza categorías y etiquetas de una entrada local con la información remota preparada.
         *
         * @param int $post_id Identificador de la entrada local a actualizar.
         * @param array $tax_input Datos de taxonomías devueltos por prepare_terms.
         */
        private function sync_terms_with_post(int $post_id, array $tax_input): void {
            $categories = isset($tax_input['category']) ? array_values(array_unique(array_map('intval', $tax_input['category']))) : [];
            $tags = isset($tax_input['post_tag']) ? array_values(array_unique(array_map('intval', $tax_input['post_tag']))) : [];

            wp_set_post_terms($post_id, $categories, 'category', false);
            wp_set_post_terms($post_id, $tags, 'post_tag', false);
        }

        /**
         * Descarga y asigna la imagen destacada a la entrada importada.
         *
         * @param int $post_id Identificador de la entrada recién creada.
         * @param array $remote_post Datos remotos con información de medios.
         */
        private function handle_featured_media(int $post_id, array $remote_post): void {
            $image_url = $this->get_featured_media_url($remote_post);
            if ($image_url === '') {
                $this->log_message(sprintf('AutoBlogAPI: sin imagen destacada para el GUID %s', $this->extract_guid($remote_post)));
                return;
            }

            $attachment_id = media_sideload_image($image_url, $post_id, null, 'id');
            if (is_wp_error($attachment_id)) {
                $this->log_message(sprintf('AutoBlogAPI: error al descargar imagen %s -> %s', $image_url, $attachment_id->get_error_message()));
                return;
            }

            set_post_thumbnail($post_id, (int) $attachment_id);
        }

        /**
         * Recupera la URL de la imagen destacada usando datos embebidos o una consulta adicional.
         *
         * @param array $remote_post Entrada remota proporcionada por la API.
         * @return string URL de la imagen destacada o cadena vacía si no existe.
         */
        private function get_featured_media_url(array $remote_post): string {
            if (!empty($remote_post['_embedded']['wp:featuredmedia'][0]['source_url'])) {
                return esc_url_raw((string) $remote_post['_embedded']['wp:featuredmedia'][0]['source_url']);
            }

            if (!empty($remote_post['_links']['wp:featuredmedia'][0]['href'])) {
                $href = esc_url_raw($remote_post['_links']['wp:featuredmedia'][0]['href']);
                if ($href !== '') {
                    $media_response = wp_remote_get(add_query_arg('_fields', 'source_url', $href), ['timeout' => 20]);
                    if (!is_wp_error($media_response)) {
                        $code = wp_remote_retrieve_response_code($media_response);
                        if ((int) $code === 200) {
                            $body = wp_remote_retrieve_body($media_response);
                            $data = json_decode($body, true);
                            if (is_array($data) && !empty($data['source_url'])) {
                                return esc_url_raw((string) $data['source_url']);
                            }
                        }
                    }
                }
            }

            return '';
        }

        /**
         * Obtiene el identificador del primer usuario administrador disponible.
         *
         * @return int ID del administrador o 1 como último recurso.
         */
        private function get_admin_user_id(): int {
            $admins = get_users([
                'role' => 'administrator',
                'number' => 1,
                'orderby' => 'ID',
                'order' => 'ASC',
                'fields' => ['ID'],
            ]);

            if (!empty($admins)) {
                return (int) $admins[0]->ID;
            }

            $current = get_current_user_id();
            return $current ? (int) $current : 1;
        }

        /**
         * Registra en el log los detalles de la entrada importada.
         *
         * @param int $post_id Identificador de la entrada local.
         * @param array $remote_post Datos remotos utilizados para la importación.
         */
        private function log_post_details(int $post_id, array $remote_post): void {
            $title = get_the_title($post_id);
            $categories = wp_get_post_terms($post_id, 'category', ['fields' => 'names']);
            $tags = wp_get_post_terms($post_id, 'post_tag', ['fields' => 'names']);
            $images = [];
            $thumbnail_id = get_post_thumbnail_id($post_id);
            if ($thumbnail_id) {
                $url = wp_get_attachment_url($thumbnail_id);
                if ($url) {
                    $images[] = $url;
                }
            }

            if (empty($images) && !empty($remote_post['_embedded']['wp:featuredmedia'])) {
                foreach ($remote_post['_embedded']['wp:featuredmedia'] as $media_item) {
                    if (!empty($media_item['source_url'])) {
                        $images[] = (string) $media_item['source_url'];
                    }
                }
            }

            $payload = sprintf(
                'AutoBlogAPI rastreo -> titulo: "%s" | categorias: %s | tags: %s | imagenes: %s',
                $title,
                empty($categories) ? '[]' : wp_json_encode(array_values($categories)),
                empty($tags) ? '[]' : wp_json_encode(array_values($tags)),
                empty($images) ? '[]' : wp_json_encode(array_values($images))
            );

            $this->log_message($payload);
        }

        /**
         * Envía un mensaje al registro de errores del servidor.
         *
         * @param string $message Texto a registrar.
         */
        private function log_message(string $message): void {
            error_log($message);
        }
    }
}

register_activation_hook(__FILE__, ['Auto_Blog_API', 'activate']);
register_deactivation_hook(__FILE__, ['Auto_Blog_API', 'deactivate']);
Auto_Blog_API::get_instance();

/*
Documentación:
- Defina AUTO_BLOG_API_URL con la ruta completa hacia wp-json/wp/v2/posts del sitio origen.
- El plugin programa una importación cada 15 minutos y expone /wp-json/autoblogapi/v1/import para ejecuciones manuales.
- Las entradas importadas guardan el GUID original en el meta _autoblogapi_guid para evitar duplicados y una bandera adicional AUTO_BLOG_API.
- Categorías, etiquetas e imagen destacada se crean o asignan según la información remota y el autor asignado siempre es un administrador.
*/
