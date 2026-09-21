<?php
/**
 * PHPUnit tests: the post editor metabox re-posts every prefilled translation
 * on each post Update (task 3520). A translation written by the REST/CLI/AI
 * path must keep its recorded source hash through that re-post even though the
 * browser/sanitizers hand back a slightly different byte string (CRLF
 * newlines, kses-normalized ampersands) — otherwise a source edit silently
 * clears the stale flag on the first Update.
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\PostEditor;
use STM\Tests\Fakes\FakeWpdb;

class StaleHashResaveNormalizationTest extends TestCase {

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
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('sanitize_title')->returnArg(1);
        Functions\when('wp_kses_post')->returnArg(1);
        Functions\when('current_time')->justReturn('2026-07-17 00:00:00');
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('wp_generate_uuid4')->justReturn('fixed-uuid');
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('get_option')->justReturn(false);
        Functions\when('update_option')->justReturn(true);
        Functions\when('__')->returnArg(1);
        Functions\when('current_user_can')->justReturn(true);

        $_POST['stm_translations_nonce'] = 'valid-nonce';
        Functions\when('wp_verify_nonce')->justReturn(true);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function seedApiRow($field, $translation, $sourceAtTranslateTime) {
        $this->wpdb->seed('wp_stm_post_translations', [
            'post_id' => 42, 'field_name' => $field, 'language_code' => 'nl',
            'translation' => $translation,
            'source_hash' => md5($sourceAtTranslateTime),
        ]);
    }

    public function test_crlf_repost_of_an_lf_translation_keeps_the_hash() {
        $this->seedApiRow('post_content', "Regel een\n\nRegel twee", "Line one\n\nLine two");

        // Source edited; the browser re-posts the prefilled textarea with CRLF.
        $_POST['stm_post_language'] = 'en';
        $_POST['stm_translations'] = ['nl' => ['post_content' => "Regel een\r\n\r\nRegel twee"]];
        PostEditor::save_translations(42, (object) ['ID' => 42, 'post_content' => "Line one\n\nLine two changed"]);

        $rows = $this->wpdb->all('wp_stm_post_translations');
        $this->assertSame(md5("Line one\n\nLine two"), $rows[0]['source_hash']);
    }

    public function test_kses_normalized_repost_of_a_raw_api_translation_keeps_the_hash() {
        // kses turns a bare ampersand into &amp; — stub that behaviour.
        Functions\when('wp_kses_post')->alias(function ($v) {
            return preg_replace('/&(?!amp;)/', '&amp;', (string) $v);
        });

        $this->seedApiRow('post_content', 'Vis & friet', 'Fish & chips');

        $_POST['stm_post_language'] = 'en';
        $_POST['stm_translations'] = ['nl' => ['post_content' => 'Vis & friet']];
        PostEditor::save_translations(42, (object) ['ID' => 42, 'post_content' => 'Fish & chips and more']);

        $rows = $this->wpdb->all('wp_stm_post_translations');
        $this->assertSame(md5('Fish & chips'), $rows[0]['source_hash'], 'Sanitizer-only differences must not count as a text change.');
    }

    public function test_a_genuinely_edited_multiline_translation_still_restamps_the_hash() {
        $this->seedApiRow('post_content', "Regel een\n\nRegel twee", "Line one\n\nLine two");

        $_POST['stm_post_language'] = 'en';
        $_POST['stm_translations'] = ['nl' => ['post_content' => "Regel een\r\n\r\nRegel drie"]];
        $edited = (object) ['ID' => 42, 'post_content' => "Line one\n\nLine two changed"];
        PostEditor::save_translations(42, $edited);

        $rows = $this->wpdb->all('wp_stm_post_translations');
        $this->assertSame(md5("Line one\n\nLine two changed"), $rows[0]['source_hash']);
    }
}
