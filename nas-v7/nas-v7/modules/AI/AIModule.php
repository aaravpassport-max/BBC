<?php
namespace NAS\Modules\AI;
use NAS\Core\{Module, Database, Security, Config};
if ( ! defined( 'ABSPATH' ) ) exit;

class AIModule extends Module {
    public function key(): string { return 'ai'; }

    public function register(): void {
        add_action('wp_ajax_nas_ai_generate',   [new AIController, 'generate']);
        add_action('wp_ajax_nas_ai_rewrite',    [new AIController, 'rewrite']);
        add_action('wp_ajax_nas_ai_action',     [new AIController, 'action']);
        add_action('wp_ajax_nas_ai_translate',  [new AIController, 'translate']);
        // Allow non-logged-in (public booking form) — rate limited inside AIController
        add_action('wp_ajax_nopriv_nas_ai_generate',  [new AIController, 'generate']);
        add_action('wp_ajax_nopriv_nas_ai_rewrite',   [new AIController, 'rewrite']);
        add_action('wp_ajax_nopriv_nas_ai_action',    [new AIController, 'action']);
        add_action('wp_ajax_nopriv_nas_ai_translate', [new AIController, 'translate']);
    }

    public function boot(): void {}
}

class AIService {
    private Config $config;

    public function __construct() { $this->config = Config::instance(); }

    // TRACE: call() — Trigger: wp_ajax_call AJAX action.
    //        Steps: returns JSON error response on failure → calls external API.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    public function call( string $prompt, int $max_tokens = 500 ): string {
        $api_key = $this->config->get('ai_api_key','');
        if (!$api_key) return '';

        $provider = $this->config->get('ai_provider','anthropic');
        $model    = $this->config->get('ai_model','claude-sonnet-4-6');

        if ($provider === 'anthropic') {
            return $this->call_anthropic($api_key, $model, $prompt, $max_tokens);
        }
        return '';
    }

    // TRACE: call_anthropic() — Trigger: wp_ajax_call_anthropic AJAX action.
    //        Steps: inserts DB row → calls external API.
    //        Output: success/error JSON response.
    //        Edge cases: WP_Error returned by external call handled.
    private function call_anthropic( string $api_key, string $model, string $prompt, int $max_tokens ): string {
        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'timeout' => 30,
            'headers' => [
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'body' => wp_json_encode([
                'model'      => $model,
                'max_tokens' => $max_tokens,
                'messages'   => [['role'=>'user','content'=>$prompt]],
            ]),
        ]);

        if (is_wp_error($response)) return '';
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ( $body === null || empty($body['content'][0]['text']) ) {
            \NAS\Core\ErrorLogger::warning('AI: malformed or empty response', ['raw' => substr(wp_remote_retrieve_body($response),0,200)]);
            return '';
        }
        $text = $body['content'][0]['text'];

        // Log AI usage — guard against missing usage key
        $tokens = ($body['usage']['input_tokens'] ?? 0) + ($body['usage']['output_tokens'] ?? 0);
        Database::instance()->insert(Database::instance()->prefix('ai_logs'), [
            'type'       => 'generate',
            'model_used' => $model,
            'tokens_used'=> $tokens,
            'output_text'=> substr($text, 0, 2000),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return trim($text);
    }

    // TRACE: generate_ad() — Trigger: wp_ajax_generate_ad AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    public function generate_ad( string $category, string $keywords, string $ad_type, int $word_limit = 60 ): string {
        $prompt = "You are a professional newspaper advertisement copywriter in India. Write a compelling {$ad_type} newspaper ad for the category '{$category}'. Use these keywords/details: {$keywords}. Keep the ad under {$word_limit} words. Write directly — no explanations, just the ad text. Make it professional and effective.";
        return $this->call($prompt, 300);
    }

    // TRACE: rewrite() — Trigger: wp_ajax_rewrite AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    public function rewrite( string $text, string $tone = 'professional' ): string {
        $prompt = "Rewrite this newspaper advertisement to be more $tone and impactful. Keep the same information but improve the language. Stay under the same word count. Output only the rewritten ad:\n\n$text";
        return $this->call($prompt, 400);
    }

    // TRACE: make_shorter() — Trigger: wp_ajax_make_shorter AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    public function make_shorter( string $text ): string {
        $prompt = "Shorten this newspaper ad by 30-40% while keeping all key information. Output only the shortened ad:\n\n$text";
        return $this->call($prompt, 300);
    }

    // TRACE: make_premium() — Trigger: wp_ajax_make_premium AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    public function make_premium( string $text ): string {
        $prompt = "Upgrade this newspaper ad to sound more premium and high-quality. Use persuasive language. Output only the improved ad:\n\n$text";
        return $this->call($prompt, 400);
    }

    // TRACE: translate() — Trigger: wp_ajax_translate AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: array (empty on no results).
    //        Edge cases: invalid input → error returned.
    public function translate( string $text, string $language ): string {
        $prompt = "Translate this newspaper advertisement to $language. Keep it professional and culturally appropriate for Indian readers. Output only the translation:\n\n$text";
        return $this->call($prompt, 500);
    }

    // TRACE: suggest_keywords() — Trigger: wp_ajax_suggest_keywords AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public function suggest_keywords( string $category ): array {
        $prompt = "List 10 relevant keywords for newspaper ads in the '{$category}' category in India. Format: comma-separated list only. No explanations.";
        $text   = $this->call($prompt, 150);
        return array_map('trim', explode(',', $text));
    }
}

