<?php
/**
 * Post Editor Integration
 *
 * Adds translation meta box to post/page editor
 * Handles saving translations for post content
 *
 * @package SimpleTranslationManager
 */

namespace STM;

class PostEditor {

    /** Pre-loaded translation data for the current admin post list page (eliminates N+1) */
    private static $list_cache = [];

    /**
     * stm_post_translations field_name values that alias a native WP_Post
     * property rather than a postmeta key — the metabox writes the
     * post_* names, while the REST API / dashboard quick-save / CLI import
     * paths use the short names (see apply_legacy_field_aliases() above).
     * Both must resolve to the same live source value for hashing, or a
     * translation saved through one path would be flagged stale purely
     * because it was read back through the other.
     */
    const NATIVE_FIELD_ALIASES = [
        'post_title'   => 'post_title',
        'post_content' => 'post_content',
        'post_excerpt' => 'post_excerpt',
        'post_name'    => 'post_name',
        'title'        => 'post_title',
        'content'      => 'post_content',
        'excerpt'      => 'post_excerpt',
    ];

    /**
     * The current live source text for a stm_post_translations field —
     * same value a translation of $field_name is translating FROM right
     * now. Native post fields (or their short-name aliases) are read off
     * $post directly; anything else (a custom field, or Elementor's
     * `_elementor_data`) falls back to postmeta, keyed by the raw
     * field_name.
     */
    public static function get_source_field_value_from_post($post, $field_name) {
        if (isset(self::NATIVE_FIELD_ALIASES[$field_name])) {
            $prop = self::NATIVE_FIELD_ALIASES[$field_name];
            return isset($post->$prop) ? (string) $post->$prop : '';
        }

        $post_id = isset($post->ID) ? (int) $post->ID : 0;
        if (!$post_id) {
            return '';
        }

        $meta = get_post_meta($post_id, $field_name, true);
        return is_string($meta) ? $meta : '';
    }

    /**
     * Same as get_source_field_value_from_post() but for callers that only
     * have a post ID (the REST API, CLI, dashboard reads) — loads the post
     * once and delegates.
     */
    public static function get_source_field_value($post_id, $field_name) {
        if (isset(self::NATIVE_FIELD_ALIASES[$field_name])) {
            $post = get_post($post_id);
            return self::get_source_field_value_from_post($post ?: (object) [], $field_name);
        }

        $meta = get_post_meta($post_id, $field_name, true);
        return is_string($meta) ? $meta : '';
    }

    /**
     * Hash of the CURRENT live source value for a post field — the same
     * md5() a translation's stored source_hash is compared against to
     * detect staleness (task 3520).
     */
    public static function compute_source_hash($post_id, $field_name) {
        return md5(self::get_source_field_value($post_id, $field_name));
    }

    /** Same as compute_source_hash() when the $post object is already at hand. */
    public static function compute_source_hash_from_post($post, $field_name) {
        return md5(self::get_source_field_value_from_post($post, $field_name));
    }

    /**
     * The source_hash to store when a form re-posts a translation (task 3520).
     *
     * The metabox renders every existing translation as a PREFILLED input inside
     * the normal post edit form, so each post Update re-posts every untouched
     * translation. Re-stamping the hash from the (possibly just edited) source on
     * those re-posts would mark a stale translation fresh again, so the stored
     * hash is kept whenever the row already exists with an identical translation
     * text and a recorded hash. It is only (re)stamped when the row is new, the
     * text was actually changed, or the row was never hashed.
     *
     * @param object|null $existing   Existing row (translation, source_hash) or null
     * @param string      $translation Submitted translation text
     * @param string      $fresh_hash  md5 of the current live source value
     */
    public static function resolve_source_hash_for_save($existing, $translation, $fresh_hash) {
        // Browsers submit textarea newlines as CRLF while API/CLI/AI-written rows
        // hold bare LF, so line endings alone must not count as a text change.
        $normalize = static function ($text) {
            return str_replace(["\r\n", "\r"], "\n", (string) $text);
        };

        if ($existing && !empty($existing->source_hash) && $normalize($existing->translation) === $normalize($translation)) {
            return $existing->source_hash;
        }
        return $fresh_hash;
    }

    /**
     * Sanitize a submitted translation by field type (post form save path).
     * Shared by the save loop and by the keep-hash comparison, which runs the
     * STORED text through the same sanitizer so a row written raw by the
     * REST/CLI path (e.g. a bare "&") is not mistaken for an edit when the
     * metabox re-posts it and kses normalizes it.
     */
    public static function sanitize_translation_value($field_name, $value) {
        if ($field_name === 'post_content') {
            return wp_kses_post($value);
        }
        if ($field_name === 'post_name') {
            return sanitize_title($value);
        }
        return sanitize_text_field($value);
    }

