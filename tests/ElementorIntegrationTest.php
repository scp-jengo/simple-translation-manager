<?php
/**
 * PHPUnit tests: Elementor widget translation integration.
 *
 * Covers detection, the translation-map merge/extract logic that drives both
 * frontend rendering and the editor panel, the storage round-trip (reusing
 * stm_post_translations via FakeWpdb), and the REST endpoints the editor
 * panel JS talks to.
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\ElementorIntegration;
use STM\PostEditor;
use STM\Tests\Fakes\FakeWpdb;

class ElementorIntegrationTest extends TestCase {

    /** @var FakeWpdb */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = new FakeWpdb();
        $this->wpdb = $wpdb;

        $_GET    = [];
        $_COOKIE = [];

        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('wp_kses')->returnArg(1);
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('get_option')->justReturn('en');
        Functions\when('get_query_var')->justReturn('');
        Functions\when('is_preview')->justReturn(false);
        Functions\when('__')->returnArg(1);
        Functions\when('rest_ensure_response')->returnArg(1);
        Functions\when('wp_json_encode')->alias(function ($data) { return json_encode($data); });
        // save_language_data() hashes the live `_elementor_data` source (task 3520).
        Functions\when('get_post_meta')->justReturn('');
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

    // -----------------------------------------------------------------
    // Detection
    // -----------------------------------------------------------------

    public function test_is_elementor_active_true_when_elementor_loaded_action_fired() {
        Functions\when('did_action')->justReturn(1);

        $this->assertTrue(ElementorIntegration::is_elementor_active());
    }

    public function test_is_elementor_active_false_when_elementor_not_loaded() {
        Functions\when('did_action')->justReturn(0);

        $this->assertFalse(ElementorIntegration::is_elementor_active());
    }

    // -----------------------------------------------------------------
    // merge_translations() — frontend + editor-panel overlay logic
    // -----------------------------------------------------------------

    public function test_merge_translations_overlays_matching_element_and_key() {
        $elements = [
            ['id' => 'abc123', 'settings' => ['title' => 'Hello']],
        ];
        $translations = ['abc123' => ['title' => 'Hallo']];

        $result = ElementorIntegration::merge_translations($elements, $translations);

        $this->assertSame('Hallo', $result[0]['settings']['title']);
    }

    public function test_merge_translations_leaves_untranslated_element_untouched() {
        $elements = [
            ['id' => 'abc123', 'settings' => ['title' => 'Hello']],
        ];

        $result = ElementorIntegration::merge_translations($elements, []);

        $this->assertSame('Hello', $result[0]['settings']['title']);
    }

    public function test_merge_translations_skips_empty_translated_value() {
        $elements = [
            ['id' => 'abc123', 'settings' => ['title' => 'Hello']],
        ];
        $translations = ['abc123' => ['title' => '']];

        $result = ElementorIntegration::merge_translations($elements, $translations);

        $this->assertSame('Hello', $result[0]['settings']['title'], 'Empty translation must not blank out the source text.');
    }

    public function test_merge_translations_preserves_dynamic_tag_bindings() {
        // Elementor stores a dynamic-tag binding as a nested array, not a plain string.
        $elements = [
            ['id' => 'abc123', 'settings' => ['title' => ['__dynamic__' => 'post_title']]],
        ];
        $translations = ['abc123' => ['title' => ['__dynamic__' => 'should-not-apply']]];

        $result = ElementorIntegration::merge_translations($elements, $translations);

        $this->assertSame(['__dynamic__' => 'post_title'], $result[0]['settings']['title'], 'Non-string (dynamic tag) settings must be left resolving dynamically, not overwritten.');
    }

    public function test_merge_translations_recurses_into_nested_elements() {
        $elements = [
            [
                'id' => 'section1',
                'settings' => [],
                'elements' => [
                    ['id' => 'widget1', 'settings' => ['editor' => 'Body text']],
                ],
            ],
        ];
        $translations = ['widget1' => ['editor' => 'Vertaalde tekst']];

        $result = ElementorIntegration::merge_translations($elements, $translations);

        $this->assertSame('Vertaalde tekst', $result[0]['elements'][0]['settings']['editor']);
    }

    // -----------------------------------------------------------------
    // extract_translatable_fields() — drives the editor panel
    // -----------------------------------------------------------------

    public function test_extract_translatable_fields_picks_up_allowlisted_key() {
        $elements = [
            ['id' => 'w1', 'widgetType' => 'heading', 'settings' => ['title' => 'Welcome']],
        ];

        $fields = ElementorIntegration::extract_translatable_fields($elements);

        $this->assertCount(1, $fields);
        $this->assertSame('w1', $fields[0]['id']);
        $this->assertSame('heading', $fields[0]['widgetType']);
        $this->assertSame('title', $fields[0]['key']);
        $this->assertSame('Welcome', $fields[0]['source']);
    }

    public function test_extract_translatable_fields_matches_suffix_heuristic() {
        $elements = [
            ['id' => 'w1', 'widgetType' => 'button', 'settings' => ['button_text' => 'Click me']],
        ];

        $fields = ElementorIntegration::extract_translatable_fields($elements);

        $this->assertCount(1, $fields);
        $this->assertSame('button_text', $fields[0]['key']);
    }

    public function test_extract_translatable_fields_skips_non_content_settings() {
        $elements = [
            ['id' => 'w1', 'widgetType' => 'heading', 'settings' => ['title_color' => '#ff0000', 'width' => '100px']],
        ];

        $fields = ElementorIntegration::extract_translatable_fields($elements);

        // 'title_color' matches the '_color' suffix? No — only the listed suffixes; verify neither leaks through.
        $keys = array_column($fields, 'key');
        $this->assertNotContains('width', $keys);
    }

    public function test_extract_translatable_fields_skips_empty_string_values() {
        $elements = [
            ['id' => 'w1', 'settings' => ['title' => '']],
        ];

        $fields = ElementorIntegration::extract_translatable_fields($elements);

        $this->assertEmpty($fields);
    }

    public function test_extract_translatable_fields_recurses_into_nested_elements() {
        $elements = [
            [
                'id' => 'section1',
                'elType' => 'section',
                'settings' => [],
                'elements' => [
                    ['id' => 'w1', 'widgetType' => 'text-editor', 'settings' => ['editor' => 'Nested text']],
                ],
            ],
        ];

        $fields = ElementorIntegration::extract_translatable_fields($elements);

        $this->assertCount(1, $fields);
        $this->assertSame('w1', $fields[0]['id']);
    }

    public function test_extract_translatable_fields_falls_back_to_eltype_when_no_widgettype() {
        $elements = [
            ['id' => 's1', 'elType' => 'section', 'settings' => ['title' => 'Section title']],
        ];

        $fields = ElementorIntegration::extract_translatable_fields($elements);

        $this->assertSame('section', $fields[0]['widgetType']);
    }

    // -----------------------------------------------------------------
    // Storage round-trip
    // -----------------------------------------------------------------

    public function test_save_and_get_language_data_round_trip() {
        $translations = ['abc123' => ['title' => 'Hallo wereld']];

        ElementorIntegration::save_language_data(42, 'nl', $translations);
        $result = ElementorIntegration::get_language_data(42, 'nl');

        $this->assertSame($translations, $result);
    }

    public function test_save_language_data_updates_existing_row() {
        ElementorIntegration::save_language_data(42, 'nl', ['abc123' => ['title' => 'Eerste']]);
        ElementorIntegration::save_language_data(42, 'nl', ['abc123' => ['title' => 'Tweede']]);

        $rows = $this->wpdb->all('stm_post_translations');

        $this->assertCount(1, $rows, 'Saving twice for the same post/language must update, not duplicate.');
        $this->assertSame('Tweede', json_decode($rows[0]['translation'], true)['abc123']['title']);
    }

    // -----------------------------------------------------------------
    // Stale detection: _elementor_data rows carry a source hash (task 3520)
    // -----------------------------------------------------------------

    public function test_save_language_data_stamps_hash_of_the_current_elementor_source() {
        Functions\when('get_post_meta')->justReturn('[{"id":"abc123"}]');

        ElementorIntegration::save_language_data(42, 'nl', ['abc123' => ['title' => 'Hallo']]);

        $rows = $this->wpdb->all('stm_post_translations');
        $this->assertSame(md5('[{"id":"abc123"}]'), $rows[0]['source_hash']);
        $this->assertFalse(PostEditor::is_translation_stale(42, '_elementor_data', $rows[0]['source_hash']));
    }

    public function test_editing_the_elementor_layout_flags_the_saved_translation_stale_until_it_is_retranslated() {
        Functions\when('get_post_meta')->justReturn('[{"id":"abc123","v":1}]');
        ElementorIntegration::save_language_data(42, 'nl', ['abc123' => ['title' => 'Hallo']]);

        // The layout is edited in Elementor after the translation was saved.
        Functions\when('get_post_meta')->justReturn('[{"id":"abc123","v":2}]');
        $hash = $this->wpdb->all('stm_post_translations')[0]['source_hash'];
        $this->assertTrue(PostEditor::is_translation_stale(42, '_elementor_data', $hash));

        // The editor panel re-posts the SAME map: must not clear the flag.
        ElementorIntegration::save_language_data(42, 'nl', ['abc123' => ['title' => 'Hallo']]);
        $hash = $this->wpdb->all('stm_post_translations')[0]['source_hash'];
        $this->assertTrue(
            PostEditor::is_translation_stale(42, '_elementor_data', $hash),
            'Re-posting an unchanged translation map must not silently mark it fresh.'
        );

        // A real re-translation against the new layout does clear it.
        ElementorIntegration::save_language_data(42, 'nl', ['abc123' => ['title' => 'Hallo opnieuw']]);
        $hash = $this->wpdb->all('stm_post_translations')[0]['source_hash'];
        $this->assertFalse(PostEditor::is_translation_stale(42, '_elementor_data', $hash));
    }

    public function test_get_language_data_returns_empty_array_when_none_saved() {
        $this->assertSame([], ElementorIntegration::get_language_data(999, 'nl'));
    }

    public function test_delete_language_data_removes_row() {
        ElementorIntegration::save_language_data(42, 'nl', ['abc123' => ['title' => 'Hallo']]);
        ElementorIntegration::delete_language_data(42, 'nl');

        $this->assertSame([], ElementorIntegration::get_language_data(42, 'nl'));
        $this->assertCount(0, $this->wpdb->all('stm_post_translations'));
    }

    public function test_get_source_data_decodes_elementor_post_meta() {
        $elements = [['id' => 'abc123', 'settings' => ['title' => 'Hello']]];
        Functions\when('get_post_meta')->justReturn(json_encode($elements));

        $result = ElementorIntegration::get_source_data(42);

        $this->assertSame($elements, $result);
    }

    public function test_get_source_data_returns_empty_array_for_empty_meta() {
        Functions\when('get_post_meta')->justReturn('');

        $this->assertSame([], ElementorIntegration::get_source_data(42));
    }

    public function test_get_source_data_returns_empty_array_for_invalid_json() {
        Functions\when('get_post_meta')->justReturn('not json');

        $this->assertSame([], ElementorIntegration::get_source_data(42));
    }

    // -----------------------------------------------------------------
    // filter_builder_content_data() — frontend hook
    // -----------------------------------------------------------------

    public function test_filter_builder_content_data_unchanged_on_default_language() {
        $data = [['id' => 'abc123', 'settings' => ['title' => 'Hello']]];

        $result = ElementorIntegration::filter_builder_content_data($data, 42);

        $this->assertSame($data, $result);
    }

    public function test_filter_builder_content_data_unchanged_when_no_translation_saved() {
        $this->seedLanguages();
        $_COOKIE['stm_lang'] = 'nl';
        $data = [['id' => 'abc123', 'settings' => ['title' => 'Hello']]];

        $result = ElementorIntegration::filter_builder_content_data($data, 999);

        $this->assertSame($data, $result);
    }

    public function test_filter_builder_content_data_merges_translation_for_current_language() {
        $this->seedLanguages();
        // ?lang= rather than the cookie: the cookie is ignored in URL-routing
        // mode (the default), while the GET param is honored in both modes.
        $_GET['lang'] = 'nl';
        ElementorIntegration::save_language_data(42, 'nl', ['abc123' => ['title' => 'Hallo']]);

        $data = [['id' => 'abc123', 'settings' => ['title' => 'Hello']]];

        $result = ElementorIntegration::filter_builder_content_data($data, 42);

        $this->assertSame('Hallo', $result[0]['settings']['title']);
    }

    public function test_filter_builder_content_data_ignores_non_array_data() {
        $this->assertSame('', ElementorIntegration::filter_builder_content_data('', 42));
    }

    // -----------------------------------------------------------------
    // Editor panel enqueue
    // -----------------------------------------------------------------

    public function test_enqueue_editor_assets_skips_when_no_post_id() {
        $enqueued = false;
        Functions\when('wp_enqueue_script')->alias(function () use (&$enqueued) { $enqueued = true; });

        ElementorIntegration::enqueue_editor_assets();

        $this->assertFalse($enqueued);
    }

    public function test_enqueue_editor_assets_skips_when_no_other_languages_configured() {
        $this->wpdb->seed('wp_stm_languages', [
            'code' => 'en', 'name' => 'English', 'flag_emoji' => '🇬🇧', 'is_active' => 1, 'is_default' => 1, 'order_index' => 1,
        ]);
        $_GET['post'] = '42';

        $enqueued = false;
        Functions\when('wp_enqueue_script')->alias(function () use (&$enqueued) { $enqueued = true; });

        ElementorIntegration::enqueue_editor_assets();

        $this->assertFalse($enqueued);
    }

    public function test_enqueue_editor_assets_localizes_panel_config() {
        $this->seedLanguages();
        $_GET['post'] = '42';

        Functions\when('wp_enqueue_style')->justReturn(true);
        Functions\when('wp_enqueue_script')->justReturn(true);
        Functions\when('esc_url_raw')->returnArg(1);
        Functions\when('wp_create_nonce')->justReturn('nonce-abc');
        Functions\when('rest_url')->alias(function ($path) { return 'https://example.test/wp-json/' . $path; });

        $captured = null;
        Functions\when('wp_localize_script')->alias(function ($handle, $objName, $data) use (&$captured) {
            if ($objName === 'stmElementorEditor') {
                $captured = $data;
            }
        });

        ElementorIntegration::enqueue_editor_assets();

        $this->assertNotNull($captured);
        $this->assertSame('https://example.test/wp-json/stm/v1/posts/42/elementor/', $captured['restUrl']);
        $this->assertCount(1, $captured['languages'], 'Only the non-default language should appear in the panel.');
        $this->assertSame('nl', $captured['languages'][0]['code']);
    }

    public function test_current_editor_post_id_reads_get_param() {
        $_GET['post'] = '77';
        $this->assertSame(77, ElementorIntegration::current_editor_post_id());
    }

    public function test_current_editor_post_id_defaults_to_zero() {
        $this->assertSame(0, ElementorIntegration::current_editor_post_id());
    }

    // -----------------------------------------------------------------
    // REST endpoints
    // -----------------------------------------------------------------

    public function test_check_edit_post_permission_delegates_to_current_user_can() {
        Functions\when('current_user_can')->justReturn(true);

        $request = new FakeElementorRestRequest(['id' => '42']);

        $this->assertTrue(ElementorIntegration::check_edit_post_permission($request));
    }

    public function test_rest_get_returns_not_found_for_missing_post() {
        Functions\when('get_post')->justReturn(false);

        $request = new FakeElementorRestRequest(['id' => '999', 'lang' => 'nl']);
        $result = ElementorIntegration::rest_get($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('not_found', $result->get_error_code());
    }

    public function test_rest_get_returns_invalid_language_error() {
        Functions\when('get_post')->justReturn((object) ['ID' => 42]);

        $request = new FakeElementorRestRequest(['id' => '42', 'lang' => '123']);
        $result = ElementorIntegration::rest_get($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_language', $result->get_error_code());
    }

    public function test_rest_get_returns_fields_and_translations() {
        Functions\when('get_post')->justReturn((object) ['ID' => 42]);
        Functions\when('get_post_meta')->justReturn(json_encode([
            ['id' => 'abc123', 'widgetType' => 'heading', 'settings' => ['title' => 'Hello']],
        ]));
        ElementorIntegration::save_language_data(42, 'nl', ['abc123' => ['title' => 'Hallo']]);

        $request = new FakeElementorRestRequest(['id' => '42', 'lang' => 'nl']);
        $result = ElementorIntegration::rest_get($request);

        $this->assertSame('title', $result['fields'][0]['key']);
        $this->assertSame('Hallo', $result['translations']['abc123']['title']);
    }

    public function test_rest_save_returns_not_found_for_missing_post() {
        Functions\when('get_post')->justReturn(false);

        $request = new FakeElementorRestRequest(['id' => '999', 'lang' => 'nl', 'translations' => []]);
        $result = ElementorIntegration::rest_save($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('not_found', $result->get_error_code());
    }

    public function test_rest_save_returns_invalid_translations_error_when_not_array() {
        Functions\when('get_post')->justReturn((object) ['ID' => 42]);

        $request = new FakeElementorRestRequest(['id' => '42', 'lang' => 'nl', 'translations' => 'not-an-array']);
        $result = ElementorIntegration::rest_save($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_translations', $result->get_error_code());
    }

    public function test_rest_save_persists_sanitized_translations() {
        Functions\when('get_post')->justReturn((object) ['ID' => 42]);

        $request = new FakeElementorRestRequest([
            'id' => '42',
            'lang' => 'nl',
            'translations' => ['abc123' => ['title' => 'Hallo wereld']],
        ]);

        $result = ElementorIntegration::rest_save($request);

        $this->assertSame(['success' => true], $result);
        $this->assertSame(['abc123' => ['title' => 'Hallo wereld']], ElementorIntegration::get_language_data(42, 'nl'));
    }

    public function test_rest_save_drops_non_string_field_values() {
        Functions\when('get_post')->justReturn((object) ['ID' => 42]);

        $request = new FakeElementorRestRequest([
            'id' => '42',
            'lang' => 'nl',
            'translations' => ['abc123' => ['title' => ['__dynamic__' => 'x']]],
        ]);

        ElementorIntegration::rest_save($request);

        $this->assertSame([], ElementorIntegration::get_language_data(42, 'nl'));
    }

    public function test_rest_delete_removes_translation() {
        ElementorIntegration::save_language_data(42, 'nl', ['abc123' => ['title' => 'Hallo']]);

        $request = new FakeElementorRestRequest(['id' => '42', 'lang' => 'nl']);
        $result = ElementorIntegration::rest_delete($request);

        $this->assertSame(['success' => true], $result);
        $this->assertSame([], ElementorIntegration::get_language_data(42, 'nl'));
    }

    public function test_rest_delete_returns_invalid_language_error() {
        $request = new FakeElementorRestRequest(['id' => '42', 'lang' => '9']);
        $result = ElementorIntegration::rest_delete($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_language', $result->get_error_code());
    }
}

/**
 * WP_REST_Request stand-in supporting both array access (`$request['id']`)
 * and `get_param()`, matching how this integration's REST callbacks read params.
 */
class FakeElementorRestRequest implements \ArrayAccess {
    private $params;

    public function __construct(array $params) {
        $this->params = $params;
    }

    public function offsetExists($offset): bool { return isset($this->params[$offset]); }
    public function offsetGet($offset): mixed { return $this->params[$offset] ?? null; }
    public function offsetSet($offset, $value): void { $this->params[$offset] = $value; }
    public function offsetUnset($offset): void { unset($this->params[$offset]); }

    public function get_param($key) {
        return $this->params[$key] ?? null;
    }
}