class AIController {
    private AIService $service;

    public function __construct() { $this->service = new AIService(); }

    /** Shared rate limiter: max 8 AI calls per IP per 60 seconds */
    // TRACE: check_rate_limit() — Trigger: wp_ajax_check_rate_limit AJAX action.
    //        Steps: verifies nonce → reads sanitised POST input → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    private function check_rate_limit(): void {
        $ip  = preg_replace( '/[^0-9a-fA-F.:,]/', '', $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' );
        $key = 'nas_ai_module_rl_' . md5( $ip );
        $hits = (int) get_transient( $key );
        if ( $hits >= 8 ) {
            wp_send_json_error( ['message' => 'Too many AI requests. Please wait a moment.'], 429 );
        }
        set_transient( $key, $hits + 1, 60 );
    }

    // TRACE: generate() — Trigger: wp_ajax_generate AJAX action.
    //        Steps: verifies nonce → reads sanitised POST input → returns JSON success response → returns JSON error response on failure.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public function generate(): void {
        Security::check_nonce(Security::post('nonce'), 'nas_action');
        $this->check_rate_limit();
        $category   = Security::post('category');
        $keywords   = Security::post('keywords');
        $ad_type    = Security::post('ad_type') ?: 'classified';
        $word_limit = Security::post('word_limit','int') ?: 60;

        if (!$category || !$keywords) { wp_send_json_error(['message'=>'Category and keywords required.']); return; }
        $text = $this->service->generate_ad($category, $keywords, $ad_type, $word_limit);
        $text ? wp_send_json_success(['text'=>$text]) : wp_send_json_error(['message'=>'AI generation failed. Please check your API key in Settings.']);
    }

    // TRACE: rewrite() — Trigger: wp_ajax_rewrite AJAX action.
    //        Steps: verifies nonce → reads sanitised POST input → returns JSON success response → returns JSON error response on failure.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public function rewrite(): void {
        Security::check_nonce(Security::post('nonce'), 'nas_action');
        $this->check_rate_limit();
        $text = Security::post('text','textarea');
        if (!$text) { wp_send_json_error(['message'=>'Text required.']); return; }
        $result = $this->service->rewrite($text, Security::post('tone') ?: 'professional');
        $result ? wp_send_json_success(['text'=>$result]) : wp_send_json_error(['message'=>'AI rewrite failed.']);
    }

    // TRACE: action() — Trigger: wp_ajax_action AJAX action.
    //        Steps: verifies nonce → reads sanitised POST input → returns JSON success response → returns JSON error response on failure.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public function action(): void {
        Security::check_nonce(Security::post('nonce'), 'nas_action');
        $this->check_rate_limit();
        $text   = Security::post('text','textarea');
        $action = Security::post('action_type');
        if (!$text) { wp_send_json_error(['message'=>'Text required.']); return; }

        $result = match($action) {
            'shorter' => $this->service->make_shorter($text),
            'premium' => $this->service->make_premium($text),
            default   => '',
        };
        $result ? wp_send_json_success(['text'=>$result]) : wp_send_json_error(['message'=>'AI action failed.']);
    }

    // TRACE: translate() — Trigger: wp_ajax_translate AJAX action.
    //        Steps: verifies nonce → reads sanitised POST input → returns JSON success response → returns JSON error response on failure.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public function translate(): void {
        Security::check_nonce(Security::post('nonce'), 'nas_action');
        $this->check_rate_limit();
        $text     = Security::post('text','textarea');
        $language = Security::post('language') ?: 'Hindi';
        if (!$text) { wp_send_json_error(['message'=>'Text required.']); return; }
        $result = $this->service->translate($text, $language);
        $result ? wp_send_json_success(['text'=>$result]) : wp_send_json_error(['message'=>'Translation failed.']);
    }
}