    /**
     * Is a stored translation stale? True when the source field's content
     * has changed since $source_hash was recorded. A translation that was
     * never hashed (legacy row, source_hash NULL/empty — normally cleared
     * by Database::maybe_upgrade()'s one-time backfill) is treated as
     * unknown rather than stale, since there is nothing to compare against.
     */
    public static function is_translation_stale($post_id, $field_name, $source_hash) {
        if (empty($source_hash)) {
            return false;
        }
        return $source_hash !== self::compute_source_hash($post_id, $field_name);
    }

    /**
     * Initialize post editor hooks
     */
    public static function init() {
        if (!is_admin()) {
            return;
        }

        // Add meta box to post/page editor
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_box']);

        // Save post translations
        add_action('save_post', [__CLASS__, 'save_translations'], 10, 2);

        // Add language column to post list
        add_filter('manage_posts_columns', [__CLASS__, 'add_language_column']);
        add_filter('manage_pages_columns', [__CLASS__, 'add_language_column']);
        add_action('manage_posts_custom_column', [__CLASS__, 'display_language_column'], 10, 2);
        add_action('manage_pages_custom_column', [__CLASS__, 'display_language_column'], 10, 2);

        // Batch-load translation data for the post list to avoid N+1 queries
        add_filter('the_posts', [__CLASS__, 'preload_list_translations'], 10, 2);

        // Enqueue assets
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        // Gutenberg sidebar panel (block editor only)
        add_action('enqueue_block_editor_assets', [__CLASS__, 'enqueue_gutenberg_assets']);
    }

    /**
     * Add translation meta box
     */
    public static function add_meta_box() {
        $post_types = get_post_types(['public' => true], 'names');

        foreach ($post_types as $post_type) {
            add_meta_box(
                'stm_translations',
                'Translations',
                [__CLASS__, 'render_meta_box'],
                $post_type,
                'normal',
                'high'
            );
        }
    }

    /**
     * Render translation meta box
     */
    public static function render_meta_box($post) {
        wp_nonce_field('stm_save_translations', 'stm_translations_nonce');

        // All languages, active or not — an admin can prepare a new
        // language's translations before it goes live on the front end.
        $languages = Database::get_all_languages();
        $current_lang = self::get_post_language($post->ID);
        $translation_group = self::get_translation_group($post->ID);

        // Get existing translations
        $translations = [];
        foreach ($languages as $lang) {
            if ($lang->code === $current_lang) {
                continue; // Skip current language
            }

            $translations[$lang->code] = self::apply_legacy_field_aliases(
                self::get_post_translation($post->ID, $lang->code)
            );
        }

        include STM_PLUGIN_DIR . 'templates/meta-box-translations.php';
    }

    /**
     * Some external integrations (e.g. Bugatti Insights' sync API) write
     * translation rows directly via STM\API::save_post_translations() using
     * short field names ('title', 'content') instead of the post_title/
     * post_content keys this metabox's own save handler uses. The rows are
     * real and correct — only the metabox display was blind to them. Fill
     * the post_title/post_content keys from their short-name equivalents
     * when the metabox's own keys are absent, so already-stored translations
     * show up instead of appearing blank.
     */
    private static function apply_legacy_field_aliases($translation) {
        if (empty($translation['post_title']) && !empty($translation['title'])) {
            $translation['post_title'] = $translation['title'];
        }
        if (empty($translation['post_content']) && !empty($translation['content'])) {
            $translation['post_content'] = $translation['content'];
        }

        return $translation;
    }

    /**
     * Enqueue admin assets
     */
    public static function enqueue_assets($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'])) {
            return;
        }

        wp_enqueue_style('dashicons');

        wp_enqueue_style(
            'stm-post-editor',
            STM_PLUGIN_URL . 'assets/admin-post-editor.css',
            ['dashicons'],
            STM_VERSION
        );

        wp_enqueue_script(
            'stm-post-editor',
            STM_PLUGIN_URL . 'assets/admin-post-editor.js',
            ['jquery', 'wp-i18n', 'wp-editor'],
            STM_VERSION,
            true
        );

        $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
        $current_lang = $post_id ? self::get_post_language($post_id) : Settings::get_default_language();

