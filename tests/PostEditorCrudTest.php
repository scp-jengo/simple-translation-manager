<?php
/**
 * PHPUnit tests: post/page editor translation CRUD (title, content,
 * excerpt, slug) — save (create/update), read, and delete, including the
 * new DELETE /stm/v1/posts/{id}/translations/{lang} route and its
 * edit_post permission check.
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\API;
use STM\PostEditor;
use STM\Tests\Fakes\FakeWpdb;

class PostEditorCrudTest extends TestCase {

    /** @var FakeWpdb */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = new FakeWpdb();
        $this->wpdb = $wpdb;

        $_POST = [];

        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('sanitize_textarea_field')->returnArg(1);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('sanitize_title')->alias(function ($v) {
            return strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', (string) $v), '-'));
        });
        Functions\when('wp_kses_post')->returnArg(1);
        Functions\when('wp_kses')->returnArg(1);
        Functions\when('current_time')->justReturn('2026-07-17 00:00:00');
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('wp_generate_uuid4')->justReturn('fixed-uuid');
        Functions\when('rest_ensure_response')->returnArg(1);
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('get_option')->justReturn(false);
        Functions\when('update_option')->justReturn(true);
        Functions\when('__')->returnArg(1);

        // stm_translations_nonce check inside PostEditor::save_translations()
        $_POST['stm_translations_nonce'] = 'valid-nonce';
        Functions\when('wp_verify_nonce')->justReturn(true);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function seedLanguages() {
        $this->wpdb->seed('wp_stm_languages', [
            'code' => 'en', 'name' => 'English', 'flag_emoji' => '🇬🇧', 'is_active' => 1, 'is_default' => 1, 'order_index' => 1,
        ]);
        $this->wpdb->seed('wp_stm_languages', [
            'code' => 'nl', 'name' => 'Dutch', 'flag_emoji' => '🇳🇱', 'is_active' => 1, 'is_default' => 0, 'order_index' => 2,
        ]);
    }

    /** Same as seedLanguages() plus a third, inactive language (German). */
    private function seedLanguagesWithInactive() {
        $this->seedLanguages();
        $this->wpdb->seed('wp_stm_languages', [
            'code' => 'de', 'name' => 'German', 'flag_emoji' => '🇩🇪', 'is_active' => 0, 'is_default' => 0, 'order_index' => 3,
        ]);
    }

    // -----------------------------------------------------------------
    // Create + read (PostEditor::save_translations / get_post_translation)
    // -----------------------------------------------------------------

    public function test_save_translations_creates_new_rows_for_each_field() {
        Functions\when('current_user_can')->justReturn(true);

        $_POST['stm_post_language'] = 'en';
        $_POST['stm_translations'] = [
            'nl' => [
                'post_title'   => 'Nederlandse titel',
                'post_name'    => 'nederlandse-titel',
                'post_excerpt' => 'Korte samenvatting',
                'post_content' => '<p>Inhoud</p>',
            ],
        ];

        PostEditor::save_translations(42, (object) ['ID' => 42]);

        $translation = PostEditor::get_post_translation(42, 'nl');

        $this->assertSame('Nederlandse titel', $translation['post_title']);
        $this->assertSame('nederlandse-titel', $translation['post_name']);
        $this->assertSame('Korte samenvatting', $translation['post_excerpt']);
        $this->assertSame('<p>Inhoud</p>', $translation['post_content']);
    }

    // -----------------------------------------------------------------
    // Stale-translation detection (task 3520)
    // -----------------------------------------------------------------

    public function test_save_translations_stores_a_source_hash_matching_the_current_source() {
        Functions\when('current_user_can')->justReturn(true);

        $post = (object) ['ID' => 42, 'post_title' => 'Original title'];

        $_POST['stm_post_language'] = 'en';
        $_POST['stm_translations'] = [
            'nl' => ['post_title' => 'Originele titel'],
        ];

        PostEditor::save_translations(42, $post);

        $rows = $this->wpdb->all('wp_stm_post_translations');
        $this->assertSame(md5('Original title'), $rows[0]['source_hash']);

        Functions\when('get_post')->justReturn($post);
        $this->assertFalse(
            PostEditor::is_translation_stale(42, 'post_title', $rows[0]['source_hash']),
            'A translation just saved against the current source must not be stale.'
        );
    }

    public function test_editing_the_source_field_flags_the_existing_translation_as_stale() {
        Functions\when('current_user_can')->justReturn(true);

        $post = (object) ['ID' => 42, 'post_title' => 'Original title'];

        $_POST['stm_post_language'] = 'en';
        $_POST['stm_translations'] = [
            'nl' => ['post_title' => 'Originele titel'],
        ];

        PostEditor::save_translations(42, $post);

        $rows = $this->wpdb->all('wp_stm_post_translations');
        $storedHash = $rows[0]['source_hash'];

        // The post title is edited after the translation was saved.
        Functions\when('get_post')->justReturn((object) ['ID' => 42, 'post_title' => 'Changed title']);

        $this->assertTrue(
            PostEditor::is_translation_stale(42, 'post_title', $storedHash),
            'Editing the source field must flip the existing translation to stale.'
        );
    }

    /**
     * The metabox renders every saved translation as a prefilled input, so a
     * post Update re-posts the untouched translations together with the edited
     * source. That re-post must NOT re-stamp the hash (regression, review of
     * task 3520): the translation has to stay stale until it is really redone.
     */
    public function test_resaving_the_prefilled_metabox_after_a_source_edit_keeps_the_translation_stale() {
        Functions\when('current_user_can')->justReturn(true);

        $_POST['stm_post_language'] = 'en';
        $_POST['stm_translations'] = ['nl' => ['post_title' => 'Originele titel']];

        // 1. Translate against the original source.
        PostEditor::save_translations(42, (object) ['ID' => 42, 'post_title' => 'Original title']);
        $storedHash = $this->wpdb->all('wp_stm_post_translations')[0]['source_hash'];

        // 2. Source edited; the Update re-posts the SAME prefilled translation.
        $edited = (object) ['ID' => 42, 'post_title' => 'Changed title'];
        PostEditor::save_translations(42, $edited);

        $rows = $this->wpdb->all('wp_stm_post_translations');
        $this->assertCount(1, $rows);
        $this->assertSame($storedHash, $rows[0]['source_hash'], 'An unchanged translation must keep its recorded hash.');

        Functions\when('get_post')->justReturn($edited);
        $this->assertTrue(
            PostEditor::is_translation_stale(42, 'post_title', $rows[0]['source_hash']),
            'Editing the source and re-saving the untouched metabox must leave the translation stale.'
        );
    }

    public function test_changing_the_translation_text_after_a_source_edit_clears_the_stale_flag() {
        Functions\when('current_user_can')->justReturn(true);

        $_POST['stm_post_language'] = 'en';
        $_POST['stm_translations'] = ['nl' => ['post_title' => 'Originele titel']];
        PostEditor::save_translations(42, (object) ['ID' => 42, 'post_title' => 'Original title']);

        // Source edited AND the translator updated the Dutch text in the same save.
        $edited = (object) ['ID' => 42, 'post_title' => 'Changed title'];
        $_POST['stm_translations'] = ['nl' => ['post_title' => 'Gewijzigde titel']];
        PostEditor::save_translations(42, $edited);

        $rows = $this->wpdb->all('wp_stm_post_translations');
        $this->assertSame(md5('Changed title'), $rows[0]['source_hash']);

        Functions\when('get_post')->justReturn($edited);
        $this->assertFalse(PostEditor::is_translation_stale(42, 'post_title', $rows[0]['source_hash']));
    }

    public function test_resaving_an_unchanged_translation_stamps_a_hash_when_the_row_was_never_hashed() {
        Functions\when('current_user_can')->justReturn(true);

        $this->wpdb->seed('wp_stm_post_translations', [
            'post_id' => 42, 'field_name' => 'post_title', 'language_code' => 'nl',
            'translation' => 'Titel', 'source_hash' => null,
        ]);

        $_POST['stm_post_language'] = 'en';
        $_POST['stm_translations'] = ['nl' => ['post_title' => 'Titel']];
        PostEditor::save_translations(42, (object) ['ID' => 42, 'post_title' => 'Title']);

        $this->assertSame(md5('Title'), $this->wpdb->all('wp_stm_post_translations')[0]['source_hash']);
    }

    public function test_is_translation_stale_treats_a_never_hashed_row_as_not_stale() {
        Functions\when('get_post')->justReturn((object) ['ID' => 42, 'post_title' => 'Anything']);

        $this->assertFalse(
            PostEditor::is_translation_stale(42, 'post_title', null),
            'A legacy row with no recorded hash has nothing to compare against, so it must not be reported stale.'
        );
    }

    public function test_get_source_field_value_resolves_short_field_name_aliases() {
        $post = (object) ['ID' => 42, 'post_title' => 'Title via alias', 'post_content' => 'Content via alias'];

        $this->assertSame('Title via alias', PostEditor::get_source_field_value_from_post($post, 'title'));
        $this->assertSame('Content via alias', PostEditor::get_source_field_value_from_post($post, 'content'));
        $this->assertSame('Title via alias', PostEditor::get_source_field_value_from_post($post, 'post_title'));
    }

    public function test_save_translations_updates_existing_row_instead_of_duplicating() {
        Functions\when('current_user_can')->justReturn(true);

        $this->wpdb->seed('wp_stm_post_translations', [
            'post_id' => 42, 'field_name' => 'post_title', 'language_code' => 'nl', 'translation' => 'Oude titel',
        ]);

        $_POST['stm_post_language'] = 'en';
        $_POST['stm_translations'] = [
            'nl' => ['post_title' => 'Nieuwe titel'],
        ];

        PostEditor::save_translations(42, (object) ['ID' => 42]);

        $rows = array_values(array_filter($this->wpdb->all('wp_stm_post_translations'), function ($r) {
            return $r['post_id'] === 42 && $r['field_name'] === 'post_title' && $r['language_code'] === 'nl';
        }));

        $this->assertCount(1, $rows, 'Saving an existing field must update it in place, not insert a duplicate row.');
        $this->assertSame('Nieuwe titel', $rows[0]['translation']);
    }

    public function test_save_translations_deletes_row_when_field_cleared() {
        Functions\when('current_user_can')->justReturn(true);

        $this->wpdb->seed('wp_stm_post_translations', [
            'post_id' => 42, 'field_name' => 'post_title', 'language_code' => 'nl', 'translation' => 'Titel',
        ]);

        $_POST['stm_post_language'] = 'en';
        $_POST['stm_translations'] = [
            'nl' => ['post_title' => ''],
        ];

        PostEditor::save_translations(42, (object) ['ID' => 42]);

        $translation = PostEditor::get_post_translation(42, 'nl');
        $this->assertArrayNotHasKey('post_title', $translation);
    }

    public function test_save_translations_noop_without_capability() {
        Functions\when('current_user_can')->justReturn(false);

        $_POST['stm_post_language'] = 'en';
        $_POST['stm_translations'] = [
            'nl' => ['post_title' => 'Should not be saved'],
        ];

        PostEditor::save_translations(42, (object) ['ID' => 42]);

        $this->assertSame([], $this->wpdb->all('wp_stm_post_translations'));
    }

    // -----------------------------------------------------------------
    // Delete — REST route (API::delete_post_translation) + permission check
    // -----------------------------------------------------------------

    public function test_delete_post_translation_removes_all_fields_for_that_language() {
        $this->wpdb->seed('wp_stm_post_translations', [
            'post_id' => 42, 'field_name' => 'post_title', 'language_code' => 'nl', 'translation' => 'Titel',
        ]);
        $this->wpdb->seed('wp_stm_post_translations', [
            'post_id' => 42, 'field_name' => 'post_content', 'language_code' => 'nl', 'translation' => 'Inhoud',
        ]);
        $this->wpdb->seed('wp_stm_post_translations', [
            'post_id' => 42, 'field_name' => 'post_title', 'language_code' => 'fr', 'translation' => 'Titre',
        ]);
        $this->seedLanguages();

        Functions\when('get_post')->justReturn((object) ['ID' => 42]);

        $request = new FakeRestRequest(['id' => '42', 'lang' => 'nl']);
        $response = API::delete_post_translation($request);

        $this->assertSame(['success' => true, 'deleted' => 2], $response);

        $remaining = $this->wpdb->all('wp_stm_post_translations');
        $this->assertCount(1, $remaining);
        $this->assertSame('fr', $remaining[0]['language_code']);
    }

    public function test_delete_post_translation_returns_404_for_missing_post() {
        Functions\when('get_post')->justReturn(null);

        $request = new FakeRestRequest(['id' => '999', 'lang' => 'nl']);
        $response = API::delete_post_translation($request);

        $this->assertInstanceOf(\WP_Error::class, $response);
        $this->assertSame('not_found', $response->get_error_code());
    }

    public function test_delete_post_translation_returns_400_for_invalid_language_code() {
        Functions\when('get_post')->justReturn((object) ['ID' => 42]);

        $request = new FakeRestRequest(['id' => '42', 'lang' => '123']);
        $response = API::delete_post_translation($request);

        $this->assertInstanceOf(\WP_Error::class, $response);
        $this->assertSame('invalid_language', $response->get_error_code());
    }

    public function test_check_edit_post_permission_reflects_edit_post_capability() {
        $request = new FakeRestRequest(['id' => '42']);

        Functions\when('current_user_can')->alias(function ($cap, $postId = null) {
            return $cap === 'edit_post' && $postId === 42;
        });
        $this->assertTrue(API::check_edit_post_permission($request));

        Functions\when('current_user_can')->justReturn(false);
        $this->assertFalse(API::check_edit_post_permission($request));
    }

    public function test_register_routes_registers_delete_post_translation_route() {
        $calls = [];
        Functions\when('add_action')->justReturn(true);
        Functions\when('register_rest_route')->alias(function ($namespace, $route, $args) use (&$calls) {
            $calls[] = [$namespace, $route, $args];
        });

        API::register_routes();

        $match = array_values(array_filter($calls, function ($c) {
            return $c[1] === '/posts/(?P<id>\d+)/translations/(?P<lang>[a-zA-Z]{2,3})';
        }));

        $this->assertCount(1, $match, 'The new DELETE route must be registered exactly once.');
        $this->assertSame('stm/v1', $match[0][0]);
        $this->assertSame('DELETE', $match[0][2]['methods']);
        $this->assertSame(['STM\\API', 'delete_post_translation'], $match[0][2]['callback']);
        $this->assertSame(['STM\\API', 'check_edit_post_permission'], $match[0][2]['permission_callback']);
    }

    // -----------------------------------------------------------------
    // Gutenberg panel data (PostEditor::enqueue_gutenberg_assets)
    // -----------------------------------------------------------------

    public function test_enqueue_gutenberg_assets_computes_per_language_status_and_localizes_panel_data() {
        $this->seedLanguages(); // current post language = en; nl is the "other" language
        $this->wpdb->seed('wp_stm_post_associations', [
            'post_id' => 42, 'language_code' => 'en', 'translation_group' => 'g1', 'is_original' => 1,
        ]);
        $this->wpdb->seed('wp_stm_post_translations', [
            'post_id' => 42, 'field_name' => 'post_title', 'language_code' => 'nl', 'translation' => 'Titel',
        ]);

        $_GET['post'] = '42';

        Functions\when('get_current_screen')->justReturn((object) ['post_type' => 'post']);
        Functions\when('post_type_supports')->justReturn(true);
        Functions\when('wp_enqueue_script')->justReturn(true);
        Functions\when('get_post')->justReturn((object) ['ID' => 42]);
        Functions\when('get_preview_post_link')->alias(function ($post, $args = []) {
            return 'https://example.test/?p=' . $post->ID . '&lang=' . ($args['lang'] ?? '');
        });

        $captured = null;
        Functions\when('wp_localize_script')->alias(function ($handle, $objName, $data) use (&$captured) {
            if ($objName === 'stmGutenberg') {
                $captured = $data;
            }
        });

        PostEditor::enqueue_gutenberg_assets();

        $this->assertNotNull($captured, 'stmGutenberg must be localized.');
        $this->assertSame(42, $captured['postId']);
        $this->assertCount(1, $captured['languages'], 'Only the non-current language should appear in the panel.');
        $this->assertSame('nl', $captured['languages'][0]['code']);
        $this->assertSame('partial', $captured['languages'][0]['status'], 'Title-only translation is partial, not complete.');
        $this->assertSame('https://example.test/?p=42&lang=nl', $captured['languages'][0]['previewUrl']);

        unset($_GET['post']);
    }

    public function test_enqueue_gutenberg_assets_includes_inactive_languages_in_the_panel() {
        $this->seedLanguagesWithInactive();
        $this->wpdb->seed('wp_stm_post_associations', [
            'post_id' => 42, 'language_code' => 'en', 'translation_group' => 'g1', 'is_original' => 1,
        ]);

        $_GET['post'] = '42';

        Functions\when('get_current_screen')->justReturn((object) ['post_type' => 'post']);
        Functions\when('post_type_supports')->justReturn(true);
        Functions\when('wp_enqueue_script')->justReturn(true);
        Functions\when('get_post')->justReturn((object) ['ID' => 42]);
        Functions\when('get_preview_post_link')->alias(function ($post, $args = []) {
            return 'https://example.test/?p=' . $post->ID . '&lang=' . ($args['lang'] ?? '');
        });

        $captured = null;
        Functions\when('wp_localize_script')->alias(function ($handle, $objName, $data) use (&$captured) {
            if ($objName === 'stmGutenberg') {
                $captured = $data;
            }
        });

        PostEditor::enqueue_gutenberg_assets();

        $codes = array_map(function ($l) { return $l['code']; }, $captured['languages']);
        $this->assertSame(['nl', 'de'], $codes, 'Both other languages, including the inactive one, must appear in the panel.');

        unset($_GET['post']);
    }

    public function test_enqueue_gutenberg_assets_skips_enqueue_when_post_type_unsupported() {
        Functions\when('get_current_screen')->justReturn((object) ['post_type' => 'attachment']);
        Functions\when('post_type_supports')->justReturn(false);

        $enqueued = false;
        Functions\when('wp_enqueue_script')->alias(function () use (&$enqueued) { $enqueued = true; });

        PostEditor::enqueue_gutenberg_assets();

        $this->assertFalse($enqueued);
    }

    // -----------------------------------------------------------------
    // Classic editor enqueue (PostEditor::enqueue_assets) — delete-related config
    // -----------------------------------------------------------------

    public function test_enqueue_assets_localizes_delete_related_config() {
        $this->seedLanguages();
        $_GET['post'] = '42';

        Functions\when('wp_enqueue_style')->justReturn(true);
        Functions\when('wp_enqueue_script')->justReturn(true);
        Functions\when('admin_url')->returnArg(1);
        Functions\when('wp_create_nonce')->justReturn('nonce-abc');
        Functions\when('esc_url_raw')->returnArg(1);
        Functions\when('rest_url')->alias(function ($path) { return 'https://example.test/wp-json/' . $path; });
        Functions\when('get_post')->justReturn((object) ['ID' => 42]);
        Functions\when('get_preview_post_link')->alias(function ($post, $args = []) {
            return 'https://example.test/?p=' . $post->ID . '&lang=' . ($args['lang'] ?? '');
        });

        $captured = null;
        Functions\when('wp_localize_script')->alias(function ($handle, $objName, $data) use (&$captured) {
            if ($objName === 'stmPostEditor') {
                $captured = $data;
            }
        });

        PostEditor::enqueue_assets('post.php');

        $this->assertNotNull($captured);
        $this->assertSame(42, $captured['postId']);
        $this->assertSame('https://example.test/wp-json/stm/v1/posts/', $captured['postsApiRoot']);
        $this->assertSame('Translation deleted', $captured['i18n']['deleted']);
        $this->assertSame('Failed to delete translation', $captured['i18n']['deleteFailed']);
        $this->assertCount(2, $captured['previewLanguages'], 'Both configured languages appear in the cycler, including the current one.');
        $this->assertSame('en', $captured['previewLanguages'][0]['code']);
        $this->assertSame('https://example.test/?p=42&lang=en', $captured['previewLanguages'][0]['previewUrl']);
        $this->assertSame('nl', $captured['previewLanguages'][1]['code']);
        $this->assertSame('https://example.test/?p=42&lang=nl', $captured['previewLanguages'][1]['previewUrl']);

        unset($_GET['post']);
    }

    public function test_enqueue_assets_falls_back_to_global_post_for_a_new_unsaved_post() {
        global $post;
        $this->seedLanguages();
        // post-new.php never puts an ID in $_GET, but WP has already created
        // the auto-draft row and populated the $post global by this point.
        $post = (object) ['ID' => 99];

        Functions\when('wp_enqueue_style')->justReturn(true);
        Functions\when('wp_enqueue_script')->justReturn(true);
        Functions\when('admin_url')->returnArg(1);
        Functions\when('wp_create_nonce')->justReturn('nonce-abc');
        Functions\when('esc_url_raw')->returnArg(1);
        Functions\when('rest_url')->alias(function ($path) { return 'https://example.test/wp-json/' . $path; });
        Functions\when('get_post')->justReturn((object) ['ID' => 99]);
        Functions\when('get_preview_post_link')->alias(function ($p, $args = []) {
            return 'https://example.test/?p=' . $p->ID . '&lang=' . ($args['lang'] ?? '');
        });

        $captured = null;
        Functions\when('wp_localize_script')->alias(function ($handle, $objName, $data) use (&$captured) {
            if ($objName === 'stmPostEditor') {
                $captured = $data;
            }
        });

        PostEditor::enqueue_assets('post-new.php');

        $this->assertNotNull($captured);
        $this->assertSame(0, $captured['postId'], 'postId itself stays $_GET-based — unrelated existing behavior.');
        $this->assertSame('https://example.test/?p=99&lang=en', $captured['previewLanguages'][0]['previewUrl']);

        $post = null;
    }

    // -----------------------------------------------------------------
    // Preview-in-language cycler data (PostEditor::build_preview_languages)
    // -----------------------------------------------------------------

    public function test_build_preview_languages_returns_url_per_language_for_a_saved_post() {
        $this->seedLanguages();

        Functions\when('get_post')->justReturn((object) ['ID' => 42]);
        Functions\when('get_preview_post_link')->alias(function ($post, $args = []) {
            return 'https://example.test/?p=' . $post->ID . '&lang=' . ($args['lang'] ?? '');
        });

        $result = PostEditor::build_preview_languages(42);

        $this->assertCount(2, $result);
        $this->assertSame('en', $result[0]['code']);
        $this->assertSame('https://example.test/?p=42&lang=en', $result[0]['previewUrl']);
        $this->assertSame('nl', $result[1]['code']);
        $this->assertSame('https://example.test/?p=42&lang=nl', $result[1]['previewUrl']);
    }

    public function test_build_preview_languages_leaves_preview_url_empty_without_a_post_id() {
        $this->seedLanguages();

        $result = PostEditor::build_preview_languages(0);

        $this->assertCount(2, $result);
        $this->assertSame('', $result[0]['previewUrl']);
        $this->assertSame('', $result[1]['previewUrl']);
    }

    public function test_build_preview_languages_includes_inactive_languages() {
        $this->seedLanguagesWithInactive();

        Functions\when('get_post')->justReturn((object) ['ID' => 42]);
        Functions\when('get_preview_post_link')->alias(function ($post, $args = []) {
            return 'https://example.test/?p=' . $post->ID . '&lang=' . ($args['lang'] ?? '');
        });

        $result = PostEditor::build_preview_languages(42);

        $codes = array_map(function ($l) { return $l['code']; }, $result);
        $this->assertSame(['en', 'nl', 'de'], $codes, 'The inactive German language must still appear in the preview cycler.');
        $this->assertSame('https://example.test/?p=42&lang=de', $result[2]['previewUrl']);
    }

    // -----------------------------------------------------------------
    // Meta box template rendering (PostEditor::render_meta_box)
    // -----------------------------------------------------------------

    private function stubTemplateEscaping() {
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_textarea')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('wp_kses_post')->returnArg(1);
        Functions\when('admin_url')->justReturn('http://example.test/wp-admin/admin.php?page=stm-languages');
        Functions\when('selected')->justReturn('');
        Functions\when('wp_nonce_field')->justReturn('');
    }

    public function test_render_meta_box_includes_preview_cycler_when_multiple_languages_exist() {
        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__) . '/');
        }
        $this->seedLanguages(); // en + nl
        $this->stubTemplateEscaping();

        ob_start();
        PostEditor::render_meta_box((object) ['ID' => 42]);
        $html = ob_get_clean();

        $this->assertStringContainsString('stm-language-preview-cycler', $html);
        $this->assertStringContainsString('data-post-id="42"', $html);
    }

    public function test_render_meta_box_omits_preview_cycler_for_a_single_language() {
        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__) . '/');
        }
        $this->wpdb->seed('wp_stm_languages', [
            'code' => 'en', 'name' => 'English', 'flag_emoji' => '🇬🇧', 'is_active' => 1, 'is_default' => 1, 'order_index' => 1,
        ]);
        $this->stubTemplateEscaping();

        ob_start();
        PostEditor::render_meta_box((object) ['ID' => 42]);
        $html = ob_get_clean();

        $this->assertStringNotContainsString('stm-language-preview-cycler', $html);
    }

    public function test_render_meta_box_lists_an_inactive_language_tab_and_accepts_saved_content() {
        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__) . '/');
        }
        $this->seedLanguagesWithInactive(); // en (default), nl, de (inactive)
        $this->wpdb->seed('wp_stm_post_translations', [
            'post_id' => 42, 'field_name' => 'post_title', 'language_code' => 'de', 'translation' => 'Deutscher Titel',
        ]);
        $this->stubTemplateEscaping();

        ob_start();
        PostEditor::render_meta_box((object) ['ID' => 42]);
        $html = ob_get_clean();

        $this->assertStringContainsString('data-lang="de"', $html, 'The inactive language must still get an editable tab.');
        $this->assertStringContainsString('Deutscher Titel', $html, 'Content already saved for the inactive language must be shown, same as any other translation.');
        $this->assertStringContainsString('stm_translations[de][post_title]', $html, 'The field name must exist so typed content for the inactive language can be submitted and saved.');
    }

    public function test_render_meta_box_shows_translations_written_with_short_field_names() {
        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__) . '/');
        }
        $this->seedLanguages(); // en (current), nl
        // Mirrors rows written by external integrations (e.g. Bugatti Insights'
        // sync API) via STM\API::save_post_translations() using its own short
        // field names instead of the metabox's post_title/post_content keys.
        $this->wpdb->seed('wp_stm_post_translations', [
            'post_id' => 42, 'field_name' => 'title', 'language_code' => 'nl', 'translation' => 'Nederlandse titel',
        ]);
        $this->wpdb->seed('wp_stm_post_translations', [
            'post_id' => 42, 'field_name' => 'content', 'language_code' => 'nl', 'translation' => '<p>Nederlandse inhoud</p>',
        ]);
        $this->stubTemplateEscaping();

        ob_start();
        PostEditor::render_meta_box((object) ['ID' => 42]);
        $html = ob_get_clean();

        $this->assertStringContainsString('Nederlandse titel', $html, 'A row saved with field_name "title" must still populate the Title input.');
        $this->assertStringContainsString('Nederlandse inhoud', $html, 'A row saved with field_name "content" must still populate the Content textarea.');
    }

    public function test_render_meta_box_prefers_native_post_title_over_legacy_title_alias() {
        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__) . '/');
        }
        $this->seedLanguages();
        $this->wpdb->seed('wp_stm_post_translations', [
            'post_id' => 42, 'field_name' => 'post_title', 'language_code' => 'nl', 'translation' => 'Titel via metabox',
        ]);
        $this->wpdb->seed('wp_stm_post_translations', [
            'post_id' => 42, 'field_name' => 'title', 'language_code' => 'nl', 'translation' => 'Stale legacy titel',
        ]);
        $this->stubTemplateEscaping();

        ob_start();
        PostEditor::render_meta_box((object) ['ID' => 42]);
        $html = ob_get_clean();

        $this->assertStringContainsString('Titel via metabox', $html);
        $this->assertStringNotContainsString('Stale legacy titel', $html);
    }

    // -----------------------------------------------------------------
    // Post-list columns (PostEditor::display_language_column)
    // -----------------------------------------------------------------

    public function test_display_language_column_resolves_flag_for_a_post_whose_own_language_is_inactive() {
        $this->seedLanguagesWithInactive();
        $this->wpdb->seed('wp_stm_post_associations', [
            'post_id' => 42, 'language_code' => 'de', 'translation_group' => 'g1', 'is_original' => 1,
        ]);

        Functions\when('esc_html')->returnArg(1);

        ob_start();
        PostEditor::display_language_column('stm_language', 42);
        $output = ob_get_clean();

        $this->assertSame('🇩🇪 de', $output, 'A post written in an inactive language must still resolve its flag/emoji, not fall back to the raw code.');
    }

    public function test_display_language_column_counts_translations_saved_in_an_inactive_language() {
        $this->seedLanguagesWithInactive();
        $this->wpdb->seed('wp_stm_post_associations', [
            'post_id' => 42, 'language_code' => 'en', 'translation_group' => 'g1', 'is_original' => 1,
        ]);
        $this->wpdb->seed('wp_stm_post_translations', [
            'post_id' => 42, 'field_name' => 'post_title', 'language_code' => 'de', 'translation' => 'Deutscher Titel',
        ]);

        Functions\when('esc_html')->returnArg(1);

        ob_start();
        PostEditor::display_language_column('stm_translations', 42);
        $output = ob_get_clean();

        $this->assertStringContainsString('🇩🇪', $output, 'A translation saved only in an inactive language must still count as a translation on the post list.');
    }

    // -----------------------------------------------------------------
    // get_post_language() — SEO God detected-language fallback (task 3047)
    // -----------------------------------------------------------------

    public function test_get_post_language_falls_back_to_seo_god_detected_language() {
        $this->seedLanguages();
        // SEO God's filter returns a full locale tag ('nl-NL'), not STM's
        // bare code ('nl') — get_post_language() must normalize it.
        Functions\when('apply_filters')->alias(function ($tag, $value, ...$args) {
            return $tag === 'seo_god_content_language' ? 'nl-NL' : $value;
        });

        $this->assertSame(
            'nl',
            PostEditor::get_post_language(55),
            'A post with no STM association row must defer to SEO God\'s detected content language before the site default.'
        );
    }

    public function test_get_post_language_ignores_unknown_detected_language() {
        $this->seedLanguages();
        Functions\when('apply_filters')->alias(function ($tag, $value, ...$args) {
            // A code STM doesn't manage must never be trusted as a language.
            return $tag === 'seo_god_content_language' ? 'xx-XX' : $value;
        });

        $this->assertSame('en', PostEditor::get_post_language(56));
    }

    public function test_get_post_language_falls_back_to_site_default_when_seo_god_has_no_signal() {
        $this->seedLanguages();
        Functions\when('apply_filters')->returnArg(2);

        $this->assertSame('en', PostEditor::get_post_language(57));
    }

    public function test_get_post_language_prefers_real_stm_association_over_detected_language() {
        $this->seedLanguages();
        $this->wpdb->seed('wp_stm_post_associations', [
            'post_id' => 58, 'language_code' => 'en', 'translation_group' => 'g1', 'is_original' => 1,
        ]);
        Functions\when('apply_filters')->alias(function ($tag, $value, ...$args) {
            return $tag === 'seo_god_content_language' ? 'nl-NL' : $value;
        });

        $this->assertSame(
            'en',
            PostEditor::get_post_language(58),
            'A real, manually-registered STM association must win over SEO God\'s detection — no regression to existing behavior.'
        );
    }
}

/**
 * Minimal WP_REST_Request stand-in — array access is all these callbacks use.
 */
class FakeRestRequest implements \ArrayAccess {
    private $params;

    public function __construct(array $params) {
        $this->params = $params;
    }

    public function offsetExists($offset): bool { return isset($this->params[$offset]); }
    public function offsetGet($offset): mixed { return $this->params[$offset] ?? null; }
    public function offsetSet($offset, $value): void { $this->params[$offset] = $value; }
    public function offsetUnset($offset): void { unset($this->params[$offset]); }
}
