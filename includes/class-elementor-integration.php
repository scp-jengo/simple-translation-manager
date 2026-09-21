<?php
/**
 * Elementor Integration
 *
 * Makes Elementor page/widget content translatable without touching
 * Elementor's own `_elementor_data` post meta:
 *
 * - The page author's original Elementor content (whatever language it was
 *   built in) stays exactly as Elementor wrote it — we never intercept or
 *   rewrite Elementor's native save.
 * - Translations are captured separately, per language, as a flat map of
 *   `{ elementId: { settingKey: translatedValue } }` and stored in STM's
 *   existing `stm_post_translations` table (field_name `_elementor_data`),
 *   reusing the same DB table/cache layer as post title/content translations.
 * - On the frontend, the stored translation map is recursively overlaid
 *   onto the live Elementor element tree for the visitor's language.
 * - Works for regular posts/pages and for Elementor Library templates /
 *   global widgets alike, since everything is keyed by post ID rather than
 *   post type — a template's `post_id` is translated exactly like a page's.
 *
 * Activates automatically when Elementor is loaded; no configuration needed.
 *
 * @package SimpleTranslationManager
 */

namespace STM;

class ElementorIntegration {

    /**
     * Same meta key Elementor itself uses for `_elementor_data`, reused here
     * as the `field_name` in `stm_post_translations` so translated Elementor
     * data lives alongside other post-field translations.
     */
    const FIELD_NAME = '_elementor_data';

    /**
     * Elementor widget setting keys that are always treated as translatable
     * content, regardless of widget type.
     */
    const CONTENT_KEYS = [
        'title', 'editor', 'text', 'description', 'caption', 'content', 'html',
        'testimonial_content', 'testimonial_name', 'testimonial_job',
        'placeholder', 'before_text', 'after_text', 'alert_title', 'alert_description',
    ];

    /**
     * Widget setting key suffixes treated as translatable content, covering
     * per-widget variants like `button_text`, `tab_title`, `accordion_content`.
     */
    const CONTENT_KEY_SUFFIXES = [
        '_text', '_title', '_content', '_label', '_description', '_caption',
    ];

    public static function init() {
        if ( ! self::is_elementor_active() ) {
            return;
        }

        add_filter( 'elementor/frontend/builder_content_data', [ __CLASS__, 'filter_builder_content_data' ], 10, 2 );
        add_action( 'elementor/editor/before_enqueue_scripts', [ __CLASS__, 'enqueue_editor_assets' ] );
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    /**
     * Detect whether Elementor is active on this site.
     */
    public static function is_elementor_active(): bool {
        return did_action( 'elementor/loaded' ) || defined( 'ELEMENTOR_VERSION' );
    }

    // -------------------------------------------------------------------------
    // Frontend rendering
    // -------------------------------------------------------------------------

    /**
     * Filters the parsed Elementor element tree right before render, overlaying
     * this visitor's language translations. Untranslated fields (and anything
     * that isn't plain translated text — layout, styling, dynamic-tag bindings)
     * fall through unchanged, so a partial translation never breaks the page.
     */
    public static function filter_builder_content_data( $data, $post_id ) {
        if ( ! is_array( $data ) || empty( $data ) ) {
            return $data;
        }

        $lang = Frontend::get_current_language();
        if ( $lang === Settings::get_default_language() ) {
            return $data;
        }

        $translations = self::get_language_data( $post_id, $lang );
        if ( empty( $translations ) ) {
            return $data;
        }

        return self::merge_translations( $data, $translations );
    }

    /**
     * Recursively overlay a flat `{ elementId: { settingKey: value } }`
     * translation map onto an Elementor element tree.
     *
     * Only string, non-empty translated values are applied — this
     * automatically skips Elementor's dynamic-tag bindings (stored as
     * nested arrays under `__dynamic__`), so dynamic content widgets keep
     * resolving their live source instead of being frozen to a translation.
     */
    public static function merge_translations( array $elements, array $translations ): array {
        foreach ( $elements as &$element ) {
            if ( isset( $element['id'], $translations[ $element['id'] ] ) && is_array( $translations[ $element['id'] ] ) ) {
                foreach ( $translations[ $element['id'] ] as $key => $value ) {
                    if ( is_string( $value ) && $value !== '' ) {
                        $element['settings'][ $key ] = $value;
                    }
                }
            }

            if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $element['elements'] = self::merge_translations( $element['elements'], $translations );
            }
        }
        unset( $element );

        return $elements;
    }

    // -------------------------------------------------------------------------
    // Translatable field extraction (drives the editor panel)
    // -------------------------------------------------------------------------