        // A brand-new post already has a real (auto-draft) ID by the time this
        // screen renders, it's just not in $_GET yet — use it so the preview
        // cycler works from the very first edit, not only after the first save.
        global $post;
        $preview_post_id = $post_id ?: ($post ? $post->ID : 0);

        wp_localize_script('stm-post-editor', 'stmPostEditor', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('stm_post_editor_nonce'),
            'restUrl'      => esc_url_raw(rest_url('stm/v1/translate/auto')),
            'postsApiRoot' => esc_url_raw(rest_url('stm/v1/posts/')),
            'restNonce'    => wp_create_nonce('wp_rest'),
            // Sent with every auto-translate request so the server-side
            // translation-memory lookup can be scoped to THIS post — without
            // it, a near-duplicate-template post elsewhere on the site could
            // have its stored translation silently reused here (869enmrpz).
            // 0 on post-new.php (no post ID exists yet), which is fine: an
            // unsaved post cannot yet be the source of a cross-post mix-up.
            'postId'       => $post_id,
            'sourceLang'   => $current_lang,
            'defaultLang'  => Settings::get_default_language(),
            'previewLanguages' => self::build_preview_languages($preview_post_id),
            'i18n' => [
                'translating'        => __('Translating…', 'simple-translation-manager'),
                'translated'         => __('Translation complete', 'simple-translation-manager'),
                'translateFailed'    => __('Auto-translate failed', 'simple-translation-manager'),
                'nothingToTranslate' => __('No source content to translate — fill in the post first.', 'simple-translation-manager'),
                'overwriteConfirm'   => __('This tab already has translations. Overwrite them with auto-translated content?', 'simple-translation-manager'),
                'saved'              => __('Translations saved', 'simple-translation-manager'),
                'deleteConfirm'      => __('Delete this translation? This cannot be undone.', 'simple-translation-manager'),
                'deleted'            => __('Translation deleted', 'simple-translation-manager'),
                'deleteFailed'       => __('Failed to delete translation', 'simple-translation-manager'),
            ],
        ]);
    }

    /**
     * Build the per-language list used by the "Preview in language" cycler.
     *
     * Each entry carries a ready-to-open front-end preview URL — WordPress'
     * own get_preview_post_link() plus a `lang` query arg, so the same
     * Frontend::get_current_language() GET-param lookup that already drives
     * the live site renders that language's translated title/content.
     */
    public static function build_preview_languages($post_id) {
        $languages = Database::get_all_languages();
        $post = $post_id ? get_post($post_id) : null;

        $preview_languages = [];
        foreach ($languages as $lang) {
            $preview_languages[] = [
                'code'       => $lang->code,
                'name'       => $lang->name,
                'flag_emoji' => $lang->flag_emoji,
                'previewUrl' => $post ? get_preview_post_link($post, ['lang' => $lang->code]) : '',
            ];
        }

        return $preview_languages;
    }

    /**
     * Enqueue the Gutenberg PluginDocumentSettingPanel script
     */
    public static function enqueue_gutenberg_assets() {
        $screen = get_current_screen();
        if (!$screen || !post_type_supports($screen->post_type, 'editor')) {
            return;
        }

        $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
        $current_lang = $post_id ? self::get_post_language($post_id) : Settings::get_default_language();
        $languages = Database::get_all_languages();

        global $post;
        $preview_post_id = $post_id ?: ($post ? $post->ID : 0);
        $preview_post = $preview_post_id ? get_post($preview_post_id) : null;

        $panel_languages = [];
        foreach ($languages as $lang) {
            if ($lang->code === $current_lang) {
                continue;
            }

            $t = $post_id ? self::get_post_translation($post_id, $lang->code) : [];
            $has_title = !empty($t['post_title']);
            $has_body  = !empty($t['post_content']);

            if ($has_title && $has_body) {
                $status = 'complete';
            } elseif ($has_title || $has_body) {
                $status = 'partial';
            } else {
                $status = 'empty';
            }

            $panel_languages[] = [
                'code'       => $lang->code,
                'name'       => $lang->name,
                'flag_emoji' => $lang->flag_emoji,
                'status'     => $status,
                'previewUrl' => $preview_post ? get_preview_post_link($preview_post, ['lang' => $lang->code]) : '',
            ];
        }

        wp_enqueue_script(
            'stm-post-editor-gutenberg',
            STM_PLUGIN_URL . 'assets/admin-post-editor-gutenberg.js',
            ['wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-element', 'wp-components', 'jquery'],
            STM_VERSION,
            true
        );

        wp_localize_script('stm-post-editor-gutenberg', 'stmGutenberg', [
            'postId'    => $post_id,
            'languages' => $panel_languages,
            'i18n' => [
                'title'    => __('Translations', 'simple-translation-manager'),
                'complete' => __('Complete', 'simple-translation-manager'),
                'partial'  => __('Partial', 'simple-translation-manager'),
                'empty'    => __('Not translated', 'simple-translation-manager'),
                'none'     => __('No other languages configured.', 'simple-translation-manager'),
                'edit'     => __('Edit', 'simple-translation-manager'),
                'preview'  => __('Preview', 'simple-translation-manager'),
            ],
        ]);
    }

    /**
     * Save post translations
     */
    public static function save_translations($post_id, $post) {
        // Security checks
        if (!isset($_POST['stm_translations_nonce']) || !wp_verify_nonce(wp_unslash($_POST['stm_translations_nonce']), 'stm_save_translations')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Get or create translation group
        $translation_group = self::get_translation_group($post_id);
        if (!$translation_group) {
            $translation_group = wp_generate_uuid4();
        }

        // Save post language
        $post_language = isset($_POST['stm_post_language']) ? sanitize_text_field(wp_unslash($_POST['stm_post_language'])) : Settings::get_default_language();
        self::set_post_language($post_id, $post_language, $translation_group);

        // Save translations
        if (isset($_POST['stm_translations']) && is_array($_POST['stm_translations'])) {
            global $wpdb;
            $table = $wpdb->prefix . 'stm_post_translations';

            $translations = wp_unslash($_POST['stm_translations']);

            foreach ($translations as $lang_code => $fields) {
                if (!Security::validate_language_code($lang_code)) {
                    continue;
                }

                // Save each field
                foreach ($fields as $field_name => $value) {
                    $field_name = sanitize_text_field(wp_unslash($field_name));
                    $value = wp_unslash($value);

                    // Sanitize based on field type
                    $value = self::sanitize_translation_value($field_name, $value);

                    $existing = $wpdb->get_row($wpdb->prepare(
                        "SELECT id, translation, source_hash FROM {$table} WHERE post_id = %d AND field_name = %s AND language_code = %s",
                        $post_id,
                        $field_name,
                        $lang_code
                    ));

                    // Empty value — delete existing row if present
                    if (empty($value)) {
                        if ($existing) {
                            $wpdb->delete($table, ['id' => $existing->id]);
                        }
                        continue;
                    }

                    $data = [
                        'post_id' => $post_id,
                        'field_name' => $field_name,
                        'language_code' => $lang_code,
                        'translation' => $value,
                        'source_hash' => self::resolve_source_hash_for_save(
                            $existing ? (object) [
                                'translation' => self::sanitize_translation_value($field_name, $existing->translation),
                                'source_hash' => $existing->source_hash,
                            ] : null,
                            $value,
                            self::compute_source_hash_from_post($post, $field_name)
                        ),
                    ];

                    if ($existing) {
                        $wpdb->update($table, $data, ['id' => $existing->id]);
                    } else {
                        $wpdb->insert($table, $data);
                    }
                }
            }

            // Invalidate cached translations for this post across all languages
            Cache::invalidate_post($post_id);
        }
    }

    /**
     * Batch-load all translation statuses for the visible post list in one query.
     * Fires on the_posts filter so it runs once before any column callbacks.
     */
    public static function preload_list_translations($posts, $query) {
        if (!$query->is_main_query() || !is_admin()) {
            return $posts;
        }

        $post_ids = array_map(function($p) { return $p->ID; }, $posts);
        if (empty($post_ids)) {
            return $posts;
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, language_code, field_name, translation
                 FROM {$wpdb->prefix}stm_post_translations
                 WHERE post_id IN ($placeholders)
                   AND field_name IN ('post_title','post_content')",
                ...$post_ids
            ),
            ARRAY_A
        );

        foreach ($rows as $row) {
            self::$list_cache[$row['post_id']][$row['language_code']][$row['field_name']] = $row['translation'];
        }

        return $posts;
    }

    /**
     * Add language column to post list
     */
    public static function add_language_column($columns) {
        $new_columns = [];
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'title') {
                $new_columns['stm_language'] = 'Language';
                $new_columns['stm_translations'] = 'Translations';
            }
        }
        return $new_columns;
    }

    /**
     * Display language column content
     */
    public static function display_language_column($column, $post_id) {
        if ($column === 'stm_language') {
            $lang_code = self::get_post_language($post_id);
            $languages = Database::get_all_languages();

            foreach ($languages as $lang) {
                if ($lang->code === $lang_code) {
                    echo esc_html($lang->flag_emoji . ' ' . $lang->code);
                    return;
                }
            }

            echo esc_html(strtoupper($lang_code));
        }

        if ($column === 'stm_translations') {
            $translation_group = self::get_translation_group($post_id);
            if (!$translation_group) {
                echo '—';
                return;
            }

            $languages = Database::get_all_languages();
            $current_lang = self::get_post_language($post_id);
            $has_translations = false;

            foreach ($languages as $lang) {
                if ($lang->code === $current_lang) {
                    continue;
                }

                // Use preloaded data when available (avoids N+1)
                $has_title = isset(self::$list_cache[$post_id][$lang->code]['post_title'])
                    && self::$list_cache[$post_id][$lang->code]['post_title'] !== '';

                if (!$has_title && !isset(self::$list_cache[$post_id])) {
                    // Fallback for when preload did not fire (e.g., direct page load)
                    $t         = self::get_post_translation($post_id, $lang->code);
                    $has_title = !empty($t['post_title']);
                }

                if ($has_title) {
                    echo esc_html($lang->flag_emoji) . ' ';
                    $has_translations = true;
                }
            }

            if (!$has_translations) {
                echo '—';
            }
        }
    }

    /**
     * Get post language.
     *
     * A manually-registered STM association is authoritative. Otherwise,
     * defer to SEO God's per-post detected content language (task 2982)
     * before falling back to the site default — the normal case for a post
     * genuinely written in a non-default language but never manually
     * registered through STM's editor UI (task 3047).
     */
    public static function get_post_language($post_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'stm_post_associations';

        $lang = $wpdb->get_var($wpdb->prepare(
            "SELECT language_code FROM {$table} WHERE post_id = %d",
            $post_id
        ));

        if ($lang) {
            return $lang;
        }

        $detected = SeoGodIntegration::get_detected_content_language((int) $post_id);
        if ($detected !== '' && self::is_known_language($detected)) {
            return $detected;
        }

        return Settings::get_default_language();
    }

    /**
     * Whether $code is one of STM's own configured active languages —
     * guards against trusting a detected code SEO God returns that STM
     * doesn't actually manage (would otherwise silently drop the
     * default-language hreflang self-reference instead of just using it).
     */
    private static function is_known_language($code) {
        foreach (Database::get_languages() as $lang) {
            if ($lang->code === $code) {
                return true;
            }
        }
        return false;
    }

    /**
     * Set post language
     */
    public static function set_post_language($post_id, $language_code, $translation_group) {
        global $wpdb;
        $table = $wpdb->prefix . 'stm_post_associations';

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE post_id = %d",
            $post_id
        ));

        $data = [
            'post_id' => $post_id,
            'language_code' => $language_code,
            'translation_group' => $translation_group,
            'is_original' => 1,
        ];

        if ($existing) {
            $wpdb->update($table, $data, ['id' => $existing]);
        } else {
            $wpdb->insert($table, $data);
        }
    }

    /**
     * Get translation group
     */
    public static function get_translation_group($post_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'stm_post_associations';

        return $wpdb->get_var($wpdb->prepare(
            "SELECT translation_group FROM {$table} WHERE post_id = %d",
            $post_id
        ));
    }

    /**
     * Get post translation
     */
    public static function get_post_translation($post_id, $language_code) {
        global $wpdb;
        $table = $wpdb->prefix . 'stm_post_translations';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT field_name, translation FROM {$table} WHERE post_id = %d AND language_code = %s",
            $post_id,
            $language_code
        ), ARRAY_A);

        $translation = [];
        foreach ($results as $row) {
            $translation[$row['field_name']] = $row['translation'];
        }

        return $translation;
    }

    /**
     * Reverse-lookup: which post has $slug as its translated post_name for
     * $language_code? Used to resolve incoming /{lang}/.../{translated-slug}/
     * requests back to the real post — see
     * Frontend::resolve_translated_slug_request().
     *
     * @return int 0 when no translated slug matches.
     */
    public static function get_post_id_by_translated_slug($language_code, $slug) {
        global $wpdb;
        $table = $wpdb->prefix . 'stm_post_translations';

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$table} WHERE field_name = 'post_name' AND language_code = %s AND translation = %s LIMIT 1",
            $language_code,
            $slug
        ));
    }

    /**
     * Get posts in translation group
     */
    public static function get_translation_group_posts($translation_group) {
        global $wpdb;
        $table = $wpdb->prefix . 'stm_post_associations';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, language_code FROM {$table} WHERE translation_group = %s",
            $translation_group
        ), ARRAY_A);
    }
}
