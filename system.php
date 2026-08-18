<?php
/**
 * Plugin Name: AI Chat (MultiModel)
 * Plugin URI:  https://ptero.pro
 * Description: A ChatGPT-style AI chat system with a conversation sidebar, powered by a free OpenAI-compatible AI API. Use the [mlp_ai_chat] shortcode to embed it on any page.
 * Version:     1.7.1
 * Author:      Amine khd
 * License:     GPL v2 or later
 *
 * Requires in wp-config.php:
 *   define( 'MLP_AURORA_SITE_KEY', 'sk-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' ); // https://aurora-ai.example
 *   define( 'MLP_EDGEAI_KEY_TOKEN', 'cfut_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' ); // Edge AI provider API token
 *   define( 'MLP_EDGEAI_ACCOUNT_ID',  'your-edge-ai-provider-account-id' );
 *   define( 'MLP_MERIDIAN_KEY', 'sk-nry-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' ); // https://router.meridian-ai.example — powers Laguna S 2.1 (Free)
 *
 * Optional, in wp-config.php (enables the first-time-join captcha, 1.7.0+):
 *   define( 'MLP_TURNSTILE_SITE_KEY',   'your-turnstile-site-key' );   // public
 *   define( 'MLP_TURNSTILE_SECRET_KEY', 'your-turnstile-secret-key' ); // private, server-side only
 *
 * NOTE: As of 1.4.0 the chat no longer requires a logged-in WP user.
 * Guests are identified by a random token generated in the browser and
 * stored in localStorage, so each visitor's conversations stay separate
 * without requiring an account. Because this opens the (paid) chat API
 * to anyone who can load the page, consider pairing this with rate
 * limiting (see the note above register_routes()) if the page is public.
 *
 * As of 1.5.0:
 *   - Conversations and messages are no longer stored in the WordPress
 *     database at all. Everything lives in the visitor's own browser
 *     localStorage; the server only ever sees a chat request in transit
 *     (to call the AI API) and never persists its contents. The server
 *     still keeps small, contentless usage counters (total requests,
 *     first/last-seen per guest name) purely for the admin dashboard.
 *   - Site admins (any user with the `manage_options` capability) see an
 *     extra "Administration" room in the chat sidebar itself, showing
 *     live status for every configured AI model (Online / Offline /
 *     Rate Limited / Blocked / Disabled), site-wide usage stats, and
 *     controls to disable the whole chat or an individual model.
 *
 * As of 1.6.0:
 *   - Every AI reply gets a thumbs up / thumbs down pair. Votes are
 *     tallied per model (not per message — no message content is ever
 *     sent for this) into a single small option, and shown per-provider
 *     in the Administration room / wp-admin dashboard so admins can see
 *     which models people actually like.
 *
 * As of 1.6.1:
 *   - Removed the auto-login-as-shared-"Guest"-account behavior. Logged-
 *     out visitors are no longer signed into any WordPress account; they
 *     stay fully anonymous and are identified only by the per-browser
 *     guest token (already in place since 1.4.0). No shared "Guest" WP
 *     user is created on activation anymore, and MLP_AI_CHAT_GUEST_PASS
 *     is no longer used or required.
 *
 * As of 1.7.0:
 *   - First-time (logged-out) visitors must now clear a Cloudflare
 *     Turnstile challenge in the username modal before they can start
 *     chatting. The token is verified server-side (siteverify) before an
 *     identity/guest token is created. Enabled automatically once both
 *     MLP_TURNSTILE_SITE_KEY and MLP_TURNSTILE_SECRET_KEY are defined in
 *     wp-config.php:
 *       define( 'MLP_TURNSTILE_SITE_KEY',   'your-turnstile-site-key' );
 *       define( 'MLP_TURNSTILE_SECRET_KEY', 'your-turnstile-secret-key' );
 *     If either is missing, the modal falls back to its previous
 *     (no-captcha) behavior. Logged-in WP users never see this modal, so
 *     they're unaffected either way.
 *
 * As of 1.7.1 (performance/CPU hardening):
 *   - /chat and /chat-stream are now rate-limited per identity (logged-in
 *     user id, guest token, or IP as a last resort) to
 *     MLP_AI_CHAT_RATE_LIMIT_PER_MINUTE (30) requests per rolling minute,
 *     returning HTTP 429 once exceeded. This is what actually stops a
 *     single visitor/script from hammering the endpoint and exhausting
 *     CPU with unlimited outbound API calls.
 *   - Added indexes on first_seen/last_seen to the guests table so the
 *     admin dashboard's COUNT(*) queries no longer do a full table scan.
 *   - The admin dashboard/Administration-room data (get_admin_dashboard_data())
 *     is now cached for MLP_AI_CHAT_DASHBOARD_CACHE_SECONDS (30s) instead
 *     of being recomputed — including looping every configured model and
 *     hitting the DB — on every single request.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'MLP_AI_CHAT_VERSION',   '1.7.1' );
define( 'MLP_AI_CHAT_API_URL',   'https://your-ai-gateway.example/v1/chat/completions' );

// No auto-login of any kind: logged-out visitors stay logged-out.
// They're identified purely by the random guest token generated in
// their browser and stored in localStorage (see requireGuestIdentity()
// in the front-end script below), which is all this plugin needs to
// keep each visitor's conversations separate. No WP account is ever
// created or signed into on a visitor's behalf.

// Available models: id => [ label, key_constant, provider, is_paid, api_url, api_model ]
// All models below are free. Gateway AI models share the Gateway AI
// endpoint (MLP_AI_CHAT_API_URL); models from other providers specify their
// own 'api_url' key which takes precedence over the Gateway AI default.
// 'api_model' overrides the model name actually sent in the request body,
// for providers whose API expects a different bare name than our id.
define( 'MLP_AI_CHAT_MODELS', serialize( array(
	'aurora-2.5-flash:free' => array(
		'label'     => 'Aurora 2.5 Flash (Free)',
		'key_const' => 'MLP_AURORA_SITE_KEY',
		'provider'  => 'aurora',
		'is_paid'   => false,
		'api_url'   => 'https://api.aurora-ai.example/v1/chat/completions',
		'api_model' => 'aurora-2.5-flash',
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/biglogo.png',
	),
	'edgeai-llama-3.3-70b:free' => array(
		'label'     => 'Llama 3.3 70B (Free)',
		'key_const' => 'MLP_EDGEAI_KEY_TOKEN',
		'provider'  => 'edgeai',
		'is_paid'   => false,
		'api_url'   => 'https://api.edgeai-provider.example/accounts/' . ( defined( 'MLP_EDGEAI_ACCOUNT_ID' ) ? MLP_EDGEAI_ACCOUNT_ID : '' ) . '/ai/v1/chat/completions',
		'api_model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
		'supports_images' => false,
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/lama.jpg',
	),
	'deepseek-v4-pro:free' => array(
		'label'     => 'DeepSeek V4 Pro (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'deepseek/deepseek-v4-pro',
		'supports_images' => false,
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/DeepSeek-Emblem-1.png',
	),
	'deepseek-v4-flash:free' => array(
		'label'     => 'DeepSeek V4 Flash (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'deepseek/deepseek-v4-flash',
		'supports_images' => false,
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/DeepSeek-Emblem-1.png',
	),
	'qwen3.8-max:free' => array(
		'label'     => 'Qwen3.8 Max (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'qwen/qwen3.8-max',
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/Qwen-Ai-Logo-PNG-Vector.png',
	),
	'minimax-m2.7:free' => array(
		'label'     => 'MiniMax M2.7 (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'minimax/minimax-m2.7',
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/minimax.png',
	),
	'minimax-m2.7-highspeed:free' => array(
		'label'     => 'MiniMax M2.7 Highspeed (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'minimax/minimax-m2.7-highspeed',
		'supports_images' => false,
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/minimax.png',
	),
	'minimax-m2.5:free' => array(
		'label'     => 'MiniMax M2.5 (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'minimax/minimax-m2.5',
		'supports_images' => false,
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/minimax.png',
	),
	'minimax-m2.5-highspeed:free' => array(
		'label'     => 'MiniMax M2.5 Highspeed (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'minimax/minimax-m2.5-highspeed',
		'supports_images' => false,
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/minimax.png',
	),
	'minimax-m2.1:free' => array(
		'label'     => 'MiniMax M2.1 (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'minimax/minimax-m2.1',
		'supports_images' => false,
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/minimax.png',
	),
	'minimax-m2.1-highspeed:free' => array(
		'label'     => 'MiniMax M2.1 Highspeed (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'minimax/minimax-m2.1-highspeed',
		'supports_images' => false,
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/minimax.png',
	),
	'minimax-m2:free' => array(
		'label'     => 'MiniMax M2 (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'minimax/minimax-m2',
		'supports_images' => false,
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/minimax.png',
	),
	'mistral-large-3:free' => array(
		'label'     => 'Mistral Large 3 (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'mistralai/mistral-large-2512',
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/announcing-mistral.png',
	),
	'deepseek-v3.2:free' => array(
		'label'     => 'DeepSeek V3.2 (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'deepseek/deepseek-v3.2',
		'supports_images' => false,
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/DeepSeek-Emblem-1.png',
	),
	'deepseek-v3.1:free' => array(
		'label'     => 'DeepSeek V3.1 (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'deepseek/deepseek-chat-v3.1',
		'supports_images' => false,
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/DeepSeek-Emblem-1.png',
	),
	'mistral-medium-3.5:free' => array(
		'label'     => 'Mistral Medium 3.5 (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'mistralai/mistral-medium-3.5',
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/announcing-mistral.png',
	),
	'mistral-small-4:free' => array(
		'label'     => 'Mistral Small 4 (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'mistralai/mistral-small-2603',
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/announcing-mistral.png',
	),
	'codestral:free' => array(
		'label'     => 'Codestral (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'mistralai/codestral-2508',
		'supports_images' => false,
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/announcing-mistral.png',
	),
	'devstral-2:free' => array(
		'label'     => 'Devstral 2 (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'mistralai/devstral-medium',
		'supports_images' => false,
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/announcing-mistral.png',
	),
	'ministral-3-14b:free' => array(
		'label'     => 'Ministral 3 14B (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'mistralai/ministral-14b',
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/announcing-mistral.png',
	),
	'ministral-3-8b:free' => array(
		'label'     => 'Ministral 3 8B (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'mistralai/ministral-8b',
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/announcing-mistral.png',
	),
	'ministral-3-3b:free' => array(
		'label'     => 'Ministral 3 3B (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'mistralai/ministral-3b',
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/announcing-mistral.png',
	),
	'mimo-v2.5-pro:free' => array(
		'label'     => 'MiMo v2.5 Pro (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'xiaomi/mimo-v2.5-pro',
		'supports_images' => false,
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/Capture.jpg',
	),
	'mimo-v2.5:free' => array(
		'label'     => 'MiMo v2.5 (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'xiaomi/mimo-v2.5',
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/Capture.jpg',
	),
	'qwen-plus-0728:free' => array(
		'label'     => 'Qwen Plus 0728 (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'qwen/qwen-plus-0728',
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/Qwen-Ai-Logo-PNG-Vector.png',
	),
	'qwen3-omni-flash:free' => array(
		'label'     => 'Qwen3 Omni Flash (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'qwen/qwen3-omni-flash',
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/Qwen-Ai-Logo-PNG-Vector.png',
	),
	'qwen3-vl-plus:free' => array(
		'label'     => 'Qwen3 VL Plus (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'qwen/qwen3-vl-plus',
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/Qwen-Ai-Logo-PNG-Vector.png',
	),
	'qwen3-max:free' => array(
		'label'     => 'Qwen3 Max (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'qwen/qwen3-max',
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/Qwen-Ai-Logo-PNG-Vector.png',
	),
	'qwen3-coder-plus:free' => array(
		'label'     => 'Qwen3 Coder Plus (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'qwen/qwen3-coder-plus',
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/Qwen-Ai-Logo-PNG-Vector.png',
	),
	'qwen3.5-omni-flash:free' => array(
		'label'     => 'Qwen3.5 Omni Flash (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'qwen/qwen3.5-omni-flash',
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/Qwen-Ai-Logo-PNG-Vector.png',
	),
	'qwen3.5-flash:free' => array(
		'label'     => 'Qwen3.5 Flash (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'qwen/qwen3.5-flash',
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/Qwen-Ai-Logo-PNG-Vector.png',
	),
	'qwen3.5-omni-plus:free' => array(
		'label'     => 'Qwen3.5 Omni Plus (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'qwen/qwen3.5-omni-plus',
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/Qwen-Ai-Logo-PNG-Vector.png',
	),
	'qwen3.5-397b-a17b:free' => array(
		'label'     => 'Qwen3.5 397B A17B VL (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'qwen/qwen3.5-397b-a17b',
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/Qwen-Ai-Logo-PNG-Vector.png',
	),
	'qwen3.5-plus:free' => array(
		'label'     => 'Qwen3.5 Plus (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'qwen/qwen3.5-plus',
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/Qwen-Ai-Logo-PNG-Vector.png',
	),
	'qwen3.6-35b-a3b:free' => array(
		'label'     => 'Qwen3.6 35B A3B (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'qwen/qwen3.6-35b-a3b',
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/Qwen-Ai-Logo-PNG-Vector.png',
	),
	'qwen3.6-27b:free' => array(
		'label'     => 'Qwen3.6 27B (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'qwen/qwen3.6-27b',
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/Qwen-Ai-Logo-PNG-Vector.png',
	),
	'qwen3.6-plus:free' => array(
		'label'     => 'Qwen3.6 Plus (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'qwen/qwen3.6-plus',
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/Qwen-Ai-Logo-PNG-Vector.png',
	),
	'qwen3.6-max-preview:free' => array(
		'label'     => 'Qwen3.6 Max Preview (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'qwen/qwen3.6-max-preview',
		'supports_images' => false,
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/Qwen-Ai-Logo-PNG-Vector.png',
	),
	'qwen3.7-plus:free' => array(
		'label'     => 'Qwen3.7 Plus (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'qwen/qwen3.7-plus',
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/Qwen-Ai-Logo-PNG-Vector.png',
	),
	'qwen3.7-max:free' => array(
		'label'     => 'Qwen3.7 Max (Free)',
		'key_const' => 'MLP_RELAY_KEY_TOKEN',
		'provider'  => 'relay',
		'is_paid'   => false,
		'api_url'   => 'https://api.relay-ai.example/v1/chat/completions',
		'api_model' => 'qwen/qwen3.7-max',
		'supports_images' => false,
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/Qwen-Ai-Logo-PNG-Vector.png',
	),
	'laguna-s-2.1:free' => array(
		'label'     => 'Laguna S 2.1 (Free)',
		'key_const' => 'MLP_MERIDIAN_KEY',
		'provider'  => 'meridian',
		'is_paid'   => false,
		'api_url'   => 'https://router.meridian-ai.example/v1/chat/completions',
		'api_model' => 'laguna-s-2.1',
		'supports_images' => false,
		'logo'      => 'https://ptero.pro/wp-content/uploads/2026/08/Capture-1.jpg',
	),
) ) );

// Available UI / AI-reply languages: code => [ label (shown in the
// language dropdowns, native name first), name (plain English name used
// when telling the AI model which language to reply in), dir (ltr/rtl) ].
// English is the default/primary language. Add more entries here to
// support additional languages — the front end and the AI system prompt
// both read from this single list, so nothing else needs to change.
define( 'MLP_AI_CHAT_LANGUAGES', serialize( array(
	'en' => array( 'label' => 'English',                 'name' => 'English',              'dir' => 'ltr' ),
	'ar' => array( 'label' => 'العربية (Arabic)',         'name' => 'Arabic',                'dir' => 'rtl' ),
	'zh' => array( 'label' => '中文 (Chinese)',            'name' => 'Chinese (Simplified)',  'dir' => 'ltr' ),
	'es' => array( 'label' => 'Español (Spanish)',        'name' => 'Spanish',               'dir' => 'ltr' ),
	'fr' => array( 'label' => 'Français (French)',        'name' => 'French',                'dir' => 'ltr' ),
	'de' => array( 'label' => 'Deutsch (German)',         'name' => 'German',                'dir' => 'ltr' ),
	'pt' => array( 'label' => 'Português (Portuguese)',   'name' => 'Portuguese',             'dir' => 'ltr' ),
	'ru' => array( 'label' => 'Русский (Russian)',        'name' => 'Russian',                'dir' => 'ltr' ),
	'hi' => array( 'label' => 'हिन्दी (Hindi)',            'name' => 'Hindi',                  'dir' => 'ltr' ),
	'ja' => array( 'label' => '日本語 (Japanese)',          'name' => 'Japanese',               'dir' => 'ltr' ),
	'ko' => array( 'label' => '한국어 (Korean)',            'name' => 'Korean',                 'dir' => 'ltr' ),
	'tr' => array( 'label' => 'Türkçe (Turkish)',         'name' => 'Turkish',                'dir' => 'ltr' ),
	'it' => array( 'label' => 'Italiano (Italian)',       'name' => 'Italian',                'dir' => 'ltr' ),
	'id' => array( 'label' => 'Bahasa Indonesia',         'name' => 'Indonesian',             'dir' => 'ltr' ),
) ) );

// Default model (used as fallback).
define( 'MLP_AI_CHAT_DEFAULT_MODEL', 'qwen3.8-max:free' );

// How long (in seconds) a model is taken out of the automatic rotation
// after it fails a request (timeout, error, rate limit, blocked key,
// etc.), before it's eligible to be tried again. During this cooldown
// window, requests for that model (including the default model, if
// that's the one that went down) are automatically routed to the next
// available model instead, and the admin dashboard shows it as
// "Cooling Down".
define( 'MLP_AI_CHAT_UNAVAILABLE_SECONDS', 3 * MINUTE_IN_SECONDS );

// Safety cap on automatic failover: at most this many models are
// actually *called* (real HTTP/cURL request) per chat message, even
// though the candidate list can contain 40-50+ configured models.
// Without this cap, a single incoming message during a provider-wide
// outage could chain through every configured model sequentially —
// each with its own connect timeout — tying up one PHP worker for a
// very long time and burning CPU for nothing once a few candidates
// have already failed. Models beyond this cap are simply left for the
// *next* request to try (they're still eligible, just not in this pass).
define( 'MLP_AI_CHAT_MAX_FAILOVER_ATTEMPTS', 4 );

// Simple per-identity request throttle: at most this many /chat or
// /chat-stream requests are allowed per identity (logged-in user id,
// or guest token, or — as a last-resort fallback — IP) in any rolling
// 60-second window. This is what actually protects the server from a
// single visitor (or a script) hammering the endpoint and burning CPU
// with unlimited outbound API calls; everything else (cooldowns,
// failover caps) only limits how much a single *request* can do.
define( 'MLP_AI_CHAT_RATE_LIMIT_PER_MINUTE', 30 );

// How often (in seconds) the admin dashboard's aggregate stats
// (new-today / all-time guest counts, per-model status list) are
// recomputed from the database. Requests within this window reuse the
// cached result instead of re-running the COUNT(*) queries and looping
// over every configured model, which is what made the dashboard/
// Administration room expensive to load repeatedly.
define( 'MLP_AI_CHAT_DASHBOARD_CACHE_SECONDS', 30 );

/**
 * Main plugin class.
 */
class MLP_AI_Chat {

	private static $instance = null;

	// Cache of the unserialized MLP_AI_CHAT_MODELS / MLP_AI_CHAT_LANGUAGES
	// config. Both are only ever unserialize()'d once per request now
	// (previously ~10+ call sites re-unserialized the ~50-entry models
	// array from scratch, including inside per-model loops, which was
	// a real CPU cost under load). Use $this->get_models() /
	// $this->get_languages() anywhere new code is added — never call
	// unserialize() on these constants directly outside this class.
	private static $models_cache    = null;
	private static $languages_cache = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function get_models() {
		if ( self::$models_cache === null ) {
			self::$models_cache = unserialize( MLP_AI_CHAT_MODELS );
		}
		return self::$models_cache;
	}

	private function get_languages() {
		if ( self::$languages_cache === null ) {
			self::$languages_cache = unserialize( MLP_AI_CHAT_LANGUAGES );
		}
		return self::$languages_cache;
	}

	private function __construct() {
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

		// Run the table/column migration for sites that had an earlier
		// version active (activation hooks don't re-fire on plugin update).
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade_db' ) );

		add_action( 'admin_notices', array( $this, 'maybe_show_missing_key_notice' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_shortcode( 'mlp_ai_chat', array( $this, 'render_shortcode' ) );

		// Twitter/X Card meta tags, output on any page containing the
		// [mlp_ai_chat] shortcode, so dropping the link in a tweet shows
		// a title + description preview under it.
		add_action( 'wp_head', array( $this, 'render_twitter_card_meta' ) );

		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_post_mlp_ai_toggle_disabled', array( $this, 'handle_toggle_disabled' ) );
		add_action( 'admin_post_mlp_ai_toggle_model', array( $this, 'handle_toggle_model' ) );
		add_action( 'admin_post_mlp_ai_reactivate_model', array( $this, 'handle_reactivate_model' ) );
	}

	/* -----------------------------------------------------------------
	 * Activation / DB setup
	 * --------------------------------------------------------------- */

	public function activate() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$guests_table     = $wpdb->prefix . 'mlp_ai_guests';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Chats themselves are never stored server-side (see 1.5.0 note at
		// the top of this file) — the only table left is this one, which
		// tracks each unique guest (logged-out) browser that has set a
		// display name to use the chat, purely for the admin "users"
		// counters. It never contains any message content.
		// Indexes on first_seen/last_seen so the admin dashboard's
		// "new today" / "all users" COUNT(*) queries (get_admin_dashboard_data())
		// can use an index range scan instead of a full table scan as the
		// guests table grows.
		$sql3 = "CREATE TABLE $guests_table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			guest_token VARCHAR(64) NOT NULL,
			username VARCHAR(60) NOT NULL DEFAULT '',
			first_seen DATETIME NOT NULL,
			last_seen DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY guest_token (guest_token),
			KEY first_seen (first_seen),
			KEY last_seen (last_seen)
		) $charset_collate;";

		dbDelta( $sql3 );

		add_option( 'mlp_ai_chat_total_requests', 0, '', false );
		add_option( 'mlp_ai_chat_disabled', '0', '', false );
		add_option( 'mlp_ai_chat_model_disabled', array(), '', false );
		add_option( 'mlp_ai_chat_model_status', array(), '', false );
		add_option( 'mlp_ai_chat_model_feedback', array(), '', false );

		// Sites upgrading from <1.5.0 no longer need the old cron or the
		// old conversations/messages tables; clean both up.
		wp_clear_scheduled_hook( 'mlp_ai_chat_prune_stale' );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mlp_ai_messages" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mlp_ai_conversations" );

		update_option( 'mlp_ai_chat_db_version', MLP_AI_CHAT_VERSION );
	}

	public function deactivate() {
		wp_clear_scheduled_hook( 'mlp_ai_chat_prune_stale' );
	}

	/**
	 * Keeps the DB schema current for sites upgrading from an older version
	 * of the plugin, where activation already ran once before and won't
	 * fire again automatically on a file update.
	 */
	public function maybe_upgrade_db() {
		if ( get_option( 'mlp_ai_chat_db_version' ) === MLP_AI_CHAT_VERSION ) {
			return;
		}
		$this->activate();
	}

	public function maybe_show_missing_key_notice() {
		$msgs = array();
		if ( ! defined( 'MLP_GATEWAY_KEY' ) || ! MLP_GATEWAY_KEY ) {
			$msgs[] = 'Please define <code>MLP_GATEWAY_KEY</code> in your wp-config.php with your Gateway AI API key (looks like <code>gw_live_…</code>, from the <a href="https://your-ai-gateway.example/dashboard" target="_blank" rel="noopener">Gateway AI dashboard</a>).';
		}
		if ( ! defined( "MLP_AI_VECTORSH-Route01-PASS" ) || ! constant( "MLP_AI_VECTORSH-Route01-PASS" ) ) {
			$msgs[] = 'Please define <code>MLP_AI_VECTORSH-Route01-PASS</code> in your wp-config.php with your Vector.sh Route01 API key to enable the Vector.sh Route01 (Free) model.';
		}
		$has_turnstile_site   = defined( 'MLP_TURNSTILE_SITE_KEY' ) && MLP_TURNSTILE_SITE_KEY;
		$has_turnstile_secret = defined( 'MLP_TURNSTILE_SECRET_KEY' ) && MLP_TURNSTILE_SECRET_KEY;
		if ( $has_turnstile_site !== $has_turnstile_secret ) {
			$msgs[] = 'Cloudflare Turnstile is only partially configured: please define both <code>MLP_TURNSTILE_SITE_KEY</code> and <code>MLP_TURNSTILE_SECRET_KEY</code> in your wp-config.php (or remove both) — the first-time join captcha will stay disabled until both are set.';
		}
		if ( $msgs ) {
			echo '<div class="notice notice-error"><p><strong>MLP AI Chat:</strong> ' . implode( ' ', $msgs ) . '</p></div>';
		}
	}

	/* -----------------------------------------------------------------
	 * Admin page (optional convenience page that also renders the chat)
	 * --------------------------------------------------------------- */

	public function register_admin_page() {
		add_menu_page(
			'AI Chat',
			'AI Chat',
			'read',
			'chat-ai-chat',
			array( $this, 'render_admin_page' ),
			'dashicons-format-chat',
			30
		);

		add_submenu_page(
			'chat-ai-chat',
			'AI Chat Dashboard',
			'Dashboard',
			'manage_options',
			'chat-ai-chat-dashboard',
			array( $this, 'render_dashboard_page' )
		);
	}

	public function render_admin_page() {
		echo '<div class="wrap"><h1 style="margin-bottom:10px;">AI Chat</h1>';
		echo $this->render_shortcode( array() );
		echo '</div>';
	}

	/**
	 * Toggles the site-wide "AI disabled" switch. Hooked to admin-post.php
	 * so the dashboard's button works with a plain form submit.
	 */
	public function handle_toggle_disabled() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to do this.' );
		}
		check_admin_referer( 'mlp_ai_toggle_disabled' );

		$new_state = $this->is_ai_disabled() ? '0' : '1';
		update_option( 'mlp_ai_chat_disabled', $new_state );
		$this->invalidate_admin_dashboard_cache();

		wp_safe_redirect( add_query_arg( 'mlp_toggled', '1', wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=chat-ai-chat-dashboard' ) ) );
		exit;
	}

	/**
	 * Toggles a single model's "disabled by admin" flag. Hooked to
	 * admin-post.php for the plain wp-admin dashboard page's forms.
	 */
	public function handle_toggle_model() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to do this.' );
		}
		check_admin_referer( 'mlp_ai_toggle_model' );

		$model_id = isset( $_POST['model_id'] ) ? sanitize_text_field( wp_unslash( $_POST['model_id'] ) ) : '';
		if ( $model_id ) {
			$this->toggle_model_disabled( $model_id );
			$this->invalidate_admin_dashboard_cache();
		}

		wp_safe_redirect( add_query_arg( 'mlp_toggled', '1', wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=chat-ai-chat-dashboard' ) ) );
		exit;
	}

	/**
	 * Force a model back into service right away, even if it's mid
	 * auto-cooldown or last reported Error/Offline/Blocked/Rate Limited.
	 * Hooked to admin-post.php for the plain wp-admin dashboard page's
	 * forms. This does NOT touch the separate manual "Disabled" switch —
	 * an admin-disabled model still needs the Enable button, not this one.
	 */
	public function handle_reactivate_model() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to do this.' );
		}
		check_admin_referer( 'mlp_ai_reactivate_model' );

		$model_id = isset( $_POST['model_id'] ) ? sanitize_text_field( wp_unslash( $_POST['model_id'] ) ) : '';
		if ( $model_id ) {
			$this->reactivate_model( $model_id );
			$this->invalidate_admin_dashboard_cache();
		}

		wp_safe_redirect( add_query_arg( 'mlp_toggled', '1', wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=chat-ai-chat-dashboard' ) ) );
		exit;
	}

	/**
	 * Admin-only dashboard: AI status, usage stats, and user counters.
	 */
	public function render_dashboard_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to access this page.' );
		}

		$data       = $this->get_admin_dashboard_data();
		$toggle_url = wp_nonce_url(
			add_query_arg( 'action', 'mlp_ai_toggle_disabled', admin_url( 'admin-post.php' ) ),
			'mlp_ai_toggle_disabled'
		);
		$state_colors = array(
			'online'       => '#00a32a',
			'rate_limited' => '#dba617',
			'blocked'      => '#d63638',
			'error'        => '#d63638',
			'offline'      => '#787c82',
			'cooldown'     => '#dba617',
			'disabled'     => '#d63638',
			'unknown'      => '#787c82',
		);
		?>
		<div class="wrap">
			<h1 style="margin-bottom:20px;">AI Chat Dashboard</h1>
			<p>This same dashboard is also built into the chat itself — open the chat and look for <strong>Administration</strong> in the sidebar.</p>

			<?php if ( isset( $_GET['mlp_toggled'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Setting updated.</p></div>
			<?php endif; ?>

			<div style="display:flex; gap:16px; flex-wrap:wrap; margin-bottom:24px;">
				<div style="background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:20px; min-width:220px; flex:1;">
					<div style="font-size:13px; color:#646970; text-transform:uppercase; letter-spacing:.03em; margin-bottom:8px;">AI Status</div>
					<div style="font-size:22px; font-weight:600; color:<?php echo $data['disabled'] ? '#d63638' : '#00a32a'; ?>;">
						<?php echo $data['disabled'] ? 'Disabled' : 'Enabled'; ?>
					</div>
				</div>
				<div style="background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:20px; min-width:220px; flex:1;">
					<div style="font-size:13px; color:#646970; text-transform:uppercase; letter-spacing:.03em; margin-bottom:8px;">Total Requests</div>
					<div style="font-size:22px; font-weight:600;"><?php echo esc_html( number_format_i18n( $data['total_requests'] ) ); ?></div>
				</div>
				<div style="background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:20px; min-width:220px; flex:1;">
					<div style="font-size:13px; color:#646970; text-transform:uppercase; letter-spacing:.03em; margin-bottom:8px;">New Users Today</div>
					<div style="font-size:22px; font-weight:600;"><?php echo esc_html( number_format_i18n( $data['new_today'] ) ); ?></div>
				</div>
				<div style="background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:20px; min-width:220px; flex:1;">
					<div style="font-size:13px; color:#646970; text-transform:uppercase; letter-spacing:.03em; margin-bottom:8px;">All Users</div>
					<div style="font-size:22px; font-weight:600;"><?php echo esc_html( number_format_i18n( $data['all_users'] ) ); ?></div>
					<div style="font-size:12px; color:#646970; margin-top:4px;">Visitors who set a name to use the AI chat.</div>
				</div>
			</div>

			<div style="background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:20px; max-width:640px; margin-bottom:24px;">
				<h2 style="margin-top:0;">Global Controls</h2>
				<p>When disabled, sending new messages is blocked for every visitor (logged in or not), for every model.</p>
				<form method="post" action="<?php echo esc_url( $toggle_url ); ?>">
					<button type="submit" class="button <?php echo $data['disabled'] ? 'button-primary' : 'button-secondary'; ?>">
						<?php echo $data['disabled'] ? 'Re-enable AI Chat' : 'Disable AI Chat'; ?>
					</button>
				</form>
			</div>

			<div style="background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:20px; max-width:820px;">
				<h2 style="margin-top:0;">Models</h2>
				<table class="widefat striped" style="max-width:780px;">
					<thead><tr><th>Model</th><th>Status</th><th>Last checked</th><th>👍 Likes</th><th>👎 Dislikes</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $data['models'] as $m ) :
						$model_toggle_url = wp_nonce_url(
							add_query_arg( array( 'action' => 'mlp_ai_toggle_model', 'model_id' => $m['id'] ), admin_url( 'admin-post.php' ) ),
							'mlp_ai_toggle_model'
						);
						// "Hidden from visitors" states: not manually disabled, but
						// currently unusable/cooling down after a failure — this is
						// what a bare Enable/Disable toggle can't fix, since Enable
						// only applies to the manual switch.
						$is_hidden_by_error = ! $m['disabled'] && in_array( $m['state'], array( 'error', 'offline', 'blocked', 'rate_limited', 'cooldown' ), true );
						if ( $is_hidden_by_error ) {
							$model_reactivate_url = wp_nonce_url(
								add_query_arg( array( 'action' => 'mlp_ai_reactivate_model', 'model_id' => $m['id'] ), admin_url( 'admin-post.php' ) ),
								'mlp_ai_reactivate_model'
							);
						}
						?>
						<tr>
							<td><?php echo esc_html( $m['label'] ); ?></td>
							<td>
								<span style="display:inline-block; width:9px; height:9px; border-radius:50%; margin-right:6px; background:<?php echo esc_attr( $state_colors[ $m['state'] ] ); ?>;"></span>
								<?php echo esc_html( $m['state_label'] ); ?>
								<?php if ( $m['message'] ) : ?><div style="font-size:11px; color:#787c82;"><?php echo esc_html( $m['message'] ); ?></div><?php endif; ?>
								<?php if ( $is_hidden_by_error ) : ?><div style="font-size:11px; color:#d63638;">Hidden from visitors</div><?php endif; ?>
							</td>
							<td><?php echo $m['last_checked'] ? esc_html( human_time_diff( strtotime( $m['last_checked'] ), current_time( 'timestamp' ) ) ) . ' ago' : '—'; ?></td>
							<td style="color:#00a32a; font-weight:600;"><?php echo esc_html( number_format_i18n( $m['likes'] ) ); ?></td>
							<td style="color:#d63638; font-weight:600;"><?php echo esc_html( number_format_i18n( $m['dislikes'] ) ); ?></td>
							<td style="white-space:nowrap;">
								<form method="post" action="<?php echo esc_url( $model_toggle_url ); ?>" style="display:inline-block;">
									<button type="submit" class="button button-small"><?php echo $m['disabled'] ? 'Enable' : 'Disable'; ?></button>
								</form>
								<?php if ( $is_hidden_by_error ) : ?>
									<form method="post" action="<?php echo esc_url( $model_reactivate_url ); ?>" style="display:inline-block; margin-left:4px;">
										<button type="submit" class="button button-small button-primary" title="Clear the error/cooldown and make this model visible to users again">Reactivate</button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Shared data source for both the wp-admin dashboard page and the
	 * REST /admin/status endpoint that powers the in-chat Administration
	 * room, so the two stay in sync automatically.
	 */
	private function get_admin_dashboard_data() {
		// This recomputes two COUNT(*) queries plus a loop over every
		// configured model (50+), so it's cached for a short window
		// instead of being rebuilt on every wp-admin dashboard load and
		// every poll of the in-chat Administration room / /admin/status
		// endpoint. A stale-by-at-most-30s view of admin stats is a fine
		// trade for not re-running this on every request.
		$cache_key = 'mlp_ai_chat_dashboard_data';
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$guests_table = $wpdb->prefix . 'mlp_ai_guests';

		$today_start = current_time( 'Y-m-d' ) . ' 00:00:00';
		$new_today   = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM $guests_table WHERE first_seen >= %s", $today_start )
		);
		$all_users = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $guests_table" );

		$state_labels = array(
			'online'       => 'Online',
			'rate_limited' => 'Rate Limited',
			'blocked'      => 'Blocked',
			'error'        => 'Error',
			'offline'      => 'Offline',
			'cooldown'     => 'Cooling Down (auto)',
			'disabled'     => 'Disabled',
			'unknown'      => 'Unknown (not used yet)',
		);

		$models        = $this->get_models();
		$status_map    = $this->get_model_status_map();
		$disabled_map  = $this->get_model_disabled_map();
		$feedback_map  = $this->get_model_feedback_map();
		$models_out    = array();

		foreach ( $models as $id => $cfg ) {
			$is_disabled   = ! empty( $disabled_map[ $id ] );
			$has_key       = defined( $cfg['key_const'] ) && constant( $cfg['key_const'] );
			$row           = isset( $status_map[ $id ] ) ? $status_map[ $id ] : array();
			$state         = isset( $row['state'] ) ? $row['state'] : 'unknown';
			$fb            = isset( $feedback_map[ $id ] ) ? $feedback_map[ $id ] : array();
			$cooldown_left = $this->model_unavailable_seconds_left( $id );
			$message       = isset( $row['message'] ) ? $row['message'] : '';

			if ( $is_disabled ) {
				$state   = 'disabled';
				$message = 'Disabled by admin';
			} elseif ( ! $has_key ) {
				$state   = 'offline';
				$message = 'API key not configured';
			} elseif ( $cooldown_left > 0 ) {
				// Failed recently and is being skipped by the automatic
				// fallback for a bit, but isn't manually disabled — will
				// resume being tried again once the cooldown ends.
				$state   = 'cooldown';
				$message = trim( ( $message ? $message . ' — ' : '' ) . 'retrying automatically in ' . ceil( $cooldown_left / 60 ) . ' min' );
			}

			$models_out[] = array(
				'id'             => $id,
				'label'          => $cfg['label'],
				'configured'     => $has_key,
				'disabled'       => $is_disabled,
				'state'          => $state,
				'state_label'    => isset( $state_labels[ $state ] ) ? $state_labels[ $state ] : ucfirst( $state ),
				'message'        => $message,
				'last_checked'   => isset( $row['last_checked'] ) ? $row['last_checked'] : '',
				'cooldown_left'  => $cooldown_left,
				'likes'          => isset( $fb['likes'] ) ? (int) $fb['likes'] : 0,
				'dislikes'       => isset( $fb['dislikes'] ) ? (int) $fb['dislikes'] : 0,
			);
		}

		$result = array(
			'disabled'       => $this->is_ai_disabled(),
			'total_requests' => (int) get_option( 'mlp_ai_chat_total_requests', 0 ),
			'new_today'      => $new_today,
			'all_users'      => $all_users,
			'models'         => $models_out,
		);

		set_transient( $cache_key, $result, MLP_AI_CHAT_DASHBOARD_CACHE_SECONDS );

		return $result;
	}

	/**
	 * Invalidates the cached dashboard data immediately, so admin actions
	 * that change it (toggling the global switch or a model, a model's
	 * status/cooldown changing after a live call) are reflected right
	 * away instead of waiting out the cache window.
	 */
	private function invalidate_admin_dashboard_cache() {
		delete_transient( 'mlp_ai_chat_dashboard_data' );
	}

	/* -----------------------------------------------------------------
	 * REST API
	 * --------------------------------------------------------------- */

	public function register_routes() {
		// NOTE: There are intentionally no /conversations or /messages
		// routes. As of 1.5.0 chats live only in the browser's
		// localStorage — the server never reads or writes conversation
		// content, so there is nothing to expose an endpoint for.

		register_rest_route(
			'mlp/v1',
			'/chat',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'rest_chat' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
			)
		);

		register_rest_route(
			'mlp/v1',
			'/chat-stream',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'rest_chat_stream' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
			)
		);

		// Public, read-only: tells the front-end whether an admin has
		// disabled the chat, so it can grey out the UI before anyone
		// tries to send a message.
		register_rest_route(
			'mlp/v1',
			'/status',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'rest_status' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		// Public: records a like/dislike vote for a model. Same trust model
		// as /chat — open to guests, nonce-protected for cookie auth. Only
		// a model id + 'like'/'dislike' is accepted; no message content.
		register_rest_route(
			'mlp/v1',
			'/feedback',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'rest_feedback' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
			)
		);

		// Public: verifies a Cloudflare Turnstile token for the username
		// modal (first-time guests only). Only checks the token against
		// Cloudflare's siteverify API — it never touches identity/guest
		// data itself, that still happens via the existing header-based
		// flow once the client proceeds.
		register_rest_route(
			'mlp/v1',
			'/verify-turnstile',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'rest_verify_turnstile' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
			)
		);

		// Admin-only: powers the in-chat "Administration" sidebar room.
		register_rest_route(
			'mlp/v1',
			'/admin/status',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'rest_admin_status' ),
					'permission_callback' => array( $this, 'permission_check_admin' ),
				),
			)
		);
		register_rest_route(
			'mlp/v1',
			'/admin/toggle-global',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'rest_admin_toggle_global' ),
					'permission_callback' => array( $this, 'permission_check_admin' ),
				),
			)
		);
		register_rest_route(
			'mlp/v1',
			'/admin/toggle-model',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'rest_admin_toggle_model' ),
					'permission_callback' => array( $this, 'permission_check_admin' ),
				),
			)
		);
		register_rest_route(
			'mlp/v1',
			'/admin/reactivate-model',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'rest_admin_reactivate_model' ),
					'permission_callback' => array( $this, 'permission_check_admin' ),
				),
			)
		);

	}

	public function permission_check() {
		// Open to everyone, logged in or not. WordPress still validates the
		// X-WP-Nonce header for cookie-authenticated requests, so this stays
		// CSRF-protected; ownership of conversations is enforced separately
		// via resolve_identity() above and the client's own localStorage.
		return true;
	}

	/**
	 * Works out "who" is making this request without requiring a WP login:
	 * logged-in users are identified by their user ID as before; guests are
	 * identified by a random token the front-end generates once and stores
	 * in localStorage, sent as the X-MLP-Guest-Token header. If a display
	 * name is also sent (X-MLP-Guest-Username), it's recorded so the admin
	 * "users" counters can pick it up.
	 *
	 * @param WP_REST_Request $request
	 * @return array{user_id:int, guest_token:string}
	 */
	private function resolve_identity( WP_REST_Request $request ) {
		$user_id = get_current_user_id();

		if ( $user_id ) {
			return array( 'user_id' => $user_id, 'guest_token' => '' );
		}

		$raw_token   = $request->get_header( 'x-mlp-guest-token' );
		$guest_token = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $raw_token );
		$guest_token = substr( $guest_token, 0, 64 );

		$raw_username = $request->get_header( 'x-mlp-guest-username' );
		if ( $guest_token && $raw_username ) {
			$this->upsert_guest( $guest_token, sanitize_text_field( $raw_username ) );
		}

		return array( 'user_id' => 0, 'guest_token' => $guest_token );
	}

	/**
	 * Permission callback for the /admin/* REST routes: only users with
	 * manage_options (WP admins) may view or change AI status.
	 */
	public function permission_check_admin() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Records/updates a guest's display name, preserving their original
	 * first_seen date, for the admin dashboard's user counters.
	 */
	private function upsert_guest( $guest_token, $username ) {
		global $wpdb;
		$table = $wpdb->prefix . 'mlp_ai_guests';
		$now   = current_time( 'mysql' );
		$username = mb_substr( $username, 0, 60 );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO $table (guest_token, username, first_seen, last_seen) VALUES (%s, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE username = VALUES(username), last_seen = VALUES(last_seen)",
				$guest_token,
				$username,
				$now,
				$now
			)
		);
	}

	/**
	 * Whether an admin has disabled the AI chat for everyone.
	 */
	private function is_ai_disabled() {
		return get_option( 'mlp_ai_chat_disabled', '0' ) === '1';
	}

	/**
	 * Bumps the all-time "total requests" counter shown in the admin
	 * dashboard. Called once per successful AI call.
	 */
	private function increment_total_requests() {
		$current = (int) get_option( 'mlp_ai_chat_total_requests', 0 );
		update_option( 'mlp_ai_chat_total_requests', $current + 1 );
	}

	/**
	 * Works out the identity key used to rate-limit /chat and
	 * /chat-stream: the logged-in user id when there is one, otherwise
	 * the per-browser guest token, otherwise (guest token missing/
	 * stripped) falls back to the request IP so the endpoint still can't
	 * be hammered anonymously.
	 */
	private function get_rate_limit_identity( array $identity ) {
		if ( ! empty( $identity['user_id'] ) ) {
			return 'u:' . $identity['user_id'];
		}
		if ( ! empty( $identity['guest_token'] ) ) {
			return 'g:' . $identity['guest_token'];
		}
		$ip = ! empty( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		return 'ip:' . $ip;
	}

	/**
	 * Simple fixed-window rate limiter backed by a transient (no extra DB
	 * table, no cron needed — transients expire on their own). Allows at
	 * most MLP_AI_CHAT_RATE_LIMIT_PER_MINUTE requests per identity in any
	 * rolling 60-second window; returns true while under the limit (and
	 * records this request), false once the window's quota is used up.
	 *
	 * This is the main defense against a single visitor/script hammering
	 * /chat or /chat-stream: each request otherwise triggers a full PHP
	 * request cycle plus at least one outbound API call, so unlimited
	 * requests translate directly into unlimited CPU + outbound traffic.
	 */
	private function check_rate_limit( $identity_key ) {
		$key  = 'mlp_ai_rl_' . md5( $identity_key );
		$now  = time();
		$data = get_transient( $key );

		if ( ! is_array( $data ) || empty( $data['reset_at'] ) || $now >= $data['reset_at'] ) {
			$data = array( 'count' => 0, 'reset_at' => $now + MINUTE_IN_SECONDS );
		}

		$data['count']++;
		$ttl = max( 1, $data['reset_at'] - $now );
		set_transient( $key, $data, $ttl );

		return $data['count'] <= MLP_AI_CHAT_RATE_LIMIT_PER_MINUTE;
	}

	public function rest_status( WP_REST_Request $request ) {
		$disabled_ids   = array_keys( array_filter( $this->get_model_disabled_map() ) );
		$cooldown_ids   = $this->get_temporarily_unavailable_model_ids();

		return rest_ensure_response( array(
			'disabled'         => $this->is_ai_disabled(),
			// Merged so the visitor-facing model picker greys out (and
			// auto-switches away from) both admin-disabled models and
			// models that are temporarily cooling down after a failure.
			'disabled_models'  => array_values( array_unique( array_merge( $disabled_ids, $cooldown_ids ) ) ),
			'cooldown_models'  => $cooldown_ids,
			'default_model'    => MLP_AI_CHAT_DEFAULT_MODEL,
		) );
	}

	/**
	 * Verifies a Cloudflare Turnstile token from the first-time username
	 * modal against Cloudflare's siteverify API. Requires
	 * MLP_TURNSTILE_SECRET_KEY to be defined in wp-config.php; if it isn't,
	 * this always fails closed (rather than silently accepting anything),
	 * so a misconfigured secret can't be used to bypass the captcha.
	 */
	public function rest_verify_turnstile( WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'token' );

		if ( ! $token ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'missing_token' ) );
		}

		if ( ! defined( 'MLP_TURNSTILE_SECRET_KEY' ) || ! MLP_TURNSTILE_SECRET_KEY ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'not_configured' ) );
		}

		$remote_ip = '';
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$remote_ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		$response = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => MLP_TURNSTILE_SECRET_KEY,
					'response' => $token,
					'remoteip' => $remote_ip,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'verify_request_failed' ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['success'] ) ) {
			return rest_ensure_response( array(
				'success'    => false,
				'error'      => 'verify_failed',
				'error_codes' => isset( $body['error-codes'] ) ? $body['error-codes'] : array(),
			) );
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Records (or retracts) a like/dislike vote for a model. Body:
	 *   model_id (string, required) — must be one of MLP_AI_CHAT_MODELS
	 *   type     (string, required) — 'like' or 'dislike'
	 *   action   (string, optional) — 'add' (default) or 'remove', so the
	 *            front end can let a visitor toggle their vote off/switch
	 *            it without ever double-counting.
	 */
	public function rest_feedback( WP_REST_Request $request ) {
		$model_id = sanitize_text_field( (string) $request->get_param( 'model_id' ) );
		$type     = (string) $request->get_param( 'type' );
		$action   = (string) $request->get_param( 'action' );

		$models = $this->get_models();
		if ( ! $model_id || ! isset( $models[ $model_id ] ) ) {
			return new WP_Error( 'mlp_bad_model', 'Unknown model.', array( 'status' => 400 ) );
		}
		if ( ! in_array( $type, array( 'like', 'dislike' ), true ) ) {
			return new WP_Error( 'mlp_bad_type', "type must be 'like' or 'dislike'.", array( 'status' => 400 ) );
		}
		$delta = ( 'remove' === $action ) ? -1 : 1;

		$counts = $this->adjust_model_feedback( $model_id, $type, $delta );

		return rest_ensure_response( array(
			'model_id' => $model_id,
			'likes'    => (int) $counts['likes'],
			'dislikes' => (int) $counts['dislikes'],
		) );
	}

	/* -----------------------------------------------------------------
	 * Per-model status / disabled tracking (contentless — no chat data)
	 * --------------------------------------------------------------- */

	private function get_model_disabled_map() {
		$map = get_option( 'mlp_ai_chat_model_disabled', array() );
		return is_array( $map ) ? $map : array();
	}

	private function is_model_disabled( $model_id ) {
		$map = $this->get_model_disabled_map();
		return ! empty( $map[ $model_id ] );
	}

	private function toggle_model_disabled( $model_id ) {
		$map               = $this->get_model_disabled_map();
		$map[ $model_id ]  = empty( $map[ $model_id ] );
		update_option( 'mlp_ai_chat_model_disabled', $map );
		return $map[ $model_id ];
	}

	/* -----------------------------------------------------------------
	 * Automatic failover: when a model's API call fails, it's placed in
	 * a short "cooldown" (MLP_AI_CHAT_UNAVAILABLE_SECONDS) during which
	 * it's skipped in favor of the next configured model — this applies
	 * even if the failing model is the configured default. It's
	 * separate from the admin's manual per-model Disable switch above;
	 * a model recovers from a cooldown on its own once the window
	 * passes, whereas a manual disable stays off until re-enabled.
	 * --------------------------------------------------------------- */

	private function get_model_unavailable_map() {
		$map = get_option( 'mlp_ai_chat_model_unavailable_until', array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Puts a model in cooldown for MLP_AI_CHAT_UNAVAILABLE_SECONDS,
	 * starting now, so it's skipped by resolve_available_model() /
	 * get_candidate_models() until the window passes.
	 *
	 * Only (re)starts the timer if the model isn't already cooling down.
	 * Without this guard, every retry that hits the same down model
	 * (users retrying by hand, or the automatic fallback loop trying it
	 * again on the next message) would push the expiry another 3 minutes
	 * into the future, so a model getting hit repeatedly during an
	 * outage could stay hidden from the model picker indefinitely instead
	 * of coming back after 3 minutes as intended.
	 */
	private function mark_model_unavailable( $model_id ) {
		$map = $this->get_model_unavailable_map();
		if ( isset( $map[ $model_id ] ) && (int) $map[ $model_id ] > time() ) {
			return; // Already cooling down — leave the existing expiry alone.
		}
		$map[ $model_id ] = time() + MLP_AI_CHAT_UNAVAILABLE_SECONDS;
		update_option( 'mlp_ai_chat_model_unavailable_until', $map );
	}

	/**
	 * Clears a model's cooldown early — used once a model answers
	 * successfully again, so it doesn't sit "cooling down" in the admin
	 * view after it has already recovered.
	 */
	private function clear_model_unavailable( $model_id ) {
		$map = $this->get_model_unavailable_map();
		if ( isset( $map[ $model_id ] ) ) {
			unset( $map[ $model_id ] );
			update_option( 'mlp_ai_chat_model_unavailable_until', $map );
		}
	}

	private function is_model_temporarily_unavailable( $model_id ) {
		return $this->model_unavailable_seconds_left( $model_id ) > 0;
	}

	/**
	 * Admin override: immediately makes a model available to visitors
	 * again, without waiting for its automatic cooldown to expire. Clears
	 * the cooldown (see mark_model_unavailable()) and resets the last
	 * recorded status (Error / Offline / Blocked / Rate Limited) back to
	 * "unknown", so it stops being merged into the /status endpoint's
	 * disabled_models list and reappears in the visitor-facing model
	 * picker right away. If the underlying problem (e.g. a bad/missing
	 * API key, or the provider still actually being down) hasn't been
	 * fixed, the very next failed request will simply put it back into
	 * cooldown. This is separate from — and does not change — the manual
	 * "Disabled by admin" switch.
	 */
	private function reactivate_model( $model_id ) {
		$this->clear_model_unavailable( $model_id );
		$this->set_model_status( $model_id, 'unknown', '' );
	}

	/**
	 * Seconds remaining in a model's cooldown, or 0 if it's not
	 * currently in one.
	 */
	private function model_unavailable_seconds_left( $model_id ) {
		$map = $this->get_model_unavailable_map();
		if ( empty( $map[ $model_id ] ) ) {
			return 0;
		}
		return max( 0, (int) $map[ $model_id ] - time() );
	}

	/**
	 * All model IDs currently in cooldown (used to also grey them out
	 * in the visitor-facing model picker, same as an admin-disabled
	 * model, via /status).
	 */
	private function get_temporarily_unavailable_model_ids() {
		$out = array();
		foreach ( array_keys( $this->get_model_unavailable_map() ) as $id ) {
			if ( $this->is_model_temporarily_unavailable( $id ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Whether a model can currently be used at all: not manually
	 * disabled by an admin, not in an automatic failure cooldown, and
	 * has an API key configured.
	 */
	private function is_model_available( $model_id ) {
		$models = $this->get_models();
		if ( ! isset( $models[ $model_id ] ) ) {
			return false;
		}
		if ( $this->is_model_disabled( $model_id ) ) {
			return false;
		}
		if ( $this->is_model_temporarily_unavailable( $model_id ) ) {
			return false;
		}
		if ( is_wp_error( $this->get_api_key_for_model( $model_id ) ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Builds the ordered list of models to try for a request: the
	 * requested/preferred model first, then the site default (if it's
	 * not already the preferred one — this is what makes the default
	 * model fail over too when it's the one that's down), then every
	 * other configured model in definition order.
	 *
	 * @param string $preferred_id The visitor's selected (or default) model.
	 * @return string[] Ordered, de-duplicated candidate model IDs.
	 */
	private function get_candidate_models( $preferred_id ) {
		$models = $this->get_models();
		$order  = array();

		if ( isset( $models[ $preferred_id ] ) ) {
			$order[] = $preferred_id;
		}
		if ( MLP_AI_CHAT_DEFAULT_MODEL !== $preferred_id && isset( $models[ MLP_AI_CHAT_DEFAULT_MODEL ] ) ) {
			$order[] = MLP_AI_CHAT_DEFAULT_MODEL;
		}
		foreach ( array_keys( $models ) as $id ) {
			if ( ! in_array( $id, $order, true ) ) {
				$order[] = $id;
			}
		}
		return $order;
	}

	/**
	 * Picks the first currently-available model out of the candidate
	 * order for $preferred_id, or null if every configured model is
	 * disabled/cooling down/unconfigured.
	 */
	private function resolve_available_model( $preferred_id ) {
		foreach ( $this->get_candidate_models( $preferred_id ) as $id ) {
			if ( $this->is_model_available( $id ) ) {
				return $id;
			}
		}
		return null;
	}

	/**
	 * Classifies + records a failed API call against $model_id and puts
	 * it into cooldown so the next request automatically routes around
	 * it. Returns [state, message] (see classify_api_failure()).
	 */
	private function record_model_failure( $model_id, $http_code, $message ) {
		list( $state, $status_msg ) = $this->classify_api_failure( $http_code, $message );
		$this->set_model_status( $model_id, $state, $status_msg );
		$this->mark_model_unavailable( $model_id );
		return array( $state, $status_msg );
	}

	private function get_model_status_map() {
		$map = get_option( 'mlp_ai_chat_model_status', array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Per-model like/dislike tallies shown in the admin dashboard.
	 * Contentless by design (matches the rest of this file, see the
	 * 1.5.0 note at the top): the server only ever receives a model id
	 * and 'like'/'dislike', never the message text being rated.
	 */
	private function get_model_feedback_map() {
		$map = get_option( 'mlp_ai_chat_model_feedback', array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Adjusts a model's like or dislike counter by +1/-1. $delta is
	 * clamped so a stray "remove" (e.g. duplicate click, or racing
	 * requests) can never push a counter below zero.
	 */
	private function adjust_model_feedback( $model_id, $type, $delta ) {
		$map = $this->get_model_feedback_map();
		if ( ! isset( $map[ $model_id ] ) ) {
			$map[ $model_id ] = array( 'likes' => 0, 'dislikes' => 0 );
		}
		$key = ( 'dislike' === $type ) ? 'dislikes' : 'likes';
		$current = isset( $map[ $model_id ][ $key ] ) ? (int) $map[ $model_id ][ $key ] : 0;
		$map[ $model_id ][ $key ] = max( 0, $current + $delta );
		update_option( 'mlp_ai_chat_model_feedback', $map );
		return $map[ $model_id ];
	}

	/**
	 * Records the outcome of the most recent call to a given model, so
	 * the admin dashboard can show a live-ish Online/Rate Limited/
	 * Blocked/Error status without storing any chat content.
	 */
	private function set_model_status( $model_id, $state, $message = '' ) {
		$map              = $this->get_model_status_map();
		$map[ $model_id ] = array(
			'state'        => $state,
			'message'      => $message,
			'last_checked' => current_time( 'mysql' ),
		);
		update_option( 'mlp_ai_chat_model_status', $map );
	}

	/**
	 * Classifies an API failure into one of our status states based on
	 * the HTTP code / error text returned.
	 */
	private function classify_api_failure( $http_code, $message ) {
		if ( $http_code === 429 || stripos( $message, 'rate limit' ) !== false || stripos( $message, 'quota' ) !== false ) {
			return array( 'rate_limited', 'Rate limited by provider' . ( $message ? ': ' . $message : '' ) );
		}
		if ( $http_code === 401 || $http_code === 403 ) {
			return array( 'blocked', 'API key rejected/blocked by provider' . ( $message ? ': ' . $message : '' ) );
		}
		if ( $http_code === 0 || ! $http_code ) {
			return array( 'offline', $message ? $message : 'Could not reach the provider' );
		}
		return array( 'error', $message ? $message : ( 'HTTP ' . $http_code ) );
	}

	public function rest_admin_status( WP_REST_Request $request ) {
		return rest_ensure_response( $this->get_admin_dashboard_data() );
	}

	public function rest_admin_toggle_global( WP_REST_Request $request ) {
		$new_state = $this->is_ai_disabled() ? '0' : '1';
		update_option( 'mlp_ai_chat_disabled', $new_state );
		$this->invalidate_admin_dashboard_cache();
		return rest_ensure_response( $this->get_admin_dashboard_data() );
	}

	public function rest_admin_toggle_model( WP_REST_Request $request ) {
		$params   = $request->get_json_params();
		$model_id = isset( $params['model_id'] ) ? sanitize_text_field( $params['model_id'] ) : '';
		$models   = $this->get_models();

		if ( ! $model_id || ! isset( $models[ $model_id ] ) ) {
			return new WP_Error( 'unknown_model', 'Unknown model: ' . $model_id, array( 'status' => 400 ) );
		}

		$this->toggle_model_disabled( $model_id );
		$this->invalidate_admin_dashboard_cache();
		return rest_ensure_response( $this->get_admin_dashboard_data() );
	}

	/**
	 * REST counterpart to handle_reactivate_model(): forces a model that's
	 * mid-cooldown (Error / Offline / Blocked / Rate Limited) back into
	 * service immediately, powering the "Reactivate" button in the
	 * in-chat Administration room.
	 */
	public function rest_admin_reactivate_model( WP_REST_Request $request ) {
		$params   = $request->get_json_params();
		$model_id = isset( $params['model_id'] ) ? sanitize_text_field( $params['model_id'] ) : '';
		$models   = $this->get_models();

		if ( ! $model_id || ! isset( $models[ $model_id ] ) ) {
			return new WP_Error( 'unknown_model', 'Unknown model: ' . $model_id, array( 'status' => 400 ) );
		}

		$this->reactivate_model( $model_id );
		$this->invalidate_admin_dashboard_cache();
		return rest_ensure_response( $this->get_admin_dashboard_data() );
	}

	/**
	 * Returns the API key constant value for a given model ID, or WP_Error if
	 * the model is unknown or its key is not configured in wp-config.php.
	 *
	 * @param string $model_id The model identifier string.
	 * @return string|WP_Error API key string, or WP_Error.
	 */
	private function get_api_key_for_model( $model_id ) {
		$models = $this->get_models();

		if ( ! isset( $models[ $model_id ] ) ) {
			return new WP_Error( 'unknown_model', 'Unknown model: ' . $model_id, array( 'status' => 400 ) );
		}

		$key_const = $models[ $model_id ]['key_const'];

		if ( ! defined( $key_const ) || ! constant( $key_const ) ) {
			return new WP_Error( 'no_api_key', 'API key constant ' . $key_const . ' is not configured in wp-config.php.', array( 'status' => 500 ) );
		}

		return constant( $key_const );
	}

	/**
	 * Returns the API endpoint URL for a given model ID.
	 *
	 * @param string $model_id The model identifier string.
	 * @return string API endpoint URL.
	 */
	private function get_api_url_for_model( $model_id ) {
		$models = $this->get_models();

		if ( isset( $models[ $model_id ]['api_url'] ) && $models[ $model_id ]['api_url'] ) {
			return $models[ $model_id ]['api_url'];
		}

		return MLP_AI_CHAT_API_URL;
	}

	/**
	 * Returns the model name to send in the API request body for a given
	 * plugin model ID. Usually the same as the ID itself, but some
	 * providers (e.g. Runtime/vector.sh) expect a different bare model name
	 * than the id we use internally — those specify 'api_model' in
	 * MLP_AI_CHAT_MODELS to override it.
	 *
	 * @param string $model_id The model identifier string.
	 * @return string Model name to send to the provider's API.
	 */
	private function get_api_model_for_model( $model_id ) {
		$models = $this->get_models();

		if ( isset( $models[ $model_id ]['api_model'] ) && $models[ $model_id ]['api_model'] ) {
			return $models[ $model_id ]['api_model'];
		}

		return $model_id;
	}

	/**
	 * Sanitises and returns a valid model ID from user input, falling back to
	 * the default model if the supplied value is empty or unrecognised.
	 *
	 * @param string $raw Raw model string from the request.
	 * @return string Valid model ID.
	 */
	private function sanitize_model( $raw ) {
		$models = $this->get_models();
		$id     = sanitize_text_field( (string) $raw );
		return isset( $models[ $id ] ) ? $id : MLP_AI_CHAT_DEFAULT_MODEL;
	}

	/**
	 * Whether a model accepts image attachments. Defaults to true unless
	 * the model config explicitly sets 'supports_images' => false (e.g.
	 * text-only models that would 400 on an image_url content part).
	 */
	private function model_supports_images( $model_id ) {
		$models = $this->get_models();
		return ! isset( $models[ $model_id ]['supports_images'] ) || (bool) $models[ $model_id ]['supports_images'];
	}

	/**
	 * Resolves a language code (as sent by the front end's language
	 * picker) to the plain English name used in the AI system prompt,
	 * e.g. 'zh' -> 'Chinese (Simplified)'. Unknown/empty codes and 'en'
	 * both resolve to '' so callers can treat that as "no instruction
	 * needed" (English is already the model's default).
	 *
	 * @param string $code
	 * @return string
	 */
	private function get_language_name( $code ) {
		$code = is_string( $code ) ? strtolower( trim( $code ) ) : '';
		if ( '' === $code || 'en' === $code ) {
			return '';
		}
		$langs = $this->get_languages();
		return isset( $langs[ $code ]['name'] ) ? $langs[ $code ]['name'] : '';
	}

	/**
	 * Turns the client-supplied `history` array (the visitor's own
	 * localStorage conversation, sent along with each request since the
	 * server keeps nothing) into the {role, content} list the API
	 * expects, with a system prompt prepended. Nothing here is written
	 * anywhere — it only exists for the duration of this single request.
	 *
	 * @param array  $history Array of ['role' => ..., 'text' => ..., 'attachments' => [...]].
	 * @param bool   $allow_images
	 * @param string $lang_code Language code from the front end's language picker (e.g. 'fr'); empty/'en' means English.
	 * @return array API-ready messages array.
	 */
	private function build_api_messages_from_history( $history, $allow_images = true, $lang_code = '' ) {
		$system_prompt =
			"You are a helpful, friendly AI assistant. When a request involves writing or changing code, don't jump straight to a wall of finished code. Work the way an experienced pair-programmer talks out loud:\n" .
			"1. Briefly state your plan in plain sentences first (what you're about to build or change and why), 1-4 short sentences.\n" .
  "2. As you work, narrate what you're doing in short, natural lines — one action per line, never combine multiple actions in one paragraph. Prefix every line with exactly one of THINK:, READ:, EDIT:, or CHECK:. Use READ: filename when inspecting a file or web source, EDIT: filename when changing a file, and THINK: for reasoning. Keep each line short and truthful; never fabricate progress.\n" .
  "3. Only after that narration, output the finished code in a single fenced code block per file. Always label the fence with the language and the real filename separated by a colon, e.g. ```php:my-plugin.php or ```js:app.js — never use the word snippet as a filename. If the user asks to create a plugin, the filename must be a meaningful plugin filename such as my-plugin.php, not snippet.php. Never leave a code block unlabeled and never split one file's code across multiple fences.\n" .
			"4. Close with one short sentence confirming what you made, e.g. \"Done — chat-widget.php is ready.\" Do not restate or re-paste the code after the fence.\n" .
			"When a user attaches a source file or plugin, treat the attached file contents as authoritative input. Read the entire attachment before answering; never respond with only a snippet, an attachment ID, a blob URL, or a summary when the user asks for the full file. If asked to send the full plugin, reproduce every line inside one complete labeled code block, preserving the original PHP structure and headers. If the attachment cannot be read, say that clearly instead of pretending you received it. For quick answers, one-liners, or anything that isn't a file-sized piece of code, skip this structure and just answer directly and concisely.\n\n" .
			"CRITICAL RULE FOR FOLLOW-UP / INCREMENTAL EDITS: If you already gave the user a complete file of code earlier in this conversation and they now ask for a small, targeted change (e.g. \"make it dark\", \"change the button color\", \"rename this variable\", \"add a loading state\"), you MUST return the ENTIRE file again, unabridged, with ONLY the requested change applied. Never respond to a follow-up tweak with a partial file, a diff-only snippet, or \"the rest stays the same\" — always re-emit every function, import, comment, and unrelated section exactly as it was, byte-for-byte, except for the specific lines the request requires you to change. Before outputting the code block, mentally diff your output against the last full version you gave: if anything is missing that wasn't explicitly asked to be removed, that is a bug — fix it before responding. Dropping unrelated code, truncating a file, or summarizing sections with comments like \"// rest of code here\" or \"... unchanged ...\" is strictly forbidden and counts as a broken response.";

		// The visitor picked a UI language other than English — ask the
		// model to reply in that language too. Code inside fenced code
		// blocks (and the language tag/filename on the fence itself) is
		// left as-is; this only affects the assistant's prose.
		$lang_name = $this->get_language_name( $lang_code );
		if ( $lang_name ) {
			$system_prompt .= "\n\nAlways write your replies to the user in {$lang_name}, no matter what language the user themselves writes in, unless they explicitly ask you to switch to a different language. Keep code, code comments, and fenced code blocks in whatever language is natural for code (do not translate code); only the surrounding prose/explanations must be in {$lang_name}.";
		}

		$messages = array(
			array(
				'role'    => 'system',
				'content' => $system_prompt,
			),
		);

		if ( ! is_array( $history ) ) {
			return $messages;
		}

		foreach ( $history as $turn ) {
			if ( ! is_array( $turn ) ) {
				continue;
			}
			$role = isset( $turn['role'] ) && $turn['role'] === 'assistant' ? 'assistant' : 'user';
			$text = isset( $turn['text'] ) ? sanitize_textarea_field( (string) $turn['text'] ) : '';
			$atts = ( isset( $turn['attachments'] ) && is_array( $turn['attachments'] ) )
				? $this->sanitize_attachments( $turn['attachments'] )
				: array();

			$messages[] = array(
				'role'    => $role,
				'content' => $this->build_message_content( $text, $atts, $allow_images ),
			);
		}

		return $messages;
	}

	public function rest_chat( WP_REST_Request $request ) {
		if ( $this->is_ai_disabled() ) {
			return new WP_Error( 'ai_disabled', 'The AI chat has been temporarily disabled by the site administrator.', array( 'status' => 503 ) );
		}

		$identity = $this->resolve_identity( $request ); // Records/updates the guest name for admin stats, nothing else.

		if ( ! $this->check_rate_limit( $this->get_rate_limit_identity( $identity ) ) ) {
			return new WP_Error(
				'rate_limited',
				'Too many messages — please slow down and try again in a moment.',
				array( 'status' => 429 )
			);
		}

		$params = $request->get_json_params();

		$message     = isset( $params['message'] ) ? sanitize_textarea_field( $params['message'] ) : '';
		$attachments = ( isset( $params['attachments'] ) && is_array( $params['attachments'] ) )
			? $this->sanitize_attachments( $params['attachments'] )
			: array();
		$history     = isset( $params['history'] ) ? $params['history'] : array();
		$lang_code   = isset( $params['lang'] ) ? sanitize_text_field( (string) $params['lang'] ) : '';

		if ( empty( $message ) && empty( $attachments ) ) {
			return new WP_Error( 'empty_message', 'Message cannot be empty.', array( 'status' => 400 ) );
		}

		$requested_model = $this->sanitize_model( isset( $params['model'] ) ? $params['model'] : '' );

		// Walk the candidate models (requested model first, then the
		// default, then everything else) and actually try each one that's
		// currently available, so a model that's down — including the
		// default — is skipped in favor of the next one automatically.
		$ai_response = null;
		$used_model  = null;
		$last_error  = null;
		$attempts    = 0; // Real API calls made this request — see MLP_AI_CHAT_MAX_FAILOVER_ATTEMPTS.

		foreach ( $this->get_candidate_models( $requested_model ) as $candidate ) {
			if ( ! $this->is_model_available( $candidate ) ) {
				continue;
			}

			$api_key = $this->get_api_key_for_model( $candidate );
			if ( is_wp_error( $api_key ) ) {
				continue;
			}

			// Cap live attempts: skipped-as-unavailable candidates above
			// don't count against this, only models we actually call out
			// to. This keeps a single request from chaining through every
			// configured model (potentially dozens) during a provider-wide
			// outage; whatever's left over stays eligible for the next
			// incoming message.
			if ( $attempts >= MLP_AI_CHAT_MAX_FAILOVER_ATTEMPTS ) {
				break;
			}
			$attempts++;

			$allow_images = $this->model_supports_images( $candidate );
			$messages     = $this->build_api_messages_from_history( $history, $allow_images, $lang_code );
			// Make sure the latest turn (with its attachments) is included
			// even if the client didn't append it to history itself.
			$messages[] = array( 'role' => 'user', 'content' => $this->build_message_content( $message, $attachments, $allow_images ) );

			$api_url   = $this->get_api_url_for_model( $candidate );
			$api_model = $this->get_api_model_for_model( $candidate );
			$response  = $this->call_chat_api( $messages, $api_model, $api_key, $api_url );

			if ( is_wp_error( $response ) ) {
				$err_data = $response->get_error_data();
				$err_code = ( is_array( $err_data ) && isset( $err_data['status'] ) ) ? (int) $err_data['status'] : 0;
				$this->record_model_failure( $candidate, $err_code, $response->get_error_message() );
				$last_error = $response;
				continue; // try the next candidate model
			}

			$ai_response = $response;
			$used_model  = $candidate;
			break;
		}

		if ( null === $ai_response ) {
			// Either every configured model was disabled/cooling down, or
			// we hit MLP_AI_CHAT_MAX_FAILOVER_ATTEMPTS live failures in a
			// row without a success.
			return $last_error ? $last_error : new WP_Error( 'all_models_unavailable', 'All AI models are currently unavailable. Please try again shortly.', array( 'status' => 503 ) );
		}

		$this->set_model_status( $used_model, 'online', '' );
		$this->clear_model_unavailable( $used_model );
		$this->increment_total_requests();

		return rest_ensure_response(
			array(
				'reply'           => $ai_response['text'],
				// The model that actually answered — the front end swaps
				// its model picker over to this if it differs from what
				// was requested, so the UI reflects the automatic failover.
				'model_used'      => $used_model,
				'requested_model' => $requested_model,
				'fallback'        => ( $used_model !== $requested_model ),
			)
		);
	}

	/**
	 * Streaming chat endpoint — sends tokens via Server-Sent Events as they
	 * arrive from the API. Nothing is persisted server-side: the client
	 * sends its whole localStorage conversation as `history` with each
	 * request, and the reply is only ever saved back into the visitor's
	 * own browser once streaming finishes.
	 */
	public function rest_chat_stream( WP_REST_Request $request ) {
		if ( $this->is_ai_disabled() ) {
			return new WP_Error( 'ai_disabled', 'The AI chat has been temporarily disabled by the site administrator.', array( 'status' => 503 ) );
		}

		$identity = $this->resolve_identity( $request ); // Records/updates the guest name for admin stats, nothing else.

		// Checked (and returned as a normal WP_Error/HTTP 429) before we
		// switch to raw SSE output below, so a throttled request never
		// even starts a streaming response.
		if ( ! $this->check_rate_limit( $this->get_rate_limit_identity( $identity ) ) ) {
			return new WP_Error(
				'rate_limited',
				'Too many messages — please slow down and try again in a moment.',
				array( 'status' => 429 )
			);
		}

		$params = $request->get_json_params();

		$message     = isset( $params['message'] ) ? sanitize_textarea_field( $params['message'] ) : '';
		$attachments = ( isset( $params['attachments'] ) && is_array( $params['attachments'] ) )
			? $this->sanitize_attachments( $params['attachments'] )
			: array();
		$history     = isset( $params['history'] ) ? $params['history'] : array();
		$conversation_id = isset( $params['conversation_id'] ) ? sanitize_text_field( (string) $params['conversation_id'] ) : '';
		$lang_code   = isset( $params['lang'] ) ? sanitize_text_field( (string) $params['lang'] ) : '';

		if ( empty( $message ) && empty( $attachments ) ) {
			return new WP_Error( 'empty_message', 'Message cannot be empty.', array( 'status' => 400 ) );
		}

		$requested_model = $this->sanitize_model( isset( $params['model'] ) ? $params['model'] : '' );
		$candidates      = $this->get_candidate_models( $requested_model );

		// Long/complex generations (big code blocks, long reasoning, etc.)
		// can legitimately take a while to stream back. Lift PHP's own
		// script timeout so the request is never killed by the server
		// while tokens are still arriving. We still notice if the visitor
		// closes the tab or hits Stop — see the connection_aborted()
		// check inside the cURL write callback below — so this doesn't
		// run forever unattended, it just removes the arbitrary cap.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}
		ignore_user_abort( true );

		// Switch to raw SSE output — bypass WordPress response handling.
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-cache' );
		header( 'X-Accel-Buffering: no' );
		header( 'Connection: keep-alive' );

		$used_model      = null;
		$last_error_msg  = 'AI request failed.';
		$attempts        = 0; // Real API calls made this request — see MLP_AI_CHAT_MAX_FAILOVER_ATTEMPTS.

		foreach ( $candidates as $candidate ) {
			if ( ! $this->is_model_available( $candidate ) ) {
				continue;
			}

			$api_key = $this->get_api_key_for_model( $candidate );
			if ( is_wp_error( $api_key ) ) {
				continue;
			}

			// Same cap as rest_chat(): don't let one message chain through
			// every configured model's cURL call during a provider-wide
			// outage. Whatever's left is still eligible for the next message.
			if ( $attempts >= MLP_AI_CHAT_MAX_FAILOVER_ATTEMPTS ) {
				break;
			}
			$attempts++;

			$allow_images = $this->model_supports_images( $candidate );
			$messages     = $this->build_api_messages_from_history( $history, $allow_images, $lang_code );
			$messages[]   = array( 'role' => 'user', 'content' => $this->build_message_content( $message, $attachments, $allow_images ) );

			$api_url   = $this->get_api_url_for_model( $candidate );
			$api_model = $this->get_api_model_for_model( $candidate );

			// Nothing has been streamed to the browser yet at this point
			// for *this* candidate — so if it's not the model the visitor
			// actually picked, tell the front end now, before any tokens
			// arrive, so the model picker/avatar already reflect the
			// model that's about to answer instead of flipping mid-reply.
			if ( $candidate !== $requested_model ) {
				echo 'data: ' . wp_json_encode( array( 'model_switched' => true, 'model_used' => $candidate ) ) . "\n\n";
				flush();
			}

			/* -----------------------------------------------------------
			 * True SSE streaming via cURL.
			 * --------------------------------------------------------- */
  $full_text       = '';
  $thinking_text   = '';
  $activity_buffer = '';
  $activity_count  = 0;
  $content_count   = 0;
  $sse_buffer      = '';
			$raw_body      = '';
			$usage_tokens  = 0;

			$ch = curl_init();
			curl_setopt_array( $ch, array(
				CURLOPT_URL        => $api_url,
				CURLOPT_POST       => true,
				CURLOPT_HTTPHEADER => array(
					'Content-Type: application/json',
					'Authorization: Bearer ' . $api_key,
				),
				CURLOPT_POSTFIELDS    => wp_json_encode( array(
					'model'    => $api_model,
					'messages' => $messages,
					'stream'   => true,
				) ),
  CURLOPT_WRITEFUNCTION => function ( $ch, $data ) use ( &$full_text, &$thinking_text, &$activity_buffer, &$activity_count, &$content_count, &$sse_buffer, &$raw_body, &$usage_tokens ) {
					// If the visitor closed the tab or clicked Stop, the
					// browser connection is gone — returning less than the
					// full byte count here tells cURL to abort the transfer
					// immediately instead of continuing to pull the response
					// from the AI provider for no one.
					if ( connection_aborted() ) {
						return 0;
					}

					$raw_body   .= $data;
					$sse_buffer .= $data;
					$lines       = explode( "\n", $sse_buffer );
					$sse_buffer  = array_pop( $lines );

					foreach ( $lines as $line ) {
						$line = trim( $line );
						if ( strpos( $line, 'data: ' ) !== 0 ) {
							continue;
						}
						$json = substr( $line, 6 );
						if ( $json === '[DONE]' ) {
							continue;
						}
						$chunk = json_decode( $json, true );
						if ( ! is_array( $chunk ) ) {
							continue;
						}

						if ( isset( $chunk['usage']['total_tokens'] ) ) {
							$usage_tokens = (int) $chunk['usage']['total_tokens'];
						}

						$delta = isset( $chunk['choices'][0]['delta'] ) ? $chunk['choices'][0]['delta'] : array();

						$thinking_token = isset( $delta['reasoning_content'] ) ? (string) $delta['reasoning_content'] : '';
  if ( $thinking_token !== '' ) {
  $thinking_text   .= $thinking_token;
  $activity_buffer .= $thinking_token;
  echo 'data: ' . wp_json_encode( array( 'thinking' => $thinking_token ) ) . "\n\n";
  $activity_lines = preg_split( '/\r?\n/', $activity_buffer );
  $activity_buffer = array_pop( $activity_lines );
  foreach ( $activity_lines as $activity_line ) {
  $activity_line = trim( $activity_line );
  if ( '' === $activity_line ) continue;
  $activity_type = 'thinking';
  if ( preg_match( '/^READ:\s*/i', $activity_line ) ) $activity_type = 'reading';
  elseif ( preg_match( '/^EDIT:\s*/i', $activity_line ) ) $activity_type = 'editing';
  elseif ( preg_match( '/^CHECK:\s*/i', $activity_line ) ) $activity_type = 'checking';
  $activity_label = preg_replace( '/^(THINK|READ|EDIT|CHECK):\s*/i', '', $activity_line );
  echo 'data: ' . wp_json_encode( array( 'activity' => array( 'type' => $activity_type, 'label' => $activity_label ) ) ) . "\n\n";
  }
  flush();
  }

						$token = isset( $delta['content'] ) ? (string) $delta['content'] : '';
if ( $token !== '' ) {
								$full_text .= $token;
								$content_count += strlen( $token );
								// Some providers do not send reasoning_content at all. Create
								// v0-style completed missions from the live response stream so
								// the activity rail never stays empty.
if ( $activity_count < 3 && ( 0 === $activity_count || $content_count >= ( $activity_count * 1400 ) ) ) {
  $activity_count++;
  $missions = array(
  1 => 'AI started planning the response',
  2 => 'AI assembled the main implementation',
  3 => 'AI checked the completed response',
  );
  $mission = $missions[ $activity_count ];
  $mission_type = 3 === $activity_count ? 'checking' : 'thinking';
  echo 'data: ' . wp_json_encode( array( 'activity' => array( 'type' => $mission_type, 'label' => $mission ) ) ) . "\n\n";
  }
								echo 'data: ' . wp_json_encode( array( 'token' => $token ) ) . "\n\n";
								flush();
							}
					}
					return strlen( $data );
				},
				// No overall time limit — a complex/long generation (large
				// code files, long step-by-step reasoning, etc.) is allowed
				// to keep streaming for as long as the AI keeps sending
				// tokens. CURLOPT_CONNECTTIMEOUT still caps how long we'll
				// wait to even establish the connection, so a totally dead
				// API endpoint still fails fast instead of hanging forever.
				CURLOPT_TIMEOUT        => 0,
				CURLOPT_CONNECTTIMEOUT => 30,
				// Belt-and-braces: if the AI provider itself stalls completely
				// (near-zero bytes/sec) for an extended period, give up rather
				// than hold the connection open indefinitely against a dead
				// stream. This is about a truly stalled connection, not a
				// slow-but-progressing generation, so the threshold is long.
				CURLOPT_LOW_SPEED_LIMIT => 1,
				CURLOPT_LOW_SPEED_TIME  => 300,
				CURLOPT_SSL_VERIFYPEER => true,
			) );

			curl_exec( $ch );
			$curl_error = curl_error( $ch );
			$http_code  = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_close( $ch );

			if ( $curl_error || ( $full_text === '' && $thinking_text === '' ) ) {
				$msg = $curl_error;
				if ( ! $msg ) {
					$decoded = json_decode( trim( $raw_body ), true );
					if ( is_array( $decoded ) && isset( $decoded['error']['message'] ) ) {
						$msg = 'API error: ' . $decoded['error']['message'];
					} elseif ( is_array( $decoded ) && isset( $decoded['message'] ) ) {
						$msg = 'API error: ' . $decoded['message'];
					} elseif ( $http_code >= 400 ) {
						$msg = 'API returned HTTP ' . $http_code . '. Response: ' . wp_strip_all_tags( substr( $raw_body, 0, 300 ) );
					} else {
						$msg = 'No response received from AI (HTTP ' . $http_code . ').';
					}
				}
				list( $state, $status_msg ) = $this->record_model_failure( $candidate, $http_code, $msg );
				$last_error_msg = $status_msg;

				// Nothing was actually shown to the visitor yet for this
				// candidate (no token/thinking events were sent above), so
				// it's safe to silently retry the next available model
				// instead of surfacing this failure.
				continue;
			}

			// Success.
			$used_model = $candidate;
			break;
		}

		if ( null === $used_model ) {
			// Either no model was available to try, or every candidate
			// that was tried failed before producing any output.
			echo 'data: ' . wp_json_encode( array( 'error' => $last_error_msg ) ) . "\n\n";
			flush();
			exit;
		}

		$this->set_model_status( $used_model, 'online', '' );
		$this->clear_model_unavailable( $used_model );
		$this->increment_total_requests();

		// Signal completion. conversation_id is just echoed back — it's a
		// client-generated localStorage key, the server never stores it.
		// model_used tells the front end which model actually generated
		// this reply, so it can finalize the picker/avatar/feedback bar
		// on it even if a failover happened during this request.
		$done_payload = array( 'done' => true, 'conversation_id' => $conversation_id, 'model_used' => $used_model );
		echo 'data: ' . wp_json_encode( $done_payload ) . "\n\n";
		flush();
		exit;
	}

	/**
	 * Sanitizes/validates the attachments array sent from the browser.
	 */
	private function sanitize_attachments( $attachments ) {
		$clean         = array();
		$max_count     = 4;
		// Base64 expands uploads by roughly 4/3. Allow large source files
			// so plugins over 200 KB are not silently discarded.
			$max_data_len  = 24 * 1024 * 1024;

		foreach ( $attachments as $att ) {
			if ( count( $clean ) >= $max_count ) {
				break;
			}
			if ( ! is_array( $att ) ) {
				continue;
			}

			// Attachments coming from the v0 upload bridge may arrive as a
			// temporary blob URL instead of an inline data URL. Resolve that
			// URL here so the model receives the complete plugin source.
			if ( empty( $att['data'] ) && ! empty( $att['content'] ) && is_string( $att['content'] ) ) {
				$att['data'] = 'data:text/plain;base64,' . base64_encode( $att['content'] );
			}
			if ( empty( $att['data'] ) && ! empty( $att['url'] ) && is_string( $att['url'] ) ) {
				$url = esc_url_raw( $att['url'] );
				if ( preg_match( '#^https?://#i', $url ) ) {
					$remote = wp_remote_get( $url, array( 'timeout' => 20, 'redirection' => 3, 'limit_response_size' => 8 * 1024 * 1024 ) );
					if ( ! is_wp_error( $remote ) && 200 === (int) wp_remote_retrieve_response_code( $remote ) ) {
						$body = wp_remote_retrieve_body( $remote );
						$mime = wp_remote_retrieve_header( $remote, 'content-type' );
						$att['data'] = 'data:' . ( $mime ? sanitize_text_field( explode( ';', $mime )[0] ) : 'text/plain' ) . ';base64,' . base64_encode( $body );
					}
				}
			}
			if ( empty( $att['data'] ) || ! is_string( $att['data'] ) ) {
				continue;
			}

			$data = $att['data'];

			if ( ! preg_match( '#^data:(image|video|audio|application|text)/[a-zA-Z0-9.+-]+;base64,[A-Za-z0-9+/=]+$#', $data ) ) {
				continue;
			}
			if ( strlen( $data ) > $max_data_len ) {
				// Keep the attachment visible to the model with a clear failure
				// marker instead of silently dropping it.
				$clean[] = array(
					'name' => isset( $att['name'] ) ? sanitize_file_name( $att['name'] ) : 'file',
					'type' => isset( $att['type'] ) ? sanitize_text_field( $att['type'] ) : 'text/plain',
					'size' => isset( $att['size'] ) ? (int) $att['size'] : 0,
					'data' => 'data:text/plain;base64,' . base64_encode( '[Attachment too large for this server request: ' . ( isset( $att['name'] ) ? $att['name'] : 'file' ) . ']' ),
				);
				continue;
			}

			$clean[] = array(
				'name' => isset( $att['name'] ) ? sanitize_file_name( $att['name'] ) : 'file',
				'type' => isset( $att['type'] ) ? sanitize_text_field( $att['type'] ) : 'application/octet-stream',
				'size' => isset( $att['size'] ) ? (int) $att['size'] : 0,
				'data' => $data,
			);
		}

		return $clean;
	}

	private function is_text_attachment( $att ) {
		$mime = strtolower( isset( $att['type'] ) ? $att['type'] : '' );
		$name = strtolower( isset( $att['name'] ) ? $att['name'] : '' );
		$ext  = pathinfo( $name, PATHINFO_EXTENSION );

		if ( strpos( $mime, 'text/' ) === 0 ) {
			return true;
		}

		$text_app_mimes = array(
			'application/json', 'application/javascript', 'application/ecmascript',
			'application/xml', 'application/xhtml+xml', 'application/x-yaml',
			'application/x-sh', 'application/x-httpd-php', 'application/x-php',
			'application/sql', 'application/graphql', 'application/ld+json',
		);
		if ( in_array( $mime, $text_app_mimes, true ) ) {
			return true;
		}

		$text_exts = array(
			'php', 'php3', 'php4', 'php5', 'phtml',
			'js', 'mjs', 'cjs', 'ts', 'tsx', 'jsx',
			'py', 'rb', 'java', 'kt', 'go', 'rs', 'swift',
			'c', 'cpp', 'cc', 'cxx', 'h', 'hpp',
			'cs', 'vb', 'fs', 'scala', 'clj', 'ex', 'exs',
			'sh', 'bash', 'zsh', 'fish',
			'sql', 'graphql', 'gql',
			'html', 'htm', 'xhtml',
			'xml', 'svg', 'xsl', 'xslt',
			'css', 'scss', 'sass', 'less',
			'json', 'jsonc', 'json5',
			'yaml', 'yml', 'toml', 'ini', 'cfg', 'conf', 'env',
			'md', 'mdx', 'rst', 'txt', 'log', 'csv', 'tsv',
			'dockerfile', 'makefile',
		);
		return in_array( $ext, $text_exts, true );
	}

	private function decode_text_attachment( $data_url, $max_bytes = 16 * 1024 * 1024 ) {
		$comma = strpos( $data_url, ',' );
		if ( $comma === false ) {
			return null;
		}
		$b64     = substr( $data_url, $comma + 1 );
		$decoded = base64_decode( $b64, true );
		if ( $decoded === false ) {
			return null;
		}
		if ( strpos( $decoded, "\x00" ) !== false ) {
			return null;
		}
		if ( strlen( $decoded ) > $max_bytes ) {
			$decoded = substr( $decoded, 0, $max_bytes )
				. "\n\n[... file truncated at " . number_format( $max_bytes / 1024 ) . " KB ...]";
		}
		return $decoded;
	}

	/**
	 * Builds the API-ready `content` value (plain string, or a multimodal
	 * content-parts array when images are attached) for one chat turn.
	 * Takes text/attachments directly — the client sends them already
	 * structured, since nothing is stored as a JSON blob server-side
	 * anymore.
	 */
	private function build_message_content( $text, $attachments, $allow_images = true ) {
		$text        = (string) $text;
		$attachments = is_array( $attachments ) ? $attachments : array();

		if ( empty( $attachments ) ) {
			return ( $text !== '' ) ? $text : '(no message text)';
		}

		$image_atts  = array();
		$text_atts   = array();
		$binary_atts = array();

		foreach ( $attachments as $att ) {
			$mime = isset( $att['type'] ) ? $att['type'] : '';
			if ( strpos( $mime, 'image/' ) === 0 && ! empty( $att['data'] ) ) {
				if ( $allow_images ) {
					$image_atts[] = $att;
				} else {
					// This model doesn't accept image content parts — fall
					// back to a text note instead of sending image_url and
					// triggering a 400 from the upstream API.
					$binary_atts[] = $att;
				}
			} elseif ( $this->is_text_attachment( $att ) && ! empty( $att['data'] ) ) {
				$text_atts[] = $att;
			} else {
				$binary_atts[] = $att;
			}
		}

		$text_block = $text;

		foreach ( $text_atts as $att ) {
			$file_content = $this->decode_text_attachment( $att['data'] );
			$filename     = isset( $att['name'] ) ? $att['name'] : 'file';
			if ( $file_content !== null ) {
				$text_block .= "\n\n--- File: " . $filename . " ---\n" . $file_content . "\n--- End of " . $filename . " ---";
			} else {
				$text_block .= "\n\n[Could not decode file: " . $filename . "]";
			}
		}

		if ( ! empty( $binary_atts ) ) {
			$names       = array_map( function( $a ) { return isset( $a['name'] ) ? $a['name'] : 'file'; }, $binary_atts );
			$text_block .= "\n\n[User also attached binary file(s): " . implode( ', ', $names ) . "]";
		}

		$text_block = trim( $text_block );

		if ( empty( $image_atts ) ) {
			return ( $text_block !== '' ) ? $text_block : '(attachment only)';
		}

		$content_parts = array();

		if ( $text_block !== '' ) {
			$content_parts[] = array( 'type' => 'text', 'text' => $text_block );
		}

		foreach ( $image_atts as $img ) {
			$content_parts[] = array(
				'type'      => 'image_url',
				'image_url' => array( 'url' => $img['data'] ),
			);
		}

		if ( empty( array_filter( $content_parts, function( $p ) { return $p['type'] === 'text'; } ) ) ) {
			array_unshift( $content_parts, array( 'type' => 'text', 'text' => 'Please describe what you see in the image(s).' ) );
		}

		return $content_parts;
	}

	/**
	 * Calls a chat completions endpoint (non-streaming) for Gateway AI models.
	 *
	 * @param array  $messages  Array of {role, content} messages.
	 * @param string $model     Model identifier to use.
	 * @param string $api_key   API key for the selected model.
	 * @param string $api_url   API endpoint URL.
	 * @return array|WP_Error   Array with 'text' and optionally 'usage', or WP_Error.
	 */
	private function call_chat_api( $messages, $model, $api_key, $api_url ) {
		$body = array(
			'model'    => $model,
			'messages' => $messages,
			'stream'   => false,
		);

		$response = wp_remote_post(
			$api_url,
			array(
				'timeout' => 90,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body' => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'api_error', 'Could not reach the AI API: ' . $response->get_error_message(), array( 'status' => 500 ) );
		}

		$code          = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		if ( $code < 200 || $code >= 300 ) {
			$err_msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Unknown API error (HTTP ' . $code . ').';
			return new WP_Error( 'api_error', $err_msg, array( 'status' => 500 ) );
		}

		if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
			return new WP_Error( 'api_error', 'Unexpected response format from the AI API.', array( 'status' => 500 ) );
		}

		return array(
			'text'  => $data['choices'][0]['message']['content'],
			'usage' => isset( $data['usage'] ) ? $data['usage'] : array(),
		);
	}

	/* -----------------------------------------------------------------
	 * Front-end shortcode: renders the full chat UI (HTML + CSS + JS)
	 * --------------------------------------------------------------- */

	/**
	 * Outputs Twitter/X Card meta tags in <head> on any singular page or
	 * post whose content contains the [mlp_ai_chat] shortcode. This is
	 * what makes X show a title/description preview under a shared link.
	 * No image tag is included, so X will render a text-only card.
	 */
	public function render_twitter_card_meta() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! ( $post instanceof WP_Post ) || ! has_shortcode( $post->post_content, 'mlp_ai_chat' ) ) {
			return;
		}

		$title = get_the_title( $post );
		if ( '' === $title ) {
			$title = get_bloginfo( 'name' );
		}

		$description = get_the_excerpt( $post );
		if ( '' === $description ) {
			$description = wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 );
		}
		if ( '' === trim( $description ) ) {
			$description = get_bloginfo( 'description' );
		}

		?>
		<meta name="twitter:card" content="summary_large_image">
		<meta name="twitter:title" content="<?php echo esc_attr( $title ); ?>">
		<meta name="twitter:description" content="<?php echo esc_attr( $description ); ?>">
		<?php
	}

	/**
	 * Terms of Service content (rendered into a hidden template div and
	 * shown inside the legal modal on demand). Ptero.pro is a free,
	 * non-profit service: there are no paid tiers, no premium plans, and
	 * every model listed in the chat is free to use. Nothing below should
	 * ever be edited to introduce pricing language.
	 */
	private function get_tos_html() {
		$updated = 'August 15, 2026';
		ob_start();
		?>
		<p><em>Last updated: <?php echo esc_html( $updated ); ?></em></p>

		<h3>1. Welcome to Ptero.pro</h3>
		<p>Ptero.pro ("we," "us," "our," or the "Service") is a free, non-profit AI chat platform. By accessing or using the Service, you agree to be bound by these Terms of Service ("Terms"). If you do not agree, please do not use the Service.</p>

		<h3>2. Always Free — No Paid Plans</h3>
		<p>Ptero.pro is completely free to use and will always be free. We do not offer, and will not introduce, premium plans, subscriptions, paywalls, or any paid tier. Every AI model made available through the Service is provided to you at no cost. We reserve the right to change which specific models are offered, but not to charge for access to the Service itself.</p>

		<h3>3. Eligibility</h3>
		<p>You must be able to form a legally binding contract to use the Service. If you are under the age required by the laws of your country to consent to use of online services without parental approval, you should only use the Service with the involvement of a parent or guardian.</p>

		<h3>4. Accounts and Guest Access</h3>
		<p>You may use the Service as a logged-in WordPress user or as a guest identified by a randomly generated token stored in your browser. You are responsible for safeguarding any device or browser profile used to access the Service, and for all activity that occurs under your session.</p>

		<h3>5. Acceptable Use</h3>
		<p>You agree not to use the Service to:</p>
		<ul>
			<li>Violate any applicable law or regulation;</li>
			<li>Generate content that is unlawful, harassing, defamatory, hateful, or that exploits or endangers minors;</li>
			<li>Attempt to gain unauthorized access to the Service, other users' data, or the underlying AI providers;</li>
			<li>Interfere with or disrupt the integrity or performance of the Service;</li>
			<li>Use the Service to develop a competing product by scraping or systematically extracting outputs;</li>
			<li>Misrepresent the origin of content generated using the Service.</li>
		</ul>

		<h3>6. AI-Generated Content</h3>
		<p>Responses are generated by third-party AI models and may be inaccurate, incomplete, or inappropriate for your purposes. You are responsible for evaluating the accuracy and suitability of any output before relying on it. The Service is provided for informational and conversational purposes and does not constitute professional advice of any kind (legal, medical, financial, or otherwise).</p>

		<h3>7. Your Content</h3>
		<p>Messages you send are transmitted to the selected third-party AI provider to generate a response and, as described in our Privacy Policy, are not stored in our database. You retain any rights you already hold in content you submit. You represent that you have the necessary rights to submit any content you send through the Service.</p>

		<h3>8. Third-Party AI Providers</h3>
		<p>The Service routes your messages to independent, third-party AI providers to generate responses. Their own terms and acceptable-use policies may also apply to how your messages are processed on their end. We select providers we believe are reliable, but we do not control their infrastructure and are not responsible for their availability or the content of their outputs.</p>

		<h3>9. Intellectual Property</h3>
		<p>The Service's design, branding, and underlying software are owned by us or our licensors. These Terms do not grant you any rights to our trademarks or branding except as necessary to use the Service as intended.</p>

		<h3>10. Disclaimers</h3>
		<p>THE SERVICE IS PROVIDED "AS IS" AND "AS AVAILABLE," WITHOUT WARRANTIES OF ANY KIND, WHETHER EXPRESS OR IMPLIED, INCLUDING WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, OR NON-INFRINGEMENT. WE DO NOT WARRANT THAT THE SERVICE WILL BE UNINTERRUPTED, ERROR-FREE, OR THAT AI OUTPUTS WILL BE ACCURATE.</p>

		<h3>11. Limitation of Liability</h3>
		<p>TO THE MAXIMUM EXTENT PERMITTED BY LAW, WE WILL NOT BE LIABLE FOR ANY INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES, OR ANY LOSS OF DATA, ARISING FROM YOUR USE OF THE SERVICE. BECAUSE THE SERVICE IS PROVIDED FREE OF CHARGE, OUR AGGREGATE LIABILITY FOR ANY CLAIM RELATING TO THE SERVICE IS LIMITED TO THE GREATEST EXTENT PERMITTED BY LAW.</p>

		<h3>12. Termination</h3>
		<p>We may suspend or disable access to the Service, in whole or for an individual account, model, or guest identity, at any time — including to enforce these Terms or to keep the Service healthy for everyone. You may stop using the Service at any time.</p>

		<h3>13. Changes to These Terms</h3>
		<p>We may update these Terms from time to time. If we make material changes, we will update the "Last updated" date above. Continued use of the Service after changes take effect constitutes acceptance of the revised Terms.</p>

		<h3>14. Governing Law</h3>
		<p>These Terms are governed by applicable law in the jurisdiction in which the Service operator is established, without regard to conflict-of-law principles, except where local law requires otherwise.</p>

		<h3>15. Contact</h3>
		<p>Questions about these Terms can be directed to the Service operator through <a href="https://pterocos.eu.org" target="_blank" rel="noopener noreferrer">pterocos.eu.org</a>.</p>
		<?php
		return ob_get_clean();
	}

	/**
	 * Privacy Policy content (rendered into a hidden template div and
	 * shown inside the legal modal on demand).
	 */
	private function get_privacy_html() {
		$updated = 'August 15, 2026';
		ob_start();
		?>
		<p><em>Last updated: <?php echo esc_html( $updated ); ?></em></p>

		<h3>1. Overview</h3>
		<p>This Privacy Policy explains what information Ptero.pro ("we," "us," "our") collects when you use our free AI chat service (the "Service"), how we use it, and the choices available to you.</p>

		<h3>2. Information We Collect</h3>
		<p>We collect as little as possible:</p>
		<ul>
			<li><strong>Guest identity:</strong> a random token and the display name you choose, generated and stored in your browser's local storage, used to keep your conversations separate from other visitors.</li>
			<li><strong>Chat content in transit:</strong> messages you send are transmitted to the selected AI provider to generate a reply. This content passes through our server momentarily to relay the request but is not written to our database.</li>
			<li><strong>Anonymous usage counters:</strong> small, contentless statistics such as total request counts and first/last-seen timestamps per guest name, used only to operate the admin dashboard and to keep the Service reliable.</li>
			<li><strong>Model feedback:</strong> thumbs up / thumbs down votes are tallied per AI model only — never per message and never with message content attached.</li>
		</ul>

		<h3>3. Where Your Conversations Live</h3>
		<p>Your conversations and messages are stored entirely in your own browser's local storage. We do not keep a copy of your conversation history on our servers. Clearing your browser data or switching devices will remove your local conversation history.</p>

		<h3>4. How We Use Information</h3>
		<p>We use the limited information described above to:</p>
		<ul>
			<li>Operate, maintain, and improve the Service;</li>
			<li>Route your messages to the AI provider you selected and return the response to you;</li>
			<li>Keep basic, anonymous usage statistics for administrators;</li>
			<li>Detect and prevent abuse of the Service.</li>
		</ul>
		<p>We do not sell your information, and we do not use your chat content for advertising.</p>

		<h3>5. Third-Party AI Providers</h3>
		<p>To generate responses, your messages are sent to the third-party AI provider associated with the model you choose. Each provider processes this data under its own privacy practices, and we encourage you to review those where available. We choose providers we believe handle data responsibly, but we do not control their systems.</p>

		<h3>6. Cookies and Local Storage</h3>
		<p>We use your browser's local storage (not third-party advertising cookies) to remember your guest identity and conversation history on your device, and to remember that you have accepted these policies so you are not asked again on every visit.</p>

		<h3>7. Data Retention</h3>
		<p>Because conversations live in your browser rather than on our servers, retention of chat content is entirely in your control. Anonymous usage counters and per-model vote tallies are retained only in aggregate, contentless form for as long as needed to operate the admin dashboard.</p>

		<h3>8. Children's Privacy</h3>
		<p>The Service is not directed at children under the age required by local law to consent to use of online services without parental approval, and we do not knowingly collect personal information from such children.</p>

		<h3>9. Security</h3>
		<p>We use reasonable technical measures to protect information in transit to and from AI providers. No method of transmission or storage is completely secure, and we cannot guarantee absolute security.</p>

		<h3>10. Your Choices</h3>
		<p>You can clear your local browser storage at any time to remove your guest identity and conversation history. Because we don't hold a server-side copy of your conversations, deleting your local data effectively removes it from the Service.</p>

		<h3>11. Changes to This Policy</h3>
		<p>We may update this Privacy Policy from time to time. Material changes will be reflected in the "Last updated" date above. Continued use of the Service after changes take effect constitutes acceptance of the revised policy.</p>

		<h3>12. Contact</h3>
		<p>Questions about this Privacy Policy can be directed to the Service operator through <a href="https://pterocos.eu.org" target="_blank" rel="noopener noreferrer">pterocos.eu.org</a>.</p>
		<?php
		return ob_get_clean();
	}

	public function render_shortcode( $atts ) {
		$rest_url   = esc_url_raw( rest_url( 'mlp/v1' ) );
		$nonce      = wp_create_nonce( 'wp_rest' );
		$user_id    = get_current_user_id();
		$can_manage = current_user_can( 'manage_options' );
		$wp_display_name = $user_id ? wp_get_current_user()->display_name : '';

		// Cloudflare Turnstile captcha, shown once in the username modal the
		// first time a logged-out visitor joins. Only enabled when both
		// keys are defined in wp-config.php; if MLP_TURNSTILE_SITE_KEY is
		// unset the modal falls back to its previous (no-captcha) behavior.
		$turnstile_site_key = defined( 'MLP_TURNSTILE_SITE_KEY' ) ? MLP_TURNSTILE_SITE_KEY : '';

		// Build JS-safe model list from PHP config.
		$models_raw = $this->get_models();
		$js_models  = array();
		foreach ( $models_raw as $id => $cfg ) {
			$js_models[] = array(
				'id'              => $id,
				'label'           => $cfg['label'],
				'supports_images' => ! isset( $cfg['supports_images'] ) || (bool) $cfg['supports_images'],
				'logo'            => ! empty( $cfg['logo'] ) ? $cfg['logo'] : '',
			);
		}

		// Build JS-safe language list (drives both the modal and header
		// language pickers, and the <select> options rendered below).
		$languages_raw = $this->get_languages();
		$js_languages  = array();
		foreach ( $languages_raw as $code => $cfg ) {
			$js_languages[] = array(
				'code'  => $code,
				'label' => $cfg['label'],
				'dir'   => $cfg['dir'],
			);
		}
		$lang_options_html = '';
		foreach ( $languages_raw as $code => $cfg ) {
			$lang_options_html .= '<option value="' . esc_attr( $code ) . '"' . selected( $code, 'en', false ) . '>' . esc_html( $cfg['label'] ) . '</option>';
		}

		ob_start();
		?>
		<div id="chat-ai-chat-fullpage" class="chat-ai-chat-fullpage">
			<div id="chat-username-modal" class="chat-username-modal" data-hidden="1">
				<div class="chat-username-modal-box">
					<button type="button" id="chat-username-modal-close" class="chat-username-modal-close" aria-label="Close" hidden>&times;</button>
					<img class="chat-username-modal-logo" src="https://ptero.pro/wp-content/uploads/2026/07/cropped-cropped-logo.png" alt="Logo">
					<div class="chat-username-modal-lang-row">
						<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
						<select id="chat-username-lang-select" class="chat-lang-select" aria-label="Editor language"><?php echo $lang_options_html; // phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_attr/esc_html above. ?></select>
					</div>
					<h2 data-i18n="welcome_title">Welcome</h2>
					<p data-i18n="welcome_desc">Pick a name to use the AI chat. It's saved on this device so your conversations are here next time.</p>
					<input type="text" id="chat-username-input" class="chat-username-input" maxlength="30" placeholder="Your name" autocomplete="off" data-i18n-placeholder="name_placeholder">
					<?php if ( $turnstile_site_key ) : ?>
					<div id="chat-turnstile" class="chat-turnstile-widget"></div>
					<?php endif; ?>
					<div id="chat-username-error" class="chat-username-error"></div>
					<button id="chat-username-submit" class="chat-username-submit" type="button" data-i18n="start_chatting"<?php echo $turnstile_site_key ? ' disabled' : ''; ?>>Start Chatting</button>
				</div>
			</div>
			<div id="chat-tos-content" hidden><?php echo $this->get_tos_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- static, admin-authored HTML. ?></div>
			<div id="chat-privacy-content" hidden><?php echo $this->get_privacy_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- static, admin-authored HTML. ?></div>

			<div id="chat-legal-modal-backdrop" class="chat-legal-modal-backdrop" hidden></div>
			<div id="chat-legal-modal" class="chat-legal-modal" hidden role="dialog" aria-modal="true" aria-labelledby="chat-legal-modal-title">
				<div class="chat-legal-modal-box">
					<div class="chat-legal-modal-head">
						<h2 id="chat-legal-modal-title">Terms of Service</h2>
						<button id="chat-legal-modal-close" class="chat-legal-modal-close" type="button" aria-label="Close">&times;</button>
					</div>
					<div id="chat-legal-modal-body" class="chat-legal-modal-body"></div>
				</div>
			</div>

			<div id="chat-source-trust-backdrop" class="chat-consent-modal-backdrop" data-hidden="1"></div>
			<div id="chat-source-trust-modal" class="chat-consent-modal" data-hidden="1" role="dialog" aria-modal="true" aria-labelledby="chat-source-trust-title">
				<div class="chat-consent-modal-box">
					<img class="chat-consent-modal-logo" src="https://ptero.pro/wp-content/uploads/2026/08/cropped-Pterocos-2.png" alt="Logo">
					<h2 id="chat-source-trust-title">View ptero.pro source code</h2>
					<p>Ptero.pro is fully open source. Before you continue, feel free to inspect exactly how the AI chat works — nothing is hidden.</p>
					<div class="chat-source-trust-actions">
						<a href="https://github.com/aminkheddache-dotcom/Pterocos/blob/main/free-ai-chat-4-coding.php" target="_blank" rel="noopener noreferrer" class="chat-source-trust-view-btn">
							<svg viewBox="0 0 16 16" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8z"></path></svg>
							<span>View Source Code</span>
						</a>
						<button id="chat-source-trust-continue-btn" class="chat-source-trust-continue-btn" type="button">Everything fine. Continue</button>
					</div>
				</div>
			</div>

			<div id="chat-consent-modal-backdrop" class="chat-consent-modal-backdrop" data-hidden="1"></div>
			<div id="chat-consent-modal" class="chat-consent-modal" data-hidden="1" role="dialog" aria-modal="true" aria-labelledby="chat-consent-modal-title">
				<div class="chat-consent-modal-box">
					<img class="chat-consent-modal-logo" src="https://ptero.pro/wp-content/uploads/2026/08/cropped-Pterocos-2.png" alt="Logo">
					<h2 id="chat-consent-modal-title">Before you start chatting</h2>
					<p>Ptero.pro is completely free to use and will always be free — every model is free, with no premium plans, ever. Please review and accept our policies below to continue.</p>
					<p class="chat-consent-links">
						<a href="#" id="chat-consent-tos-link" class="chat-legal-link">Terms of Service</a>
						&nbsp;and&nbsp;
						<a href="#" id="chat-consent-privacy-link" class="chat-legal-link">Privacy Policy</a>
					</p>
					<label class="chat-consent-checkbox-row">
						<input type="checkbox" id="chat-consent-checkbox">
						<span>I have read and agree to the Terms of Service and Privacy Policy.</span>
					</label>
					<button id="chat-consent-accept-btn" class="chat-consent-accept-btn" type="button" disabled>Accept &amp; Continue</button>
				</div>
			</div>

			<div id="chat-new-models-modal" class="chat-new-models-modal" data-hidden="1">
				<div class="chat-new-models-box">
					<button id="chat-new-models-close" class="chat-new-models-close" type="button" aria-label="Close">&times;</button>
					<h2>New Models</h2>
					<p>Start a fresh chat with one of the latest AI models.</p>
					<div class="chat-new-models-list">
						<?php foreach ( $models_raw as $model_id => $model_cfg ) : ?>
							<?php if ( isset( $model_cfg['provider'] ) && 'relay' === $model_cfg['provider'] ) : ?>
							<div class="chat-new-models-item">
								<?php if ( ! empty( $model_cfg['logo'] ) ) : ?>
								<img class="chat-new-models-item-logo" src="<?php echo esc_url( $model_cfg['logo'] ); ?>" alt="<?php echo esc_attr( $model_cfg['label'] ); ?>">
								<?php endif; ?>
								<div class="chat-new-models-item-label"><?php echo esc_html( $model_cfg['label'] ); ?></div>
								<button class="chat-new-models-start-btn" type="button" data-model-id="<?php echo esc_attr( $model_id ); ?>">Start Chat</button>
							</div>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
					<p class="chat-new-models-footnote">ptero.pro is a non-profit — these new models will always be free. Powered by <a href="https://pterocos.eu.org" target="_blank" rel="noopener noreferrer">pterocos.eu.org</a>.</p>
				</div>
			</div>

			<div id="chat-new-project-modal" class="chat-new-models-modal" data-hidden="1">
				<div class="chat-new-models-box chat-new-project-box">
					<button id="chat-new-project-close" class="chat-new-models-close" type="button" aria-label="Close">&times;</button>
					<h2 data-i18n="new_project">New project</h2>
					<p data-i18n="new_project_desc">Give your project a name to keep related chats together.</p>
					<input type="text" id="chat-new-project-input" class="chat-new-project-input" placeholder="Project name" maxlength="60" autocomplete="off" data-i18n-placeholder="project_name_placeholder">
					<div class="chat-new-project-error" id="chat-new-project-error" hidden data-i18n="error_enter_project_name">Please enter a project name.</div>
					<div class="chat-new-project-actions">
						<button id="chat-new-project-cancel" type="button" class="chat-new-project-cancel-btn" data-i18n="cancel">Cancel</button>
						<button id="chat-new-project-create" type="button" class="chat-new-project-create-btn" data-i18n="create_project">Create project</button>
					</div>
				</div>
			</div>

			<div id="chat-featured-on-modal" class="chat-new-models-modal" data-hidden="1">
				<div class="chat-new-models-box">
					<button id="chat-featured-on-close" class="chat-new-models-close" type="button" aria-label="Close">&times;</button>
					<h2>Featured On</h2>
					<p>Places that have written about or listed ptero.pro.</p>
					<div class="chat-featured-on-list">
						<a href="https://tools.launchllama.co?utm_source=badge&utm_medium=referral" target="_blank" rel="noopener noreferrer" class="chat-featured-on-badge">
							<img src="https://tools.launchllama.co/featured-badge.png?v=2" alt="As seen on Launch Llama Newsletter" width="200" height="50" loading="lazy">
						</a>
						<a href="https://neeed.directory" target="_blank" rel="noopener" class="chat-featured-on-badge">
							<img src="https://neeed.directory/badges/neeed-badge-light.svg" alt="Featured on neeed.directory" width="139" loading="lazy">
						</a>
						<a href="https://noonlaunch.com/product/ptero" rel="dofollow" class="chat-featured-on-badge">
							<img src="https://noonlaunch.com/badges/ptero.svg" alt="Featured on Noonlaunch" width="220" height="60" loading="lazy">
						</a>
						<a href="https://nicklaunches.com/products/ptero/?utm_source=ptero.pro&utm_medium=badge&utm_campaign=featured" target="_blank" rel="noopener" class="chat-featured-on-badge">
							<img src="https://nicklaunches.com/badges/featured.png" alt="Ptero on Nick Launches" width="244" height="56" loading="lazy">
						</a>
                        <a href="https://dang.ai" target="_blank" rel="dofollow noopener" style="display:inline-block;text-decoration:none;"><img src="https://assets.dang.ai/badges/dang-verified-dark.png" alt="Verified on DANG!" width="260" height="94" style="display:block;width:260px;max-width:100%;height:auto;border:0;outline:none;text-decoration:none;" /></a>
						<a href="https://saasgrow.ai/tools/ptero" target="_blank" rel="noopener" class="chat-featured-on-badge">
							<img src="https://saasgrow.ai/badge/dark.svg" alt="Ptero is featured on saasgrow.ai" width="200" height="54" loading="lazy">
						</a>
						<a href="https://startupfa.st" target="_blank" rel="noopener" title="Powered by Startup Fast" class="chat-featured-on-badge">
							<img src="https://startupfa.st/images/badges/powered-by-light.svg" alt="Powered by Startup Fast" width="150" height="44" loading="lazy">
						</a>
					</div>
				</div>
			</div>
			<div id="chat-ai-chat-app" class="chat-ai-chat-app">
				<div id="chat-sidebar-backdrop" class="chat-sidebar-backdrop"></div>
				<div class="chat-sidebar" id="chat-sidebar">
					<div class="chat-sidebar-logo">
						<img src="https://ptero.pro/wp-content/uploads/2026/08/cropped-Pterocos-2.png" alt="Logo">
					</div>
					<div class="chat-sidebar-legal-links">
						<button id="chat-sidebar-tos-btn" class="chat-sidebar-legal-btn" type="button" data-i18n="terms_of_service">Terms of Service</button>
						<button id="chat-sidebar-privacy-btn" class="chat-sidebar-legal-btn" type="button" data-i18n="privacy_policy">Privacy Policy</button>
					</div>
					<button id="chat-new-models-btn" class="chat-new-models-btn" type="button">✨ <span data-i18n="new_models">New Models</span></button>
					<div class="chat-projects-section">
						<div class="chat-projects-section-head">
							<button id="chat-projects-toggle-btn" class="chat-media-room-btn chat-projects-toggle-btn" type="button" aria-expanded="true" aria-controls="chat-projects-list">
								<svg class="chat-media-room-btn-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"></path></svg>
								<span data-i18n="projects">Projects</span>
								<svg class="chat-projects-chevron" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
							</button>
							<button id="chat-projects-add-btn" class="chat-projects-add-btn" type="button" title="New project" aria-label="New project">+</button>
						</div>
						<div id="chat-projects-list" class="chat-projects-list"></div>
					</div>
					<button id="chat-media-room-btn" class="chat-media-room-btn" type="button">
						<svg class="chat-media-room-btn-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><path d="M21 15l-5-5L5 21"></path></svg>
						<span data-i18n="media">Media</span>
					</button>
					<button id="chat-new-chat-btn" class="chat-new-chat-btn" data-i18n="new_chat">+ New Chat</button>
					<div class="chat-conv-search-wrap">
						<svg class="chat-conv-search-icon" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
						<input type="text" id="chat-conv-search" class="chat-conv-search" placeholder="Search chats..." autocomplete="off" data-i18n-placeholder="search_placeholder">
						<button type="button" id="chat-conv-search-clear" class="chat-conv-search-clear" title="Clear search" hidden>&times;</button>
					</div>
					<div id="chat-conversation-list" class="chat-conversation-list"></div>
					<?php if ( $can_manage ) : ?>
					<div class="chat-sidebar-divider"></div>
					<button id="chat-admin-room-btn" class="chat-room-btn" type="button">
						<span class="chat-room-btn-icon" aria-hidden="true">&#9881;</span> <span data-i18n="administration">Administration</span>
					</button>
					<?php endif; ?>
					<a href="https://github.com/aminkheddache-dotcom/Pterocos/blob/main/free-ai-chat-4-coding.php" target="_blank" rel="noopener noreferrer" class="chat-sidebar-source-link">
						<svg viewBox="0 0 16 16" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8z"></path></svg>
						<span data-i18n="source_code">Source code</span>
					</a>
					<button id="chat-featured-on-btn" class="chat-featured-on-btn" type="button">🏅 <span data-i18n="featured_on">Featured On</span></button>
					<div class="chat-sidebar-divider"></div>
					<div class="chat-profile" id="chat-profile">
						<button type="button" class="chat-profile-trigger" id="chat-profile-trigger" aria-haspopup="true" aria-expanded="false">
							<span class="chat-profile-avatar" id="chat-profile-avatar">?</span>
							<span class="chat-profile-name" id="chat-profile-name">Guest</span>
							<span class="chat-profile-gear" aria-hidden="true">
								<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
							</span>
						</button>
						<div class="chat-profile-menu" id="chat-profile-menu" role="menu" hidden>
							<?php if ( ! $user_id ) : ?>
							<button type="button" class="chat-profile-menu-item" id="chat-profile-menu-edit-name" role="menuitem">
								<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"></path><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
								<span>Edit name</span>
							</button>
							<div class="chat-profile-menu-divider"></div>
							<?php endif; ?>
							<button type="button" class="chat-profile-menu-item" id="chat-profile-menu-tos" role="menuitem">
								<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
								<span data-i18n="terms_of_service">Terms of Service</span>
							</button>
							<button type="button" class="chat-profile-menu-item" id="chat-profile-menu-privacy" role="menuitem">
								<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
								<span data-i18n="privacy_policy">Privacy Policy</span>
							</button>
							<div class="chat-profile-menu-divider"></div>
							<button type="button" class="chat-profile-menu-item danger" id="chat-profile-menu-clear" role="menuitem">
								<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"></path></svg>
								<span>Clear all chats</span>
							</button>
						</div>
					</div>
				</div>
				<div class="chat-main" id="chat-chat-view">
					<div id="chat-disabled-banner" class="chat-disabled-banner" data-i18n="disabled_banner">The AI chat has been temporarily disabled by the site administrator.</div>
					<div class="chat-chat-header">
						<div class="chat-header-left">
							<button id="chat-menu-btn" class="chat-menu-btn" type="button" aria-label="Open menu" aria-controls="chat-sidebar" aria-expanded="false">
								<span></span><span></span><span></span>
							</button>
							<span id="chat-current-title" data-i18n="new_chat_title">New Chat</span>
						</div>
						<div class="chat-header-right">
							<div class="chat-lang-picker" id="chat-lang-picker" title="Editor language">
								<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
								<select id="chat-header-lang-select" class="chat-lang-select chat-header-lang-select" aria-label="Editor language"><?php echo $lang_options_html; // phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_attr/esc_html above. ?></select>
							</div>
							<div class="chat-model-picker" id="chat-model-picker">
								<button type="button" class="chat-model-picker-trigger" id="chat-model-picker-trigger" title="Choose AI model" aria-haspopup="listbox" aria-expanded="false">
									<span class="chat-model-picker-trigger-icon" id="chat-model-picker-trigger-icon"></span>
									<span class="chat-model-picker-trigger-label" id="chat-model-picker-trigger-label" data-i18n="choose_model">Choose model</span>
									<svg class="chat-model-picker-chevron" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
								</button>
								<div class="chat-model-picker-panel" id="chat-model-picker-panel" role="listbox" tabindex="-1" hidden>
									<div class="chat-model-picker-panel-title">Select a model</div>
									<div class="chat-model-picker-search">
										<svg class="chat-model-picker-search-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
										<input type="text" class="chat-model-picker-search-input" id="chat-model-picker-search" placeholder="Search models…" autocomplete="off" spellcheck="false">
									</div>
									<div class="chat-model-picker-empty" id="chat-model-picker-empty" hidden>No models found</div>
									<?php
									foreach ( $models_raw as $model_id => $model_cfg ) :
										$mlp_logo        = ! empty( $model_cfg['logo'] ) ? $model_cfg['logo'] : '';
										$mlp_is_paid     = ! empty( $model_cfg['is_paid'] );
										$mlp_clean_label = trim( preg_replace( '/\s*\(free\)\s*$/i', '', $model_cfg['label'] ) );
										$mlp_is_default  = ( $model_id === MLP_AI_CHAT_DEFAULT_MODEL );
									?>
									<div class="chat-model-picker-option<?php echo $mlp_is_default ? ' is-selected' : ''; ?>" role="option" aria-selected="<?php echo $mlp_is_default ? 'true' : 'false'; ?>" data-model-id="<?php echo esc_attr( $model_id ); ?>" data-label="<?php echo esc_attr( $mlp_clean_label ); ?>" tabindex="-1">
										<span class="chat-model-picker-option-icon">
											<?php if ( $mlp_logo ) : ?>
											<img src="<?php echo esc_url( $mlp_logo ); ?>" alt="" loading="lazy">
											<?php else : ?>
											<span class="chat-model-picker-option-icon-fallback"><?php echo esc_html( strtoupper( substr( $mlp_clean_label, 0, 1 ) ) ); ?></span>
											<?php endif; ?>
										</span>
										<span class="chat-model-picker-option-text">
											<span class="chat-model-picker-option-label"><?php echo esc_html( $mlp_clean_label ); ?></span>
										</span>
										<?php if ( ! $mlp_is_paid ) : ?>
										<span class="chat-model-picker-option-badge">Free</span>
										<?php endif; ?>
										<svg class="chat-model-picker-option-check" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>
									</div>
									<?php endforeach; ?>
								</div>
								<select id="chat-model-select" class="chat-model-select-native" title="Choose AI model" aria-hidden="true" tabindex="-1">
									<?php foreach ( $models_raw as $model_id => $model_cfg ) :
										$mlp_native_label = trim( preg_replace( '/\s*\(free\)\s*$/i', '', $model_cfg['label'] ) );
									?>
										<option value="<?php echo esc_attr( $model_id ); ?>" data-logo="<?php echo esc_attr( ! empty( $model_cfg['logo'] ) ? $model_cfg['logo'] : '' ); ?>" data-label="<?php echo esc_attr( $mlp_native_label ); ?>" <?php selected( $model_id, MLP_AI_CHAT_DEFAULT_MODEL ); ?>><?php echo esc_html( $mlp_native_label ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
					</div>
					<div id="chat-messages" class="chat-messages">
						<div class="chat-empty-state">
							<h2>AI Chat</h2>
							<p>Start a conversation below.</p>
						</div>
					</div>
					<div class="chat-input-wrap">
						<div id="chat-attach-preview" class="chat-attach-preview"></div>
						<div id="chat-input-area" class="chat-input-area">
							<div class="chat-attach-wrap">
								<button id="chat-attach-btn" class="chat-attach-btn" title="Attach" type="button" aria-haspopup="true" aria-expanded="false">
									<span class="chat-icon-plus" aria-hidden="true"></span>
								</button>
								<div id="chat-attach-menu" class="chat-attach-menu" hidden>
									<button type="button" class="chat-attach-menu-item" id="chat-attach-menu-image">
										<span class="chat-attach-menu-icon"><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"></rect><circle cx="9" cy="9" r="2"></circle><path d="M21 15l-5-5L5 21"></path></svg></span>
										<span>Add image</span>
									</button>
									<button type="button" class="chat-attach-menu-item" id="chat-attach-menu-file">
										<span class="chat-attach-menu-icon"><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg></span>
										<span>Add file</span>
									</button>
								</div>
							</div>
							<input type="file" id="chat-image-input" class="chat-file-input" accept="image/*" multiple hidden>
							<input type="file" id="chat-file-input" class="chat-file-input" accept=".pdf,.doc,.docx,.txt,video/*,audio/*" multiple hidden>
							<textarea id="chat-input" class="chat-input" placeholder="Message the AI..." rows="1"></textarea>
							<button id="chat-send-btn" class="chat-send-btn" title="Send">
								<span class="chat-icon-send" aria-hidden="true"></span>
								<span class="chat-icon-stop" aria-hidden="true" style="display:none;"></span>
							</button>
						</div>
						<div id="chat-drop-hint" class="chat-drop-hint">
							<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
							<span>Drop files to attach</span>
						</div>
					</div>
				</div>
				<?php if ( $can_manage ) : ?>
				<div class="chat-main chat-admin-view" id="chat-admin-view" data-hidden="1">
					<div class="chat-admin-header">
						<div class="chat-header-left">
							<button id="chat-admin-menu-btn" class="chat-menu-btn" type="button" aria-label="Open menu" aria-controls="chat-sidebar" aria-expanded="false">
								<span></span><span></span><span></span>
							</button>
							<span>Administration</span>
						</div>
						<button id="chat-admin-refresh-btn" class="chat-admin-refresh-btn" type="button">Refresh</button>
					</div>
					<div class="chat-admin-body" id="chat-admin-body">
						<div class="chat-admin-stats" id="chat-admin-stats"></div>
						<div class="chat-admin-section">
							<div class="chat-admin-section-head">
								<h3>Global Controls</h3>
							</div>
							<p class="chat-admin-note">When disabled, no visitor can send messages to any model.</p>
							<button id="chat-admin-toggle-global-btn" class="chat-admin-toggle-btn" type="button">Disable AI Chat</button>
						</div>
						<div class="chat-admin-section">
							<div class="chat-admin-section-head">
								<h3>Models</h3>
							</div>
							<div id="chat-admin-models" class="chat-admin-models"></div>
						</div>
					</div>
				</div>
				<?php endif; ?>
				<div class="chat-main chat-media-view" id="chat-media-view" data-hidden="1">
					<div class="chat-media-header">
						<div class="chat-header-left">
							<button id="chat-media-menu-btn" class="chat-menu-btn" type="button" aria-label="Open menu" aria-controls="chat-sidebar" aria-expanded="false">
								<span></span><span></span><span></span>
							</button>
							<span data-i18n="media">Media</span>
						</div>
						<button id="chat-media-add-btn" class="chat-media-add-btn" type="button">
							<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
							<span data-i18n="add_media">Add Media</span>
						</button>
						<input type="file" id="chat-media-file-input" accept="image/*,video/*" multiple hidden>
					</div>
					<div class="chat-media-body" id="chat-media-body">
						<div id="chat-media-gallery" class="chat-media-gallery"></div>
						<div id="chat-media-empty" class="chat-media-empty" data-i18n="media_empty">No media yet — click "+ Add Media" to upload images or videos from your device.</div>
					</div>
				</div>
				<div class="chat-main chat-project-view" id="chat-project-view" data-hidden="1">
					<div class="chat-project-header">
						<div class="chat-header-left">
							<button id="chat-project-menu-btn" class="chat-menu-btn" type="button" aria-label="Open menu" aria-controls="chat-sidebar" aria-expanded="false">
								<span></span><span></span><span></span>
							</button>
							<span id="chat-project-title" class="chat-project-title">Project</span>
						</div>
						<div class="chat-project-header-actions">
							<button id="chat-project-new-chat-btn" class="chat-project-new-chat-btn" type="button">
								<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
								<span data-i18n="new_chat_in_project">New Chat</span>
							</button>
							<button id="chat-project-delete-btn" class="chat-project-delete-btn" type="button" title="Delete project" aria-label="Delete project">
								<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"></path></svg>
							</button>
						</div>
					</div>
					<div class="chat-project-body" id="chat-project-body">
						<div id="chat-project-conv-list" class="chat-project-conv-list"></div>
						<div id="chat-project-empty" class="chat-project-empty" data-i18n="project_empty">No chats in this project yet — click "+ New Chat" to start one.</div>
					</div>
				</div>
				<!-- Code Editor Sidebar (Monaco) -->
				<div id="chat-code-sidebar" class="chat-code-sidebar" data-hidden="1">
					<div class="chat-code-sidebar-header">
						<div class="chat-code-sidebar-title-wrap">
							<span id="chat-code-sidebar-icon" class="chat-code-sidebar-icon">&#128196;</span>
							<span id="chat-code-sidebar-title">Code Editor</span>
						</div>
						<button id="chat-code-sidebar-close" class="chat-code-sidebar-close" type="button" title="Close">&times;</button>
					</div>
					<div id="chat-code-sidebar-editor" class="chat-code-sidebar-editor"></div>
					<div class="chat-code-sidebar-footer">
						<button id="chat-code-sidebar-download" class="chat-code-sidebar-download" type="button">
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
							Download File
						</button>
					</div>
				</div>
				<!-- Live HTML Preview Sidebar (separate from the Monaco code sidebar) -->
				<div id="chat-preview-sidebar" class="chat-code-sidebar chat-preview-sidebar" data-hidden="1">
					<div class="chat-code-sidebar-header">
						<div class="chat-code-sidebar-title-wrap">
							<span class="chat-code-sidebar-icon">&#128065;</span>
							<span id="chat-preview-sidebar-title">Preview</span>
						</div>
						<div class="chat-preview-sidebar-actions">
							<button id="chat-preview-sidebar-fullscreen" class="chat-code-sidebar-close chat-preview-sidebar-fullscreen" type="button" title="View fullscreen">
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3"></path><path d="M21 8V5a2 2 0 0 0-2-2h-3"></path><path d="M3 16v3a2 2 0 0 0 2 2h3"></path><path d="M16 21h3a2 2 0 0 0 2-2v-3"></path></svg>
							</button>
							<button id="chat-preview-sidebar-close" class="chat-code-sidebar-close" type="button" title="Close">&times;</button>
						</div>
					</div>
					<iframe id="chat-preview-sidebar-frame" class="chat-preview-sidebar-frame" sandbox="allow-scripts allow-forms allow-popups allow-modals" title="HTML preview"></iframe>
				</div>
			</div>
		</div>

		<style>
			html.chat-fullpage-active,
			html.chat-fullpage-active body {
				overflow: hidden !important;
				height: 100% !important;
			}
			[data-mlp-hidden="1"] { display: none !important; }
			.chat-ai-chat-fullpage {
				position: fixed;
				top: 0; left: 0; right: 0; bottom: 0;
				width: 100vw; height: 100vh;
				height: 100dvh; /* accounts for mobile browser address/toolbar chrome */
				z-index: 2147483000;
				background: #ffffff;
				margin: 0; padding: 0;
			}
			.chat-ai-chat-app {
				display: flex;
				width: 100%; height: 100%;
				border: none; border-radius: 0;
				overflow: hidden;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
				background: #ffffff;
			}
			.chat-sidebar {
				width: 260px; min-width: 260px;
				background: #202123; color: #ececf1;
				display: flex; flex-direction: column;
				padding: 10px; box-sizing: border-box;
			}
			.chat-sidebar-logo {
				display: flex; align-items: center; justify-content: center;
				padding: 6px 0 14px 0;
			}
			.chat-sidebar-logo img {
				max-width: 140px; max-height: 48px; width: auto; height: auto;
				object-fit: contain;
			}
			.chat-username-modal {
				position: absolute; inset: 0; z-index: 10;
				display: flex; align-items: center; justify-content: center;
				background: rgba(32,33,35,0.72);
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
			}
			.chat-username-modal[data-hidden="1"] { display: none; }
			.chat-username-modal-box {
				position: relative;
				background: #ffffff; border-radius: 12px; padding: 32px 28px;
				width: 340px; max-width: 90vw; box-sizing: border-box;
				text-align: center; box-shadow: 0 12px 40px rgba(0,0,0,0.25);
			}
			.chat-username-modal-close {
				position: absolute; top: 10px; right: 10px;
				width: 26px; height: 26px; display: flex; align-items: center; justify-content: center;
				background: none; border: none; border-radius: 6px; font-size: 20px; line-height: 1;
				color: #8e8ea0; cursor: pointer;
			}
			.chat-username-modal-close:hover { background: #f2f2f4; color: #202123; }
			.chat-username-modal-close[hidden] { display: none; }
			.chat-username-modal-logo { max-width: 120px; max-height: 42px; object-fit: contain; margin-bottom: 14px; }
			.chat-username-modal-lang-row { display: flex; align-items: center; justify-content: center; gap: 6px; color: #6e6e80; margin-bottom: 10px; }
			.chat-lang-select {
				appearance: none; -webkit-appearance: none; -moz-appearance: none;
				border: 1px solid #e5e5e8; border-radius: 8px; background: #fff;
				font-size: 12.5px; color: #353740; padding: 5px 22px 5px 8px; cursor: pointer;
				background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' width='11' height='11' fill='none' stroke='%236e6e80' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'><polyline points='6 9 12 15 18 9'></polyline></svg>");
				background-repeat: no-repeat; background-position: right 6px center;
			}
			.chat-lang-select:focus { outline: none; border-color: #10a37f; }
			.chat-lang-picker { display: flex; align-items: center; gap: 5px; color: #6e6e80; }
			.chat-username-modal-box h2 { margin: 0 0 8px 0; font-size: 20px; color: #202123; }
			.chat-username-modal-box p { margin: 0 0 18px 0; font-size: 13px; color: #6e6e80; line-height: 1.4; }
			.chat-username-input {
				width: 100%; box-sizing: border-box; padding: 10px 12px;
				border: 1px solid #d9d9e3; border-radius: 8px; font-size: 14px; margin-bottom: 6px;
			}
			.chat-username-input:focus { outline: none; border-color: #10a37f; }
			.chat-turnstile-widget { display: flex; justify-content: center; margin: 4px 0 10px 0; min-height: 65px; }
			.chat-username-error { color: #d63638; font-size: 12px; min-height: 16px; margin-bottom: 10px; text-align: left; }
			.chat-username-submit {
				width: 100%; background: #10a37f; color: #fff; border: none;
				padding: 11px 12px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer;
			}
			.chat-username-submit:hover { background: #0d8f6e; }
			.chat-username-submit:disabled { background: #b9e4d7; cursor: not-allowed; }
			.chat-sidebar-legal-links {
				display: flex; align-items: center; justify-content: center; gap: 10px;
				margin: 0 0 10px 0;
			}
			.chat-sidebar-legal-btn {
				background: none; border: none; padding: 2px 0; margin: 0;
				font-size: 11.5px; font-weight: 500; color: #b7b7bd;
				text-decoration: underline; text-underline-offset: 2px;
				cursor: pointer; font-family: inherit;
			}
			.chat-sidebar-legal-btn:hover { color: #ffffff; }
			.chat-legal-link { color: #10a37f; text-decoration: underline; text-underline-offset: 2px; cursor: pointer; }
			.chat-legal-link:hover { color: #0d8f6e; }
			.chat-legal-modal-backdrop {
				position: fixed; inset: 0; z-index: 50; background: rgba(32,33,35,0.72);
			}
			.chat-legal-modal-backdrop[hidden] { display: none; }
			.chat-legal-modal {
				position: fixed; inset: 0; z-index: 51;
				display: flex; align-items: center; justify-content: center;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
				padding: 24px; box-sizing: border-box;
			}
			.chat-legal-modal[hidden] { display: none; }
			.chat-legal-modal-box {
				background: #ffffff; border-radius: 12px;
				width: 640px; max-width: 100%; max-height: 82vh;
				box-sizing: border-box; box-shadow: 0 12px 40px rgba(0,0,0,0.25);
				display: flex; flex-direction: column; overflow: hidden;
			}
			.chat-legal-modal-head {
				display: flex; align-items: center; justify-content: space-between;
				padding: 18px 22px; border-bottom: 1px solid #e5e5ea; flex-shrink: 0;
			}
			.chat-legal-modal-head h2 { margin: 0; font-size: 18px; color: #202123; }
			.chat-legal-modal-close {
				background: none; border: none; font-size: 22px; line-height: 1;
				color: #8e8ea0; cursor: pointer; padding: 4px;
			}
			.chat-legal-modal-close:hover { color: #202123; }
			.chat-legal-modal-body {
				padding: 20px 24px 28px; overflow-y: auto; font-size: 13.5px;
				line-height: 1.6; color: #3a3a45; text-align: left;
			}
			.chat-legal-modal-body h3 { font-size: 14.5px; color: #202123; margin: 20px 0 8px 0; }
			.chat-legal-modal-body h3:first-child { margin-top: 0; }
			.chat-legal-modal-body p { margin: 0 0 10px 0; }
			.chat-legal-modal-body ul { margin: 0 0 10px 0; padding-left: 20px; }
			.chat-legal-modal-body li { margin: 0 0 6px 0; }
			.chat-legal-modal-body a { color: #10a37f; }
			.chat-consent-modal-backdrop {
				position: fixed; inset: 0; z-index: 40; background: rgba(32,33,35,0.82);
			}
			.chat-consent-modal-backdrop[data-hidden="1"] { display: none; }
			.chat-consent-modal {
				position: fixed; inset: 0; z-index: 41;
				display: flex; align-items: center; justify-content: center;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
				padding: 20px; box-sizing: border-box;
			}
			.chat-consent-modal[data-hidden="1"] { display: none; }
			.chat-consent-modal-box {
				background: #ffffff; border-radius: 12px; padding: 32px 28px;
				width: 400px; max-width: 92vw; box-sizing: border-box;
				text-align: center; box-shadow: 0 16px 48px rgba(0,0,0,0.3);
			}
			.chat-consent-modal-logo { max-width: 120px; max-height: 42px; object-fit: contain; margin-bottom: 14px; }
			.chat-consent-modal-box h2 { margin: 0 0 8px 0; font-size: 19px; color: #202123; }
			.chat-consent-modal-box p { margin: 0 0 16px 0; font-size: 13px; color: #6e6e80; line-height: 1.45; }
			.chat-source-trust-actions { display: flex; flex-direction: column; gap: 10px; margin-top: 4px; }
			.chat-source-trust-view-btn {
				display: flex; align-items: center; justify-content: center; gap: 8px;
				background: #ffffff; color: #202123; border: 1px solid #d9d9e3;
				padding: 10px 14px; border-radius: 8px; font-size: 13.5px; font-weight: 600;
				text-decoration: none; cursor: pointer;
			}
			.chat-source-trust-view-btn:hover { background: #f5f5f7; }
			.chat-source-trust-continue-btn {
				background: #10a37f; color: #fff; border: none;
				padding: 11px 14px; border-radius: 8px; font-size: 13.5px; font-weight: 600; cursor: pointer;
			}
			.chat-source-trust-continue-btn:hover { background: #0d8f6e; }
			.chat-consent-links { font-size: 13.5px !important; }
			.chat-consent-checkbox-row {
				display: flex; align-items: flex-start; gap: 8px; text-align: left;
				font-size: 12.5px; color: #3a3a45; line-height: 1.4;
				margin: 4px 0 18px 0; cursor: pointer;
			}
			.chat-consent-checkbox-row input { margin-top: 2px; flex-shrink: 0; cursor: pointer; }
			.chat-consent-accept-btn {
				width: 100%; background: #10a37f; color: #fff; border: none;
				padding: 11px 12px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer;
			}
			.chat-consent-accept-btn:hover:not(:disabled) { background: #0d8f6e; }
			.chat-consent-accept-btn:disabled { background: #c9c9cf; cursor: not-allowed; }
			.chat-new-models-btn {
				display: block; width: 100%; box-sizing: border-box;
				margin: 0 0 8px 0; padding: 10px 12px;
				background: linear-gradient(135deg, #10a37f, #0d8f6e);
				color: #fff; border: none; border-radius: 8px;
				font-size: 14px; font-weight: 600; cursor: pointer; text-align: left;
			}
			.chat-new-models-btn:hover { filter: brightness(1.08); }
			.chat-new-models-modal {
				position: absolute; inset: 0; z-index: 11;
				display: flex; align-items: center; justify-content: center;
				background: rgba(32,33,35,0.72);
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
			}
			.chat-new-models-modal[data-hidden="1"] { display: none; }
			.chat-new-models-box {
				position: relative;
				background: #ffffff; border-radius: 12px; padding: 32px 28px;
				width: 380px; max-width: 90vw; box-sizing: border-box;
				text-align: center; box-shadow: 0 12px 40px rgba(0,0,0,0.25);
			}
			.chat-new-models-box h2 { margin: 0 0 8px 0; font-size: 20px; color: #202123; }
			.chat-new-models-box p { margin: 0 0 18px 0; font-size: 13px; color: #6e6e80; line-height: 1.4; }
			.chat-new-models-close {
				position: absolute; top: 10px; right: 12px;
				background: none; border: none; font-size: 22px; line-height: 1;
				color: #8e8ea0; cursor: pointer; padding: 4px;
			}
			.chat-new-models-close:hover { color: #202123; }
			.chat-new-models-list { display: flex; flex-direction: column; gap: 10px; }
			.chat-new-models-item {
				display: flex; align-items: center; justify-content: space-between; gap: 12px;
				border: 1px solid #e5e5ea; border-radius: 8px; padding: 12px 14px;
				text-align: left;
			}
			.chat-new-models-item-label { font-size: 13px; font-weight: 600; color: #202123; flex: 1 1 auto; }
			.chat-new-models-item-logo {
				flex-shrink: 0; width: 28px; height: 28px; object-fit: contain; border-radius: 6px;
			}
			.chat-new-models-footnote {
				margin: 18px 0 0 0 !important; font-size: 12px; color: #8e8ea0; line-height: 1.4;
			}
			.chat-new-models-footnote a { color: #10a37f; text-decoration: none; }
			.chat-new-models-footnote a:hover { text-decoration: underline; }
			.chat-new-models-start-btn {
				flex-shrink: 0; background: #10a37f; color: #fff; border: none;
				padding: 8px 14px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer;
			}
			.chat-new-models-start-btn:hover { background: #0d8f6e; }
			.chat-featured-on-btn {
				display: block; width: 100%; box-sizing: border-box;
				margin: 10px 0 0 0; padding: 10px 12px; flex-shrink: 0;
				background: #F6C453; color: #3D2B00; border: 1px solid #E0AB3A; border-radius: 8px;
				font-size: 14px; font-weight: 600; cursor: pointer; text-align: left;
			}
			.chat-featured-on-btn:hover { background: #F0B93C; border-color: #D69A28; }
			.chat-featured-on-list {
				display: flex; flex-wrap: wrap; align-items: center; justify-content: center;
				gap: 14px;
			}
			.chat-featured-on-badge { display: inline-flex; line-height: 0; }
			.chat-featured-on-badge img { display: block; max-width: 100%; height: auto; }
			.chat-disabled-banner {
				display: none; background: #fff3cd; color: #7a5b00; font-size: 13px;
				padding: 8px 16px; text-align: center; border-bottom: 1px solid #ffe08a;
			}
			.chat-disabled-banner[data-show="1"] { display: block; }
			.chat-new-chat-btn {
				background: transparent;
				border: 1px solid #565869; color: #ececf1;
				padding: 10px 12px; border-radius: 6px;
				cursor: pointer; text-align: left; font-size: 14px; margin-bottom: 10px;
			}
			.chat-new-chat-btn:hover { background: #2b2c2f; }
			.chat-media-room-btn {
				display: flex; align-items: center; gap: 8px;
				background: transparent;
				border: 1px solid #565869; color: #ececf1;
				padding: 10px 12px; border-radius: 6px;
				cursor: pointer; text-align: left; font-size: 14px; margin-bottom: 10px; width: 100%; box-sizing: border-box;
			}
			.chat-media-room-btn:hover, .chat-media-room-btn.active { background: #2b2c2f; }
			.chat-media-room-btn.active { border-color: #10a37f; color: #10a37f; }
			.chat-media-room-btn-icon { flex-shrink: 0; }

			/* ── Projects (ChatGPT-style) ─��─���─────────────────────────────── */
			.chat-projects-section { flex-shrink: 0; margin-bottom: 10px; }
			.chat-projects-section-head { display: flex; align-items: stretch; gap: 6px; }
			.chat-projects-toggle-btn.chat-media-room-btn {
				flex: 1 1 auto; min-width: 0; width: auto; margin-bottom: 0;
			}
			.chat-projects-toggle-btn span:first-of-type { flex: 1 1 auto; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
			.chat-projects-chevron { flex-shrink: 0; color: #8e8ea0; transition: transform 0.15s ease; margin-left: auto; }
			.chat-projects-toggle-btn[aria-expanded="false"] .chat-projects-chevron { transform: rotate(-90deg); }
			.chat-projects-add-btn {
				flex-shrink: 0; display: flex; align-items: center; justify-content: center;
				width: 36px; background: transparent; border: 1px solid #565869; color: #ececf1;
				border-radius: 6px; cursor: pointer; font-size: 18px; line-height: 1; font-weight: 400; padding: 0;
			}
			.chat-projects-add-btn:hover { background: #2b2c2f; }
			.chat-projects-list { display: flex; flex-direction: column; gap: 2px; margin-bottom: 4px; }
			.chat-projects-list[data-collapsed="1"] { display: none; }
			.chat-projects-empty { padding: 6px 10px; font-size: 12px; color: #8e8ea0; }
			.chat-project-item {
				display: flex; align-items: center; gap: 8px; padding: 8px 10px 8px 26px;
				border-radius: 6px; cursor: pointer; font-size: 13px; white-space: nowrap;
				overflow: hidden; text-overflow: ellipsis;
			}
			.chat-project-item:hover { background: #2b2c2f; }
			.chat-project-item.active { background: #343541; }
			.chat-project-item-icon { flex-shrink: 0; color: #8e8ea0; }
			.chat-project-item-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
			.chat-project-item-delete { opacity: 0; background: none; border: none; color: #ececf1; cursor: pointer; font-size: 13px; flex-shrink: 0; }
			.chat-project-item:hover .chat-project-item-delete { opacity: 0.7; }
			.chat-project-item-delete:hover { opacity: 1 !important; color: #ff6b6b; }
			.chat-new-project-box { text-align: left; }
			.chat-new-project-box h2, .chat-new-project-box p { text-align: left; }
			.chat-new-project-input {
				width: 100%; box-sizing: border-box; background: #fff; border: 1px solid #d9d9e3; color: #202123;
				border-radius: 8px; padding: 10px 12px; font-size: 14px; font-family: inherit; outline: none; margin-bottom: 6px;
			}
			.chat-new-project-input:focus { border-color: #10a37f; }
			.chat-new-project-error { color: #d93025; font-size: 12px; margin-bottom: 6px; }
			.chat-new-project-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 12px; }
			.chat-new-project-cancel-btn {
				background: transparent; border: 1px solid #d9d9e3; color: #202123;
				padding: 8px 14px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;
			}
			.chat-new-project-cancel-btn:hover { background: #f5f5f7; }
			.chat-new-project-create-btn {
				background: #10a37f; color: #fff; border: none;
				padding: 8px 14px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;
			}
			.chat-new-project-create-btn:hover { background: #0d8f6e; }
			.chat-project-header {
				display: flex; align-items: center; justify-content: space-between; gap: 10px;
				padding: 14px 20px; border-bottom: 1px solid #3a3b3d; flex-shrink: 0;
			}
			.chat-project-title { font-size: 15px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
			.chat-project-header-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
			.chat-project-new-chat-btn {
				display: flex; align-items: center; gap: 6px;
				background: #10a37f; color: #fff; border: none;
				padding: 8px 12px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;
			}
			.chat-project-new-chat-btn:hover { background: #0d8f6e; }
			.chat-project-delete-btn {
				display: flex; align-items: center; justify-content: center;
				width: 32px; height: 32px; background: transparent; border: 1px solid #565869; color: #ececf1;
				border-radius: 6px; cursor: pointer;
			}
			.chat-project-delete-btn:hover { background: #2b2c2f; color: #ff6b6b; border-color: #ff6b6b; }
			.chat-project-view { display: none; overflow-y: auto; }
			.chat-project-view[data-hidden="1"] { display: none; }
			.chat-project-view[data-hidden="0"] { display: flex; }
			.chat-project-body { padding: 14px; overflow-y: auto; flex: 1; }
			.chat-project-conv-list { display: flex; flex-direction: column; gap: 4px; }
			.chat-project-empty { display: none; text-align: center; color: #8e8ea0; font-size: 13.5px; padding: 40px 20px; }
			.chat-project-conv-list:empty + .chat-project-empty { display: block; }
			.chat-conv-search-wrap {
				position: relative; display: flex; align-items: center;
				margin-bottom: 10px; flex-shrink: 0;
			}
			.chat-conv-search-icon {
				position: absolute; left: 9px; color: #8e8ea0; pointer-events: none; flex-shrink: 0;
			}
			.chat-sidebar .chat-conv-search,
			.chat-conv-search {
				width: 100%; background: #202123 !important; border: 1px solid #565869; color: #ececf1 !important;
				border-radius: 6px; padding: 8px 28px 8px 30px; font-size: 13px; font-family: inherit;
				outline: none; box-sizing: border-box; -webkit-text-fill-color: #ececf1;
			}
			.chat-conv-search::placeholder,
			.chat-conv-search::-webkit-input-placeholder { color: #8e8ea0 !important; opacity: 1; }
			.chat-conv-search:-webkit-autofill,
			.chat-conv-search:-webkit-autofill:hover,
			.chat-conv-search:-webkit-autofill:focus {
				-webkit-text-fill-color: #ececf1 !important;
				-webkit-box-shadow: 0 0 0px 1000px #202123 inset !important;
				box-shadow: 0 0 0px 1000px #202123 inset !important;
				caret-color: #ececf1;
			}
			.chat-conv-search:focus { border-color: #10a37f; }
			.chat-conv-search-clear {
				position: absolute; right: 6px; background: none; border: none; color: #8e8ea0;
				cursor: pointer; font-size: 15px; line-height: 1; padding: 4px 5px; border-radius: 4px;
			}
			.chat-conv-search-clear:hover { color: #ececf1; background: #2b2c2f; }
			.chat-conv-empty-search { padding: 12px 10px; font-size: 12.5px; color: #8e8ea0; text-align: center; }
			.chat-conversation-list { flex: 1; overflow-y: auto; }
			.chat-conv-item {
				padding: 10px; border-radius: 6px; cursor: pointer;
				font-size: 13px; white-space: nowrap; overflow: hidden;
				text-overflow: ellipsis; margin-bottom: 4px;
				display: flex; justify-content: space-between; align-items: center; gap: 6px;
			}
			.chat-conv-item:hover { background: #2b2c2f; }
			.chat-conv-item.active { background: #343541; }
			.chat-conv-title { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
			.chat-conv-delete { opacity: 0; background: none; border: none; color: #ececf1; cursor: pointer; font-size: 13px; }
			.chat-conv-item:hover .chat-conv-delete { opacity: 0.7; }
			.chat-conv-delete:hover { opacity: 1 !important; color: #ff6b6b; }

			/* ── Mobile sidebar drawer (hamburger + backdrop) ────────────── */
			/* Inert on desktop; activated inside the max-width:768px query below. */
			.chat-menu-btn {
				display: none;
				flex-direction: column; align-items: center; justify-content: center;
				gap: 4px; width: 36px; height: 36px; padding: 0;
				border: none; background: transparent; border-radius: 6px;
				cursor: pointer; flex-shrink: 0; -webkit-tap-highlight-color: transparent;
			}
			.chat-menu-btn span { display: block; width: 18px; height: 2px; background: #333; border-radius: 2px; }
			.chat-menu-btn:hover { background: rgba(0,0,0,0.06); }
			.chat-header-left { display: flex; align-items: center; gap: 8px; min-width: 0; }
			.chat-sidebar-backdrop {
				display: none;
				position: fixed; inset: 0; background: rgba(0,0,0,0.45);
				z-index: 199; opacity: 0; transition: opacity 0.2s ease;
			}
			.chat-sidebar-backdrop.open { display: block; opacity: 1; }

			.chat-main { flex: 1; display: flex; flex-direction: column; background: #ffffff; min-width: 0; }
			.chat-chat-header {
				padding: 12px 18px; border-bottom: 1px solid #eee;
				font-weight: 600; display: flex; justify-content: space-between;
				align-items: center; font-size: 14px; color: #333; gap: 10px;
			}
			#chat-current-title { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
			.chat-header-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }

			/* The real <select> stays in the DOM (fully functional — value,
			   options, disabled state, change events) but is visually
			   replaced by the .chat-model-picker widget below. */
			.chat-model-select-native {
				position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
				overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;
			}

			.chat-model-picker { position: relative; }
			.chat-model-picker-trigger {
				display: flex; align-items: center; gap: 7px;
				font-size: 12.5px; font-family: inherit; font-weight: 500;
				background: #f7f7f8; border: 1px solid #e2e2e5;
				border-radius: 999px; padding: 4px 12px 4px 6px;
				color: #383941; cursor: pointer; outline: none;
				transition: background 0.15s, border-color 0.15s, box-shadow 0.15s;
				max-width: 220px;
			}
			.chat-model-picker-trigger:hover { background: #eeeef0; border-color: #d3d3d8; }
			.chat-model-picker-trigger:focus-visible { border-color: #10a37f; box-shadow: 0 0 0 3px rgba(16,163,127,0.15); }
			.chat-model-picker-trigger[aria-expanded="true"] { background: #eeeef0; border-color: #10a37f; box-shadow: 0 0 0 3px rgba(16,163,127,0.15); }
			.chat-model-picker-trigger:disabled { opacity: 0.55; cursor: not-allowed; }
			.chat-model-picker-trigger-icon {
				width: 22px; height: 22px; border-radius: 50%; flex-shrink: 0;
				display: flex; align-items: center; justify-content: center;
				background: #fff; border: 1px solid #e5e5e8; overflow: hidden;
			}
			.chat-model-picker-trigger-icon img { width: 100%; height: 100%; object-fit: cover; display: block; }
			.chat-model-picker-trigger-icon .chat-model-picker-option-icon-fallback { font-size: 10px; }
			.chat-model-picker-trigger-label {
				overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0;
			}
			.chat-model-picker-chevron { flex-shrink: 0; color: #8a8a94; transition: transform 0.15s; }
			.chat-model-picker-trigger[aria-expanded="true"] .chat-model-picker-chevron { transform: rotate(180deg); color: #10a37f; }

			.chat-model-picker-panel {
				position: absolute; top: calc(100% + 8px); right: 0; z-index: 60;
				width: 280px; max-height: 340px; overflow-y: auto;
				background: #fff; border: 1px solid #e5e5e8; border-radius: 14px;
				box-shadow: 0 12px 32px rgba(20,20,30,0.14), 0 2px 8px rgba(20,20,30,0.06);
				padding: 6px; animation: chatModelPanelIn 0.14s ease-out;
			}
			@keyframes chatModelPanelIn {
				from { opacity: 0; transform: translateY(-4px) scale(0.98); }
				to   { opacity: 1; transform: translateY(0) scale(1); }
			}
			.chat-model-picker-panel[hidden] { display: none; }
			.chat-model-picker-panel-title {
				font-size: 11px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase;
				color: #9a9aa4; padding: 8px 10px 6px;
			}
			.chat-model-picker-search {
				position: relative; margin: 0 4px 6px; position: sticky; top: 0; z-index: 1;
				background: #fff;
			}
			.chat-model-picker-search-icon {
				position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
				color: #9a9aa4; pointer-events: none;
			}
			.chat-model-picker-search-input {
				width: 100%; box-sizing: border-box; font-family: inherit;
				font-size: 13px; color: #202123; background: #f7f7f8;
				border: 1px solid #e2e2e5; border-radius: 9px;
				padding: 7px 10px 7px 30px; outline: none;
				transition: background 0.15s, border-color 0.15s, box-shadow 0.15s;
			}
			.chat-model-picker-search-input::placeholder { color: #9a9aa4; }
			.chat-model-picker-search-input:focus {
				background: #fff; border-color: #10a37f; box-shadow: 0 0 0 3px rgba(16,163,127,0.15);
			}
			.chat-model-picker-empty {
				padding: 14px 10px; text-align: center; font-size: 12.5px; color: #9a9aa4;
			}
			.chat-model-picker-option {
				display: flex; align-items: center; gap: 10px;
				padding: 8px 10px; border-radius: 10px; cursor: pointer;
				transition: background 0.12s;
			}
			.chat-model-picker-option:hover,
			.chat-model-picker-option.is-active { background: #f2f7f5; }
			.chat-model-picker-option[aria-disabled="true"] { opacity: 0.45; cursor: not-allowed; }
			.chat-model-picker-option[aria-disabled="true"]:hover { background: transparent; }
			.chat-model-picker-option[hidden] { display: none; }
			.chat-model-picker-option-icon {
				width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
				display: flex; align-items: center; justify-content: center;
				background: #fff; border: 1px solid #ececef; overflow: hidden;
			}
			.chat-model-picker-option-icon img { width: 100%; height: 100%; object-fit: cover; display: block; }
			.chat-model-picker-option-icon-fallback {
				font-size: 13px; font-weight: 700; color: #10a37f;
			}
			.chat-model-picker-option-text { display: flex; flex-direction: column; min-width: 0; flex: 1 1 auto; }
			.chat-model-picker-option-label {
				font-size: 13.5px; font-weight: 600; color: #202123;
				overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
			}
			.chat-model-picker-option-badge {
				flex-shrink: 0; font-size: 10px; font-weight: 700; letter-spacing: 0.02em;
				color: #10a37f; background: rgba(16,163,127,0.12);
				border-radius: 999px; padding: 2px 8px; text-transform: uppercase;
			}
			.chat-model-picker-option-check { flex-shrink: 0; color: #10a37f; opacity: 0; }
			.chat-model-picker-option[aria-selected="true"] .chat-model-picker-option-check { opacity: 1; }
			.chat-model-picker-option[aria-selected="true"] .chat-model-picker-option-label { color: #10a37f; }

			.chat-messages { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 16px; }
			.chat-empty-state { margin: auto; text-align: center; color: #999; }
			.chat-msg {
				max-width: 85%;
				display: flex;
				align-items: flex-start;
				gap: 10px;
				padding: 0;
				background: transparent;
				border-radius: 0;
				font-size: 14px;
				line-height: 1.5;
			}
			.chat-msg.user { align-self: flex-end; flex-direction: row-reverse; }
			.chat-msg.assistant { align-self: flex-start; }
			.chat-msg.typing { align-self: flex-start; }

			.chat-msg-avatar {
				width: 32px; height: 32px;
				border-radius: 50%;
				object-fit: cover;
				flex-shrink: 0;
				margin-top: 4px;
				animation: mlpAvatarFloat 3s ease-in-out infinite, mlpAvatarGlow 2.5s ease-in-out infinite;
			}
			.chat-msg-avatar-wrap {
				display: flex; flex-direction: column; align-items: center;
				flex-shrink: 0; gap: 2px;
			}
			.chat-msg-avatar-name {
				font-size: 10px; font-weight: 600; color: #6e6e80;
				max-width: 62px; text-align: center; line-height: 1.2;
				white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
			}
			.chat-msg-avatar-tooltip {
				position: absolute;
				z-index: 9999;
				background: #1a1a1a;
				color: #fff;
				font-size: 12px;
				font-weight: 500;
				padding: 6px 10px;
				border-radius: 6px;
				box-shadow: 0 4px 14px rgba(0,0,0,0.25);
				white-space: nowrap;
				pointer-events: none;
				animation: mlpTooltipIn 0.12s ease-out;
			}
			@keyframes mlpTooltipIn {
				from { opacity: 0; transform: translateY(-4px); }
				to   { opacity: 1; transform: translateY(0); }
			}
			@keyframes mlpAvatarFloat {
				0%, 100% { transform: translateY(0); }
				50% { transform: translateY(-4px); }
			}
			@keyframes mlpAvatarGlow {
				0%, 100% { box-shadow: 0 0 0 0 rgba(16, 163, 127, 0.35); }
				50% { box-shadow: 0 0 12px 4px rgba(16, 163, 127, 0.15); }
			}

			.chat-msg-content {
				padding: 10px 14px;
				border-radius: 12px;
				white-space: normal;
				word-wrap: break-word;
				min-width: 0;
			}
			.chat-msg.user .chat-msg-content {
				background: #10a37f;
				color: #fff;
				border-bottom-right-radius: 2px;
			}
			.chat-msg.assistant .chat-msg-content {
				background: #f2f2f2;
				color: #222;
				border-bottom-left-radius: 2px;
			}
			.chat-msg.typing .chat-msg-content {
				background: #f2f2f2;
				color: #999;
				border-bottom-left-radius: 2px;
				padding: 12px 16px;
			}

			/* ── Message feedback (like/dislike) ─────────────────────────── */
			.chat-feedback-bar {
				display: flex; align-items: center; gap: 4px;
				margin-top: 8px;
			}
			.chat-feedback-btn {
				display: flex; align-items: center; justify-content: center;
				width: 26px; height: 26px; padding: 0;
				background: transparent; border: none; border-radius: 6px;
				color: #8e8ea0; cursor: pointer;
				transition: background 0.12s, color 0.12s, transform 0.08s;
			}
			.chat-feedback-btn:hover { background: rgba(0,0,0,0.06); color: #444; }
			.chat-feedback-btn:active { transform: scale(0.9); }
			.chat-feedback-btn.like.active { color: #00a32a; background: rgba(0,163,42,0.12); }
			.chat-feedback-btn.dislike.active { color: #d63638; background: rgba(214,54,56,0.12); }
			.chat-feedback-btn.copy.copied { color: #00a32a; }
			.chat-feedback-btn svg { display: block; }

			/* ── Code Blocks with Copy Button ───────────────────────────── */
			.chat-code-block {
				margin: 8px 0;
				border-radius: 10px;
				overflow: hidden;
				background: #1e1e2e;
				border: 1px solid #2d2d3d;
				max-width: 100%;
			}
			.chat-code-block-header {
				display: flex;
				align-items: center;
				justify-content: space-between;
				padding: 8px 14px;
				background: #252536;
				border-bottom: 1px solid #2d2d3d;
			}
			.chat-code-block-lang {
				font-size: 11px;
				font-weight: 600;
				color: #8b8b9a;
				text-transform: lowercase;
				font-family: "SFMono-Regular", Consolas, Menlo, monospace;
			}
			.chat-code-block-actions {
				display: flex;
				gap: 6px;
			}
			.chat-code-block-btn {
				background: rgba(255,255,255,0.06);
				border: 1px solid rgba(255,255,255,0.08);
				color: #a0a0b0;
				border-radius: 5px;
				padding: 4px 10px;
				font-size: 11px;
				cursor: pointer;
				transition: all 0.15s;
				display: flex;
				align-items: center;
				gap: 4px;
			}
			.chat-code-block-btn:hover {
				background: rgba(255,255,255,0.12);
				color: #fff;
			}
			.chat-code-block-btn.copied {
				background: rgba(16, 163, 127, 0.2);
				color: #10a37f;
				border-color: rgba(16, 163, 127, 0.3);
			}
			.chat-code-block-body {
				padding: 12px 16px;
				overflow-x: auto;
				max-height: 480px;
				overflow-y: auto;
			}
			.chat-code-block-body pre {
				margin: 0;
				padding: 0;
				background: transparent;
				font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, Courier, monospace;
				font-size: 13px;
				line-height: 1.6;
				color: #d4d4d4;
				white-space: pre;
				word-wrap: normal;
			}
			.chat-code-block-body code {
				background: transparent;
				padding: 0;
				border-radius: 0;
				font-size: inherit;
				color: inherit;
			}

			/* ── File cards (large files -> Monaco sidebar) ───────────── */
			.chat-file-card {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 14px;
				background: #fafafa;
				border: 1px solid #e6e6e9;
				border-radius: 14px;
				padding: 12px 14px;
				max-width: 400px;
				box-shadow: 0 1px 2px rgba(20, 20, 30, 0.03);
				transition: border-color 0.15s, box-shadow 0.15s, background 0.15s, transform 0.15s;
			}
			.chat-file-card:hover {
				background: #fff;
				border-color: #d7d7dc;
				box-shadow: 0 4px 14px rgba(20, 20, 30, 0.07);
				transform: translateY(-1px);
			}
			.chat-file-card-main {
				display: flex;
				align-items: center;
				gap: 12px;
				min-width: 0;
				flex: 1;
			}
			.chat-file-card-icon {
				width: 38px; height: 38px;
				border-radius: 11px;
				background: #eafaf4;
				color: #10a37f;
				display: flex;
				align-items: center;
				justify-content: center;
				flex-shrink: 0;
			}
			.chat-file-card-info {
				min-width: 0;
				flex: 1;
			}
			.chat-file-card-name {
				font-size: 13.5px;
				font-weight: 600;
				color: #1c1c1f;
				overflow: hidden;
				text-overflow: ellipsis;
				white-space: nowrap;
			}
			.chat-file-card-meta {
				font-size: 11.5px;
				color: #9a9aa4;
				margin-top: 2px;
			}
			.chat-file-card-btn {
				background: #fff;
				color: #383941;
				border: 1px solid #dcdce0;
				border-radius: 999px;
				padding: 6px 16px;
				font-size: 12px;
				font-weight: 600;
				cursor: pointer;
				flex-shrink: 0;
  transition: background 0.15s, border-color 0.15s, color 0.15s;
  font-variant-emoji: text; /* keep the ↗ glyph plain text, not a colored emoji glyph */
  }
  .chat-file-card-btn:hover { background: #eafaf4; border-color: #10a37f; color: #10a37f; }
			.chat-artifact-card { margin: 8px 0; cursor: pointer; max-width: 380px; }
			.chat-artifact-card:focus-visible { outline: 2px solid #10a37f; outline-offset: 2px; }

			/* ── Monaco Code Sidebar ────────────────────────────────────── */
			.chat-code-sidebar {
				position: absolute;
				top: 0; right: 0;
				width: 55%; min-width: 420px; max-width: 720px;
				height: 100%;
				background: #1e1e2e;
				z-index: 100;
				display: flex; flex-direction: column;
				transform: translateX(100%);
				transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1);
				box-shadow: -8px 0 32px rgba(0,0,0,0.4);
				border-left: 1px solid #333;
			}
			.chat-code-sidebar[data-hidden="0"] { transform: translateX(0); }
			.chat-code-sidebar-header {
				display: flex;
				align-items: center;
				justify-content: space-between;
				padding: 12px 18px;
				background: #252536;
				border-bottom: 1px solid #333;
				flex-shrink: 0;
			}
			.chat-code-sidebar-title-wrap {
				display: flex;
				align-items: center;
				gap: 10px;
				min-width: 0;
				flex: 1 1 auto;
				overflow: hidden;
			}
			.chat-code-sidebar-icon {
				font-size: 18px;
				line-height: 1;
			}
			.chat-code-sidebar-title {
				font-size: 14px;
				font-weight: 600;
				color: #ececf1;
				overflow: hidden;
				text-overflow: ellipsis;
				white-space: nowrap;
			}
			.chat-code-sidebar-close {
				background: none;
				border: none;
				color: #888;
				font-size: 22px;
				cursor: pointer;
				width: 32px; height: 32px;
				display: flex;
				align-items: center;
				justify-content: center;
				border-radius: 6px;
				transition: background 0.15s, color 0.15s;
			}
			.chat-code-sidebar-close:hover {
				background: rgba(255,255,255,0.08);
				color: #fff;
			}
			.chat-code-sidebar-editor {
				flex: 1;
				min-height: 0;
				overflow: hidden;
			}
			.chat-code-sidebar-footer {
				padding: 12px 18px;
				background: #252536;
				border-top: 1px solid #333;
				display: flex;
				justify-content: flex-end;
				flex-shrink: 0;
			}
			.chat-code-sidebar-download {
				display: flex;
				align-items: center;
				gap: 6px;
				background: #10a37f;
				color: #fff;
				border: none;
				border-radius: 8px;
				padding: 8px 16px;
				font-size: 13px;
				font-weight: 600;
				cursor: pointer;
				transition: background 0.15s;
			}
			.chat-code-sidebar-download:hover { background: #0d8a6a; }
			.chat-code-sidebar-download svg {
				width: 14px; height: 14px;
			}

			/* ── HTML Preview Sidebar (separate from the Monaco editor) ── */
			.chat-preview-sidebar-frame {
				flex: 1;
				min-height: 0;
				width: 100%;
				border: none;
				background: #fff;
			}
			.chat-view-btn { margin-left: 6px; }
			#chat-preview-sidebar-title {
				overflow: hidden;
				text-overflow: ellipsis;
				white-space: nowrap;
				display: block;
			}
			.chat-preview-sidebar-actions {
				display: flex;
				align-items: center;
				gap: 4px;
				flex-shrink: 0;
			}
			.chat-preview-sidebar-fullscreen {
				flex-shrink: 0;
				color: #888;
			}
			.chat-preview-sidebar-fullscreen svg {
				display: block;
				width: 15px;
				height: 15px;
				stroke: currentColor;
				pointer-events: none;
			}
			/* Expanded state: escape the widget's own container (which is
			   normally clipped to the chat window) and cover the entire
			   browser viewport, without touching the shared
			   .chat-code-sidebar rules used by the Monaco editor sidebar. */
			.chat-preview-sidebar.chat-preview-sidebar--fullscreen {
				position: fixed;
				top: 0;
				right: 0;
				bottom: 0;
				left: 0;
				width: 100vw;
				height: 100vh;
				min-width: 0;
				max-width: none;
				z-index: 2147483000;
			}

			@media (max-width: 768px) {
				.chat-ai-chat-app { position: relative; overflow: hidden; }

				/* Sidebar becomes an off-canvas drawer instead of squeezing the chat. */
				.chat-menu-btn { display: flex; }
				.chat-sidebar {
					position: fixed; top: 0; left: 0; bottom: 0;
					width: 82%; max-width: 300px; min-width: 0;
					z-index: 200;
					transform: translateX(-100%);
					transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1);
					box-shadow: 4px 0 24px rgba(0,0,0,0.35);
					padding-top: calc(10px + env(safe-area-inset-top));
					padding-bottom: calc(10px + env(safe-area-inset-bottom));
				}
				.chat-sidebar.open { transform: translateX(0); }

				.chat-chat-header, .chat-admin-header, .chat-media-header, .chat-project-header {
					padding: 10px 12px;
					padding-top: calc(10px + env(safe-area-inset-top));
				}
				#chat-current-title { max-width: 42vw; }
				.chat-model-picker-trigger {
					font-size: 11px; padding: 3px 10px 3px 5px; max-width: 40vw;
				}
				.chat-model-picker-trigger-icon { width: 20px; height: 20px; }
				.chat-model-picker-panel {
					position: fixed; top: auto; bottom: 0; left: 0; right: 0;
					width: auto; max-height: 60vh; border-radius: 16px 16px 0 0;
					padding-bottom: calc(6px + env(safe-area-inset-bottom));
				}

				.chat-messages { padding: 14px 12px; gap: 12px; }
				.chat-msg { max-width: 92%; }
				.chat-msg-avatar { width: 28px; height: 28px; }
				.chat-msg-content { padding: 9px 12px; font-size: 14.5px; }

				.chat-input-area { padding: 10px 12px; gap: 8px; }
				.chat-input-wrap { padding-bottom: env(safe-area-inset-bottom); }
				/* 16px prevents iOS Safari from auto-zooming the page on focus. */
				.chat-input { font-size: 16px; padding: 10px 12px; max-height: 120px; }
				.chat-send-btn, .chat-attach-btn { width: 40px; height: 40px; flex-shrink: 0; }
				.chat-attach-preview { padding: 0 12px; }

				/* No hover on touch devices, so keep delete/remove controls reachable. */
				.chat-conv-delete { opacity: 0.6; }
				.chat-attach-chip { max-width: 42vw; }

				.chat-admin-body { padding: 14px; }
				.chat-admin-stats { gap: 8px; }
				.chat-admin-stat-card { min-width: 44%; padding: 12px 14px; }
				.chat-admin-model-row { flex-wrap: wrap; }

				.chat-media-body { padding: 14px; }
				.chat-media-gallery { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 10px; }
				.chat-project-body { padding: 14px; }
				.chat-project-item-delete { opacity: 0.6; }

				.chat-username-modal-box { width: 88vw; padding: 26px 20px; }

				.chat-code-sidebar {
					width: 100%;
					min-width: auto;
					max-width: none;
				}
			}

			@media (max-width: 420px) {
				.chat-admin-stat-card { min-width: 100%; }
				#chat-current-title { max-width: 34vw; }
			}

			.chat-input-wrap { position: relative; border-top: 1px solid #eee; }
			.chat-input-area { display: flex; align-items: flex-end; gap: 10px; padding: 14px 18px; }
			.chat-input {
				flex: 1; resize: none; border: 1px solid #ddd; border-radius: 8px;
				padding: 10px 12px; font-size: 14px; font-family: inherit; max-height: 140px; outline: none;
			}
			.chat-input:focus { border-color: #10a37f; }
			.chat-send-btn {
				display: flex; align-items: center; justify-content: center;
				background: #10a37f; color: #fff; border: none; border-radius: 8px;
				width: 40px; height: 40px; font-size: 16px; cursor: pointer; flex-shrink: 0;
			}
			.chat-send-btn:disabled { background: #a7d9c9; cursor: not-allowed; }
			.chat-send-btn:hover:not(:disabled) { background: #0d8a6a; }

			.chat-attach-btn {
				display: flex; align-items: center; justify-content: center;
				width: 40px; height: 40px; border-radius: 8px; border: 1px solid #ddd;
				background: #fff; color: #555; cursor: pointer; flex-shrink: 0;
				transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease, transform 0.1s ease;
			}
			.chat-attach-btn:hover { background: #eafaf4; border-color: #10a37f; color: #10a37f; }
			.chat-attach-btn:active { transform: scale(0.94); }
			.chat-attach-btn[aria-expanded="true"] { background: #eafaf4; border-color: #10a37f; color: #10a37f; }
			.chat-file-input { display: none; }

			.chat-attach-wrap { position: relative; flex-shrink: 0; }
			.chat-attach-menu {
				position: absolute; bottom: calc(100% + 8px); left: 0; z-index: 20;
				background: #fff; border: 1px solid #e5e5e5; border-radius: 10px;
				box-shadow: 0 6px 20px rgba(0,0,0,0.12); padding: 6px; min-width: 168px;
				display: flex; flex-direction: column; gap: 2px;
				animation: mlpChipIn 0.12s ease;
			}
			.chat-attach-menu[hidden] { display: none; }
			.chat-attach-menu-item {
				display: flex; align-items: center; gap: 10px; width: 100%;
				background: none; border: none; text-align: left; cursor: pointer;
				padding: 9px 10px; border-radius: 7px; font-size: 13.5px; color: #333;
				font-family: inherit;
			}
			.chat-attach-menu-item:hover { background: #f2f9f6; color: #10a37f; }
			.chat-attach-menu-icon { display: flex; align-items: center; justify-content: center; color: #666; flex-shrink: 0; }
			.chat-attach-menu-item:hover .chat-attach-menu-icon { color: #10a37f; }
			.chat-attach-menu-item.disabled,
			.chat-attach-menu-item[disabled] { opacity: 0.4; cursor: not-allowed; pointer-events: none; }

			.chat-attach-preview { display: flex; flex-wrap: wrap; gap: 8px; padding: 0 18px; }
			.chat-attach-preview:not(:empty) { padding-top: 12px; }
			.chat-attach-chip {
				position: relative; display: flex; align-items: center; gap: 6px;
				background: #f5f5f5; border: 1px solid #e5e5e5; border-radius: 10px;
				padding: 6px 10px 6px 6px; font-size: 12px; color: #444;
				max-width: 200px; animation: mlpChipIn 0.15s ease;
			}
			@keyframes mlpChipIn { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }
			.chat-attach-chip-thumb { width: 32px; height: 32px; border-radius: 6px; object-fit: cover; flex-shrink: 0; background: #ddd; }
			.chat-attach-chip-icon {
				width: 32px; height: 32px; border-radius: 6px; background: #10a37f; color: #fff;
				display: flex; align-items: center; justify-content: center; flex-shrink: 0;
			}
			.chat-attach-chip-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
			.chat-attach-chip-remove {
				border: none; background: #fff; color: #888; width: 18px; height: 18px;
				min-width: 18px; border-radius: 50%; cursor: pointer; display: flex;
				align-items: center; justify-content: center; font-size: 12px; line-height: 1;
				box-shadow: 0 0 0 1px #ddd inset;
			}
			.chat-attach-chip-remove:hover { background: #ff6b6b; color: #fff; box-shadow: none; }

			.chat-drop-hint {
				display: none; position: absolute; inset: 0; flex-direction: column;
				align-items: center; justify-content: center; gap: 6px;
				background: rgba(16, 163, 127, 0.06); border: 2px dashed #10a37f;
				border-radius: 10px; color: #10a37f; font-size: 13px; font-weight: 500;
				pointer-events: none; margin: 6px;
			}
			.chat-input-wrap.drag-over .chat-drop-hint { display: flex; }

			.chat-msg-attachments { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
			.chat-msg-attachments:first-child { margin-top: 0; }
			.chat-msg-img { max-width: 220px; max-height: 220px; border-radius: 8px; display: block; cursor: zoom-in; }
			.chat-msg-file {
				display: flex; align-items: center; gap: 6px;
				background: rgba(0,0,0,0.06); border-radius: 8px; padding: 6px 10px; font-size: 12px; max-width: 220px;
			}
			.chat-msg.user .chat-msg-file { background: rgba(255,255,255,0.2); }
			.chat-msg-file-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

			.chat-msg.typing { display: flex; align-items: center; gap: 8px; font-style: normal; padding: 12px 16px; }
			.chat-typing-dots { display: flex; align-items: center; gap: 4px; }
			.chat-typing-dots span {
				width: 7px; height: 7px; border-radius: 50%; background: #999;
				animation: mlpBounce 1.2s infinite ease-in-out both;
			}
			.chat-typing-dots span:nth-child(1) { animation-delay: -0.28s; }
			.chat-typing-dots span:nth-child(2) { animation-delay: -0.14s; }
			.chat-typing-dots span:nth-child(3) { animation-delay: 0s; }
			@keyframes mlpBounce { 0%, 80%, 100% { transform: scale(0.6); opacity: 0.5; } 40% { transform: scale(1); opacity: 1; } }

			.chat-icon-plus { position: relative; display: inline-block; width: 14px; height: 14px; }
			.chat-icon-plus::before, .chat-icon-plus::after { content: ''; position: absolute; background: currentColor; border-radius: 1px; }
			.chat-icon-plus::before { top: 0; left: 6px; width: 2px; height: 14px; }
			.chat-icon-plus::after { top: 6px; left: 0; width: 14px; height: 2px; }

			.chat-icon-send { display: inline-block; width: 0; height: 0; border-top: 7px solid transparent; border-bottom: 7px solid transparent; border-left: 13px solid #fff; margin-left: 3px; flex-shrink: 0; }
			.chat-send-btn:disabled .chat-icon-send { border-left-color: rgba(255,255,255,0.55); }

			.chat-icon-stop { display: inline-block; width: 12px; height: 12px; background: #fff; border-radius: 2px; flex-shrink: 0; }
			.chat-send-btn.is-stop { background: #d63638; }
			.chat-send-btn.is-stop:hover { background: #b9282a; }
			.chat-send-btn.is-stop:disabled { background: #eeb3b3; }

			.chat-stopped-note { margin-top: 6px; font-size: 12px; color: #999; font-style: italic; }
			.chat-model-switch-note { margin-bottom: 6px; font-size: 12px; color: #b8860b; font-style: italic; }

			.chat-cursor { display: inline-block; width: 2px; height: 1em; background: #555; margin-left: 2px; vertical-align: text-bottom; animation: mlpBlink 0.75s step-end infinite; }
			@keyframes mlpBlink { 0%, 100% { opacity: 1; } 50% { opacity: 0; } }
			/* While a reply is still streaming in it's rendered as plain text
			   into a single text node (fast) instead of re-parsing HTML on
			   every token, so newlines need to wrap via CSS instead of <br>. */
			.chat-msg-text { white-space: pre-wrap; word-wrap: break-word; }

			.chat-thinking { margin-bottom: 6px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden; font-size: 12px; background: #fafafa; }
			.chat-thinking summary { cursor: pointer; padding: 6px 10px; color: #888; user-select: none; list-style: none; display: flex; align-items: center; gap: 6px; }
			.chat-thinking summary::-webkit-details-marker { display: none; }
			.chat-thinking summary::before { content: '+'; font-size: 15px; font-weight: 400; width: 14px; color: #888; transition: transform 0.15s; display: inline-flex; align-items: center; justify-content: center; }
			.chat-thinking[open] summary::before { content: '−'; transform: none; }
			.chat-thinking-body { display: none; padding: 8px 12px; border-top: 1px solid #e8e8e8; color: #777; line-height: 1.5; white-space: pre-wrap; word-wrap: break-word; max-height: 260px; overflow-y: auto; }
			.chat-activity-list { display: flex; flex-direction: column; gap: 5px; padding: 8px 10px; border-top: 1px solid #e8e8e8; background: #f7f7f8; }
			.chat-activity-row { display: block; padding: 5px 7px; border-radius: 6px; color: #666; cursor: pointer; transition: background .12s, color .12s; }
			.chat-activity-row:hover, .chat-activity-row[open] { background: #ececef; color: #222; }
			.chat-activity-row:not([open]) .chat-activity-icon { opacity: .78; }
			.chat-activity-row:not([open]) .chat-activity-summary::after { content: 'Done'; margin-left: auto; color: #999; font-size: 11px; }
			.chat-activity-icon { width: 18px; height: 18px; margin-top: 1px; display: inline-flex; align-items: center; justify-content: center; color: #10a37f; flex: 0 0 auto; }
			.chat-activity-icon svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
			.chat-activity-row[data-type="reading"] .chat-activity-icon { color: #4c8bf5; }
			.chat-activity-row[data-type="editing"] .chat-activity-icon { color: #a855f7; }
			.chat-activity-row[data-type="checking"] .chat-activity-icon { color: #e59f22; }
			.chat-activity-summary { display: flex; align-items: center; gap: 8px; }
			.chat-activity-summary { list-style: none; font-size: 12px; line-height: 1.4; }
			.chat-activity-summary::-webkit-details-marker { display: none; }
			.chat-activity-detail { margin: 3px 0 0 15px; color: #888; font-size: 11px; line-height: 1.45; }

			/* ── Code status indicator (Thinking/Editing while code streams) ── */
  .chat-code-status {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 5px 10px;
  margin: 2px 0 8px 2px;
  border-radius: 8px;
  background: transparent;
  width: fit-content;
  }
			.chat-code-status-icon {
				flex-shrink: 0;
				color: #888;
				animation: chatCodeStatusSpin 1s linear infinite;
			}
			@keyframes chatCodeStatusSpin {
				from { transform: rotate(0deg); }
				to { transform: rotate(360deg); }
			}
			.chat-code-status-text {
				font-size: 12.5px;
				font-weight: 500;
				background: linear-gradient(90deg, #b5b5b5 0%, #3a3a3a 45%, #b5b5b5 90%);
				background-size: 200% 100%;
				-webkit-background-clip: text;
				background-clip: text;
				color: transparent;
				animation: chatCodeStatusShimmer 1.6s linear infinite;
			}
			@keyframes chatCodeStatusShimmer {
				0% { background-position: 200% 0; }
				100% { background-position: -200% 0; }
			}

			/* ── Administration room ─────────────��───────────────────────── */
			.chat-sidebar-divider { height: 1px; background: #3a3b3d; margin: 10px 0; flex-shrink: 0; }
			.chat-sidebar-disclaimer {
				margin: 14px 0 0 0 !important; padding-top: 12px; flex-shrink: 0;
				border-top: 1px solid #3a3b3d; font-size: 11px; line-height: 1.4; color: #8e8ea0;
			}
			.chat-sidebar-disclaimer a { color: #10a37f; text-decoration: none; }
			.chat-sidebar-disclaimer a:hover { text-decoration: underline; }
			.chat-sidebar-source-link {
				display: flex; align-items: center; gap: 8px; margin-top: 10px; flex-shrink: 0;
				font-size: 12px; color: #8e8ea0; text-decoration: none; transition: color 0.15s ease;
			}
			.chat-sidebar-source-link:hover { color: #ffffff; }
			.chat-sidebar-source-link svg { flex-shrink: 0; }
			.chat-room-btn {
				background: transparent; border: 1px solid #565869; color: #ececf1;
				padding: 10px 12px; border-radius: 6px; cursor: pointer; text-align: left;
				font-size: 14px; display: flex; align-items: center; gap: 8px; flex-shrink: 0;
			}
			.chat-room-btn:hover, .chat-room-btn.active { background: #2b2c2f; }
			.chat-room-btn.active { border-color: #10a37f; color: #10a37f; }
			.chat-room-btn-icon { font-size: 14px; }

			/* ── Profile / settings ──────────────────────────────────────── */
			.chat-profile { position: relative; flex-shrink: 0; }
			.chat-profile-trigger {
				width: 100%; display: flex; align-items: center; gap: 10px;
				background: none; border: none; border-radius: 8px;
				padding: 8px; cursor: pointer; color: #ececf1; font-family: inherit;
				text-align: left;
			}
			.chat-profile-trigger:hover, .chat-profile-trigger[aria-expanded="true"] { background: #2b2c2f; }
			.chat-profile-avatar {
				width: 30px; height: 30px; flex-shrink: 0; border-radius: 50%;
				background: #10a37f; color: #fff; font-size: 13px; font-weight: 700;
				display: flex; align-items: center; justify-content: center;
				text-transform: uppercase;
			}
			.chat-profile-name {
				flex: 1; min-width: 0;
				font-size: 13px; font-weight: 600; color: #ececf1;
				overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
			}
			.chat-profile-gear { display: flex; align-items: center; justify-content: center; color: #8e8ea0; flex-shrink: 0; }
			.chat-profile-trigger:hover .chat-profile-gear { color: #ececf1; }
			.chat-profile-menu {
				position: absolute; bottom: calc(100% + 6px); left: 0; right: 0;
				background: #2b2c2f; border: 1px solid #3a3b3d; border-radius: 10px;
				padding: 6px; box-shadow: 0 8px 24px rgba(0,0,0,0.35); z-index: 20;
			}
			.chat-profile-menu[hidden] { display: none; }
			.chat-profile-menu-item {
				width: 100%; display: flex; align-items: center; gap: 9px;
				background: none; border: none; border-radius: 7px;
				padding: 8px 9px; font-size: 13px; color: #ececf1; font-family: inherit;
				cursor: pointer; text-align: left;
			}
			.chat-profile-menu-item svg { flex-shrink: 0; color: #b7b7bd; }
			.chat-profile-menu-item:hover { background: #3a3b3d; }
			.chat-profile-menu-item.danger { color: #ff6b6b; }
			.chat-profile-menu-item.danger svg { color: #ff6b6b; }
			.chat-profile-menu-divider { height: 1px; background: #3a3b3d; margin: 5px 2px; }

			.chat-admin-view { display: none; overflow-y: auto; }
			.chat-admin-view[data-hidden="1"] { display: none; }
			.chat-admin-view[data-hidden="0"] { display: flex; }
			.chat-admin-header {
				padding: 12px 18px; border-bottom: 1px solid #eee; font-weight: 600;
				display: flex; justify-content: space-between; align-items: center; font-size: 14px; color: #333; flex-shrink: 0; gap: 10px;
			}
			.chat-admin-refresh-btn {
				background: #f0f0f0; border: 1px solid #e0e0e0; border-radius: 8px;
				padding: 5px 12px; font-size: 12px; cursor: pointer; color: #555;
			}
			.chat-admin-refresh-btn:hover { background: #e8e8e8; }
			.chat-admin-body { padding: 20px; overflow-y: auto; flex: 1; }
			.chat-admin-stats { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 22px; }
			.chat-admin-stat-card {
				background: #f7f7f8; border: 1px solid #eee; border-radius: 8px;
				padding: 14px 16px; min-width: 150px; flex: 1;
			}
			.chat-admin-stat-label { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: .03em; margin-bottom: 6px; }
			.chat-admin-stat-value { font-size: 20px; font-weight: 700; color: #222; }
			.chat-admin-section { margin-bottom: 26px; }
			.chat-admin-section-head h3 { margin: 0 0 8px 0; font-size: 15px; color: #222; }
			.chat-admin-note { font-size: 12px; color: #888; margin: 0 0 10px 0; }
			.chat-admin-toggle-btn {
				background: #10a37f; color: #fff; border: none; border-radius: 8px;
				padding: 9px 16px; font-size: 13px; font-weight: 600; cursor: pointer;
			}
			.chat-admin-toggle-btn.is-disabled { background: #d63638; }
			.chat-admin-toggle-btn:hover { opacity: 0.9; }
			.chat-admin-models { display: flex; flex-direction: column; gap: 8px; }
			.chat-admin-model-row {
				display: flex; align-items: center; justify-content: space-between; gap: 10px;
				background: #f7f7f8; border: 1px solid #eee; border-radius: 8px; padding: 10px 14px;
			}
			.chat-admin-model-name { font-size: 13px; font-weight: 600; color: #222; }
			.chat-admin-model-meta { font-size: 11px; color: #888; margin-top: 2px; }
			.chat-admin-model-status { display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; }
			.chat-status-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
			.chat-status-online       { color: #00a32a; } .chat-status-online .chat-status-dot       { background: #00a32a; }
			.chat-status-rate_limited { color: #b8860b; } .chat-status-rate_limited .chat-status-dot { background: #dba617; }
			.chat-status-blocked      { color: #d63638; } .chat-status-blocked .chat-status-dot      { background: #d63638; }
			.chat-status-error        { color: #d63638; } .chat-status-error .chat-status-dot        { background: #d63638; }
			.chat-status-offline      { color: #787c82; } .chat-status-offline .chat-status-dot      { background: #787c82; }
			.chat-status-cooldown     { color: #b8860b; } .chat-status-cooldown .chat-status-dot     { background: #dba617; }
			.chat-status-disabled     { color: #d63638; } .chat-status-disabled .chat-status-dot     { background: #d63638; }
			.chat-status-unknown      { color: #787c82; } .chat-status-unknown .chat-status-dot      { background: #ababab; }
			/* ── Media room ───────────────────────────────────────────────���─ */
			.chat-media-view { display: none; overflow-y: auto; }
			.chat-media-view[data-hidden="1"] { display: none; }
			.chat-media-view[data-hidden="0"] { display: flex; }
			.chat-media-header {
				padding: 12px 18px; border-bottom: 1px solid #eee; font-weight: 600;
				display: flex; justify-content: space-between; align-items: center; font-size: 14px; color: #333; flex-shrink: 0; gap: 10px;
			}
			.chat-media-add-btn {
				display: flex; align-items: center; gap: 6px;
				background: #10a37f; color: #fff; border: none; border-radius: 8px;
				padding: 8px 14px; font-size: 13px; font-weight: 600; cursor: pointer;
			}
			.chat-media-add-btn:hover { background: #0d8f6e; }
			.chat-media-body { padding: 20px; overflow-y: auto; flex: 1; }
			.chat-media-gallery {
				display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 14px;
			}
			.chat-media-empty { display: none; text-align: center; color: #8e8ea0; font-size: 13.5px; padding: 40px 20px; }
			.chat-media-gallery:empty + .chat-media-empty { display: block; }
			.chat-media-item {
				position: relative; border: 1px solid #eee; border-radius: 10px; overflow: hidden;
				background: #f7f7f8; aspect-ratio: 1 / 1; display: flex; align-items: center; justify-content: center;
			}
			.chat-media-item img, .chat-media-item video { width: 100%; height: 100%; object-fit: cover; display: block; }
			.chat-media-item-file-icon { color: #8e8ea0; }
			.chat-media-item-overlay {
				position: absolute; inset: 0; background: rgba(0,0,0,0.55);
				display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px;
				opacity: 0; transition: opacity 0.15s ease; padding: 10px; box-sizing: border-box;
			}
			.chat-media-item:hover .chat-media-item-overlay,
			.chat-media-item:focus-within .chat-media-item-overlay { opacity: 1; }
			.chat-media-item-name {
				position: absolute; left: 0; right: 0; bottom: 0; padding: 6px 8px;
				background: linear-gradient(transparent, rgba(0,0,0,0.65));
				color: #fff; font-size: 11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
			}
			.chat-media-item-insert-btn, .chat-media-item-delete-btn {
				border: none; border-radius: 6px; padding: 6px 10px; font-size: 12px; font-weight: 600; cursor: pointer; width: 100%;
			}
			.chat-media-item-insert-btn { background: #10a37f; color: #fff; }
			.chat-media-item-insert-btn:hover { background: #0d8f6e; }
			.chat-media-item-delete-btn { background: rgba(255,255,255,0.15); color: #fff; }
			.chat-media-item-delete-btn:hover { background: #d63638; }

			.chat-admin-model-toggle {
				background: #fff; border: 1px solid #ddd; border-radius: 6px; padding: 6px 12px;
				font-size: 12px; cursor: pointer; color: #444; flex-shrink: 0;
			}
			.chat-admin-model-toggle:hover { background: #f0f0f0; }
			.chat-admin-model-toggle.is-disabled { color: #10a37f; border-color: #10a37f; }
			.chat-admin-model-reactivate {
				background: #d63638; border: 1px solid #d63638; border-radius: 6px; padding: 6px 12px;
				font-size: 12px; font-weight: 600; cursor: pointer; color: #fff; flex-shrink: 0; margin-left: 6px;
			}
			.chat-admin-model-reactivate:hover { background: #b32d2e; border-color: #b32d2e; }
			.chat-admin-model-votes {
				display: flex; align-items: center; gap: 12px; font-size: 12px; font-weight: 600; flex-shrink: 0;
			}
			.chat-admin-model-vote { display: flex; align-items: center; gap: 4px; }
			.chat-admin-model-vote.likes { color: #00a32a; }
			.chat-admin-model-vote.dislikes { color: #d63638; }
			.chat-admin-model-vote svg { display: block; }

			.chat-reload-btn {
				background: #10a37f;
				color: #fff;
				border: none;
				border-radius: 8px;
				padding: 10px 20px;
				font-size: 14px;
				font-weight: 600;
				cursor: pointer;
				transition: background 0.15s, transform 0.1s;
				display: inline-flex;
				align-items: center;
				gap: 6px;
			}
			.chat-reload-btn:hover {
				background: #0d8a6a;
				transform: translateY(-1px);
			}
				</style>

		<script>
		(function() {
			// Full-page takeover.
			(function fullPageTakeover() {
				var wrap = document.getElementById('chat-ai-chat-fullpage');
				if (!wrap) return;
				wrap.id = 'chat-ai-chat-fullpage-portal';
				document.body.appendChild(wrap);
				document.documentElement.classList.add('chat-fullpage-active');
				document.body.classList.add('chat-fullpage-active');
				Array.prototype.forEach.call(document.body.children, function(el) {
					if (el !== wrap) el.setAttribute('data-mlp-hidden', '1');
				});
			})();

			var restUrl    = <?php echo wp_json_encode( $rest_url ); ?>;
			var nonce      = <?php echo wp_json_encode( $nonce ); ?>;
			var jsModels   = <?php echo wp_json_encode( $js_models ); ?>;
			var jsLanguages = <?php echo wp_json_encode( $js_languages ); ?>;
			var TURNSTILE_SITE_KEY = <?php echo wp_json_encode( $turnstile_site_key ); ?>;
			var WP_USER_DISPLAY_NAME = <?php echo wp_json_encode( $wp_display_name ); ?>;
			var IS_WP_USER = <?php echo $user_id ? 'true' : 'false'; ?>;

			// ─��� Language / i18n ──────────────────────────────────────────────
			// Codes here must match MLP_AI_CHAT_LANGUAGES on the PHP side
			// (that's what drives the <select> options and the "reply in
			// this language" instruction sent to the AI). `i18n` is this
			// editor's own UI copy in that language — add a new language by
			// adding it to MLP_AI_CHAT_LANGUAGES in PHP *and* an entry here.
			var LANG_STORAGE_KEY = 'mlp_ai_chat_lang';
			var LANGS = {
				en: { dir: 'ltr', i18n: {
					welcome_title: 'Welcome', welcome_desc: "Pick a name to use the AI chat. It's saved on this device so your conversations are here next time.",
					name_placeholder: 'Your name', start_chatting: 'Start Chatting', new_chat: '+ New Chat', new_chat_title: 'New Chat',
				media: 'Media', add_media: 'Add Media', media_empty: 'No media yet — click "+ Add Media" to upload images or videos from your device.',
					projects: 'Projects', new_project: 'New project', new_project_desc: 'Give your project a name to keep related chats together.',
					project_name_placeholder: 'Project name', create_project: 'Create project', cancel: 'Cancel', new_chat_in_project: '+ New Chat',
					project_empty: 'No chats in this project yet — click "+ New Chat" to start one.', error_enter_project_name: 'Please enter a project name.',
					confirm_delete_project: 'Delete this project? Chats inside it will move back to your regular chat list.',
					search_placeholder: 'Search chats...', choose_model: 'Choose model', administration: 'Administration',
					terms_of_service: 'Terms of Service', privacy_policy: 'Privacy Policy', featured_on: 'Featured On',
					new_models: 'New Models', source_code: 'Source code', disabled_banner: 'The AI chat has been temporarily disabled by the site administrator.',
					error_enter_name: 'Please enter a name.', error_name_too_long: 'Name is too long (30 characters max).',
					error_verification: 'Please complete the verification below.', error_verification_failed: 'Verification failed, please try again.'
				} },
				ar: { dir: 'rtl', i18n: {
					welcome_title: 'أهلاً بك', welcome_desc: 'اختر اسمًا لاستخدام محادثة الذكاء الاصطناعي. يُحفظ على هذا الجهاز لتجد محادثاتك في المرة القادمة.',
					name_placeholder: 'اسمك', start_chatting: 'ابدأ المحادثة', new_chat: '+ محادثة جديدة', new_chat_title: 'محادثة جديدة',
					search_placeholder: 'ابحث في المحادثات...', choose_model: 'اختر نموذجًا', administration: 'الإدارة',
					terms_of_service: 'شروط الخدمة', privacy_policy: 'سياسة الخصوصية', featured_on: 'ظهرنا في',
					new_models: 'نماذج جديدة', source_code: 'الكود المصدري', disabled_banner: 'تم تعطيل محادثة الذكاء الاصطناعي مؤقتًا من قبل مسؤول الموقع.',
					error_enter_name: 'الرجاء إدخال اسم.', error_name_too_long: 'الاسم طويل جدًا (30 حرفًا كحد أقصى).',
					error_verification: 'يرجى إكمال الت������قق أدناه.', error_verification_failed: 'فشل التحقق، حاول مرة أخرى.'
				} },
				zh: { dir: 'ltr', i18n: {
					welcome_title: '欢迎', welcome_desc: '选择一个名字来使用 AI 聊天。它会保存在此设备上，方便您下次继续对话。',
					name_placeholder: '你的名字', start_chatting: '开始聊天', new_chat: '+ 新建聊天', new_chat_title: '新建���天',
					search_placeholder: '搜索聊天记录...', choose_model: '选择模型', administration: '管理',
					terms_of_service: '服务条款', privacy_policy: '隐私政策', featured_on: '媒体报道',
					new_models: '新模型', source_code: '源代码', disabled_banner: '网站管理员已暂时禁用 AI 聊天。',
					error_enter_name: '请输入名字。', error_name_too_long: '名字过长（最多 30 个字符）。',
					error_verification: '请完成下方验证。', error_verification_failed: '验证失败，请重试。'
				} },
				es: { dir: 'ltr', i18n: {
					welcome_title: 'Bienvenido', welcome_desc: 'Elige un nombre para usar el chat de IA. Se guarda en este dispositivo para que tus conversaciones sigan aquí la próxima vez.',
					name_placeholder: 'Tu nombre', start_chatting: 'Empezar a chatear', new_chat: '+ Nuevo chat', new_chat_title: 'Nuevo chat',
					search_placeholder: 'Buscar chats...', choose_model: 'Elegir modelo', administration: 'Administración',
					terms_of_service: 'Términos del servicio', privacy_policy: 'Política de privacidad', featured_on: 'Aparecemos en',
					new_models: 'Nuevos modelos', source_code: 'Código fuente', disabled_banner: 'El chat de IA ha sido desactivado temporalmente por el administrador del sitio.',
					error_enter_name: 'Por favor, introduce un nombre.', error_name_too_long: 'El nombre es demasiado largo (máximo 30 caracteres).',
					error_verification: 'Completa la verificación de abajo.', error_verification_failed: 'Verificación fallida, inténtalo de nuevo.'
				} },
				fr: { dir: 'ltr', i18n: {
					welcome_title: 'Bienvenue', welcome_desc: "Choisissez un nom pour utiliser le chat IA. Il est enregistré sur cet appareil afin de retrouver vos conversations la prochaine fois.",
					name_placeholder: 'Votre nom', start_chatting: 'Commencer à discuter', new_chat: '+ Nouvelle discussion', new_chat_title: 'Nouvelle discussion',
					search_placeholder: 'Rechercher des discussions...', choose_model: 'Choisir un modèle', administration: 'Administration',
					terms_of_service: "Conditions d'utilisation", privacy_policy: 'Politique de confidentialité', featured_on: 'Ils parlent de nous',
					new_models: 'Nouveaux modèles', source_code: 'Code source', disabled_banner: "Le chat IA a été temporairement désactivé par l'administrateur du site.",
					error_enter_name: 'Veuillez saisir un nom.', error_name_too_long: 'Le nom est trop long (30 caractères maximum).',
					error_verification: 'Veuillez compléter la vérification ci-dessous.', error_verification_failed: 'Échec de la vérification, veuillez réessayer.'
				} },
				de: { dir: 'ltr', i18n: {
					welcome_title: 'Willkommen', welcome_desc: 'Wähle einen Namen für den KI-Chat. Er wird auf diesem Gerät gespeichert, damit deine Unterhaltungen beim nächsten Mal noch da sind.',
					name_placeholder: 'Dein Name', start_chatting: 'Chat starten', new_chat: '+ Neuer Chat', new_chat_title: 'Neuer Chat',
					search_placeholder: 'Chats durchsuchen...', choose_model: 'Modell wählen', administration: 'Verwaltung',
					terms_of_service: 'Nutzungsbedingungen', privacy_policy: 'Datenschutzerklärung', featured_on: 'Erwähnt auf',
					new_models: 'Neue Modelle', source_code: 'Quellcode', disabled_banner: 'Der KI-Chat wurde vom Website-Administrator vorübergehend deaktiviert.',
					error_enter_name: 'Bitte gib einen Namen ein.', error_name_too_long: 'Der Name ist zu lang (max. 30 Zeichen).',
					error_verification: 'Bitte schließe die Verifizierung unten ab.', error_verification_failed: 'Verifizierung fehlgeschlagen, bitte versuche es erneut.'
				} },
				pt: { dir: 'ltr', i18n: {
					welcome_title: 'Bem-vindo', welcome_desc: 'Escolha um nome para usar o chat de IA. Ele é salvo neste dispositivo para suas conversas continuarem aqui na próxima vez.',
					name_placeholder: 'Seu nome', start_chatting: 'Começar a conversar', new_chat: '+ Nova conversa', new_chat_title: 'Nova conversa',
					search_placeholder: 'Pesquisar conversas...', choose_model: 'Escolher modelo', administration: 'Administração',
					terms_of_service: 'Termos de Serviço', privacy_policy: 'Política de Privacidade', featured_on: 'Já falaram de nós',
					new_models: 'Novos modelos', source_code: 'Código-fonte', disabled_banner: 'O chat de IA foi temporariamente desativado pelo administrador do site.',
					error_enter_name: 'Por favor, insira um nome.', error_name_too_long: 'Nome muito longo (máximo de 30 caracteres).',
					error_verification: 'Conclua a verificação abaixo.', error_verification_failed: 'Falha na verificação, tente novamente.'
				} },
				ru: { dir: 'ltr', i18n: {
					welcome_title: 'Добро пожаловать', welcome_desc: 'Выберите имя для использования ИИ-чата. Оно сохраняется на этом устройстве, чтобы ваши беседы были здесь в следующий раз.',
					name_placeholder: 'Ваше имя', start_chatting: 'Начать чат', new_chat: '+ Новый чат', new_chat_title: 'Новый чат',
					search_placeholder: 'Поиск по ��атам...', choose_model: 'Выбрать модель', administration: 'Администрирование',
					terms_of_service: 'Условия использования', privacy_policy: 'Политика конфиденциальности', featured_on: 'О нас пишут',
					new_models: 'Новые модели', source_code: 'Исходный код', disabled_banner: 'ИИ-чат временно отключён администратором сайта.',
					error_enter_name: 'Пожалуйста, введите имя.', error_name_too_long: 'Имя слишком длинное (максимум 30 символов).',
					error_verification: 'Пожалуйста, пройдите проверку ниже.', error_verification_failed: 'Проверка не пройдена, попробуйте снова.'
				} },
				hi: { dir: 'ltr', i18n: {
					welcome_title: 'स्वागत है', welcome_desc: 'AI चैट इस्तेमा�� करने के लिए एक नाम चुनें। यह इस डिवाइस पर सेव रहेगा ताकि अगली बार आपकी बातचीत यहीं मिले।',
					name_placeholder: 'आपका नाम', start_chatting: 'चैट शुरू करें', new_chat: '+ नई चैट', new_chat_title: 'नई चैट',
					search_placeholder: 'चैट खोजें...', choose_model: 'मॉडल चुनें', administration: 'प्रशासन',
					terms_of_service: 'सेवा की शर्तें', privacy_policy: 'गोपनीयता नीति', featured_on: 'हमारी चर्चा यहाँ हुई',
					new_models: 'नए मॉडल', source_code: 'सोर्स कोड', disabled_banner: 'साइट व्यवस्थापक ने AI चैट को अस्थायी रूप से बंद कर दिया है।',
					error_enter_name: 'कृपया एक नाम दर्ज करें।', error_name_too_long: 'नाम बहुत लंबा है (अधिकतम 30 अक्षर)।',
					error_verification: 'कृपया नीचे सत्यापन ��ूरा करें।', error_verification_failed: 'सत्यापन विफल रहा, कृपया पुनः प्रयास करें।'
				} },
				ja: { dir: 'ltr', i18n: {
					welcome_title: 'ようこそ', welcome_desc: 'AIチャットを使うための名前を選んでください。この端末に保存され、次回もここで会話を続けられます。',
					name_placeholder: 'お名前', start_chatting: 'チャットを始める', new_chat: '+ 新しいチャット', new_chat_title: '新しいチャット',
					search_placeholder: 'チャットを検索...', choose_model: 'モデルを選択', administration: '管理',
					terms_of_service: '利用規約', privacy_policy: 'プライバシーポリシー', featured_on: '掲載メディア',
					new_models: '新しいモデル', source_code: 'ソースコード', disabled_banner: 'サイト管理者によりAIチャットは一時的に無効化されています。',
					error_enter_name: '名前を入力してください。', error_name_too_long: '名前が長すぎます（最大30文字）。',
					error_verification: '下記の確認を完了してください。', error_verification_failed: '確認に失敗しました。もう一度お試しください。'
				} },
				ko: { dir: 'ltr', i18n: {
					welcome_title: '환영합니다', welcome_desc: 'AI 채팅을 사용할 이름을 선택하세요. 이 기기에 저장되어 다음에도 대화를 이어갈 수 있습니다.',
					name_placeholder: '이름', start_chatting: '채팅 시작', new_chat: '+ 새 채팅', new_chat_title: '새 채팅',
					search_placeholder: '채팅 검색...', choose_model: '모델 선택', administration: '관리',
					terms_of_service: '서비스 약관', privacy_policy: '개인정보 처리방침', featured_on: '소개된 곳',
					new_models: '새 모델', source_code: '소스 코드', disabled_banner: '사이트 관리자가 AI 채팅을 일시적으로 비활성화했습니다.',
					error_enter_name: '이름을 입력해 주세요.', error_name_too_long: '이름이 너무 깁니다 (최대 30자).',
					error_verification: '아래 인���을 완료해 주세요.', error_verification_failed: '인증에 실패했습니다. 다시 시도해 주세요.'
				} },
				tr: { dir: 'ltr', i18n: {
					welcome_title: 'Hoş geldiniz', welcome_desc: 'Yapay zeka sohbetini kullanmak için bir isim seçin. Bu cihazda saklanır, böylece sohbetleriniz bir sonraki sefer burada olur.',
					name_placeholder: 'Adınız', start_chatting: 'Sohbete başla', new_chat: '+ Yeni sohbet', new_chat_title: 'Yeni sohbet',
					search_placeholder: 'Sohbetlerde ara...', choose_model: 'Model seç', administration: 'Yönetim',
					terms_of_service: 'Hizmet Şartları', privacy_policy: 'Gizlilik Politikas��', featured_on: 'Bizden bahsedenler',
					new_models: 'Yeni modeller', source_code: 'Kaynak kod', disabled_banner: 'Yapay zeka sohbeti site yöneticisi tarafından geçici olarak devre dışı bırakıldı.',
					error_enter_name: 'Lütfen bir isim girin.', error_name_too_long: 'İsim çok uzun (en fazla 30 karakter).',
					error_verification: 'Lütfen aşağıdaki doğrulamayı tamamlayın.', error_verification_failed: 'Doğrulama başarısız, lütfen tekrar deneyin.'
				} },
				it: { dir: 'ltr', i18n: {
					welcome_title: 'Benvenuto', welcome_desc: 'Scegli un nome per usare la chat IA. Viene salvato su questo dispositivo così le tue conversazioni saranno qui la prossima volta.',
					name_placeholder: 'Il tuo nome', start_chatting: 'Inizia a chattare', new_chat: '+ Nuova chat', new_chat_title: 'Nuova chat',
					search_placeholder: 'Cerca nelle chat...', choose_model: 'Scegli modello', administration: 'Amministrazione',
					terms_of_service: 'Termini di servizio', privacy_policy: 'Informativa sulla privacy', featured_on: 'Hanno parlato di noi',
					new_models: 'Nuovi modelli', source_code: 'Codice sorgente', disabled_banner: "La chat IA è stata temporaneamente disabilitata dall'amministratore del sito.",
					error_enter_name: 'Inserisci un nome.', error_name_too_long: 'Il nome è troppo lungo (massimo 30 caratteri).',
					error_verification: 'Completa la verifica qui sotto.', error_verification_failed: 'Verifica non riuscita, riprova.'
				} },
				id: { dir: 'ltr', i18n: {
					welcome_title: 'Selamat datang', welcome_desc: 'Pilih nama untuk menggunakan chat AI. Nama disimpan di perangkat ini agar percakapan Anda tetap ada lain kali.',
					name_placeholder: 'Nama Anda', start_chatting: 'Mulai mengobrol', new_chat: '+ Obrolan baru', new_chat_title: 'Obrolan baru',
					search_placeholder: 'Cari obrolan...', choose_model: 'Pilih model', administration: 'Administrasi',
					terms_of_service: 'Ketentuan Layanan', privacy_policy: 'Kebijakan Privasi', featured_on: 'Diliput di',
					new_models: 'Model baru', source_code: 'Kode sumber', disabled_banner: 'Chat AI untuk sementara dinonaktifkan oleh admin situs.',
					error_enter_name: 'Silakan masukkan nama.', error_name_too_long: 'Nama terlalu panjang (maksimal 30 karakter).',
					error_verification: 'Silakan selesaikan verifikasi di bawah.', error_verification_failed: 'Verifikasi gagal, silakan coba lagi.'
				} }
			};

			function detectDefaultLang() {
				var nav = (navigator.language || navigator.userLanguage || 'en').toLowerCase().slice(0, 2);
				return LANGS[nav] ? nav : 'en';
			}

			function loadStoredLang() {
				try {
					var v = window.localStorage.getItem(LANG_STORAGE_KEY);
					return (v && LANGS[v]) ? v : null;
				} catch (e) { return null; }
			}

			function saveStoredLang(code) {
				try { window.localStorage.setItem(LANG_STORAGE_KEY, code); } catch (e) {}
			}

			var currentLang = loadStoredLang() || detectDefaultLang();

			function t(key) {
				var dict = (LANGS[currentLang] || LANGS.en).i18n;
				return dict[key] || LANGS.en.i18n[key] || key;
			}

			// Applies `code`'s UI copy to every element carrying data-i18n /
			// data-i18n-placeholder, flips the page direction for RTL
			// languages (e.g. Arabic), and syncs both language <select>
			// elements (modal + header) to match.
			function applyLanguage(code) {
				if (!LANGS[code]) code = 'en';
				currentLang = code;
				var dict = LANGS[code].i18n;

				document.documentElement.setAttribute('lang', code);
				document.documentElement.setAttribute('dir', LANGS[code].dir || 'ltr');

				Array.prototype.forEach.call(document.querySelectorAll('[data-i18n]'), function(el) {
					var key = el.getAttribute('data-i18n');
					if (dict[key]) el.textContent = dict[key];
				});
				Array.prototype.forEach.call(document.querySelectorAll('[data-i18n-placeholder]'), function(el) {
					var key = el.getAttribute('data-i18n-placeholder');
					if (dict[key]) el.placeholder = dict[key];
				});

				var selects = document.querySelectorAll('#chat-username-lang-select, #chat-header-lang-select');
				Array.prototype.forEach.call(selects, function(sel) { sel.value = code; });
			}

			function setLanguage(code) {
				applyLanguage(code);
				saveStoredLang(code);
			}

			// Apply immediately, before anything else renders, so the
			// username modal (first thing a new visitor sees) already
			// shows in the detected/saved language.
			applyLanguage(currentLang);

			// ── Identity (username + guest token) ────────────────────��─────
			// Logged-out visitors pick a display name once; it's stored in
			// localStorage alongside a random token, so their conversations
			// stay theirs (and separate from anyone else picking the same
			// name) and persist across visits without needing a WP account.
			var IDENTITY_KEY = 'mlp_ai_chat_identity';
			var CONVOS_KEY   = 'mlp_ai_chat_conversations_v2';
			var PROJECTS_KEY = 'mlp_ai_chat_projects_v1';
			var STALE_MS     = 10 * 24 * 60 * 60 * 1000; // 10 days

			function loadIdentity() {
				try {
					var raw = window.localStorage.getItem(IDENTITY_KEY);
					if (!raw) return null;
					var parsed = JSON.parse(raw);
					if (parsed && parsed.token && parsed.username) return parsed;
					return null;
				} catch (e) {
					return null;
				}
			}

			function saveIdentity(username, existingToken) {
				var token = existingToken || ((window.crypto && window.crypto.randomUUID)
					? window.crypto.randomUUID().replace(/-/g, '')
					: (Date.now().toString(36) + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2)));
				var identity = { username: username, token: token };
				try { window.localStorage.setItem(IDENTITY_KEY, JSON.stringify(identity)); } catch (e) {}
				return identity;
			}

			var identity   = loadIdentity();
			var guestToken = identity ? identity.token : '';

			// ── Conversation storage (100% client-side) ─────────────────────
			// Every conversation and message lives only in this browser's
			// localStorage. The server never sees or stores chat content —
			// it only ever receives one request's worth of history in
			// transit, to relay to the AI API, and forgets it immediately
			// after streaming the reply back.
			function newId() {
				return (window.crypto && window.crypto.randomUUID)
					? window.crypto.randomUUID()
					: ('c_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10));
			}
			function readConvos() {
				try {
					var raw = window.localStorage.getItem(CONVOS_KEY);
					var list = raw ? JSON.parse(raw) : [];
					return Array.isArray(list) ? list : [];
				} catch (e) { return []; }
			}
			function writeConvos(list) {
				try { window.localStorage.setItem(CONVOS_KEY, JSON.stringify(list)); } catch (e) {}
			}
			function getConvo(id) {
				var list = readConvos();
				for (var i = 0; i < list.length; i++) { if (list[i].id === id) return list[i]; }
				return null;
			}
			function upsertConvo(convo) {
				var list = readConvos();
				var idx  = -1;
				for (var i = 0; i < list.length; i++) { if (list[i].id === convo.id) { idx = i; break; } }
				if (idx === -1) list.unshift(convo); else list[idx] = convo;
				writeConvos(list);
			}
			function deleteConvoLocal(id) {
				writeConvos(readConvos().filter(function(c) { return c.id !== id; }));
			}

			// ── Projects (ChatGPT-style, 100% client-side) ───────────────────
			// Projects are just a named grouping applied to conversations via
			// their project_id field; everything still lives in the same
			// localStorage conversation list.
			function readProjects() {
				try {
					var raw = window.localStorage.getItem(PROJECTS_KEY);
					var list = raw ? JSON.parse(raw) : [];
					return Array.isArray(list) ? list : [];
				} catch (e) { return []; }
			}
			function writeProjects(list) {
				try { window.localStorage.setItem(PROJECTS_KEY, JSON.stringify(list)); } catch (e) {}
			}
			function getProject(id) {
				var list = readProjects();
				for (var i = 0; i < list.length; i++) { if (list[i].id === id) return list[i]; }
				return null;
			}
			function createProject(name) {
				var project = { id: newId(), name: name, created_at: new Date().toISOString() };
				var list = readProjects();
				list.unshift(project);
				writeProjects(list);
				return project;
			}
			function deleteProjectLocal(id) {
				writeProjects(readProjects().filter(function(p) { return p.id !== id; }));
				// Chats that belonged to the project move back to the regular
				// chat list instead of being deleted, so nothing is lost.
				var convos = readConvos();
				var changed = false;
				convos.forEach(function(c) {
					if (c.project_id === id) { c.project_id = null; changed = true; }
				});
				if (changed) writeConvos(convos);
			}
			function convosForProject(id) {
				return readConvos().filter(function(c) { return c.project_id === id; }).sort(function(a, b) {
					return Date.parse(b.updated_at || 0) - Date.parse(a.updated_at || 0);
				});
			}
			function makeTitle(source) {
				source = (source || 'New Chat').trim() || 'New Chat';
				return source.length > 40 ? source.slice(0, 40) + '...' : source;
			}
			function pruneStaleConversations() {
				var now  = Date.now();
				var kept = readConvos().filter(function(c) {
					var ts = Date.parse(c.updated_at || c.created_at || '');
					return isNaN(ts) || (now - ts) <= STALE_MS;
				});
				writeConvos(kept);
				return Promise.resolve();
			}

			var currentConversationId = null;
			var currentProjectContext = null; // project id a freshly-started chat should belong to, if any
			var currentProjectViewId  = null; // project id whose page is currently open in the main pane

			// Tracks the in-flight AI request so the Send button can be
			// turned into a Stop button while a reply is streaming, and so
			// the user can cancel a long/complex generation at any time.
			var activeGenerations = {}; // convoId -> { abortController, reader, userBubbleEl, assistantBubble }

			var elList        = document.getElementById('chat-conversation-list');
			var elConvSearch  = document.getElementById('chat-conv-search');
			var elConvSearchClear = document.getElementById('chat-conv-search-clear');
			var elMessages    = document.getElementById('chat-messages');
			var elInput       = document.getElementById('chat-input');
			var elSend        = document.getElementById('chat-send-btn');
			var elNewChat     = document.getElementById('chat-new-chat-btn');
			var elNewModelsBtn   = document.getElementById('chat-new-models-btn');
			var elNewModelsModal = document.getElementById('chat-new-models-modal');
			var elNewModelsClose = document.getElementById('chat-new-models-close');
			var elFeaturedOnBtn   = document.getElementById('chat-featured-on-btn');
			var elFeaturedOnModal = document.getElementById('chat-featured-on-modal');
			var elFeaturedOnClose = document.getElementById('chat-featured-on-close');
			var elTitle       = document.getElementById('chat-current-title');
			var elModelSelect = document.getElementById('chat-model-select');
			var elAttachBtn   = document.getElementById('chat-attach-btn');
			var elAttachMenu  = document.getElementById('chat-attach-menu');
			var elAttachMenuImage = document.getElementById('chat-attach-menu-image');
			var elAttachMenuFile  = document.getElementById('chat-attach-menu-file');
			var elFileInput   = document.getElementById('chat-file-input');
			var elImageInput  = document.getElementById('chat-image-input');
			var elAttachPrev  = document.getElementById('chat-attach-preview');
			var elInputWrap   = document.querySelector('.chat-input-wrap');
			var elModal       = document.getElementById('chat-username-modal');
			var elModalInput  = document.getElementById('chat-username-input');
			var elModalError  = document.getElementById('chat-username-error');
			var elModalSubmit = document.getElementById('chat-username-submit');
			var elModalCloseBtn = document.getElementById('chat-username-modal-close');
			var elModalLangSelect  = document.getElementById('chat-username-lang-select');
			var elHeaderLangSelect = document.getElementById('chat-header-lang-select');
			var elDisabledBanner = document.getElementById('chat-disabled-banner');
			var elChatView    = document.getElementById('chat-chat-view');
			var elAdminView   = document.getElementById('chat-admin-view');
			var elAdminRoomBtn = document.getElementById('chat-admin-room-btn');
			var elAdminRefreshBtn = document.getElementById('chat-admin-refresh-btn');
			var elAdminStats  = document.getElementById('chat-admin-stats');
			var elAdminModels = document.getElementById('chat-admin-models');
			var elAdminToggleGlobalBtn = document.getElementById('chat-admin-toggle-global-btn');
			var elMediaView        = document.getElementById('chat-media-view');
			var elMediaRoomBtn     = document.getElementById('chat-media-room-btn');
			var elMediaMenuBtn     = document.getElementById('chat-media-menu-btn');
			var elMediaAddBtn      = document.getElementById('chat-media-add-btn');
			var elMediaFileInput   = document.getElementById('chat-media-file-input');
			var elMediaGallery     = document.getElementById('chat-media-gallery');
			var elProjectsToggleBtn = document.getElementById('chat-projects-toggle-btn');
			var elProjectsAddBtn    = document.getElementById('chat-projects-add-btn');
			var elProjectsList      = document.getElementById('chat-projects-list');
			var elNewProjectModal   = document.getElementById('chat-new-project-modal');
			var elNewProjectClose   = document.getElementById('chat-new-project-close');
			var elNewProjectInput   = document.getElementById('chat-new-project-input');
			var elNewProjectError   = document.getElementById('chat-new-project-error');
			var elNewProjectCancel  = document.getElementById('chat-new-project-cancel');
			var elNewProjectCreate  = document.getElementById('chat-new-project-create');
			var elProjectView       = document.getElementById('chat-project-view');
			var elProjectMenuBtn    = document.getElementById('chat-project-menu-btn');
			var elProjectTitle      = document.getElementById('chat-project-title');
			var elProjectNewChatBtn = document.getElementById('chat-project-new-chat-btn');
			var elProjectDeleteBtn  = document.getElementById('chat-project-delete-btn');
			var elProjectConvList   = document.getElementById('chat-project-conv-list');
			var elSidebar          = document.getElementById('chat-sidebar');
			var elSidebarBackdrop  = document.getElementById('chat-sidebar-backdrop');
			var elMenuBtn          = document.getElementById('chat-menu-btn');
			var elAdminMenuBtn     = document.getElementById('chat-admin-menu-btn');

			// ── Legal (ToS / Privacy Policy) viewer + mandatory consent ────────
			var elSidebarTosBtn      = document.getElementById('chat-sidebar-tos-btn');
			var elSidebarPrivacyBtn  = document.getElementById('chat-sidebar-privacy-btn');
			var elLegalModalBackdrop = document.getElementById('chat-legal-modal-backdrop');
			var elLegalModal         = document.getElementById('chat-legal-modal');
			var elLegalModalTitle    = document.getElementById('chat-legal-modal-title');
			var elLegalModalBody     = document.getElementById('chat-legal-modal-body');
			var elLegalModalClose    = document.getElementById('chat-legal-modal-close');
			var elTosContentSrc      = document.getElementById('chat-tos-content');
			var elPrivacyContentSrc  = document.getElementById('chat-privacy-content');
			var elConsentBackdrop    = document.getElementById('chat-consent-modal-backdrop');
			var elConsentModal       = document.getElementById('chat-consent-modal');
			var elSourceTrustBackdrop     = document.getElementById('chat-source-trust-backdrop');
			var elSourceTrustModal        = document.getElementById('chat-source-trust-modal');
			var elSourceTrustContinueBtn  = document.getElementById('chat-source-trust-continue-btn');
			var elConsentTosLink     = document.getElementById('chat-consent-tos-link');
			var elConsentPrivacyLink = document.getElementById('chat-consent-privacy-link');
			var elConsentCheckbox    = document.getElementById('chat-consent-checkbox');
			var elConsentAcceptBtn   = document.getElementById('chat-consent-accept-btn');
			var CONSENT_STORAGE_KEY  = 'mlpAiChatLegalAccepted_v1';

			function openLegalModal(type) {
				if (type === 'privacy') {
					elLegalModalTitle.textContent = 'Privacy Policy';
					elLegalModalBody.innerHTML = elPrivacyContentSrc ? elPrivacyContentSrc.innerHTML : '';
				} else {
					elLegalModalTitle.textContent = 'Terms of Service';
					elLegalModalBody.innerHTML = elTosContentSrc ? elTosContentSrc.innerHTML : '';
				}
				elLegalModalBody.scrollTop = 0;
				elLegalModalBackdrop.hidden = false;
				elLegalModal.hidden = false;
			}
			function closeLegalModal() {
				elLegalModalBackdrop.hidden = true;
				elLegalModal.hidden = true;
			}
			if (elSidebarTosBtn) elSidebarTosBtn.addEventListener('click', function() { openLegalModal('tos'); closeSidebar(); });
			if (elSidebarPrivacyBtn) elSidebarPrivacyBtn.addEventListener('click', function() { openLegalModal('privacy'); closeSidebar(); });
			if (elLegalModalClose) elLegalModalClose.addEventListener('click', closeLegalModal);
			if (elLegalModalBackdrop) elLegalModalBackdrop.addEventListener('click', closeLegalModal);
			if (elConsentTosLink) elConsentTosLink.addEventListener('click', function(e) { e.preventDefault(); openLegalModal('tos'); });
			if (elConsentPrivacyLink) elConsentPrivacyLink.addEventListener('click', function(e) { e.preventDefault(); openLegalModal('privacy'); });

			function hasAcceptedLegal() {
				try { return window.localStorage.getItem(CONSENT_STORAGE_KEY) === '1'; }
				catch (e) { return false; }
			}
			function markLegalAccepted() {
				try { window.localStorage.setItem(CONSENT_STORAGE_KEY, '1'); } catch (e) {}
			}
			function showConsentModal(onAccept) {
				elConsentBackdrop.setAttribute('data-hidden', '0');
				elConsentModal.setAttribute('data-hidden', '0');
				elConsentCheckbox.checked = false;
				elConsentAcceptBtn.disabled = true;
				elConsentCheckbox.onchange = function() {
					elConsentAcceptBtn.disabled = !elConsentCheckbox.checked;
				};
				elConsentAcceptBtn.onclick = function() {
					if (!elConsentCheckbox.checked) return;
					markLegalAccepted();
					elConsentBackdrop.setAttribute('data-hidden', '1');
					elConsentModal.setAttribute('data-hidden', '1');
					onAccept();
				};
			}
			function showSourceTrustModal(onContinue) {
				elSourceTrustBackdrop.setAttribute('data-hidden', '0');
				elSourceTrustModal.setAttribute('data-hidden', '0');
				elSourceTrustContinueBtn.onclick = function() {
					elSourceTrustBackdrop.setAttribute('data-hidden', '1');
					elSourceTrustModal.setAttribute('data-hidden', '1');
					onContinue();
				};
			}
			function requireLegalConsent(onReady) {
				if (hasAcceptedLegal()) { onReady(); return; }
				showSourceTrustModal(function() { showConsentModal(onReady); });
			}

			// ── Custom "Choose AI model" dropdown ───────────────────────────
			// The real <select id="chat-model-select"> stays fully functional
			// (value/options/disabled/change event) and is only visually
			// hidden; this widget is a richer view on top of it so each model
			// can show its logo instead of plain text.
			var elModelPicker        = document.getElementById('chat-model-picker');
			var elModelPickerTrigger = document.getElementById('chat-model-picker-trigger');
			var elModelPickerIcon    = document.getElementById('chat-model-picker-trigger-icon');
			var elModelPickerLabel   = document.getElementById('chat-model-picker-trigger-label');
			var elModelPickerPanel   = document.getElementById('chat-model-picker-panel');
			var elModelPickerSearch  = document.getElementById('chat-model-picker-search');
			var elModelPickerEmpty   = document.getElementById('chat-model-picker-empty');
			var modelPickerOptionEls = Array.prototype.slice.call(elModelPickerPanel.querySelectorAll('.chat-model-picker-option'));

			function filterModelPicker() {
				var q = (elModelPickerSearch.value || '').trim().toLowerCase();
				var visibleCount = 0;
				modelPickerOptionEls.forEach(function(el) {
					var label = (el.getAttribute('data-label') || '').toLowerCase();
					var matches = !q || label.indexOf(q) !== -1;
					el.hidden = !matches;
					if (matches) visibleCount++;
				});
				elModelPickerEmpty.hidden = visibleCount !== 0;
			}

			function modelPickerIconMarkup(logoUrl, fallbackLetter) {
				if (logoUrl) return '<img src="' + logoUrl + '" alt="">';
				return '<span class="chat-model-picker-option-icon-fallback">' + (fallbackLetter || '?') + '</span>';
			}

			function syncModelPicker() {
				var selectedOpt = elModelSelect.options[elModelSelect.selectedIndex];
				if (selectedOpt) {
					var label = selectedOpt.dataset.label || selectedOpt.textContent;
					elModelPickerIcon.innerHTML = modelPickerIconMarkup(selectedOpt.dataset.logo, label.charAt(0).toUpperCase());
					elModelPickerLabel.textContent = label;
				}
				elModelPickerTrigger.disabled = elModelSelect.disabled;

				modelPickerOptionEls.forEach(function(el) {
					var id = el.getAttribute('data-model-id');
					var nativeOpt = null;
					Array.prototype.forEach.call(elModelSelect.options, function(o) { if (o.value === id) nativeOpt = o; });
					var isDisabled = !!(nativeOpt && nativeOpt.disabled);
					var isSelected = !!(nativeOpt && elModelSelect.value === id);
					el.setAttribute('aria-disabled', isDisabled ? 'true' : 'false');
					el.setAttribute('aria-selected', isSelected ? 'true' : 'false');
					el.classList.toggle('is-active', isSelected);
					var labelEl = el.querySelector('.chat-model-picker-option-label');
					if (labelEl) {
						var base = el.getAttribute('data-label') || labelEl.textContent;
						labelEl.textContent = base + (isDisabled ? ' (disabled)' : '');
					}
				});
			}

			function onModelPickerOutsideClick(e) {
				if (!elModelPicker.contains(e.target)) closeModelPicker();
			}
			function onModelPickerKeydown(e) {
				if (e.key === 'Escape' || e.key === 'Esc') { closeModelPicker(); elModelPickerTrigger.focus(); }
			}
			function openModelPicker() {
				if (elModelPickerTrigger.disabled) return;
				syncModelPicker();
				elModelPickerSearch.value = '';
				filterModelPicker();
				elModelPickerPanel.hidden = false;
				elModelPickerTrigger.setAttribute('aria-expanded', 'true');
				document.addEventListener('click', onModelPickerOutsideClick, true);
				document.addEventListener('keydown', onModelPickerKeydown, true);
				// Focus async so the panel is visible/unhidden first.
				setTimeout(function() { elModelPickerSearch.focus(); }, 0);
			}
			function closeModelPicker() {
				elModelPickerPanel.hidden = true;
				elModelPickerTrigger.setAttribute('aria-expanded', 'false');
				document.removeEventListener('click', onModelPickerOutsideClick, true);
				document.removeEventListener('keydown', onModelPickerKeydown, true);
			}

			elModelPickerSearch.addEventListener('input', filterModelPicker);
			elModelPickerSearch.addEventListener('click', function(e) { e.stopPropagation(); });

			elModelPickerTrigger.addEventListener('click', function() {
				if (elModelPickerPanel.hidden) openModelPicker(); else closeModelPicker();
			});

			modelPickerOptionEls.forEach(function(el) {
				el.addEventListener('click', function() {
					if (el.getAttribute('aria-disabled') === 'true') return;
					var id = el.getAttribute('data-model-id');
					if (elModelSelect.value !== id) {
						elModelSelect.value = id;
						updateModelUI();
					}
					closeModelPicker();
				});
			});

			var pendingAttachments = [];
			var MAX_ATTACHMENTS    = 4;
			var MAX_FILE_BYTES     = 16 * 1024 * 1024;

			function currentModelConfig() {
				var found = null;
				jsModels.forEach(function(m) { if (m.id === elModelSelect.value) found = m; });
				return found;
			}

			var currentImagesOk = true;

			function updateModelUI() {
				elInput.placeholder = 'Message the AI…';

				var cfg = currentModelConfig();
				var imagesOk = !cfg || cfg.supports_images !== false;
				currentImagesOk = imagesOk;

				// The + button and "Add file" are always available. Only the
				// "Add image" menu option is restricted per-model.
				elAttachMenuImage.hidden = !imagesOk;
				elAttachMenuImage.disabled = !imagesOk;

				if (!imagesOk) {
					// Drop any pending image attachments so a leftover image
					// from a previous model doesn't get silently sent (and
					// rejected) once the user switches to a text-only model.
					var hadImages = pendingAttachments.some(function(a) { return a.isImage; });
					pendingAttachments = pendingAttachments.filter(function(a) { return !a.isImage; });
					if (hadImages) renderAttachPreview();
				}

				syncModelPicker();
			}

			// Refresh input placeholder when model changes.
			elModelSelect.addEventListener('change', updateModelUI);

			// ── API helper ──────��───���────────────────────────────────────────

			function apiFetch(path, options) {
				options = options || {};
				options.headers = Object.assign(
					{
						'Content-Type': 'application/json',
						'X-WP-Nonce': nonce,
						'X-MLP-Guest-Token': guestToken,
						'X-MLP-Guest-Username': identity ? identity.username : ''
					},
					options.headers || {}
				);
				return fetch(restUrl + path, options).then(function(res) {
					if (!res.ok) {
						return res.json().then(function(err) {
							throw new Error(err.message || 'Request failed');
						});
					}
					return res.json();
				});
			}

			// ── AI disabled (admin switch) ──────────────────────────────────

			var disabledModelIds = [];

			function applyDisabledState(isDisabled) {
				elDisabledBanner.setAttribute('data-show', isDisabled ? '1' : '0');
				elModelSelect.disabled = isDisabled;
				elInput.disabled       = isDisabled;
				elSend.disabled        = isDisabled;
				elAttachBtn.disabled   = isDisabled;
			}

			function applyModelDisabledOptions() {
				Array.prototype.forEach.call(elModelSelect.options, function(opt) {
					var isOff = disabledModelIds.indexOf(opt.value) !== -1;
					opt.disabled = isOff;
					opt.textContent = (opt.dataset.label || opt.textContent.replace(' (disabled)', '')) + (isOff ? ' (disabled)' : '');
					if (!opt.dataset.label) opt.dataset.label = opt.textContent.replace(' (disabled)', '');
				});
				if (elModelSelect.selectedOptions[0] && elModelSelect.selectedOptions[0].disabled) {
					var firstOk = Array.prototype.filter.call(elModelSelect.options, function(o) { return !o.disabled; })[0];
					if (firstOk) elModelSelect.value = firstOk.value;
				}

				syncModelPicker();
			}

			function checkAiStatus() {
				return fetch(restUrl + '/status').then(function(res) { return res.json(); })
					.then(function(data) {
						applyDisabledState(!!data.disabled);
						disabledModelIds = data.disabled_models || [];
						applyModelDisabledOptions();
					})
					.catch(function() {});
			}

			// ── Username modal ──────────────────────────────────────────────
			// First-time (logged-out) visitors must also clear a Cloudflare
			// Turnstile challenge here before they can start chatting. The
			// widget is lazy-loaded/rendered only when this modal is shown
			// (never for logged-in users, who skip this modal entirely),
			// and the resulting token is verified server-side in
			// submitUsername() before an identity is created.
			var elTurnstileBox   = document.getElementById('chat-turnstile');
			var turnstileWidgetId  = null;
			var turnstileToken     = '';
			var turnstileVerifying = false;

			function turnstileRequired() {
				return !!(TURNSTILE_SITE_KEY && elTurnstileBox);
			}

			// Set true while the modal is being reused from the profile menu's
			// "Edit name" action on an already-verified identity — skips the
			// Turnstile challenge again and updates the name in place instead
			// of (re)running initChatApp().
			var editingIdentityOnly = false;

			function updateUsernameSubmitState() {
				if (!turnstileRequired() || editingIdentityOnly) { elModalSubmit.disabled = false; return; }
				elModalSubmit.disabled = turnstileVerifying || !turnstileToken;
			}

			function loadTurnstileScript(callback) {
				if (window.turnstile) { callback(); return; }
				var existing = document.getElementById('mlp-turnstile-script');
				if (existing) { existing.addEventListener('load', callback); return; }
				var s = document.createElement('script');
				s.id = 'mlp-turnstile-script';
				s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
				s.async = true;
				s.defer = true;
				s.addEventListener('load', callback);
				document.head.appendChild(s);
			}

			function renderTurnstileWidget() {
				if (!turnstileRequired()) return;
				if (turnstileWidgetId !== null) return; // already rendered once
				loadTurnstileScript(function() {
					if (turnstileWidgetId !== null || !window.turnstile) return;
					turnstileWidgetId = window.turnstile.render(elTurnstileBox, {
						sitekey: TURNSTILE_SITE_KEY,
						callback: function(token) {
							turnstileToken = token;
							elModalError.textContent = '';
							updateUsernameSubmitState();
						},
						'expired-callback': function() {
							turnstileToken = '';
							updateUsernameSubmitState();
						},
						'error-callback': function() {
							turnstileToken = '';
							updateUsernameSubmitState();
						}
					});
					updateUsernameSubmitState();
				});
			}

			function resetTurnstileWidget() {
				turnstileToken = '';
				if (window.turnstile && turnstileWidgetId !== null) {
					window.turnstile.reset(turnstileWidgetId);
				}
				updateUsernameSubmitState();
			}

			function showUsernameModal() {
				elModal.removeAttribute('data-hidden');
				elModalInput.focus();
				if (!editingIdentityOnly) renderTurnstileWidget();
				updateUsernameSubmitState();
			}

			// Reopens the same modal pre-filled with the current name, for
			// the profile menu's "Edit name" action — a verified guest
			// changing their display name shouldn't have to solve the
			// Turnstile challenge again.
			function openEditNameModal() {
				editingIdentityOnly = true;
				elModalInput.value = identity ? identity.username : '';
				elModalError.textContent = '';
				if (elModalCloseBtn) elModalCloseBtn.hidden = false;
				showUsernameModal();
			}
			function hideUsernameModal() {
				elModal.setAttribute('data-hidden', '1');
				editingIdentityOnly = false;
				if (elModalCloseBtn) elModalCloseBtn.hidden = true;
			}

			function submitUsername() {
				var name = elModalInput.value.trim();
				if (!name) {
					elModalError.textContent = t('error_enter_name');
					return;
				}
				if (name.length > 30) {
					elModalError.textContent = t('error_name_too_long');
					return;
				}
				if (turnstileRequired() && !turnstileToken && !editingIdentityOnly) {
					elModalError.textContent = t('error_verification');
					return;
				}

				function createIdentityAndStart() {
					// hideUsernameModal() below resets editingIdentityOnly, so
					// capture it first to decide what happens after.
					var wasEditing = editingIdentityOnly;
					identity   = saveIdentity(name, (wasEditing && identity) ? identity.token : null);
					guestToken = identity.token;
					elModalError.textContent = '';
					hideUsernameModal();
					updateProfileDisplay();
					if (!wasEditing) initChatApp();
				}

				if (!turnstileRequired() || editingIdentityOnly) {
					createIdentityAndStart();
					return;
				}

				turnstileVerifying = true;
				updateUsernameSubmitState();
				fetch(restUrl + '/verify-turnstile', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
					body: JSON.stringify({ token: turnstileToken })
				})
					.then(function(res) { return res.json(); })
					.then(function(data) {
						turnstileVerifying = false;
						if (data && data.success) {
							createIdentityAndStart();
						} else {
							elModalError.textContent = t('error_verification_failed');
							resetTurnstileWidget();
						}
					})
					.catch(function() {
						turnstileVerifying = false;
						elModalError.textContent = t('error_verification_failed');
						resetTurnstileWidget();
					});
			}

			elModalSubmit.addEventListener('click', submitUsername);
			elModalInput.addEventListener('keydown', function(e) {
				if (e.key === 'Enter') { e.preventDefault(); submitUsername(); }
			});
			if (elModalCloseBtn) elModalCloseBtn.addEventListener('click', hideUsernameModal);

			// ── Profile / settings menu ──────────────────────────────────────
			// Bottom-of-sidebar row (avatar + name + gear), matching the
			// pattern most AI chat apps use. Logged-in WP users show their
			// WP display name and skip the "Edit name" option (their name
			// isn't managed here); guests show the localStorage identity.
			var elProfileTrigger = document.getElementById('chat-profile-trigger');
			var elProfileMenu    = document.getElementById('chat-profile-menu');
			var elProfileAvatar  = document.getElementById('chat-profile-avatar');
			var elProfileName    = document.getElementById('chat-profile-name');
			var elProfileEditNameBtn = document.getElementById('chat-profile-menu-edit-name');
			var elProfileTosBtn      = document.getElementById('chat-profile-menu-tos');
			var elProfilePrivacyBtn  = document.getElementById('chat-profile-menu-privacy');
			var elProfileClearBtn    = document.getElementById('chat-profile-menu-clear');

			function currentDisplayName() {
				if (IS_WP_USER && WP_USER_DISPLAY_NAME) return WP_USER_DISPLAY_NAME;
				if (identity && identity.username) return identity.username;
				return 'Guest';
			}

			function updateProfileDisplay() {
				var name = currentDisplayName();
				if (elProfileName) elProfileName.textContent = name;
				if (elProfileAvatar) elProfileAvatar.textContent = name.trim().charAt(0) || '?';
			}
			updateProfileDisplay();

			function closeProfileMenu() {
				if (!elProfileMenu || elProfileMenu.hidden) return;
				elProfileMenu.hidden = true;
				elProfileTrigger.setAttribute('aria-expanded', 'false');
			}
			function openProfileMenu() {
				if (!elProfileMenu) return;
				elProfileMenu.hidden = false;
				elProfileTrigger.setAttribute('aria-expanded', 'true');
			}
			if (elProfileTrigger) {
				elProfileTrigger.addEventListener('click', function(e) {
					e.stopPropagation();
					if (elProfileMenu.hidden) openProfileMenu(); else closeProfileMenu();
				});
			}
			document.addEventListener('click', function(e) {
				if (elProfileMenu && !elProfileMenu.hidden && !elProfileMenu.contains(e.target) && e.target !== elProfileTrigger) {
					closeProfileMenu();
				}
			});
			document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeProfileMenu(); });

			if (elProfileEditNameBtn) {
				elProfileEditNameBtn.addEventListener('click', function() {
					closeProfileMenu();
					openEditNameModal();
				});
			}
			if (elProfileTosBtn) {
				elProfileTosBtn.addEventListener('click', function() { closeProfileMenu(); openLegalModal('tos'); });
			}
			if (elProfilePrivacyBtn) {
				elProfilePrivacyBtn.addEventListener('click', function() { closeProfileMenu(); openLegalModal('privacy'); });
			}
			if (elProfileClearBtn) {
				elProfileClearBtn.addEventListener('click', function() {
					closeProfileMenu();
					if (!window.confirm('Delete all conversations on this device? This cannot be undone.')) return;
					writeConvos([]);
					startNewChat();
					loadConversations();
				});
			}

			// ── Language pickers ─────────────────────────────────────────────
			// Modal select: shown once during first-time setup (guests only).
			// Header select: always visible, lets anyone (guest or logged-in)
			// change the editor language at any time. Both write through the
			// same setLanguage(), which persists the choice and re-applies
			// every data-i18n string on the page immediately.
			if (elModalLangSelect) {
				elModalLangSelect.addEventListener('change', function() {
					setLanguage(elModalLangSelect.value);
				});
			}
			if (elHeaderLangSelect) {
				elHeaderLangSelect.addEventListener('change', function() {
					setLanguage(elHeaderLangSelect.value);
				});
			}

			function escapeHtml(str) {
				var div = document.createElement('div');
				div.innerText = str;
				return div.innerHTML;
			}

			function renderMarkdown(text) {
				try {
					if (!text) return '';

					var lines = text.split(/\r?\n/);
					var blocks = [];
					var currentPara = [];

					function flushPara() {
						if (currentPara.length === 0) return;
						var html = currentPara.map(function(line) {
							var p = escapeHtml(line);
							p = p.replace(/`([^`]+)`/g, '<code>$1</code>');
							p = p.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
							p = p.replace(/\*(.+?)\*/g, '<em>$1</em>');
							p = p.replace(/_(.+?)_/g, '<em>$1</em>');
							p = p.replace(/__(.+?)__/g, '<u>$1</u>');
							return p;
						}).join('<br>');
						blocks.push(html);
						currentPara = [];
					}

					// Claude-style "artifact" card: instead of dumping the raw
					// source straight into the chat bubble, we show a small
					// file card (icon + filename + line count). The full code
					// only appears once the user actually opens it in the
					// Monaco sidebar — same idea as Claude's artifact preview.
					function makeCodeBlock(lang, code, filenameHint) {
						var displayLang = lang || 'text';
						var blockId  = 'code_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
						var filename = (filenameHint || '').trim() || guessFilename(displayLang);
							if (/^snippet(?:\.[a-z0-9]+)?$/i.test(filename)) filename = guessFilename(displayLang);
						var fileId   = storeFile(filename, monacoLangFor(displayLang), code);
						var lineCount = code.split(/\r?\n/).length;
						var meta = lineCount + (lineCount === 1 ? ' line' : ' lines') + ' · Click to open';
						// .html files (or ```html fences) also get a "View" button
						// that renders the actual markup live in a dedicated preview
						// sidebar — separate from the read-only Monaco code sidebar.
						var isHtmlFile = /\.html?$/i.test(filename) || displayLang.toLowerCase() === 'html';
						var viewBtnHtml = isHtmlFile
							? '<button class="chat-file-card-btn chat-view-btn" type="button" data-file-id="' + fileId + '" title="Preview rendered HTML">View &#8599;&#65038;</button>'
							: '';
						return '<div class="chat-file-card chat-artifact-card chat-open-btn" id="' + blockId + '" data-file-id="' + fileId + '" role="button" tabindex="0">' +
							'<div class="chat-file-card-main">' +
								'<div class="chat-file-card-icon">' + fileIconSvg() + '</div>' +
								'<div class="chat-file-card-info">' +
									'<div class="chat-file-card-name">' + escapeHtml(filename) + '</div>' +
									'<div class="chat-file-card-meta">' + escapeHtml(meta) + '</div>' +
								'</div>' +
							'</div>' +
						'<button class="chat-file-card-btn chat-open-btn" type="button" data-file-id="' + fileId + '" title="Open in code editor">' +
							'Open &#8599;&#65038;' +
						'</button>' +
							viewBtnHtml +
							'</div>';
					}

					var inCodeBlock = false;
					var codeBlockLang = '';
					var codeBlockFilename = '';
					var codeBlockLines = [];

					for (var i = 0; i < lines.length; i++) {
						var line = lines[i];
						// Accepts a plain ```lang fence or a ```lang:filename.ext
						// fence — the model is asked to always name the file it's
						// writing, so we can show that real name on the card
						// instead of a generic "snippet.ext".
						var fenceMatch = line.match(/^```\s*([\w+-]*)(?::(\S+))?\s*$/);

						if (fenceMatch) {
							if (inCodeBlock) {
								flushPara();
								blocks.push(makeCodeBlock(codeBlockLang, codeBlockLines.join('\n'), codeBlockFilename));
								inCodeBlock = false;
								codeBlockLang = '';
								codeBlockFilename = '';
								codeBlockLines = [];
							} else {
								flushPara();
								inCodeBlock = true;
								codeBlockLang = fenceMatch[1];
								codeBlockFilename = fenceMatch[2] || '';
							}
							continue;
						}

						if (inCodeBlock) {
							codeBlockLines.push(line);
							continue;
						}

						if (line.trim() === '') {
							flushPara();
							continue;
						}

						var headingMatch = line.match(/^(#{1,6})\s+(.*)$/);
						if (headingMatch) {
							flushPara();
							var level = headingMatch[1].length;
							blocks.push('<h' + level + '>' + escapeHtml(headingMatch[2]) + '</h' + level + '>');
							continue;
						}

						currentPara.push(line);
					}

					flushPara();

					if (inCodeBlock) {
						blocks.push(makeCodeBlock(codeBlockLang, codeBlockLines.join('\n'), codeBlockFilename));
					}

					var result = [];
					for (var j = 0; j < blocks.length; j++) {
						result.push(blocks[j]);
						if (j < blocks.length - 1) {
							result.push('<div style="height:8px;"></div>');
						}
					}
					return result.join('');
				} catch (e) {
					return escapeHtml(text).replace(/\n/g, '<br>');
				}
			}

			function fileIconSvg() {
				return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>';
			}

			function likeSvg() {
				return '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"></path></svg>';
			}
			function dislikeSvg() {
				return '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 15v4a3 3 0 0 0 3 3l4-9V2H5.72a2 2 0 0 0-2 1.7l-1.38 9a2 2 0 0 0 2 2.3zm7-13h2.67A2.31 2.31 0 0 1 22 4v7a2.31 2.31 0 0 1-2.33 2H17"></path></svg>';
			}

			// Copy-to-clipboard icon shown on the AI message action bar, plus
			// the checkmark it briefly swaps to once the copy succeeds.
			function copyMsgSvg() {
				return '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';
			}
			function checkMsgSvg() {
				return '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
			}

			// "Réessayer" (retry/regenerate) icon — counter-clockwise arrow.
			function retrySvg() {
				return '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>';
			}

			// ── File store & Monaco Sidebar ──────────────────────────────
			var fileStore = {};
			function storeFile(filename, lang, code) {
				var id = 'file_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10);
				fileStore[id] = { filename: filename, lang: lang || 'plaintext', code: code };
				return id;
			}

			// Fenced code blocks only carry a loose language tag (```js,
			// ```py, ```sh, ...). Map that to a plausible file extension
			// (for the download button) and to the language id Monaco
			// actually understands (for syntax highlighting).
			var LANG_EXT_MAP = {
				javascript: 'js', js: 'js', typescript: 'ts', ts: 'ts', jsx: 'jsx', tsx: 'tsx',
				python: 'py', py: 'py', php: 'php', html: 'html', xml: 'xml', css: 'css',
				scss: 'scss', less: 'less', json: 'json', java: 'java', c: 'c', cpp: 'cpp',
				'c++': 'cpp', csharp: 'cs', 'c#': 'cs', cs: 'cs', go: 'go', golang: 'go',
				rust: 'rs', rs: 'rs', ruby: 'rb', rb: 'rb', swift: 'swift', kotlin: 'kt',
				sql: 'sql', bash: 'sh', sh: 'sh', shell: 'sh', zsh: 'sh', powershell: 'ps1',
				yaml: 'yml', yml: 'yml', markdown: 'md', md: 'md', dockerfile: 'Dockerfile',
				text: 'txt', plaintext: 'txt', '': 'txt'
			};
			var LANG_MONACO_MAP = {
				js: 'javascript', jsx: 'javascript', ts: 'typescript', tsx: 'typescript',
				py: 'python', rb: 'ruby', rs: 'rust', cs: 'csharp', 'c++': 'cpp', sh: 'shell',
				zsh: 'shell', bash: 'shell', yml: 'yaml', md: 'markdown', text: 'plaintext', '': 'plaintext'
			};
function guessFilename(lang) {
  var key = (lang || '').toLowerCase().trim();
  var ext = LANG_EXT_MAP[key] || (/^[a-z0-9]+$/.test(key) ? key : 'txt');
  if (ext === 'php') return 'my-plugin.php';
  if (ext === 'js') return 'app.js';
  if (ext === 'css') return 'styles.css';
  if (ext === 'html') return 'index.html';
  if (ext === 'json') return 'config.json';
  return 'generated-file.' + ext;
  }
			function monacoLangFor(lang) {
				var key = (lang || '').toLowerCase().trim();
				return LANG_MONACO_MAP[key] || key || 'plaintext';
			}
			function formatBytes(bytes) {
				if (bytes === 0) return '0 B';
				var k = 1024;
				var sizes = ['B', 'KB', 'MB', 'GB'];
				var i = Math.floor(Math.log(bytes) / Math.log(k));
				return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
			}

			var monacoEditor = null;
			var monacoLoaded = false;
			var currentFileData = null;

			function loadMonaco(callback) {
				if (monacoLoaded) { callback(); return; }
				var script = document.createElement('script');
				script.src = 'https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs/loader.js';
				script.onload = function() {
					require.config({ paths: { 'vs': 'https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs' }});
					require(['vs/editor/editor.main'], function() {
						monacoLoaded = true;
						callback();
					});
				};
				script.onerror = function() {
					alert('Failed to load code editor.');
				};
				document.head.appendChild(script);
			}

			function openCodeSidebar(fileId) {
				var file = fileStore[fileId];
				if (!file) return;
				closePreviewSidebar();
				currentFileData = file;
				document.getElementById('chat-code-sidebar-title').textContent = file.filename;
				document.getElementById('chat-code-sidebar').setAttribute('data-hidden', '0');
				loadMonaco(function() {
					var container = document.getElementById('chat-code-sidebar-editor');
					if (monacoEditor) monacoEditor.dispose();
					monacoEditor = monaco.editor.create(container, {
						value: file.code,
						language: file.lang || 'plaintext',
						theme: 'vs-dark',
						automaticLayout: true,
						minimap: { enabled: false },
						scrollBeyondLastLine: false,
						fontSize: 13,
						lineNumbers: 'on',
						roundedSelection: false,
						readOnly: true,
						wordWrap: 'on'
					});
				});
			}

			function closeCodeSidebar() {
				document.getElementById('chat-code-sidebar').setAttribute('data-hidden', '1');
				if (monacoEditor) { monacoEditor.dispose(); monacoEditor = null; }
				currentFileData = null;
			}

			function downloadCurrentFile() {
				if (!currentFileData) return;
				var blob = new Blob([currentFileData.code], { type: 'text/plain' });
				var url = URL.createObjectURL(blob);
				var a = document.createElement('a');
				a.href = url;
				a.download = currentFileData.filename;
				document.body.appendChild(a);
				a.click();
				document.body.removeChild(a);
				URL.revokeObjectURL(url);
			}

			// ── Live HTML Preview Sidebar ────────────────────────────────
			// Renders the file's actual markup in a sandboxed iframe instead
			// of showing source — a separate panel from the read-only Monaco
			// code sidebar above, so "View" and "Open" never fight over the
			// same UI. Closing the code sidebar if it happens to be open
			// avoids the two panels stacking on top of each other.
			function openPreviewSidebar(fileId) {
				var file = fileStore[fileId];
				if (!file) return;
				closeCodeSidebar();
				var previewEl = document.getElementById('chat-preview-sidebar');
				previewEl.classList.remove('chat-preview-sidebar--fullscreen');
				setFullscreenIcon(false);
				document.getElementById('chat-preview-sidebar-title').textContent = file.filename + ' — Preview';
				previewEl.setAttribute('data-hidden', '0');
				document.getElementById('chat-preview-sidebar-frame').srcdoc = file.code;
			}

			function closePreviewSidebar() {
				var previewEl = document.getElementById('chat-preview-sidebar');
				previewEl.setAttribute('data-hidden', '1');
				previewEl.classList.remove('chat-preview-sidebar--fullscreen');
				setFullscreenIcon(false);
				document.getElementById('chat-preview-sidebar-frame').srcdoc = '';
			}

			var FULLSCREEN_ICON = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3"></path><path d="M21 8V5a2 2 0 0 0-2-2h-3"></path><path d="M3 16v3a2 2 0 0 0 2 2h3"></path><path d="M16 21h3a2 2 0 0 0 2-2v-3"></path></svg>';
			var EXIT_FULLSCREEN_ICON = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3v3a2 2 0 0 1-2 2H3"></path><path d="M21 8h-3a2 2 0 0 1-2-2V3"></path><path d="M3 16h3a2 2 0 0 1 2 2v3"></path><path d="M16 21v-3a2 2 0 0 1 2-2h3"></path></svg>';

			function setFullscreenIcon(isFullscreen) {
				var btn = document.getElementById('chat-preview-sidebar-fullscreen');
				if (!btn) return;
				btn.innerHTML = isFullscreen ? EXIT_FULLSCREEN_ICON : FULLSCREEN_ICON;
				btn.title = isFullscreen ? 'Exit fullscreen' : 'View fullscreen';
			}

			function togglePreviewFullscreen() {
				var previewEl = document.getElementById('chat-preview-sidebar');
				var isFullscreen = previewEl.classList.toggle('chat-preview-sidebar--fullscreen');
				setFullscreenIcon(isFullscreen);
			}

			var copyListenersAttached = false;
			function attachCopyListeners() {
				// This is called both at boot and again once the chat app
				// initializes; without this guard the same delegated click
				// handler got bound twice, double-firing Copy/Open actions.
				if (copyListenersAttached) return;
				copyListenersAttached = true;
				elMessages.addEventListener('keydown', function(e) {
					if (e.key !== 'Enter' && e.key !== ' ') return;
					var card = e.target.closest('.chat-artifact-card');
					if (!card) return;
					e.preventDefault();
					var fileId = card.dataset.fileId;
					if (fileId) openCodeSidebar(fileId);
				});
				elMessages.addEventListener('click', function(e) {
					// Checked before .chat-open-btn: the "View" button lives inside
					// the file card, and the card wrapper itself also carries the
					// .chat-open-btn class, so without this the click would bubble
					// up and open the code editor instead of the live preview.
					var viewBtn = e.target.closest('.chat-view-btn');
					if (viewBtn) {
						e.stopPropagation();
						var viewFileId = viewBtn.dataset.fileId;
						if (viewFileId) openPreviewSidebar(viewFileId);
						return;
					}
					var openBtn = e.target.closest('.chat-open-btn');
					if (openBtn) {
						var fileId = openBtn.dataset.fileId;
						if (fileId) openCodeSidebar(fileId);
						return;
					}
					var fbBtn = e.target.closest('.chat-feedback-btn');
					if (fbBtn) {
						var bar     = fbBtn.closest('.chat-feedback-bar');
						var modelId = bar.dataset.model;
						var msgId   = bar.dataset.msgId;
						var type    = fbBtn.dataset.type; // 'like' | 'dislike' | 'copy' | 'retry'

						if (type === 'copy') {
							var contentWrapEl = bar.closest('.chat-msg-content');
							var msgTextEl     = contentWrapEl && contentWrapEl.querySelector('.chat-msg-text');
							copyMsgTextToClipboard(msgTextEl ? msgTextEl.innerText : '', fbBtn);
							return;
						}
						if (type === 'retry') {
							retryMessage(modelId, msgId);
							return;
						}

						var current = bar.dataset.current || '';
						var likeBtn    = bar.querySelector('.chat-feedback-btn.like');
						var dislikeBtn = bar.querySelector('.chat-feedback-btn.dislike');
						var newState;

						if (current === type) {
							// Clicking the already-active vote retracts it.
							newState = '';
							sendFeedback(modelId, type, 'remove');
						} else {
							// Switching votes (or voting for the first time):
							// clear out any opposite vote first so a model
							// never ends up double-counted for one message.
							if (current) sendFeedback(modelId, current, 'remove');
							sendFeedback(modelId, type, 'add');
							newState = type;
						}

						bar.dataset.current = newState;
						likeBtn.classList.toggle('active', newState === 'like');
						likeBtn.setAttribute('aria-pressed', newState === 'like' ? 'true' : 'false');
						dislikeBtn.classList.toggle('active', newState === 'dislike');
						dislikeBtn.setAttribute('aria-pressed', newState === 'dislike' ? 'true' : 'false');

						persistMessageFeedback(msgId, newState || null);
						return;
					}
					var btn = e.target.closest('.chat-copy-btn');
					if (!btn) return;
					var encoded = btn.dataset.clipboard;
					if (!encoded) return;
					var code = decodeURIComponent(encoded);
					navigator.clipboard.writeText(code).then(function() {
						btn.classList.add('copied');
						btn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Copied!';
						setTimeout(function() {
							btn.classList.remove('copied');
							btn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg> Copy';
						}, 2000);
					}).catch(function() {
						var ta = document.createElement('textarea');
						ta.value = code;
						document.body.appendChild(ta);
						ta.select();
						document.execCommand('copy');
						document.body.removeChild(ta);
					});
				});
			}

			function readFileAsDataURL(file) {
				return new Promise(function(resolve, reject) {
					var reader = new FileReader();
					reader.onload  = function() { resolve(reader.result); };
					reader.onerror = function() { reject(new Error('Could not read file')); };
					reader.readAsDataURL(file);
				});
			}

			function addFiles(fileList) {
				var files = Array.prototype.slice.call(fileList || []);
				if (!files.length) return;
				var overLimitWarned = false;
				var imageBlockedWarned = false;
				files.forEach(function(file) {
					var isImageFile = file.type.indexOf('image/') === 0;
					if (isImageFile && !currentImagesOk) {
						if (!imageBlockedWarned) { alert("This AI Model doesn't support images."); imageBlockedWarned = true; }
						return;
					}
					if (pendingAttachments.length >= MAX_ATTACHMENTS) {
						if (!overLimitWarned) { alert('You can attach up to ' + MAX_ATTACHMENTS + ' files per message.'); overLimitWarned = true; }
						return;
					}
					if (file.size > MAX_FILE_BYTES) { alert(file.name + ' is larger than 16MB and was skipped.'); return; }
					var entry = {
						id: 'att_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8),
						name: file.name, type: file.type || 'application/octet-stream',
						size: file.size, dataUrl: null,
						isImage: file.type.indexOf('image/') === 0
					};
					pendingAttachments.push(entry);
					renderAttachPreview();
					readFileAsDataURL(file).then(function(dataUrl) {
						entry.dataUrl = dataUrl; renderAttachPreview();
					}).catch(function() {
						pendingAttachments = pendingAttachments.filter(function(a) { return a.id !== entry.id; });
						renderAttachPreview();
					});
				});
			}

			function removeAttachment(id) {
				pendingAttachments = pendingAttachments.filter(function(a) { return a.id !== id; });
				renderAttachPreview();
			}

			function renderAttachPreview() {
				elAttachPrev.innerHTML = '';
				pendingAttachments.forEach(function(att) {
					var chip = document.createElement('div');
					chip.className = 'chat-attach-chip';
					if (att.isImage && att.dataUrl) {
						var img = document.createElement('img');
						img.className = 'chat-attach-chip-thumb'; img.src = att.dataUrl;
						chip.appendChild(img);
					} else {
						var iconWrap = document.createElement('div');
						iconWrap.className = 'chat-attach-chip-icon'; iconWrap.innerHTML = fileIconSvg();
						chip.appendChild(iconWrap);
					}
					var nameSpan = document.createElement('span');
					nameSpan.className = 'chat-attach-chip-name'; nameSpan.textContent = att.name;
					chip.appendChild(nameSpan);
					var removeBtn = document.createElement('button');
					removeBtn.className = 'chat-attach-chip-remove'; removeBtn.type = 'button';
					removeBtn.innerHTML = '&times;'; removeBtn.title = 'Remove';
					removeBtn.addEventListener('click', function() { removeAttachment(att.id); });
					chip.appendChild(removeBtn);
					elAttachPrev.appendChild(chip);
				});
			}

			function renderEmptyState() {
				elMessages.innerHTML = '<div class="chat-empty-state"><h2>AI Chat</h2><p>Start a conversation below.</p></div>';
			}

			function modelLabelFor(id) {
				var found = null;
				jsModels.forEach(function(m) { if (m.id === id) found = m; });
				return found ? found.label : null;
			}

			function showAvatarTooltip(avatarEl, modelId) {
				var existing = document.querySelector('.chat-msg-avatar-tooltip');
				if (existing) existing.remove();

				var label = modelLabelFor(modelId);
				var tip = document.createElement('div');
				tip.className = 'chat-msg-avatar-tooltip';
				tip.textContent = label ? ('Powered by ' + label) : 'Model info unavailable for this message';
				document.body.appendChild(tip);

				var rect = avatarEl.getBoundingClientRect();
				tip.style.top  = (rect.bottom + window.scrollY + 6) + 'px';
				tip.style.left = (rect.left + window.scrollX) + 'px';

				function dismiss(e) {
					if (tip.contains(e.target) || e.target === avatarEl) return;
					tip.remove();
					document.removeEventListener('click', dismiss);
				}
				setTimeout(function() { document.addEventListener('click', dismiss); }, 0);
				setTimeout(function() { if (tip.parentNode) tip.remove(); }, 4000);
			}

			function makeAssistantAvatar(modelId) {
				var wrap = document.createElement('div');
				wrap.className = 'chat-msg-avatar-wrap';

				var avatar = document.createElement('img');
				avatar.className = 'chat-msg-avatar';
				avatar.src = 'https://ptero.pro/wp-content/uploads/2026/08/3234427.png';
				avatar.alt = 'AI';
				var label = modelLabelFor(modelId);
				avatar.title = label ? ('Powered by ' + label) : 'AI';
				avatar.style.cursor = 'pointer';
				avatar.addEventListener('click', function(e) {
					e.stopPropagation();
					showAvatarTooltip(avatar, modelId);
				});
				wrap.appendChild(avatar);

				var nameEl = document.createElement('span');
				nameEl.className = 'chat-msg-avatar-name';
				nameEl.textContent = label ? label.replace(/\s*\(Free\)\s*$/i, '') : 'AI';
				wrap.appendChild(nameEl);

				return wrap;
			}

			// modelId/msgId identify who to credit the vote to and which
			// local message to remember it against; feedback is the current
			// vote state for this message ('like' / 'dislike' / falsy).
			function makeFeedbackBar(modelId, msgId, feedback) {
				var bar = document.createElement('div');
				bar.className = 'chat-feedback-bar';
				bar.dataset.model = modelId;
				bar.dataset.msgId = msgId;
				bar.dataset.current = feedback || '';

				var likeBtn = document.createElement('button');
				likeBtn.type = 'button';
				likeBtn.className = 'chat-feedback-btn like' + (feedback === 'like' ? ' active' : '');
				likeBtn.dataset.type = 'like';
				likeBtn.title = 'Good response';
				likeBtn.setAttribute('aria-label', 'Good response');
				likeBtn.setAttribute('aria-pressed', feedback === 'like' ? 'true' : 'false');
				likeBtn.innerHTML = likeSvg();

				var dislikeBtn = document.createElement('button');
				dislikeBtn.type = 'button';
				dislikeBtn.className = 'chat-feedback-btn dislike' + (feedback === 'dislike' ? ' active' : '');
				dislikeBtn.dataset.type = 'dislike';
				dislikeBtn.title = 'Bad response';
				dislikeBtn.setAttribute('aria-label', 'Bad response');
				dislikeBtn.setAttribute('aria-pressed', feedback === 'dislike' ? 'true' : 'false');
				dislikeBtn.innerHTML = dislikeSvg();

				var copyBtn = document.createElement('button');
				copyBtn.type = 'button';
				copyBtn.className = 'chat-feedback-btn copy';
				copyBtn.dataset.type = 'copy';
				copyBtn.title = 'Copy';
				copyBtn.setAttribute('aria-label', 'Copy response');
				copyBtn.innerHTML = copyMsgSvg();

				var retryBtn = document.createElement('button');
				retryBtn.type = 'button';
				retryBtn.className = 'chat-feedback-btn retry';
				retryBtn.dataset.type = 'retry';
				retryBtn.title = 'Réessayer';
				retryBtn.setAttribute('aria-label', 'Réessayer');
				retryBtn.innerHTML = retrySvg();

				bar.appendChild(likeBtn);
				bar.appendChild(dislikeBtn);
				bar.appendChild(copyBtn);
				bar.appendChild(retryBtn);
				return bar;
			}

			// Copies an AI reply's rendered text to the clipboard and briefly
			// swaps the button icon to a checkmark for feedback.
			function copyMsgTextToClipboard(text, btn) {
				if (!text) return;
				function showCopied() {
					btn.classList.add('copied');
					btn.innerHTML = checkMsgSvg();
					setTimeout(function() {
						btn.classList.remove('copied');
						btn.innerHTML = copyMsgSvg();
					}, 1500);
				}
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(showCopied).catch(function() {
						fallbackCopyMsgText(text);
						showCopied();
					});
				} else {
					fallbackCopyMsgText(text);
					showCopied();
				}
			}
			function fallbackCopyMsgText(text) {
				var ta = document.createElement('textarea');
				ta.value = text;
				ta.style.position = 'fixed';
				ta.style.left = '-9999px';
				document.body.appendChild(ta);
				ta.select();
				try { document.execCommand('copy'); } catch (e) {}
				document.body.removeChild(ta);
			}

			// "Réessayer" �� drops the assistant reply and the user turn that
			// prompted it, then resends that same user turn so a fresh reply
			// is generated in its place. Only acts on the message that is
			// still tracked in this conversation's history (identified by
			// msgId), and refuses to run while a reply is already streaming.
			function retryMessage(modelId, msgId) {
				if (!currentConversationId || !msgId) return;
				if (activeGenerations[currentConversationId]) return;
				var convo = getConvo(currentConversationId);
				if (!convo || !convo.messages) return;

				var idx = -1;
				for (var i = 0; i < convo.messages.length; i++) {
					if (convo.messages[i].id === msgId && convo.messages[i].role === 'assistant') { idx = i; break; }
				}
				if (idx < 1) return;
				var userMsg = convo.messages[idx - 1];
				if (!userMsg || userMsg.role !== 'user') return;

				var bubbles = elMessages.querySelectorAll('.chat-msg');
				var assistantBubbleEl = bubbles[idx];
				var userBubbleEl      = bubbles[idx - 1];

				convo.messages.splice(idx - 1, 2);
				upsertConvo(convo);

				if (assistantBubbleEl) assistantBubbleEl.remove();
				if (userBubbleEl) userBubbleEl.remove();

				if (modelId && elModelSelect.value !== modelId) {
					elModelSelect.value = modelId;
					updateModelUI();
				}

				elInput.value = userMsg.text || '';
				pendingAttachments = (userMsg.attachments || []).slice();
				renderAttachPreview();
				sendMessage();
			}

			function sendFeedback(modelId, type, action) {
				if (!modelId) return;
				apiFetch('/feedback', {
					method: 'POST',
					body: JSON.stringify({ model_id: modelId, type: type, action: action })
				}).catch(function() {}); // best-effort — a dropped vote isn't worth surfacing an error for
			}

			// Keeps a vote alive across page reloads / re-opening the chat by
			// writing it back onto the message object in localStorage. Scans
			// every conversation (not just the open one) so this stays correct
			// even for a message that's currently reattached mid-stream.
			function persistMessageFeedback(msgId, feedback) {
				var convos = readConvos();
				for (var i = 0; i < convos.length; i++) {
					var msgs = convos[i].messages || [];
					for (var j = 0; j < msgs.length; j++) {
						if (msgs[j].id === msgId) {
							msgs[j].feedback = feedback;
							writeConvos(convos);
							return;
						}
					}
				}
			}

			function addMessageBubble(role, content, attachments, model, msgId, feedback) {
				var emptyState = elMessages.querySelector('.chat-empty-state');
				if (emptyState) emptyState.remove();
				var bubble = document.createElement('div');
				bubble.className = 'chat-msg ' + role;

				if (role === 'assistant') {
					bubble.appendChild(makeAssistantAvatar(model));
				}

				var contentWrap = document.createElement('div');
				contentWrap.className = 'chat-msg-content';

				if (content) {
					var textDiv = document.createElement('div');
					textDiv.className = 'chat-msg-text';
					textDiv.innerHTML = renderMarkdown(content);
					contentWrap.appendChild(textDiv);
				}
				if (attachments && attachments.length) {
					var attWrap = document.createElement('div');
					attWrap.className = 'chat-msg-attachments';
					attachments.forEach(function(att) {
						var src     = att.dataUrl || att.data;
						var isImage = (att.isImage !== undefined) ? att.isImage : (att.type || '').indexOf('image/') === 0;
						if (isImage && src) {
							var img = document.createElement('img');
							img.className = 'chat-msg-img'; img.src = src; img.alt = att.name || 'attachment';
							attWrap.appendChild(img);
						} else {
							var fileChip = document.createElement('div');
							fileChip.className = 'chat-msg-file';
							fileChip.innerHTML = fileIconSvg() + '<span class="chat-msg-file-name">' + escapeHtml(att.name || 'file') + '</span>';
							attWrap.appendChild(fileChip);
						}
					});
					contentWrap.appendChild(attWrap);
				}
				// Feedback only makes sense once we know which model answered
				// and have a stable id to remember the vote against.
				if (role === 'assistant' && model && msgId) {
					contentWrap.appendChild(makeFeedbackBar(model, msgId, feedback));
				}
				bubble.appendChild(contentWrap);
				elMessages.appendChild(bubble);
				elMessages.scrollTop = elMessages.scrollHeight;
				return bubble;
			}

			// Builds one clickable conversation row, shared by the main
			// conversation list and each project's own chat list.
			function buildConvItem(c, onDeleted) {
				var item = document.createElement('div');
				item.className = 'chat-conv-item' + (c.id === currentConversationId ? ' active' : '');
				item.dataset.id = c.id;
				var titleSpan = document.createElement('span');
				titleSpan.className = 'chat-conv-title'; titleSpan.textContent = c.title;
				item.appendChild(titleSpan);
				var delBtn = document.createElement('button');
				delBtn.className = 'chat-conv-delete'; delBtn.innerHTML = '&times;'; delBtn.title = 'Delete';
				delBtn.addEventListener('click', function(e) {
					e.stopPropagation();
					if (!confirm('Delete this conversation?')) return;
					deleteConvoLocal(c.id);
					if (currentConversationId === c.id) {
						currentConversationId = null; elTitle.textContent = 'New Chat'; renderEmptyState();
					}
					if (onDeleted) onDeleted();
				});
				item.appendChild(delBtn);
				item.addEventListener('click', function() {
					showChatView();
					openConversation(c.id, c.title);
					closeSidebar();
				});
				return item;
			}

			function loadConversations() {
				// Chats that belong to a project live on that project's own
				// page instead of cluttering the main list, same as ChatGPT.
				var convos = readConvos().filter(function(c) { return !c.project_id; }).sort(function(a, b) {
					return Date.parse(b.updated_at || 0) - Date.parse(a.updated_at || 0);
				});

				var query = (elConvSearch && elConvSearch.value || '').trim().toLowerCase();
				elConvSearchClear.hidden = !query;
				if (query) {
					convos = convos.filter(function(c) {
						return (c.title || '').toLowerCase().indexOf(query) !== -1;
					});
				}

				elList.innerHTML = '';

				if (!convos.length) {
					var empty = document.createElement('div');
					empty.className = 'chat-conv-empty-search';
					empty.textContent = query ? 'No chats found for "' + query + '"' : 'No conversations yet';
					elList.appendChild(empty);
					return;
				}

				convos.forEach(function(c) {
					elList.appendChild(buildConvItem(c, loadConversations));
				});
			}

			function setActiveConversationItem(id) {
				Array.prototype.forEach.call(document.querySelectorAll('.chat-conv-item'), function(el) {
					el.classList.toggle('active', el.dataset.id === id);
				});
			}

			// ── Projects UI ───────────────────────────────────────────────
			function renderProjectsList() {
				var projects = readProjects();
				elProjectsList.innerHTML = '';
				if (!projects.length) {
					var empty = document.createElement('div');
					empty.className = 'chat-projects-empty';
					empty.textContent = 'No projects yet';
					elProjectsList.appendChild(empty);
					return;
				}
				projects.forEach(function(p) {
					var item = document.createElement('div');
					item.className = 'chat-project-item' + (p.id === currentProjectViewId ? ' active' : '');
					item.dataset.id = p.id;
					var icon = document.createElement('span');
					icon.className = 'chat-project-item-icon';
					icon.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"></path></svg>';
					item.appendChild(icon);
					var nameSpan = document.createElement('span');
					nameSpan.className = 'chat-project-item-name'; nameSpan.textContent = p.name;
					item.appendChild(nameSpan);
					var delBtn = document.createElement('button');
					delBtn.className = 'chat-project-item-delete'; delBtn.innerHTML = '&times;'; delBtn.title = 'Delete project';
					delBtn.addEventListener('click', function(e) {
						e.stopPropagation();
						if (!confirm(LANGS[currentLang].i18n.confirm_delete_project || 'Delete this project? Chats inside it will move back to your regular chat list.')) return;
						deleteProjectLocal(p.id);
						if (currentProjectViewId === p.id) { showChatView(); startNewChat(); }
						renderProjectsList();
						loadConversations();
					});
					item.appendChild(delBtn);
					item.addEventListener('click', function() { openProjectView(p.id); closeSidebar(); });
					elProjectsList.appendChild(item);
				});
			}

			function renderProjectConvList() {
				elProjectConvList.innerHTML = '';
				if (!currentProjectViewId) return;
				convosForProject(currentProjectViewId).forEach(function(c) {
					elProjectConvList.appendChild(buildConvItem(c, renderProjectConvList));
				});
			}

			function openProjectView(projectId) {
				var project = getProject(projectId);
				if (!project) return;
				currentProjectViewId = projectId;
				elProjectTitle.textContent = project.name;
				showProjectView();
				renderProjectConvList();
				renderProjectsList();
			}

			function updateSendButtonForCurrentConvo() {
				setSendButtonState(activeGenerations[currentConversationId] ? 'stop' : 'send');
			}

			function openConversation(id, title) {
				currentConversationId = id;
				elTitle.textContent = title || 'Chat';
				setActiveConversationItem(id);
				renderEmptyState();
				var convo = getConvo(id);
				if (convo && convo.messages && convo.messages.length) {
					elMessages.innerHTML = '';
					var idsBackfilled = false;
					convo.messages.forEach(function(m) {
						if (m.role === 'assistant' && m.model && !m.id) {
							m.id = newId();
							idsBackfilled = true;
						}
						addMessageBubble(m.role, m.text || '', m.attachments || [], m.model, m.id, m.feedback);
					});
					if (idsBackfilled) upsertConvo(convo);
				}
				// If this conversation still has a reply generating in the
				// background (e.g. it was started, then the user switched
				// away before it finished), re-attach the live bubbles so
				// the in-progress reply keeps streaming into view instead
				// of looking like it vanished.
				var gen = activeGenerations[id];
				if (gen) {
					var emptyState = elMessages.querySelector('.chat-empty-state');
					if (emptyState) emptyState.remove();
					elMessages.appendChild(gen.userBubbleEl);
					elMessages.appendChild(gen.assistantBubble);
					elMessages.scrollTop = elMessages.scrollHeight;
				}
				updateSendButtonForCurrentConvo();
			}

			function startNewChat(projectId) {
				currentConversationId = null;
				currentProjectContext = projectId || null;
				elTitle.textContent = 'New Chat';
				setActiveConversationItem(null);
				renderEmptyState();
				updateSendButtonForCurrentConvo();
			}

			// Toggles the single send/stop button between its two states.
			// 'stop' is shown the whole time a reply is streaming in, so
			// the user can cancel a long/complex generation whenever they
			// want instead of being stuck waiting.
			function setSendButtonState(state) {
				var isStop = state === 'stop';
				elSend.classList.toggle('is-stop', isStop);
				elSend.title = isStop ? 'Stop generating' : 'Send';
				elSend.querySelector('.chat-icon-send').style.display = isStop ? 'none' : '';
				elSend.querySelector('.chat-icon-stop').style.display = isStop ? '' : 'none';
				elSend.disabled = false;
			}

			function stopGeneration() {
				var g = activeGenerations[currentConversationId];
				if (!g) return;
				if (g.abortController) g.abortController.abort();
				if (g.reader) { try { g.reader.cancel(); } catch (e) {} }
			}

			elSend.addEventListener('click', function() {
				if (activeGenerations[currentConversationId]) {
					stopGeneration();
				} else {
					sendMessage();
				}
			});

			function sendMessage() {
				var text = elInput.value.trim();
				var attachmentsToSend = pendingAttachments.filter(function(a) { return !!a.dataUrl; });
				if (!text && !attachmentsToSend.length) return;

				var selectedModel = elModelSelect.value;

				// Make sure we have a local conversation to append to.
				if (!currentConversationId) {
					currentConversationId = newId();
					var now = new Date().toISOString();
					upsertConvo({
						id: currentConversationId,
						title: makeTitle(text || (attachmentsToSend[0] && attachmentsToSend[0].name) || 'New Chat'),
						created_at: now,
						updated_at: now,
						project_id: currentProjectContext || null,
						messages: []
					});
					elTitle.textContent = getConvo(currentConversationId).title;
					if (currentProjectContext) renderProjectsList();
				}
				var convo = getConvo(currentConversationId);
				if (!convo) {
					// Conversation vanished (e.g. deleted in another tab) — start fresh.
					currentConversationId = null;
					return sendMessage();
				}

				// Snapshot which conversation this send belongs to. The user
				// may switch to a different chat while the reply is still
				// streaming in — currentConversationId will change, but this
				// generation must keep targeting the conversation it was
				// actually started from (both for saving the reply, and for
				// telling the server which conversation it's replying to).
				var genConversationId = currentConversationId;
				if (activeGenerations[genConversationId]) return; // already generating here

				// Assigned now (not at persist time) so the feedback bar
				// attached to the live-streaming bubble below can reference
				// the same id that ends up saved with the message.
				var assistantMsgId = newId();

				// Everything already in this conversation becomes the history
				// sent to the API. Older attachments are dropped from the
				// resend to keep the request small — only this turn's
				// attachments are sent (matches what the model actually needs
				// to answer the latest message).
				var historyForApi = convo.messages.map(function(m) {
					return { role: m.role, text: m.text || '', attachments: [] };
				});

				var userBubbleEl = addMessageBubble('user', text, attachmentsToSend.map(function(a) {
					return { name: a.name, type: a.type, isImage: a.isImage, dataUrl: a.dataUrl };
				}));

				elInput.value = '';
				elInput.style.height = 'auto';
				updateSendButtonForCurrentConvo();

				var apiAttachments = attachmentsToSend.map(function(a) {
					return { name: a.name, type: a.type, size: a.size, data: a.dataUrl };
				});
				var localAttachments = attachmentsToSend.map(function(a) {
					return { name: a.name, type: a.type, isImage: a.isImage, dataUrl: a.dataUrl };
				});
				pendingAttachments = [];
				renderAttachPreview();

				var emptyState = elMessages.querySelector('.chat-empty-state');
				if (emptyState) emptyState.remove();

				var assistantBubble = document.createElement('div');
				assistantBubble.className = 'chat-msg assistant';

				var avatar = makeAssistantAvatar(selectedModel);
				assistantBubble.appendChild(avatar);

				var contentWrap = document.createElement('div');
				contentWrap.className = 'chat-msg-content';

				var textDiv = document.createElement('div');
				textDiv.className = 'chat-msg-text';
				contentWrap.appendChild(textDiv);

  var thinkingEl   = null;
  var thinkingBody = null;
  var activityList = null;
var activityMissionKeys = {};
					var activityQueue = [];
					var activityTimer = null;
					var activityVisibleCount = 0;
					var thinkingText = '';

				// Claude-style "Thinking… / Editing…" pill shown above the
				// reply while the model is actively streaming a fenced code
				// block, so the user gets the same visual cue Claude gives
				// while it writes out full code — a spinning icon plus
				// shimmering text that alternates between the two labels
				// until the code block closes.
				var codeStatusEl       = null;
				var codeStatusTextEl   = null;
var codeStatusInterval = null;
					var workUpdateInterval = null;
					var workUpdateIndex = 0;
					var codeStatusPhrases  = ['Thinking', 'Reading', 'Editing', 'Reviewing'];
  var codeStatusPhraseIx = 0;

				assistantBubble.appendChild(contentWrap);
				elMessages.appendChild(assistantBubble);

				// Track this generation against the conversation it actually
				// belongs to (genConversationId), not whichever conversation
				// happens to be open later. This is what lets openConversation()
				// re-attach a still-streaming reply if the user switches away
				// and back, and lets the send/stop button + persistTurn() below
				// target the right chat even after the user has navigated off it.
				activeGenerations[genConversationId] = {
					abortController: null,
					reader: null,
					userBubbleEl: userBubbleEl,
					assistantBubble: assistantBubble
				};
				updateSendButtonForCurrentConvo();

				var cursor = document.createElement('span');
				cursor.className = 'chat-cursor';
				// The streamed reply is written into this single text node
				// instead of being re-parsed as HTML on every token — see
				// the batched render pipeline below.
				var streamTextNode = document.createTextNode('');
				textDiv.appendChild(streamTextNode);
				textDiv.appendChild(cursor);
				elMessages.scrollTop = elMessages.scrollHeight;

				var fullText = '';

				// ── Batched rendering ───────────────��────────────────────
				// A fast model can emit far more than 60 tokens/sec. The
				// old code re-escaped the *entire* accumulated reply and
				// rebuilt textDiv's innerHTML on every single token, plus
				// forced a synchronous scroll reflow each time — cost grows
				// with the reply length, so a long code block made every
				// subsequent token more expensive than the last and the
				// tab would freeze. Instead we just append to plain JS
				// strings as tokens arrive (cheap) and flush the DOM at
				// most once per animation frame, however many tokens
				// landed in between.
				var streamEnded      = false;
				var renderScheduled  = false;
				var scrollNeeded     = false;
				var thinkingDirty    = false;

				function flushFrame() {
					renderScheduled = false;
					if (streamEnded) return; // final markdown render already replaced the DOM
						// Keep the assistant surface clean while generating. The complete
						// answer is promoted to the chat/artifact renderer only on done.
						if (streamTextNode.nodeValue !== '') {
							streamTextNode.nodeValue = '';
						}
					if (thinkingDirty && thinkingBody) {
						thinkingBody.textContent = thinkingText;
						thinkingDirty = false;
					}
					if (scrollNeeded) {
						if (genConversationId === currentConversationId) {
							elMessages.scrollTop = elMessages.scrollHeight;
						}
						scrollNeeded = false;
					}
				}
				function scheduleFrame() {
					if (renderScheduled) return;
					renderScheduled = true;
					requestAnimationFrame(flushFrame);
				}

function ensureThinkingBlock() {
						if (thinkingEl) return;
						activityMissionKeys = {};
					thinkingEl = document.createElement('details');
						thinkingEl.className = 'chat-thinking';
						thinkingEl.open = true;
						var summary = document.createElement('summary');
						summary.textContent = 'Working';
					thinkingBody = document.createElement('div');
					thinkingBody.className = 'chat-thinking-body';
  thinkingEl.appendChild(summary);
  activityList = document.createElement('div');
  activityList.className = 'chat-activity-list';
  thinkingEl.appendChild(activityList);
  thinkingEl.appendChild(thinkingBody);
  contentWrap.insertBefore(thinkingEl, textDiv);
				}

function enqueueActivity(activity) {
						activityQueue.push(activity);
						if (!activityTimer) activityTimer = setInterval(flushActivityQueue, 260);
						flushActivityQueue();
					}

					function flushActivityQueue() {
						if (!activityList || !activityQueue.length) {
							if (activityTimer && !activityQueue.length) { clearInterval(activityTimer); activityTimer = null; }
							return;
						}
						var activity = activityQueue.shift();
						activityVisibleCount++;
						if (activityVisibleCount > 5) {
							// Keep the complete v0-style mission history visible. Do not
							// remove older rows while a response is being generated.
							activityVisibleCount = 5;
						}
						var didLabels = { thinking: 'AI thought through the next step', reading: 'AI read the relevant source', editing: 'AI updated the file', checking: 'AI reviewed the result' };
						var didLabel = activity.label || didLabels[activity.type] || 'AI completed a step';
						var row = document.createElement('details');
						row.className = 'chat-activity-row';
						row.dataset.type = activity.type || 'thinking';
						var rowSummary = document.createElement('summary');
						rowSummary.className = 'chat-activity-summary';
						var icon = document.createElement('span');
						icon.className = 'chat-activity-icon';
						icon.textContent = '•';
						var label = document.createElement('span');
						label.textContent = didLabel;
						rowSummary.appendChild(icon);
						rowSummary.appendChild(label);
						var detail = document.createElement('div');
						detail.className = 'chat-activity-detail';
						detail.textContent = activity.label ? didLabel + ': ' + activity.label : didLabel + '.';
						row.appendChild(rowSummary);
						row.appendChild(detail);
						activityList.appendChild(row);
						scrollNeeded = true;
						scheduleFrame();
					}

					function ensureCodeStatus() {
						if (codeStatusEl) return;
					codeStatusEl = document.createElement('div');
					codeStatusEl.className = 'chat-code-status';
					codeStatusEl.innerHTML =
						'<svg class="chat-code-status-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">' +
							'<circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-dasharray="34 100"></circle>' +
						'</svg>' +
						'<span class="chat-code-status-text">AI is thinking</span>';
					contentWrap.insertBefore(codeStatusEl, textDiv);
					codeStatusTextEl = codeStatusEl.querySelector('.chat-code-status-text');
					codeStatusPhraseIx = 0;
codeStatusInterval = setInterval(function() {
							codeStatusPhraseIx = (codeStatusPhraseIx + 1) % codeStatusPhrases.length;
							if (codeStatusTextEl) codeStatusTextEl.textContent = codeStatusPhrases[codeStatusPhraseIx];
						}, 1100);
						if (!workUpdateInterval) {
							var workUpdates = [
								{ type: 'thinking', label: 'AI is planning the implementation' },
								{ type: 'reading', label: 'AI is checking the request and attached context' },
								{ type: 'editing', label: 'AI is assembling the code and file structure' },
								{ type: 'checking', label: 'AI is reviewing the generated result' }
							];
							workUpdateInterval = setInterval(function() {
								if (streamEnded) return;
								if (workUpdateIndex < workUpdates.length) enqueueActivity(workUpdates[workUpdateIndex++]);
							}, 3200);
						}

					scrollNeeded = true;
					scheduleFrame();
				}

function removeCodeStatus() {
						if (codeStatusInterval) { clearInterval(codeStatusInterval); codeStatusInterval = null; }
						if (workUpdateInterval) { clearInterval(workUpdateInterval); workUpdateInterval = null; }
						workUpdateIndex = 0;
					if (codeStatusEl) { codeStatusEl.remove(); codeStatusEl = null; codeStatusTextEl = null; }
				}

				function persistTurn(replyText) {
					var c = getConvo(genConversationId);
					if (!c) return;
					c.messages.push({ role: 'user', text: text, attachments: localAttachments });
					c.messages.push({ role: 'assistant', text: replyText, attachments: [], model: selectedModel, id: assistantMsgId, feedback: null });
					c.updated_at = new Date().toISOString();
					upsertConvo(c);
					loadConversations();
					if (currentProjectViewId) renderProjectConvList();
				}

				// Called when the server had to fail over to a different
				// model than the one selected in the picker (the selected
				// one was down/cooling down/disabled). Updates the model
				// picker, the in-progress assistant avatar, and drops a
				// small note in the reply bubble so it's clear what
				// happened — without interrupting the generation itself.
				function applyModelFallback(newModelId, opts) {
					opts = opts || {};
					if (!newModelId || newModelId === selectedModel) return;
					var oldModelId = selectedModel;
					selectedModel = newModelId;

					if (elModelSelect.value !== newModelId) {
						elModelSelect.value = newModelId;
						updateModelUI();
					}

					var newAvatar = makeAssistantAvatar(newModelId);
					if (avatar && avatar.parentNode) avatar.parentNode.replaceChild(newAvatar, avatar);
					avatar = newAvatar;

					if (opts.notify !== false) {
						var note = document.createElement('div');
						note.className = 'chat-model-switch-note';
						note.textContent = modelLabelFor(oldModelId) + ' was unavailable — switched to ' + modelLabelFor(newModelId) + '.';
						contentWrap.insertBefore(note, textDiv);
					}

					// Refresh the picker's greyed-out options soon so this
					// (and any other open tab) reflects the cooldown right away
					// instead of waiting for the next 30s poll.
					checkAiStatus();
				}

				// No client-side time limit is imposed on the request itself
				// — fetch() has no timeout by default and none is added
				// here, so a long/complex reply (large code files, deep
				// reasoning, etc.) is free to keep streaming for as long as
				// it takes. The only way this ends early is the user
				// clicking Stop (or leaving the page), via the abort
				// controller below.
				var abortController = new AbortController();
				activeGenerations[genConversationId].abortController = abortController;
				var wasStopped = false;

				function finishGenerating() {
					delete activeGenerations[genConversationId];
					// Only touch the send button / steal keyboard focus if the
					// user is still looking at this conversation. If they've
					// switched away, this generation finishing in the background
					// shouldn't hijack whatever chat is now on screen.
					if (genConversationId === currentConversationId) {
						setSendButtonState('send');
						elInput.focus();
					}
				}

				fetch(restUrl + '/chat-stream', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': nonce,
						'X-MLP-Guest-Token': guestToken,
						'X-MLP-Guest-Username': identity ? identity.username : ''
					},
					body: JSON.stringify({
						message: text, model: selectedModel,
						conversation_id: currentConversationId,
						attachments: apiAttachments,
						history: historyForApi,
						lang: currentLang
					}),
					signal: abortController.signal
				}).then(function(response) {
					if (!response.ok) {
						return response.json().then(function(err) { throw new Error(err.message || 'Request failed'); });
					}
					var reader    = response.body.getReader();
					var decoder   = new TextDecoder();
					var sseBuffer = '';
					activeGenerations[genConversationId].reader = reader;

					function readChunk() {
						return reader.read().then(function(result) {
							if (result.done) return;
							sseBuffer += decoder.decode(result.value, { stream: true });
							var lines = sseBuffer.split('\n');
							sseBuffer = lines.pop();
							lines.forEach(function(line) {
								line = line.trim();
								if (line.indexOf('data: ') !== 0) return;
								try {
									var data = JSON.parse(line.slice(6));
									if (data.model_switched) {
										// Sent before any tokens for this attempt, so
										// it's safe to swap the picker/avatar now.
										applyModelFallback(data.model_used);
										return;
									}
									if (data.error) {
										streamEnded = true;
										cursor.remove();
										removeCodeStatus();
										if (!fullText) {
											var errMsg = data.error.indexOf('quota') !== -1
												? '⚠️ ' + data.error
												: 'Error: ' + data.error;
											var isCookieErr = data.error.indexOf('Cookie check failed') !== -1 || data.error.indexOf('cookie') !== -1;
											if (isCookieErr) {
												textDiv.innerHTML = '<div style="color:#d63638;font-weight:600;margin-bottom:10px;">' + escapeHtml(errMsg) + '</div>' +
													'<button class="chat-reload-btn" type="button" onclick="window.location.reload();">&#x21bb; Reload the site</button>';
											} else {
												textDiv.innerHTML = escapeHtml(errMsg);
											}
										}
										finishGenerating();
										return;
									}
  if (data.thinking) {
  ensureThinkingBlock();
  thinkingText += data.thinking;
  thinkingDirty = true;
  scrollNeeded = true;
  scheduleFrame();
  }
  if (data.activity) {
  ensureThinkingBlock();
  ensureCodeStatus();
  var activity = data.activity;
  var liveStatus = activity.type === 'checking' ? 'Reviewing' : activity.type === 'editing' ? 'Editing' : activity.type === 'reading' ? 'Reading' : 'Thinking';
  if (codeStatusTextEl) codeStatusTextEl.textContent = liveStatus;
  var didLabels = { thinking: 'AI thought through the next step', reading: 'AI read the relevant source', editing: 'AI updated the file', checking: 'AI reviewed the result' };
  var didLabel = activity.label || didLabels[activity.type] || 'AI completed a step';
  var missionKey = (activity.type || 'thinking') + '|' + didLabel.toLowerCase().replace(/\s+/g, ' ').trim();
  if (activityMissionKeys[missionKey]) return;
  activityMissionKeys[missionKey] = true;
  enqueueActivity(activity);
  return;
  var row = document.createElement('details');
  row.className = 'chat-activity-row';
  row.dataset.type = activity.type || 'thinking';
  var rowSummary = document.createElement('summary');
  rowSummary.className = 'chat-activity-summary';
						var icon = document.createElement('span');
						icon.className = 'chat-activity-icon';
						var iconPaths = {
							thinking: '<path d="M9 18h6M10 22h4M8.5 14.5a7 7 0 1 1 7 0c-.8.6-1.3 1.2-1.5 2h-4c-.2-.8-.7-1.4-1.5-2Z"/>',
							reading: '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5v-15ZM4 20.5A2.5 2.5 0 0 1 6.5 18H20"/>',
							editing: '<path d="m14 6 4 4M5 19l3.5-.8L19 7.7a1.4 1.4 0 0 0 0-2l-.7-.7a1.4 1.4 0 0 0-2 0L5.8 15.5 5 19Z"/>',
							checking: '<path d="m5 12 4 4L19 6"/>'
						};
					icon.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' + (iconPaths[activity.type] || iconPaths.thinking) + '</svg>';
						var label = document.createElement('span');
  label.textContent = activity.label || didLabel;
  rowSummary.appendChild(icon);
  rowSummary.appendChild(label);
  var detail = document.createElement('div');
  detail.className = 'chat-activity-detail';
  detail.textContent = activity.label ? didLabel + ': ' + activity.label : didLabel + '.';
  row.appendChild(rowSummary);
  row.appendChild(detail);
  activityList.appendChild(row);
  scrollNeeded = true;
  scheduleFrame();
  }
  if (data.token) {
										// v0-style generation: keep the final answer hidden while
										// the model works. Only activity/status is live; the
										// complete response is rendered once the stream finishes.
										fullText += data.token;
										ensureCodeStatus();
										scrollNeeded = true;
										scheduleFrame();
									}
									if (data.done) {
										// Safety net in case a model_switched event was
										// missed — makes sure the feedback bar/history
										// entry below are tagged with whichever model
										// actually produced this reply.
										if (data.model_used) applyModelFallback(data.model_used, { notify: false });
										streamEnded = true; // stop any in-flight rAF from touching the (about to be replaced) DOM
										cursor.remove();
										removeCodeStatus();
										if (thinkingEl) {
											var smry = thinkingEl.querySelector('summary');
											if (smry) smry.textContent = 'Thinking';
										}
							var finalText = fullText.replace(/^\s*(?:THINK|READ|EDIT|CHECK):[^\n]*\n?/gim, '').trim();
							textDiv.innerHTML = renderMarkdown(finalText);
							contentWrap.appendChild(makeFeedbackBar(selectedModel, assistantMsgId, null));
							persistTurn(finalText);
										finishGenerating();
									}
								} catch(e) {}
							});
							return readChunk();
						});
					}
					return readChunk();
				}).catch(function(err) {
					streamEnded = true;
					cursor.remove();
					removeCodeStatus();
					wasStopped = err && (err.name === 'AbortError');
					if (wasStopped) {
						// User hit Stop mid-stream — keep whatever text has
						// already arrived rather than discarding it, and
						// save the partial reply just like a finished one.
							if (fullText) {
								var stoppedText = fullText.replace(/^\s*(?:THINK|READ|EDIT|CHECK):[^\n]*\n?/gim, '').trim();
								textDiv.innerHTML = renderMarkdown(stoppedText) + '<div class="chat-stopped-note">Stopped by user</div>';
								contentWrap.appendChild(makeFeedbackBar(selectedModel, assistantMsgId, null));
								persistTurn(stoppedText);
						} else {
							textDiv.innerHTML = '<div class="chat-stopped-note">Stopped by user</div>';
						}
					} else if (!fullText) {
						textDiv.innerHTML = escapeHtml('Error: ' + err.message);
					}
					finishGenerating();
				});
			}

			function closeAttachMenu() {
				elAttachMenu.hidden = true;
				elAttachBtn.setAttribute('aria-expanded', 'false');
			}
			function openAttachMenu() {
				elAttachMenu.hidden = false;
				elAttachBtn.setAttribute('aria-expanded', 'true');
			}
			elAttachBtn.addEventListener('click', function(e) {
				e.stopPropagation();
				if (elAttachMenu.hidden) openAttachMenu(); else closeAttachMenu();
			});
			elAttachMenuImage.addEventListener('click', function() {
				closeAttachMenu();
				if (!currentImagesOk) { alert("This AI Model doesn't support images."); return; }
				elImageInput.click();
			});
			elAttachMenuFile.addEventListener('click', function() {
				closeAttachMenu();
				elFileInput.click();
			});
			document.addEventListener('click', function(e) {
				if (!elAttachMenu.hidden && !elAttachMenu.contains(e.target) && e.target !== elAttachBtn) closeAttachMenu();
			});
			document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeAttachMenu(); });
			elImageInput.addEventListener('change', function() { addFiles(this.files); this.value = ''; });
			elFileInput.addEventListener('change', function() { addFiles(this.files); this.value = ''; });

			var dragCounter = 0;
			elInputWrap.addEventListener('dragover',  function(e) { e.preventDefault(); });
			elInputWrap.addEventListener('dragenter', function(e) { e.preventDefault(); dragCounter++; elInputWrap.classList.add('drag-over'); });
			elInputWrap.addEventListener('dragleave', function(e) { e.preventDefault(); dragCounter = Math.max(0, dragCounter - 1); if (!dragCounter) elInputWrap.classList.remove('drag-over'); });
			elInputWrap.addEventListener('drop',      function(e) { e.preventDefault(); dragCounter = 0; elInputWrap.classList.remove('drag-over'); if (e.dataTransfer && e.dataTransfer.files) addFiles(e.dataTransfer.files); });

			elInput.addEventListener('paste', function(e) {
				var items = (e.clipboardData || {}).items || [];
				var pastedFiles = [];
				for (var i = 0; i < items.length; i++) {
					if (items[i].kind === 'file') { var f = items[i].getAsFile(); if (f) pastedFiles.push(f); }
				}
				if (pastedFiles.length) addFiles(pastedFiles);
			});

			elInput.addEventListener('keydown', function(e) {
				if (e.key === 'Enter' && !e.shiftKey) {
					e.preventDefault();
					if (!elSend.classList.contains('is-stop')) sendMessage();
				}
			});
			elInput.addEventListener('input', function() {
				elInput.style.height = 'auto';
				elInput.style.height = Math.min(elInput.scrollHeight, 140) + 'px';
			});
			elNewChat.addEventListener('click', function() { showChatView(); startNewChat(); closeSidebar(); });

		// ── New Models popup ─────────────────────────────────────���───────
		function openNewModelsModal() { elNewModelsModal.removeAttribute('data-hidden'); }
		function closeNewModelsModal() { elNewModelsModal.setAttribute('data-hidden', '1'); }

		if (elNewModelsBtn) {
			elNewModelsBtn.addEventListener('click', function() { openNewModelsModal(); });
		}
		if (elNewModelsClose) {
			elNewModelsClose.addEventListener('click', closeNewModelsModal);
		}
		elNewModelsModal.addEventListener('click', function(e) {
			if (e.target === elNewModelsModal) closeNewModelsModal();
		});
		Array.prototype.forEach.call(elNewModelsModal.querySelectorAll('.chat-new-models-start-btn'), function(btn) {
			btn.addEventListener('click', function() {
				var modelId = btn.getAttribute('data-model-id');
				if (modelId) {
					elModelSelect.value = modelId;
					updateModelUI();
				}
				closeNewModelsModal();
				showChatView();
				startNewChat();
				closeSidebar();
			});
		});

		// ── Projects (ChatGPT-style) ──────────────────────────────────────
		var PROJECTS_COLLAPSED_KEY = 'mlp_ai_chat_projects_collapsed';
		function isProjectsCollapsed() {
			try { return window.localStorage.getItem(PROJECTS_COLLAPSED_KEY) === '1'; } catch (e) { return false; }
		}
		function setProjectsCollapsed(collapsed) {
			try { window.localStorage.setItem(PROJECTS_COLLAPSED_KEY, collapsed ? '1' : '0'); } catch (e) {}
			elProjectsList.setAttribute('data-collapsed', collapsed ? '1' : '0');
			elProjectsToggleBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
		}
		setProjectsCollapsed(isProjectsCollapsed());
		if (elProjectsToggleBtn) {
			elProjectsToggleBtn.addEventListener('click', function() {
				setProjectsCollapsed(elProjectsList.getAttribute('data-collapsed') !== '1');
			});
		}

		function openNewProjectModal() {
			elNewProjectError.hidden = true;
			elNewProjectInput.value = '';
			elNewProjectModal.removeAttribute('data-hidden');
			setTimeout(function() { elNewProjectInput.focus(); }, 30);
		}
		function closeNewProjectModal() { elNewProjectModal.setAttribute('data-hidden', '1'); }

		if (elProjectsAddBtn) {
			elProjectsAddBtn.addEventListener('click', function() { openNewProjectModal(); });
		}
		if (elNewProjectClose) {
			elNewProjectClose.addEventListener('click', closeNewProjectModal);
		}
		if (elNewProjectCancel) {
			elNewProjectCancel.addEventListener('click', closeNewProjectModal);
		}
		if (elNewProjectModal) {
			elNewProjectModal.addEventListener('click', function(e) {
				if (e.target === elNewProjectModal) closeNewProjectModal();
			});
		}
		function submitNewProject() {
			var name = (elNewProjectInput.value || '').trim();
			if (!name) {
				elNewProjectError.hidden = false;
				elNewProjectInput.focus();
				return;
			}
			var project = createProject(name);
			closeNewProjectModal();
			setProjectsCollapsed(false);
			renderProjectsList();
			openProjectView(project.id);
			closeSidebar();
		}
		if (elNewProjectCreate) {
			elNewProjectCreate.addEventListener('click', submitNewProject);
		}
		if (elNewProjectInput) {
			elNewProjectInput.addEventListener('keydown', function(e) {
				if (e.key === 'Enter') { e.preventDefault(); submitNewProject(); }
			});
			elNewProjectInput.addEventListener('input', function() { elNewProjectError.hidden = true; });
		}
		if (elProjectNewChatBtn) {
			elProjectNewChatBtn.addEventListener('click', function() {
				var pid = currentProjectViewId;
				showChatView();
				startNewChat(pid);
				closeSidebar();
			});
		}
		if (elProjectDeleteBtn) {
			elProjectDeleteBtn.addEventListener('click', function() {
				if (!currentProjectViewId) return;
				if (!confirm(LANGS[currentLang].i18n.confirm_delete_project || 'Delete this project? Chats inside it will move back to your regular chat list.')) return;
				deleteProjectLocal(currentProjectViewId);
				showChatView();
				startNewChat();
				loadConversations();
				closeSidebar();
			});
		}

		// ── Featured On popup ────────────────────────────────────────────
		function openFeaturedOnModal() { elFeaturedOnModal.removeAttribute('data-hidden'); }
		function closeFeaturedOnModal() { elFeaturedOnModal.setAttribute('data-hidden', '1'); }

		if (elFeaturedOnBtn) {
			elFeaturedOnBtn.addEventListener('click', function() { openFeaturedOnModal(); });
		}
		if (elFeaturedOnClose) {
			elFeaturedOnClose.addEventListener('click', closeFeaturedOnModal);
		}
		if (elFeaturedOnModal) {
			elFeaturedOnModal.addEventListener('click', function(e) {
				if (e.target === elFeaturedOnModal) closeFeaturedOnModal();
			});
		}

			// Sidebar controls
			document.getElementById('chat-code-sidebar-close').addEventListener('click', closeCodeSidebar);
			document.getElementById('chat-code-sidebar-download').addEventListener('click', downloadCurrentFile);
			document.getElementById('chat-preview-sidebar-close').addEventListener('click', closePreviewSidebar);
			document.getElementById('chat-preview-sidebar-fullscreen').addEventListener('click', togglePreviewFullscreen);

			// Copy buttons
			attachCopyListeners();

			// Sidebar chat search
			elConvSearch.addEventListener('input', function() { loadConversations(); });
			elConvSearchClear.addEventListener('click', function() {
				elConvSearch.value = '';
				loadConversations();
				elConvSearch.focus();
			});

			// Sidebar logo click -> New Chat
			var elSidebarLogo = document.querySelector('.chat-sidebar-logo');
			if (elSidebarLogo) {
				elSidebarLogo.title = 'Start new chat';
				elSidebarLogo.style.cursor = 'pointer';
				elSidebarLogo.addEventListener('click', function() {
					showChatView();
					startNewChat();
					closeSidebar();
				});
			}

			// ── Mobile off-canvas sidebar ────────────────────────────────────
			// Below 768px the sidebar becomes a slide-in drawer opened via the
			// hamburger button in the header; above that width these are
			// harmless no-ops since the CSS keeps the sidebar always visible.
			function openSidebar() {
				elSidebar.classList.add('open');
				elSidebarBackdrop.classList.add('open');
				if (elMenuBtn) elMenuBtn.setAttribute('aria-expanded', 'true');
				if (elAdminMenuBtn) elAdminMenuBtn.setAttribute('aria-expanded', 'true');
				if (elMediaMenuBtn) elMediaMenuBtn.setAttribute('aria-expanded', 'true');
				if (elProjectMenuBtn) elProjectMenuBtn.setAttribute('aria-expanded', 'true');
			}
			function closeSidebar() {
				elSidebar.classList.remove('open');
				elSidebarBackdrop.classList.remove('open');
				if (elMenuBtn) elMenuBtn.setAttribute('aria-expanded', 'false');
				if (elAdminMenuBtn) elAdminMenuBtn.setAttribute('aria-expanded', 'false');
				if (elMediaMenuBtn) elMediaMenuBtn.setAttribute('aria-expanded', 'false');
				if (elProjectMenuBtn) elProjectMenuBtn.setAttribute('aria-expanded', 'false');
			}
			function toggleSidebar() {
				if (elSidebar.classList.contains('open')) closeSidebar(); else openSidebar();
			}
			if (elMenuBtn) elMenuBtn.addEventListener('click', toggleSidebar);
			if (elAdminMenuBtn) elAdminMenuBtn.addEventListener('click', toggleSidebar);
			if (elMediaMenuBtn) elMediaMenuBtn.addEventListener('click', toggleSidebar);
			if (elProjectMenuBtn) elProjectMenuBtn.addEventListener('click', toggleSidebar);
			elSidebarBackdrop.addEventListener('click', closeSidebar);

			// ── Administration room (manage_options users only) ─────────────
			var STATE_LABELS = {
				online: 'Online', rate_limited: 'Rate Limited', blocked: 'Blocked',
				error: 'Error', offline: 'Offline', cooldown: 'Cooling Down (auto)',
				disabled: 'Disabled', unknown: 'Unknown'
			};

			function showChatView() {
				if (elAdminView) elAdminView.setAttribute('data-hidden', '1');
				if (elMediaView) elMediaView.setAttribute('data-hidden', '1');
				if (elProjectView) elProjectView.setAttribute('data-hidden', '1');
				elChatView.style.display = '';
				if (elAdminRoomBtn) elAdminRoomBtn.classList.remove('active');
				if (elMediaRoomBtn) elMediaRoomBtn.classList.remove('active');
				currentProjectViewId = null;
				renderProjectsList();
			}
			function showAdminView() {
				elChatView.style.display = 'none';
				if (elMediaView) elMediaView.setAttribute('data-hidden', '1');
				if (elProjectView) elProjectView.setAttribute('data-hidden', '1');
				if (elMediaRoomBtn) elMediaRoomBtn.classList.remove('active');
				elAdminView.setAttribute('data-hidden', '0');
				elAdminRoomBtn.classList.add('active');
				currentProjectViewId = null;
				renderProjectsList();
				refreshAdminData();
			}
			function showMediaView() {
				elChatView.style.display = 'none';
				if (elAdminView) elAdminView.setAttribute('data-hidden', '1');
				if (elProjectView) elProjectView.setAttribute('data-hidden', '1');
				if (elAdminRoomBtn) elAdminRoomBtn.classList.remove('active');
				elMediaView.setAttribute('data-hidden', '0');
				elMediaRoomBtn.classList.add('active');
				currentProjectViewId = null;
				renderProjectsList();
				renderMediaGallery();
			}
			function showProjectView() {
				elChatView.style.display = 'none';
				if (elAdminView) elAdminView.setAttribute('data-hidden', '1');
				if (elMediaView) elMediaView.setAttribute('data-hidden', '1');
				if (elAdminRoomBtn) elAdminRoomBtn.classList.remove('active');
				if (elMediaRoomBtn) elMediaRoomBtn.classList.remove('active');
				elProjectView.setAttribute('data-hidden', '0');
			}

			function renderAdminStats(data) {
				elAdminStats.innerHTML = '';
				var cards = [
					{ label: 'AI Status', value: data.disabled ? 'Disabled' : 'Enabled' },
					{ label: 'Total Requests', value: data.total_requests },
					{ label: 'New Users Today', value: data.new_today },
					{ label: 'All Users', value: data.all_users }
				];
				cards.forEach(function(c) {
					var card = document.createElement('div');
					card.className = 'chat-admin-stat-card';
					card.innerHTML = '<div class="chat-admin-stat-label"></div><div class="chat-admin-stat-value"></div>';
					card.querySelector('.chat-admin-stat-label').textContent = c.label;
					card.querySelector('.chat-admin-stat-value').textContent = c.value;
					elAdminStats.appendChild(card);
				});
			}

			function renderAdminModels(data) {
				elAdminModels.innerHTML = '';
				(data.models || []).forEach(function(m) {
					var row = document.createElement('div');
					row.className = 'chat-admin-model-row';

					var left = document.createElement('div');
					left.innerHTML = '<div class="chat-admin-model-name"></div><div class="chat-admin-model-meta"></div>';
					left.querySelector('.chat-admin-model-name').textContent = m.label;
					left.querySelector('.chat-admin-model-meta').textContent = m.message || (m.configured ? '' : 'API key not configured');
					row.appendChild(left);

					var status = document.createElement('div');
					status.className = 'chat-admin-model-status chat-status-' + m.state;
					status.innerHTML = '<span class="chat-status-dot"></span><span></span>';
					status.querySelector('span:last-child').textContent = STATE_LABELS[m.state] || m.state;
					row.appendChild(status);

					var votes = document.createElement('div');
					votes.className = 'chat-admin-model-votes';
					votes.innerHTML =
						'<span class="chat-admin-model-vote likes">' + likeSvg() + '<span></span></span>' +
						'<span class="chat-admin-model-vote dislikes">' + dislikeSvg() + '<span></span></span>';
					votes.querySelector('.likes span').textContent = m.likes || 0;
					votes.querySelector('.dislikes span').textContent = m.dislikes || 0;
					row.appendChild(votes);

					var toggleBtn = document.createElement('button');
					toggleBtn.type = 'button';
					toggleBtn.className = 'chat-admin-model-toggle' + (m.disabled ? ' is-disabled' : '');
					toggleBtn.textContent = m.disabled ? 'Enable' : 'Disable';
					toggleBtn.addEventListener('click', function() { toggleModel(m.id); });
					row.appendChild(toggleBtn);

					// Not manually disabled, but currently unusable/cooling down
					// after a failure — this is exactly what's hiding the model
					// from the visitor-facing picker. The plain toggle above only
					// controls the manual switch, so give admins a direct way to
					// clear the error/cooldown and make it active again.
					var hiddenByError = !m.disabled && ['error', 'offline', 'blocked', 'rate_limited', 'cooldown'].indexOf(m.state) !== -1;
					if (hiddenByError) {
						var reactivateBtn = document.createElement('button');
						reactivateBtn.type = 'button';
						reactivateBtn.className = 'chat-admin-model-reactivate';
						reactivateBtn.textContent = 'Reactivate';
						reactivateBtn.title = 'Clear the error/cooldown and make this model visible to users again';
						reactivateBtn.addEventListener('click', function() { reactivateModel(m.id); });
						row.appendChild(reactivateBtn);
					}

					elAdminModels.appendChild(row);
				});
			}

			function renderAdminData(data) {
				renderAdminStats(data);
				renderAdminModels(data);
				elAdminToggleGlobalBtn.textContent = data.disabled ? 'Re-enable AI Chat' : 'Disable AI Chat';
				elAdminToggleGlobalBtn.classList.toggle('is-disabled', !data.disabled);
				// Keep the visitor-facing UI's disabled state and model list in sync too.
				applyDisabledState(!!data.disabled);
				disabledModelIds = (data.models || []).filter(function(m) { return m.disabled; }).map(function(m) { return m.id; });
				applyModelDisabledOptions();
			}

			function refreshAdminData() {
				apiFetch('/admin/status').then(renderAdminData).catch(function() {});
			}

			function toggleGlobal() {
				elAdminToggleGlobalBtn.disabled = true;
				apiFetch('/admin/toggle-global', { method: 'POST' })
					.then(renderAdminData)
					.finally(function() { elAdminToggleGlobalBtn.disabled = false; });
			}

			function toggleModel(modelId) {
				apiFetch('/admin/toggle-model', {
					method: 'POST',
					body: JSON.stringify({ model_id: modelId })
				}).then(renderAdminData);
			}

			function reactivateModel(modelId) {
				apiFetch('/admin/reactivate-model', {
					method: 'POST',
					body: JSON.stringify({ model_id: modelId })
				}).then(renderAdminData);
			}

			if (elAdminRoomBtn) {
				elAdminRoomBtn.addEventListener('click', function() { showAdminView(); closeSidebar(); });
				elAdminRefreshBtn.addEventListener('click', refreshAdminData);
				elAdminToggleGlobalBtn.addEventListener('click', toggleGlobal);
			}

			// ── Media room (personal media library, stored in the browser) ──
			// Media the user uploads themselves lives entirely in localStorage
			// on this device — nothing is sent to the server. From here it can
			// be inserted into the chat input as a regular attachment.
			var MEDIA_STORAGE_KEY = 'mlpAiChatMediaLibrary';
			var MAX_MEDIA_FILE_BYTES = 3 * 1024 * 1024; // 3MB per item, mind localStorage's ~5-10MB quota

			function loadMediaLibrary() {
				try {
					var raw = localStorage.getItem(MEDIA_STORAGE_KEY);
					var parsed = raw ? JSON.parse(raw) : [];
					return Array.isArray(parsed) ? parsed : [];
				} catch (e) {
					return [];
				}
			}

			function saveMediaLibrary(items) {
				try {
					localStorage.setItem(MEDIA_STORAGE_KEY, JSON.stringify(items));
					return true;
				} catch (e) {
					alert('Could not save this media — your browser storage is full. Try removing some older items first.');
					return false;
				}
			}

			function addMediaFiles(fileList) {
				var files = Array.prototype.slice.call(fileList || []);
				if (!files.length) return;
				var library = loadMediaLibrary();
				var remaining = files.length;
				var anyAdded = false;

				files.forEach(function(file) {
					var isMediaFile = file.type.indexOf('image/') === 0 || file.type.indexOf('video/') === 0;
					if (!isMediaFile) {
						alert(file.name + ' is not an image or video and was skipped.');
						remaining--;
						return;
					}
					if (file.size > MAX_MEDIA_FILE_BYTES) {
						alert(file.name + ' is larger than 3MB and was skipped.');
						remaining--;
						return;
					}
					readFileAsDataURL(file).then(function(dataUrl) {
						library.push({
							id: 'media_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8),
							name: file.name,
							type: file.type || 'application/octet-stream',
							isImage: file.type.indexOf('image/') === 0,
							isVideo: file.type.indexOf('video/') === 0,
							dataUrl: dataUrl,
							addedAt: Date.now()
						});
						anyAdded = true;
					}).catch(function() {
						/* skip unreadable file */
					}).finally(function() {
						remaining--;
						if (remaining <= 0 && anyAdded) {
							saveMediaLibrary(library);
							renderMediaGallery();
						}
					});
				});
			}

			function deleteMediaItem(id) {
				var library = loadMediaLibrary().filter(function(item) { return item.id !== id; });
				saveMediaLibrary(library);
				renderMediaGallery();
			}

			function insertMediaIntoChat(item) {
				if (item.isImage && !currentImagesOk) {
					alert("This AI Model doesn't support images.");
					return;
				}
				if (pendingAttachments.length >= MAX_ATTACHMENTS) {
					alert('You can attach up to ' + MAX_ATTACHMENTS + ' files per message.');
					return;
				}
				pendingAttachments.push({
					id: 'att_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8),
					name: item.name,
					type: item.type,
					size: null,
					dataUrl: item.dataUrl,
					isImage: !!item.isImage
				});
				renderAttachPreview();
				showChatView();
				closeSidebar();
				elInput.focus();
			}

			function renderMediaGallery() {
				if (!elMediaGallery) return;
				var library = loadMediaLibrary().slice().sort(function(a, b) { return (b.addedAt || 0) - (a.addedAt || 0); });
				elMediaGallery.innerHTML = '';

				library.forEach(function(item) {
					var card = document.createElement('div');
					card.className = 'chat-media-item';

					if (item.isImage) {
						var img = document.createElement('img');
						img.src = item.dataUrl; img.alt = item.name;
						card.appendChild(img);
					} else if (item.isVideo) {
						var video = document.createElement('video');
						video.src = item.dataUrl; video.muted = true;
						card.appendChild(video);
					} else {
						var iconWrap = document.createElement('div');
						iconWrap.className = 'chat-media-item-file-icon';
						iconWrap.innerHTML = fileIconSvg();
						card.appendChild(iconWrap);
					}

					var nameLabel = document.createElement('div');
					nameLabel.className = 'chat-media-item-name';
					nameLabel.textContent = item.name;
					card.appendChild(nameLabel);

					var overlay = document.createElement('div');
					overlay.className = 'chat-media-item-overlay';

					var insertBtn = document.createElement('button');
					insertBtn.type = 'button';
					insertBtn.className = 'chat-media-item-insert-btn';
					insertBtn.textContent = 'Insert into chat';
					insertBtn.addEventListener('click', function() { insertMediaIntoChat(item); });
					overlay.appendChild(insertBtn);

					var deleteBtn = document.createElement('button');
					deleteBtn.type = 'button';
					deleteBtn.className = 'chat-media-item-delete-btn';
					deleteBtn.textContent = 'Delete';
					deleteBtn.addEventListener('click', function() {
						if (confirm('Remove "' + item.name + '" from your media?')) deleteMediaItem(item.id);
					});
					overlay.appendChild(deleteBtn);

					card.appendChild(overlay);
					elMediaGallery.appendChild(card);
				});
			}

			if (elMediaRoomBtn) {
				elMediaRoomBtn.addEventListener('click', function() { showMediaView(); closeSidebar(); });
			}
			if (elMediaAddBtn && elMediaFileInput) {
				elMediaAddBtn.addEventListener('click', function() { elMediaFileInput.click(); });
				elMediaFileInput.addEventListener('change', function() { addMediaFiles(this.files); this.value = ''; });
			}

			// ── Bootstrapping ────────────────────────────────────────────────
			// Everything that talks to the server waits until we know who's
			// asking: for logged-in users that's immediate; for guests, the
			// username modal has to be completed first.
			var chatStarted = false;
			function initChatApp() {
				if (chatStarted) return;
				chatStarted = true;
				checkAiStatus();
				// Keep polling so a model that starts cooling down (or
				// recovers after its 3-minute cooldown) gets reflected in
				// the model picker even if the visitor isn't actively
				// sending messages right now.
				setInterval(checkAiStatus, 30000);
				updateModelUI();
				attachCopyListeners();
				pruneStaleConversations().then(loadConversations, loadConversations);
				renderProjectsList();
			}

			function startAppFlow() {
				<?php if ( $user_id ) : ?>
				// Logged-in WP user — no name prompt needed.
				initChatApp();
				<?php else : ?>
				if (identity && identity.username) {
					initChatApp();
				} else {
					showUsernameModal();
				}
				<?php endif; ?>
			}

			// Everyone — logged-in or guest — must accept the Terms of
			// Service and Privacy Policy before the chat app starts.
			requireLegalConsent(startAppFlow);
		})();
		</script>
		<?php
		return ob_get_clean();
	}
}

MLP_AI_Chat::instance();