    /**
     * Recursively walk an Elementor element tree and return a flat list of
     * translatable text fields: `[{ id, widgetType, key, source }, ...]`.
     */
    public static function extract_translatable_fields( array $elements, array $fields = [] ): array {
        foreach ( $elements as $element ) {
            if ( ! empty( $element['settings'] ) && is_array( $element['settings'] ) && isset( $element['id'] ) ) {
                foreach ( $element['settings'] as $key => $value ) {
                    if ( ! is_string( $value ) || $value === '' || ! self::is_translatable_key( $key ) ) {
                        continue;
                    }

                    $fields[] = [
                        'id'         => $element['id'],
                        'widgetType' => $element['widgetType'] ?? ( $element['elType'] ?? '' ),
                        'key'        => $key,
                        'source'     => $value,
                    ];
                }
            }

            if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $fields = self::extract_translatable_fields( $element['elements'], $fields );
            }
        }

        return $fields;
    }

    private static function is_translatable_key( string $key ): bool {
        if ( in_array( $key, self::CONTENT_KEYS, true ) ) {
            return true;
        }

        foreach ( self::CONTENT_KEY_SUFFIXES as $suffix ) {
            if ( substr( $key, -strlen( $suffix ) ) === $suffix ) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Storage
    // -------------------------------------------------------------------------

    /**
     * Source (author-entered) Elementor elements for a post, read directly
     * from Elementor's own `_elementor_data` post meta. This is never
     * modified by this integration.
     */
    public static function get_source_data( int $post_id ): array {
        $raw = get_post_meta( $post_id, self::FIELD_NAME, true );
        if ( empty( $raw ) ) {
            return [];
        }

        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * Save the translated field map for a post/language.
     *
     * @param array $translations Flat `{ elementId: { settingKey: value } }` map.
     */
    public static function save_language_data( int $post_id, string $lang_code, array $translations ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'stm_post_translations';

        $json = wp_json_encode( $translations );

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, translation, source_hash FROM {$table} WHERE post_id = %d AND field_name = %s AND language_code = %s",
            $post_id,
            self::FIELD_NAME,
            $lang_code
        ) );

        // Stamp the source hash (task 3520) so a later edit to the Elementor
        // layout flags this row stale. Kept as-is when the editor panel re-posts
        // an unchanged map, same rule as the post editor metabox.
        $data = [
            'post_id'       => $post_id,
            'field_name'    => self::FIELD_NAME,
            'language_code' => $lang_code,
            'translation'   => $json,
            'source_hash'   => PostEditor::resolve_source_hash_for_save(
                $existing,
                $json,
                PostEditor::compute_source_hash( $post_id, self::FIELD_NAME )
            ),
        ];

        if ( $existing ) {
            $wpdb->update( $table, $data, [ 'id' => $existing->id ] );
        } else {
            $wpdb->insert( $table, $data );
        }

        Cache::invalidate_post( $post_id, self::FIELD_NAME );

        return true;
    }

    /**
     * Get the translated field map for a post/language.
     *
     * @return array Flat `{ elementId: { settingKey: value } }` map, or [] if none.
     */
    public static function get_language_data( int $post_id, string $lang_code ): array {
        $json = Cache::get_post_translation( $post_id, self::FIELD_NAME, $lang_code );
        if ( empty( $json ) ) {
            return [];
        }

        $decoded = json_decode( $json, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    public static function delete_language_data( int $post_id, string $lang_code ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'stm_post_translations';

        $deleted = $wpdb->delete( $table, [
            'post_id'       => $post_id,
            'field_name'    => self::FIELD_NAME,
            'language_code' => $lang_code,
        ] );

        Cache::invalidate_post( $post_id, self::FIELD_NAME );

        return $deleted !== false;
    }

    // -------------------------------------------------------------------------
    // Editor panel
    // -------------------------------------------------------------------------

    /**
     * Enqueue the STM language-switcher panel inside the Elementor editor.
     * Renders as a standalone floating panel (not injected into Elementor's
     * own internal DOM) so it stays stable across Elementor versions.
     */
    public static function enqueue_editor_assets() {
        $post_id = self::current_editor_post_id();
        if ( ! $post_id ) {
            return;
        }

        $languages = Database::get_languages();
        $default   = Settings::get_default_language();

        $panel_languages = [];
        foreach ( $languages as $lang ) {
            if ( $lang->code === $default ) {
                continue;
            }
            $panel_languages[] = [
                'code'       => $lang->code,
                'name'       => $lang->name,
                'flag_emoji' => $lang->flag_emoji,
            ];
        }

        if ( empty( $panel_languages ) ) {
            return;
        }

        wp_enqueue_style(
            'stm-elementor-editor',
            STM_PLUGIN_URL . 'assets/admin-elementor-editor.css',
            [],
            STM_VERSION
        );

        wp_enqueue_script(
            'stm-elementor-editor',
            STM_PLUGIN_URL . 'assets/admin-elementor-editor.js',
            [ 'jquery' ],
            STM_VERSION,
            true
        );

        wp_localize_script( 'stm-elementor-editor', 'stmElementorEditor', [
            'restUrl'   => esc_url_raw( rest_url( 'stm/v1/posts/' . $post_id . '/elementor/' ) ),
            'restNonce' => wp_create_nonce( 'wp_rest' ),
            'languages' => $panel_languages,
            'i18n'      => [
                'panelTitle' => __( 'Translations', 'simple-translation-manager' ),
                'noText'     => __( 'No translatable text found on this page yet.', 'simple-translation-manager' ),
                'saved'      => __( 'Translations saved', 'simple-translation-manager' ),
                'saveFailed' => __( 'Failed to save translations', 'simple-translation-manager' ),
            ],
        ] );
    }

    public static function current_editor_post_id(): int {
        return isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
    }

    // -------------------------------------------------------------------------
    // REST API
    // -------------------------------------------------------------------------

    public static function register_routes() {
        register_rest_route( 'stm/v1', '/posts/(?P<id>\d+)/elementor/(?P<lang>[a-zA-Z]{2,3})', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'rest_get' ],
            'permission_callback' => [ __CLASS__, 'check_edit_post_permission' ],
        ] );

        register_rest_route( 'stm/v1', '/posts/(?P<id>\d+)/elementor/(?P<lang>[a-zA-Z]{2,3})', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'rest_save' ],
            'permission_callback' => [ __CLASS__, 'check_edit_post_permission' ],
        ] );

        register_rest_route( 'stm/v1', '/posts/(?P<id>\d+)/elementor/(?P<lang>[a-zA-Z]{2,3})', [
            'methods'             => 'DELETE',
            'callback'            => [ __CLASS__, 'rest_delete' ],
            'permission_callback' => [ __CLASS__, 'check_edit_post_permission' ],
        ] );
    }

    public static function check_edit_post_permission( $request ): bool {
        $post_id = intval( $request['id'] );
        return current_user_can( 'edit_post', $post_id );
    }

    /**
     * GET /posts/{id}/elementor/{lang} — translatable fields + saved translations.
     */
    public static function rest_get( $request ) {
        $post_id = intval( $request['id'] );
        $lang    = sanitize_text_field( $request['lang'] );

        if ( ! get_post( $post_id ) ) {
            return new \WP_Error( 'not_found', 'Post not found', [ 'status' => 404 ] );
        }
        if ( ! Security::validate_language_code( $lang ) ) {
            return new \WP_Error( 'invalid_language', 'Invalid language code', [ 'status' => 400 ] );
        }

        $source = self::get_source_data( $post_id );

        return rest_ensure_response( [
            'fields'       => self::extract_translatable_fields( $source ),
            'translations' => self::get_language_data( $post_id, $lang ),
        ] );
    }

    /**
     * POST /posts/{id}/elementor/{lang} — save translated field map.
     */
    public static function rest_save( $request ) {
        $post_id      = intval( $request['id'] );
        $lang         = sanitize_text_field( $request['lang'] );
        $translations = $request->get_param( 'translations' );

        if ( ! get_post( $post_id ) ) {
            return new \WP_Error( 'not_found', 'Post not found', [ 'status' => 404 ] );
        }
        if ( ! Security::validate_language_code( $lang ) ) {
            return new \WP_Error( 'invalid_language', 'Invalid language code', [ 'status' => 400 ] );
        }
        if ( ! is_array( $translations ) ) {
            return new \WP_Error( 'invalid_translations', 'Translations must be an object of element id to field values', [ 'status' => 400 ] );
        }

        $clean = [];
        foreach ( $translations as $element_id => $fields ) {
            if ( ! is_array( $fields ) ) {
                continue;
            }
            $element_id = sanitize_text_field( (string) $element_id );
            foreach ( $fields as $key => $value ) {
                if ( is_string( $value ) ) {
                    $clean[ $element_id ][ sanitize_text_field( (string) $key ) ] = Security::sanitize_translation( $value );
                }
            }
        }

        self::save_language_data( $post_id, $lang, $clean );

        return rest_ensure_response( [ 'success' => true ] );
    }

    /**
     * DELETE /posts/{id}/elementor/{lang} — remove a language's translations.
     */
    public static function rest_delete( $request ) {
        $post_id = intval( $request['id'] );
        $lang    = sanitize_text_field( $request['lang'] );

        if ( ! Security::validate_language_code( $lang ) ) {
            return new \WP_Error( 'invalid_language', 'Invalid language code', [ 'status' => 400 ] );
        }

        self::delete_language_data( $post_id, $lang );

        return rest_ensure_response( [ 'success' => true ] );
    }
}
