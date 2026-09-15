<?php
/**
 * MLP Chat AI transport logic.
 *
 * This module owns the provider-facing chat request and response
 * normalization code. The main plugin file is responsible for WordPress
 * routes, permissions, conversations, UI rendering, and orchestration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MLP_AI_Logic' ) ) {
	/**
	 * Provider-facing AI logic kept outside the main plugin file.
	 */
	final class MLP_AI_Logic {

		/**
		 * Some providers/gateways (especially free-tier or proxy endpoints)
		 * don't reliably surface finish_reason === 'length' when a response
		 * is actually cut off mid-output, which silently disables the
		 * auto-continuation logic below and pushes the burden back onto the
		 * user clicking "Continue" repeatedly. An odd number of triple-
		 * backtick fences is a strong, cheap signal that a code block was
		 * opened and never closed — i.e. the response stopped mid-file even
		 * though the provider claimed it finished normally. Used as a
		 * fallback truncation signal alongside finish_reason everywhere
		 * auto-continuation decides whether to keep going.
		 *
		 * @param string $text
		 * @return bool
		 */
		public static function looks_truncated_mid_code( $text ) {
			$text = (string) $text;
			if ( '' === trim( $text ) ) {
				return false;
			}
			return 1 === ( substr_count( $text, '```' ) % 2 );
		}

		/**
		 * Calls an OpenAI-compatible chat completions endpoint, automatically
		 * continuing the generation if the provider cuts it off for hitting
		 * max_tokens (finish_reason === 'length').
		 *
		 * Large requests (long files, big refactors, etc.) routinely need more
		 * output than a single completion allows. Rather than handing the
		 * caller a response that just stops mid-file, this asks the model to
		 * continue exactly where it left off and stitches the pieces back
		 * together — the same behavior Claude and Replit show users as one
		 * uninterrupted answer. Tool calls are never auto-continued; that
		 * loop belongs to run_agentic_turn().
		 *
		 * @param array       $messages           Array of role/content messages.
		 * @param string      $model              Provider model name.
		 * @param string      $api_key            Provider API key.
		 * @param string      $api_url            Provider endpoint URL.
		 * @param array|null  $tools              Optional function-calling tools.
		 * @param int|null    $max_tokens         Maximum output token count per call.
		 * @param array       $sampling           Optional temperature/top_p/presence_penalty/frequency_penalty.
		 * @param int         $retry_attempts     Max attempts for transient failures (429/5xx/network), per call.
		 * @param int         $max_continuations  Max number of continuation calls for a single truncated answer.
		 * @return array|WP_Error Array with text/usage/finish_reason, or a WP_Error.
		 */
		public static function call_chat_api( $messages, $model, $api_key, $api_url, $tools = null, $max_tokens = null, $sampling = array(), $retry_attempts = 3, $max_continuations = 6 ) {
			$working_messages   = $messages;
			$accumulated_text   = '';
			$accumulated_usage  = array();
			$continuations_used = 0;
			$result             = null;

			do {
				$result = self::single_chat_request( $working_messages, $model, $api_key, $api_url, $tools, $max_tokens, $sampling, $retry_attempts );

				if ( is_wp_error( $result ) ) {
					// Nothing usable came back for this leg; if we already
					// accumulated earlier continuation text, prefer returning
					// that over discarding it, matching how a partial-but-
					// readable answer beats a hard failure for the user.
					if ( '' !== $accumulated_text ) {
						break;
					}

					return $result;
				}

				// Tool calls are a structurally different turn (they need a
				// dispatcher to execute and feed results back); don't try to
				// auto-continue through them here.
				if ( ! empty( $result['tool_calls'] ) ) {
					return $result;
				}

				$accumulated_text .= $result['text'];

				foreach ( array( 'prompt_tokens', 'completion_tokens', 'total_tokens' ) as $usage_key ) {
					if ( isset( $result['usage'][ $usage_key ] ) ) {
						$accumulated_usage[ $usage_key ] = ( isset( $accumulated_usage[ $usage_key ] ) ? $accumulated_usage[ $usage_key ] : 0 ) + (int) $result['usage'][ $usage_key ];
					}
				}

				$was_truncated = '' !== trim( (string) $result['text'] )
					&& ( 'length' === $result['finish_reason'] || self::looks_truncated_mid_code( $result['text'] ) );

				if ( ! $was_truncated || $continuations_used >= $max_continuations ) {
					break;
				}

				$continuations_used++;

				$working_messages[] = array(
					'role'    => 'assistant',
					'content' => $result['text'],
				);
				$working_messages[] = array(
					'role'    => 'user',
					'content' => 'Continue exactly where you left off. Do not repeat any earlier text and do not add commentary about continuing — just resume the output.',
				);
			} while ( true );

			return array(
				'text'          => $accumulated_text,
				'usage'         => $accumulated_usage,
				'finish_reason' => is_array( $result ) && isset( $result['finish_reason'] ) ? $result['finish_reason'] : '',
			);
		}

		/**
		 * Performs a single (non-continued) call to an OpenAI-compatible chat
		 * completions endpoint, including transient-failure retries. Split out
		 * from call_chat_api() so the retry loop and response parsing stay in
		 * one place while call_chat_api() layers auto-continuation on top.
		 *
		 * @param array       $messages       Array of role/content messages.
		 * @param string      $model          Provider model name.
		 * @param string      $api_key        Provider API key.
		 * @param string      $api_url        Provider endpoint URL.
		 * @param array|null  $tools          Optional function-calling tools.
		 * @param int|null    $max_tokens     Maximum output token count.
		 * @param array       $sampling       Optional temperature/top_p/presence_penalty/frequency_penalty.
		 * @param int         $retry_attempts Max attempts for transient failures (429/5xx/network).
		 * @return array|WP_Error Array with text/usage, or a WP_Error.
		 */
		private static function single_chat_request( $messages, $model, $api_key, $api_url, $tools = null, $max_tokens = null, $sampling = array(), $retry_attempts = 3 ) {
			$messages = self::fit_messages_to_context( $messages, $model, $max_tokens );

			$body = self::build_request_body( $messages, $model, $max_tokens, false, $tools, $sampling );

			$attempt = 0;
			$delay   = 1;
			$response = null;

			do {
				$attempt++;

				$response = wp_remote_post(
					$api_url,
					array(
						'timeout' => MLP_AI_CHAT_PROVIDER_TIMEOUT,
						'headers' => array(
							'Content-Type'  => 'application/json',
							'Authorization' => 'Bearer ' . $api_key,
						),
						'body'    => wp_json_encode( $body ),
					)
				);

				if ( is_wp_error( $response ) ) {
					if ( $attempt >= $retry_attempts ) {
						return new WP_Error(
							'api_error',
							'Could not reach the API: ' . $response->get_error_message(),
							array( 'status' => 500 )
						);
					}
					sleep( $delay );
					$delay *= 2;
					continue;
				}

				$retry_code = wp_remote_retrieve_response_code( $response );

				// Only retry on rate limiting and transient server errors;
				// anything else (4xx auth/validation issues) fails fast.
				if ( ( 429 === $retry_code || $retry_code >= 500 ) && $attempt < $retry_attempts ) {
					$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
					sleep( $retry_after ? max( 1, (int) $retry_after ) : $delay );
					$delay *= 2;
					continue;
				}

				break;
			} while ( true );

			$code          = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );
			$data          = json_decode( $response_body, true );

			if ( $code < 200 || $code >= 300 ) {
				$err_msg = isset( $data['error']['message'] ) ? $data['error']['message'] : '';

				if ( ! $err_msg && isset( $data['message'] ) && is_string( $data['message'] ) ) {
					$err_msg = $data['message'];
				}

				if ( ! $err_msg ) {
					$err_msg = 'Unknown API error (HTTP ' . $code . ').';
				}

				// Preserve the provider status so the caller can perform
				// failover and distinguish rate limits from other failures.
				$status = $code > 0 ? $code : 502;

				return new WP_Error( 'api_error', $err_msg, array( 'status' => $status ) );
			}

			$msg = (
				isset( $data['choices'][0]['message'] ) && is_array( $data['choices'][0]['message'] )
			) ? $data['choices'][0]['message'] : array();

			// Tool calls commonly return empty content and put the request
			// in tool_calls instead.
			if ( ! empty( $msg['tool_calls'] ) && is_array( $msg['tool_calls'] ) ) {
				return array(
					'text'          => ( isset( $msg['content'] ) && is_string( $msg['content'] ) ) ? $msg['content'] : '',
					'tool_calls'    => $msg['tool_calls'],
					'usage'         => isset( $data['usage'] ) ? $data['usage'] : array(),
					'finish_reason' => isset( $data['choices'][0]['finish_reason'] ) ? (string) $data['choices'][0]['finish_reason'] : '',
				);
			}

			$content = self::extract_chat_content( $data );

			if ( null !== $content ) {
				return array(
					'text'          => $content,
					'usage'         => isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : array(),
					'finish_reason' => isset( $data['choices'][0]['finish_reason'] ) ? (string) $data['choices'][0]['finish_reason'] : '',
				);
			}

			// Keep a short provider response snippet for the admin status
			// message instead of hiding the actual response shape.
			$snippet = wp_strip_all_tags( substr( (string) $response_body, 0, 300 ) );

			return new WP_Error(
				'api_error',
				'Unexpected response format from the API. Response: ' . $snippet,
				array( 'status' => 502 )
			);
		}

		/**
		 * Streams an OpenAI-compatible chat completion, automatically
		 * continuing — invisibly, through the same $emit_event callback — if
		 * the provider cuts the stream off for hitting max_tokens
		 * (finish_reason === 'length'). This is what lets a large generation
		 * (a long file, a big refactor) keep streaming past a single
		 * completion's output cap without the user seeing a seam, matching
		 * how Claude and Replit handle long streamed output.
		 *
		 * @param array         $messages          Array of role/content messages.
		 * @param string        $model             Provider model name.
		 * @param string        $api_key           Provider API key.
		 * @param string        $api_url           Provider endpoint URL.
		 * @param int           $max_tokens        Maximum output token count per call.
		 * @param callable|null $emit_event        Receives normalized event arrays.
		 * @param callable|null $emit_keepalive    Called while the provider waits.
		 * @param array|null    $tools             Optional function-calling tools.
		 * @param array         $sampling          Optional temperature/top_p/presence_penalty/frequency_penalty.
		 * @param int           $max_continuations Max number of continuation streams for one truncated answer.
		 * @return array Combined stream state, in the same shape single_stream_attempt() returns.
		 */
		public static function stream_chat_api( $messages, $model, $api_key, $api_url, $max_tokens, $emit_event = null, $emit_keepalive = null, $tools = null, $sampling = array(), $max_continuations = 6 ) {
			$working_messages   = $messages;
			$combined_text      = '';
			$combined_thinking  = '';
			$combined_usage     = 0;
			$continuations_used = 0;
			$result             = null;

			do {
				$result = self::single_stream_attempt( $working_messages, $model, $api_key, $api_url, $max_tokens, $emit_event, $emit_keepalive, $tools, $sampling );

				$combined_text     .= $result['text'];
				$combined_thinking .= $result['thinking'];
				$combined_usage    += (int) $result['usage_tokens'];

				// A curl-level failure, a non-2xx response, or a stream that
				// returned tool calls all end the loop as-is — auto-
				// continuation only applies to a clean text stream that got
				// cut off for length, not to errors or tool-call turns (those
				// belong to run_agentic_turn()'s call/execute/feed-back cycle).
				$was_truncated = '' === $result['curl_error']
					&& $result['http_code'] >= 200 && $result['http_code'] < 300
					&& empty( $result['tool_calls'] )
					&& '' !== trim( (string) $result['text'] )
					&& ( 'length' === $result['finish_reason'] || self::looks_truncated_mid_code( $result['text'] ) );

				if ( ! $was_truncated || $continuations_used >= $max_continuations ) {
					break;
				}

				$continuations_used++;

				$working_messages[] = array(
					'role'    => 'assistant',
					'content' => $result['text'],
				);
				$working_messages[] = array(
					'role'    => 'user',
					'content' => 'Continue exactly where you left off. Do not repeat any earlier text and do not add commentary about continuing — just resume the output.',
				);
			} while ( true );

			return array(
				'text'          => $combined_text,
				'thinking'      => $combined_thinking,
				'usage_tokens'  => $combined_usage,
				'finish_reason' => $result['finish_reason'],
				'curl_error'    => $result['curl_error'],
				'raw_body'      => $result['raw_body'],
				'http_code'     => $result['http_code'],
				'tool_calls'    => $result['tool_calls'],
			);
		}

	/**
	 * Streams a complete agentic turn. Each model response is streamed to the
	 * caller immediately; when a response asks for tools, the dispatcher runs
	 * and the next model round starts without falling back to a buffered
	 * non-streaming request.
	 *
	 * @param array    $messages
	 * @param string   $model
	 * @param string   $api_key
	 * @param string   $api_url
	 * @param array    $tools
	 * @param callable $tool_dispatcher function( string $name, array $args ): string|array.
	 * @param callable|null $emit_event
	 * @param callable|null $emit_keepalive
	 * @param array    $options Optional max_tokens, sampling, max_iterations.
	 * @return array Stream state, or WP_Error when the dispatcher is invalid.
	 */
	public static function stream_agentic_turn( $messages, $model, $api_key, $api_url, $tools, $tool_dispatcher, $emit_event = null, $emit_keepalive = null, $options = array() ) {
		if ( ! is_callable( $tool_dispatcher ) ) {
			return new WP_Error( 'invalid_dispatcher', 'A callable tool dispatcher is required.', array( 'status' => 500 ) );
		}

		$max_tokens     = isset( $options['max_tokens'] ) ? $options['max_tokens'] : null;
		$sampling       = isset( $options['sampling'] ) ? $options['sampling'] : array();
		$max_iterations = isset( $options['max_iterations'] ) ? max( 1, (int) $options['max_iterations'] ) : 5;
		$working_messages = $messages;
		$all_text         = '';
		$all_thinking     = '';
		$total_usage      = 0;
		$last_result      = null;

		for ( $iteration = 0; $iteration < $max_iterations; $iteration++ ) {
			$result = self::stream_chat_api(
				$working_messages,
				$model,
				$api_key,
				$api_url,
				$max_tokens,
				$emit_event,
				$emit_keepalive,
				$tools,
				$sampling
			);
			$last_result = $result;
			$all_text .= (string) $result['text'];
			$all_thinking .= (string) $result['thinking'];
			$total_usage += (int) $result['usage_tokens'];

			// Do not start another round after an upstream or client failure.
			if ( '' !== (string) $result['curl_error'] || $result['http_code'] < 200 || $result['http_code'] >= 300 ) {
				break;
			}

			if ( empty( $result['tool_calls'] ) ) {
				break;
			}

			$working_messages[] = array(
				'role'       => 'assistant',
				'content'    => (string) $result['text'],
				'tool_calls' => $result['tool_calls'],
			);

			foreach ( $result['tool_calls'] as $tool_call ) {
				$name     = isset( $tool_call['function']['name'] ) ? (string) $tool_call['function']['name'] : '';
				$raw_args = isset( $tool_call['function']['arguments'] ) ? $tool_call['function']['arguments'] : '{}';
				$args     = json_decode( is_string( $raw_args ) ? $raw_args : wp_json_encode( $raw_args ), true );
				if ( ! is_array( $args ) ) {
					$args = array();
				}

				if ( is_callable( $tool_dispatcher ) ) {
					if ( is_callable( $options['on_tool_call'] ?? null ) ) {
						call_user_func( $options['on_tool_call'], $name, $args );
					}
					$tool_output = call_user_func( $tool_dispatcher, $name, $args );
				} else {
					$tool_output = array( 'error' => 'Tool dispatcher unavailable.' );
				}

				$working_messages[] = array(
					'role'         => 'tool',
					'tool_call_id' => isset( $tool_call['id'] ) ? $tool_call['id'] : '',
					'content'      => is_string( $tool_output ) ? $tool_output : wp_json_encode( $tool_output ),
				);
			}
		}

		if ( ! is_array( $last_result ) ) {
			return new WP_Error( 'stream_failed', 'The AI stream did not return a response.', array( 'status' => 502 ) );
		}

		$last_result['text']         = $all_text;
		$last_result['thinking']     = $all_thinking;
		$last_result['usage_tokens'] = $total_usage;
			// A provider can close a reasoning/tool stream cleanly without
			// ever producing a user-facing answer. Do not report that as a
			// successful turn: the REST layer can fail over or show a useful
			// retry error instead of sending `done` with an empty reply.
			if ( empty( $last_result['tool_calls'] ) && '' === trim( (string) $last_result['text'] ) && '' === (string) $last_result['curl_error'] ) {
				$last_result['curl_error'] = 'The provider ended the stream without a final answer.';
			}
		return $last_result;
	}

		/**
		 * Performs a single (non-continued) streaming call to an
		 * OpenAI-compatible chat completions endpoint, normalizing SSE chunks
		 * into application events. Split out from stream_chat_api() so the
		 * curl/SSE machinery stays in one place while stream_chat_api() layers
		 * auto-continuation on top when a response gets cut off mid-stream.
		 *
		 * The adapter deliberately does not echo anything. The caller owns
		 * the response protocol (WordPress SSE in this plugin) and receives
		 * normalized payloads through $emit_event.
		 *
		 * @param array         $messages       Array of role/content messages.
		 * @param string        $model          Provider model name.
		 * @param string        $api_key        Provider API key.
		 * @param string        $api_url        Provider endpoint URL.
		 * @param int            $max_tokens     Maximum output token count.
		 * @param callable|null  $emit_event     Receives normalized event arrays.
		 * @param callable|null  $emit_keepalive Called while the provider waits.
		 * @param array|null     $tools          Optional function-calling tools.
		 * @param array          $sampling       Optional temperature/top_p/presence_penalty/frequency_penalty.
		 * @return array Stream state used by stream_chat_api().
		 */
		private static function single_stream_attempt( $messages, $model, $api_key, $api_url, $max_tokens, $emit_event = null, $emit_keepalive = null, $tools = null, $sampling = array() ) {
			$messages = self::fit_messages_to_context( $messages, $model, $max_tokens );

			$full_text       = '';
			$thinking_text   = '';
			$activity_buffer  = '';
			$narration_buffer = '';
			$finish_reason   = '';
			$sse_buffer      = '';
			$raw_body        = '';
			$usage_tokens    = 0;
			$tool_calls_acc  = array();
			$last_heartbeat  = microtime( true );
			// Precise idle watchdog: updated on every byte actually received
			// from the provider. Large/complex generations can legitimately
			// spend a long time on a hidden "thinking" phase before any
			// visible token or thinking delta arrives, so this is intentionally
			// more generous (and more precise — it only measures real silence,
			// not a rolling average) than curl's built-in LOW_SPEED detector,
			// which remains active underneath as a backstop.
			$last_data_time  = microtime( true );

			// Emit coalescing: providers commonly stream deltas a handful of
			// characters at a time, which is great for the provider<->server
			// leg but is the wrong granularity to hand to the browser. Firing
			// one SSE frame (and one frontend re-render/re-highlight pass) per
			// tiny delta is what makes long code responses feel laggy, even
			// though total generation time hasn't changed. Claude and Replit
			// avoid this by batching rapid deltas into fewer, slightly larger
			// UI updates. Buffering by time+size below bounds added latency to
			// EMIT_FLUSH_INTERVAL_SECONDS while cutting emit volume by roughly
			// an order of magnitude on fast/verbose (e.g. code-heavy) streams.
			$out_content_buffer  = '';
			$out_thinking_buffer = '';
			$last_emit_flush     = microtime( true );

			$emit = is_callable( $emit_event ) ? $emit_event : static function() {};
			$emit_narration_activity = static function ( $line ) use ( $emit ) {
				$line = trim( (string) $line );
				if ( '' === $line || ! preg_match( '/^(THINK|READ|EDIT|CHECK):\s*(.+)$/i', $line, $match ) ) {
					return;
				}

				$type_map = array(
					'THINK' => 'thinking',
					'READ'  => 'reading',
					'EDIT'  => 'editing',
					'CHECK' => 'checking',
				);
				$prefix = strtoupper( $match[1] );
				$emit(
					array(
						'activity' => array(
							'type'  => $type_map[ $prefix ],
							'label' => trim( $match[2] ),
						),
					)
				);
			};

			$provider_body = self::build_request_body( $messages, $model, $max_tokens, true, $tools, $sampling );

			$ch = curl_init();

			if ( false === $ch ) {
				return array(
					'text'          => '',
					'thinking'      => '',
					'usage_tokens'  => 0,
					'finish_reason' => '',
					'curl_error'    => 'Could not initialize the streaming client.',
					'raw_body'      => '',
					'http_code'     => 0,
					'tool_calls'    => array(),
				);
			}

			curl_setopt_array(
				$ch,
				array(
					CURLOPT_URL        => $api_url,
					CURLOPT_POST       => true,
					CURLOPT_HTTPHEADER => array(
						'Content-Type: application/json',
						'Authorization: Bearer ' . $api_key,
						'Accept: text/event-stream',
					),
					CURLOPT_POSTFIELDS       => wp_json_encode( $provider_body ),
					CURLOPT_ENCODING         => '',
					CURLOPT_HTTP_VERSION     => defined( 'CURL_HTTP_VERSION_2TLS' ) ? CURL_HTTP_VERSION_2TLS : CURL_HTTP_VERSION_1_1,
					CURLOPT_TCP_NODELAY      => true,
					CURLOPT_WRITEFUNCTION    => static function ( $curl, $data ) use ( &$full_text, &$thinking_text, &$activity_buffer, &$narration_buffer, &$sse_buffer, &$raw_body, &$usage_tokens, &$finish_reason, &$tool_calls_acc, &$out_content_buffer, &$out_thinking_buffer, &$last_emit_flush, &$last_data_time, $emit, $emit_narration_activity ) {
						// Any bytes at all (including provider keep-alive
						// comments/pings) count as the connection being alive,
						// regardless of whether they parse into a usable delta.
						$last_data_time = microtime( true );

						if ( connection_aborted() ) {
							return 0;
						}

						// Coalesces buffered content/thinking text into at most
						// one emit() call each, no more often than every
						// EMIT_FLUSH_INTERVAL_SECONDS (or sooner once
						// EMIT_FLUSH_MIN_BYTES has piled up). Pass $force = true
						// to flush immediately regardless of the window, used
						// once the stream ends so no trailing text is dropped.
						$flush_stream_buffers = static function ( $force = false ) use ( &$out_content_buffer, &$out_thinking_buffer, &$last_emit_flush, $emit ) {
							$EMIT_FLUSH_INTERVAL_SECONDS = 0.04;
							$EMIT_FLUSH_MIN_BYTES        = 24;

							$due = $force
								|| ( microtime( true ) - $last_emit_flush ) >= $EMIT_FLUSH_INTERVAL_SECONDS
								|| strlen( $out_content_buffer ) >= $EMIT_FLUSH_MIN_BYTES
								|| strlen( $out_thinking_buffer ) >= $EMIT_FLUSH_MIN_BYTES;

							if ( ! $due ) {
								return;
							}

							if ( '' !== $out_thinking_buffer ) {
								$emit( array( 'thinking' => $out_thinking_buffer ) );
								$out_thinking_buffer = '';
							}

							if ( '' !== $out_content_buffer ) {
								$emit( array( 'token' => $out_content_buffer ) );
								$out_content_buffer = '';
							}

							$last_emit_flush = microtime( true );
						};

						$raw_body   .= $data;
						$sse_buffer .= $data;
						$lines       = explode( "\n", $sse_buffer );
						$sse_buffer  = array_pop( $lines );

						foreach ( $lines as $line ) {
							$line = trim( $line );

							if ( strpos( $line, 'data:' ) !== 0 ) {
								continue;
							}

							$json = ltrim( substr( $line, 5 ) );

							if ( '[DONE]' === $json ) {
								continue;
							}

							$chunk = json_decode( $json, true );

							if ( ! is_array( $chunk ) ) {
								continue;
							}

							if ( isset( $chunk['usage']['total_tokens'] ) ) {
								$usage_tokens = (int) $chunk['usage']['total_tokens'];
							}

							$delta = isset( $chunk['choices'][0]['delta'] ) && is_array( $chunk['choices'][0]['delta'] )
								? $chunk['choices'][0]['delta']
								: array();

							if ( isset( $chunk['choices'][0]['finish_reason'] ) && $chunk['choices'][0]['finish_reason'] ) {
								$finish_reason = (string) $chunk['choices'][0]['finish_reason'];
							}

							$thinking_token = isset( $delta['reasoning_content'] ) ? (string) $delta['reasoning_content'] : '';

							if ( '' !== $thinking_token ) {
								$thinking_text       .= $thinking_token;
								$activity_buffer     .= $thinking_token;
								$out_thinking_buffer .= $thinking_token;

								$activity_lines  = preg_split( '/\r?\n/', $activity_buffer );
								$activity_buffer = array_pop( $activity_lines );

								foreach ( $activity_lines as $activity_line ) {
									$activity_line = trim( $activity_line );

									if ( '' === $activity_line ) {
										continue;
									}

									$activity_type = 'thinking';

									if ( preg_match( '/^READ:\s*/i', $activity_line ) ) {
										$activity_type = 'reading';
									} elseif ( preg_match( '/^EDIT:\s*/i', $activity_line ) ) {
										$activity_type = 'editing';
									} elseif ( preg_match( '/^CHECK:\s*/i', $activity_line ) ) {
										$activity_type = 'checking';
									}

									$activity_label = preg_replace( '/^(THINK|READ|EDIT|CHECK):\s*/i', '', $activity_line );
									$emit(
										array(
											'activity' => array(
												'type'  => $activity_type,
												'label' => $activity_label,
											),
										)
									);
								}
							}

							// Streamed tool calls arrive fragmented across chunks,
							// keyed by index; accumulate name/arguments until the
							// stream ends so the caller receives complete calls.
							$delta_tool_calls = isset( $delta['tool_calls'] ) && is_array( $delta['tool_calls'] )
								? $delta['tool_calls']
								: array();

							if ( ! empty( $delta_tool_calls ) ) {
								foreach ( $delta_tool_calls as $tc_delta ) {
									$index = isset( $tc_delta['index'] ) ? (int) $tc_delta['index'] : 0;

									if ( ! isset( $tool_calls_acc[ $index ] ) ) {
										$tool_calls_acc[ $index ] = array(
											'id'       => '',
											'type'     => 'function',
											'function' => array(
												'name'      => '',
												'arguments' => '',
											),
										);
									}

									if ( isset( $tc_delta['id'] ) ) {
										$tool_calls_acc[ $index ]['id'] = $tc_delta['id'];
									}

									if ( isset( $tc_delta['function']['name'] ) ) {
										$tool_calls_acc[ $index ]['function']['name'] .= (string) $tc_delta['function']['name'];
									}

									if ( isset( $tc_delta['function']['arguments'] ) ) {
										$tool_calls_acc[ $index ]['function']['arguments'] .= (string) $tc_delta['function']['arguments'];
									}
								}

								$emit( array( 'tool_call_delta' => $delta_tool_calls ) );
							}

							$token = isset( $delta['content'] ) ? (string) $delta['content'] : '';

							if ( '' !== $token ) {
								$full_text     .= $token;
								// Some compatible providers expose no private
								// reasoning stream. The model's explicit,
								// user-visible THINK/READ/EDIT/CHECK lines are
								// still real progress; surface them as soon as
								// each complete line arrives.
								$narration_buffer .= $token;
								$narration_lines = preg_split( '/\r?\n/', $narration_buffer );
								$narration_buffer = array_pop( $narration_lines );
								foreach ( $narration_lines as $narration_line ) {
									$emit_narration_activity( $narration_line );
								}

								$out_content_buffer .= $token;
							}

							$flush_stream_buffers();
						}

						// A single WRITEFUNCTION invocation can contain many SSE
						// lines (one network read may bundle several provider
						// deltas); flushing once more here ensures a burst that
						// arrives inside the time/size window still ends up
						// visible before curl waits on the next read.
						$flush_stream_buffers();

						return strlen( $data );
					},
					CURLOPT_NOPROGRESS      => false,
					CURLOPT_XFERINFOFUNCTION => static function () use ( &$last_heartbeat, &$last_data_time, $emit_keepalive ) {
						if ( microtime( true ) - $last_heartbeat >= 15 ) {
							if ( is_callable( $emit_keepalive ) ) {
								call_user_func( $emit_keepalive );
							}
							$last_heartbeat = microtime( true );
						}

						if ( connection_aborted() ) {
							return 1;
						}

						// Genuine stall: no byte at all — not even a provider
						// keep-alive comment — for a long stretch. This is the
						// precise idle check that replaces relying solely on
						// curl's rolling LOW_SPEED average below, which can
						// behave inconsistently once a connection has already
						// moved a lot of data before going quiet (exactly the
						// shape of a large code answer that pauses, then drops).
						// 180s is generous enough for a legitimate silent
						// "thinking" phase while still failing well before a
						// typical browser/user gives up waiting.
						if ( microtime( true ) - $last_data_time >= 180 ) {
							return 1;
						}

						return 0;
					},
					CURLOPT_TIMEOUT         => 0,
					CURLOPT_CONNECTTIMEOUT  => 30,
					// Backstop only — the CURLOPT_XFERINFOFUNCTION idle check
					// above is the primary, more precise stall detector now.
					CURLOPT_LOW_SPEED_LIMIT => 1,
					CURLOPT_LOW_SPEED_TIME  => 240,
					CURLOPT_SSL_VERIFYPEER  => true,
				)
			);

			// Tell the caller that the provider request has entered its
			// blocking phase before curl waits for the first upstream byte.
			// This is a truthful connection state, not model output or a
			// fabricated tool/action event.
			if ( is_callable( $emit_keepalive ) ) {
				call_user_func( $emit_keepalive );
			}

			$curl_result = curl_exec( $ch );
			$curl_error  = curl_error( $ch );

			// The provider connection is closed at this point, so nothing will
			// invoke $flush_stream_buffers() again — force out whatever is
			// still sitting in the coalescing buffers now.
			if ( '' !== $out_thinking_buffer ) {
				$emit( array( 'thinking' => $out_thinking_buffer ) );
				$out_thinking_buffer = '';
			}

			if ( '' !== $out_content_buffer ) {
				$emit( array( 'token' => $out_content_buffer ) );
				$out_content_buffer = '';
			}

			// A provider may end a narration line without a trailing newline.
			// Emit it now, but do not invent a progress message when it is
			// ordinary answer text.
			if ( '' !== trim( $narration_buffer ) ) {
				$emit_narration_activity( $narration_buffer );
			}

			if ( false === $curl_result && '' === $curl_error ) {
				$curl_error = 'The provider stream ended unexpectedly.';
			}

			$http_code  = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_close( $ch );

			return array(
				'text'          => $full_text,
				'thinking'      => $thinking_text,
				'usage_tokens'  => $usage_tokens,
				'finish_reason' => $finish_reason,
				'curl_error'    => $curl_error,
				'raw_body'      => $raw_body,
				'http_code'     => $http_code,
				'tool_calls'    => empty( $tool_calls_acc ) ? array() : array_values( $tool_calls_acc ),
			);
		}

		/**
		 * Central per-model quirks/config. Keeping this as a map (instead of
		 * growing a chain of `if ( 'model-x' === $model )` branches) is what
		 * lets new models get onboarded without touching request-building
		 * logic elsewhere.
		 *
		 * - supports_temperature: some reasoning-style models reject sampling
		 *   params entirely and will error if temperature/top_p are sent.
		 * - max_tokens_param: reasoning-style models commonly use
		 *   `max_completion_tokens` instead of `max_tokens`.
		 * - max_output_tokens: a hard provider-side output cap for the model.
		 * - reasoning_effort: fixed reasoning effort to request, if any.
		 * - context_limit: total context window, used for trimming.
		 *
		 * @param string $model Provider model name.
		 * @return array Resolved config for the model.
		 */
		private static function get_model_config( $model ) {
			$configs = array(
				'mercury-2' => array(
					'reasoning_effort'  => 'instant',
					'max_output_tokens' => defined( 'MLP_AI_CHAT_MERCURY_MAX_OUTPUT_TOKENS' ) ? MLP_AI_CHAT_MERCURY_MAX_OUTPUT_TOKENS : null,
					'context_limit'     => 32000,
				),
				'o1' => array(
					'supports_temperature' => false,
					'max_tokens_param'     => 'max_completion_tokens',
					'context_limit'        => 200000,
				),
				'o3' => array(
					'supports_temperature' => false,
					'max_tokens_param'     => 'max_completion_tokens',
					'context_limit'        => 200000,
				),
				'o3-mini' => array(
					'supports_temperature' => false,
					'max_tokens_param'     => 'max_completion_tokens',
					'context_limit'        => 200000,
				),
			);

			$defaults = array(
				'supports_temperature' => true,
				'max_tokens_param'     => 'max_tokens',
				'max_output_tokens'    => null,
				'reasoning_effort'     => null,
				'context_limit'        => 128000,
			);

			return isset( $configs[ $model ] ) ? array_merge( $defaults, $configs[ $model ] ) : $defaults;
		}

		/**
		 * Builds the shared provider request body used by normal and streaming
		 * calls. Keeping provider-specific options here prevents the two
		 * transports from drifting apart as models are added.
		 *
		 * @param array         $messages   Array of role/content messages.
		 * @param string        $model      Provider model name.
		 * @param int|null      $max_tokens Maximum output token count.
		 * @param bool           $stream     Whether the provider should stream.
		 * @param array|null     $tools      Optional function-calling tools.
		 * @param array          $sampling   Optional temperature/top_p/presence_penalty/frequency_penalty.
		 * @return array Provider request body.
		 */
		private static function build_request_body( $messages, $model, $max_tokens = null, $stream = false, $tools = null, $sampling = array() ) {
			$config     = self::get_model_config( $model );
			$max_tokens = $max_tokens ? (int) $max_tokens : MLP_AI_CHAT_MAX_OUTPUT_TOKENS;

			if ( ! empty( $config['max_output_tokens'] ) ) {
				$max_tokens = min( $max_tokens, (int) $config['max_output_tokens'] );
			}

			$body = array(
				'model'    => $model,
				'messages' => $messages,
				'stream'   => (bool) $stream,
			);

			$body[ $config['max_tokens_param'] ] = $max_tokens;

			if ( $config['supports_temperature'] && is_array( $sampling ) ) {
				if ( isset( $sampling['temperature'] ) ) {
					$body['temperature'] = max( 0, min( 2, (float) $sampling['temperature'] ) );
				}

				if ( isset( $sampling['top_p'] ) ) {
					$body['top_p'] = max( 0, min( 1, (float) $sampling['top_p'] ) );
				}

				if ( isset( $sampling['presence_penalty'] ) ) {
					$body['presence_penalty'] = max( -2, min( 2, (float) $sampling['presence_penalty'] ) );
				}

				if ( isset( $sampling['frequency_penalty'] ) ) {
					$body['frequency_penalty'] = max( -2, min( 2, (float) $sampling['frequency_penalty'] ) );
				}
			}

			if ( ! empty( $config['reasoning_effort'] ) ) {
				$body['reasoning_effort'] = $config['reasoning_effort'];
			}

			if ( ! empty( $tools ) ) {
				$body['tools']       = $tools;
				$body['tool_choice'] = 'auto';
			}

			return $body;
		}

		/**
		 * Rough token estimator (chars/4 heuristic). Good enough to make
		 * trimming decisions without pulling in a full tokenizer dependency.
		 *
		 * @param mixed $content String or array message content.
		 * @return int Estimated token count.
		 */
		private static function estimate_tokens( $content ) {
			if ( ! is_string( $content ) ) {
				$content = wp_json_encode( $content );
			}

			return (int) ceil( strlen( (string) $content ) / 4 );
		}

		/**
		 * Estimates total tokens for a list of role/content messages,
		 * including a small per-message overhead for role/formatting.
		 *
		 * @param array $messages Array of role/content messages.
		 * @return int Estimated token count.
		 */
		private static function estimate_messages_tokens( $messages ) {
			$total = 0;

			foreach ( $messages as $message ) {
				$content = isset( $message['content'] ) ? $message['content'] : '';
				$total  += self::estimate_tokens( $content );
				$total  += 4;
			}

			return $total;
		}

		/**
		 * Trims the oldest non-system messages when the conversation risks
		 * exceeding the model's context window, keeping any leading system
		 * message(s) and as many of the most recent turns as fit. This keeps
		 * long-running conversations from silently truncating provider-side
		 * or erroring out once the context limit is exceeded.
		 *
		 * @param array      $messages   Array of role/content messages.
		 * @param string     $model      Provider model name.
		 * @param int|null   $max_tokens Reserved output token budget.
		 * @return array Possibly-trimmed messages.
		 */
		private static function fit_messages_to_context( $messages, $model, $max_tokens = null ) {
			$config  = self::get_model_config( $model );
			$reserve = $max_tokens ? (int) $max_tokens : MLP_AI_CHAT_MAX_OUTPUT_TOKENS;
			$budget  = (int) $config['context_limit'] - $reserve;

			if ( $budget <= 0 || self::estimate_messages_tokens( $messages ) <= $budget ) {
				return $messages;
			}

			$system = array();
			$rest   = array();

			foreach ( $messages as $message ) {
				if ( empty( $rest ) && isset( $message['role'] ) && 'system' === $message['role'] ) {
					$system[] = $message;
				} else {
					$rest[] = $message;
				}
			}

			$used = self::estimate_messages_tokens( $system );
			$kept = array();

			// Walk backwards from the most recent message, keeping as many
			// as fit; always keep at least the latest message even if it
			// alone exceeds the remaining budget.
			for ( $i = count( $rest ) - 1; $i >= 0; $i-- ) {
				$tokens = self::estimate_messages_tokens( array( $rest[ $i ] ) );

				if ( $used + $tokens > $budget && ! empty( $kept ) ) {
					break;
				}

				$used += $tokens;
				array_unshift( $kept, $rest[ $i ] );
			}

			return array_merge( $system, $kept );
		}

		/**
		 * Runs a full agentic turn: calls the provider, and if it responds
		 * with tool_calls, executes them via the supplied dispatcher and
		 * feeds the results back for a follow-up turn. Loops until the model
		 * returns a plain text answer or the iteration cap is reached.
		 *
		 * This closes the loop that call_chat_api() alone leaves open: that
		 * method can *detect* tool_calls, but without something driving the
		 * call → execute → feed-back → call-again cycle, tool use never
		 * actually informs the final answer.
		 *
		 * @param array    $messages        Conversation so far (role/content).
		 * @param string   $model           Provider model name.
		 * @param string   $api_key         Provider API key.
		 * @param string   $api_url         Provider endpoint URL.
		 * @param array    $tools           Tool/function definitions offered to the model.
		 * @param callable $tool_dispatcher function( string $name, array $args ): string|array.
		 * @param array    $options         Optional: max_tokens, sampling, max_iterations.
		 * @return array|WP_Error { text, usage, messages } on success, or WP_Error.
		 */
		public static function run_agentic_turn( $messages, $model, $api_key, $api_url, $tools, $tool_dispatcher, $options = array() ) {
			if ( ! is_callable( $tool_dispatcher ) ) {
				return new WP_Error( 'invalid_dispatcher', 'A callable tool dispatcher is required.', array( 'status' => 500 ) );
			}

			$max_tokens     = isset( $options['max_tokens'] ) ? $options['max_tokens'] : null;
			$sampling       = isset( $options['sampling'] ) ? $options['sampling'] : array();
			$max_iterations = isset( $options['max_iterations'] ) ? max( 1, (int) $options['max_iterations'] ) : 5;

			$total_usage = array();
			$iteration   = 0;

			while ( $iteration < $max_iterations ) {
				$iteration++;

				$result = self::call_chat_api( $messages, $model, $api_key, $api_url, $tools, $max_tokens, $sampling );

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				foreach ( array( 'prompt_tokens', 'completion_tokens', 'total_tokens' ) as $usage_key ) {
					if ( isset( $result['usage'][ $usage_key ] ) ) {
						$total_usage[ $usage_key ] = ( isset( $total_usage[ $usage_key ] ) ? $total_usage[ $usage_key ] : 0 ) + (int) $result['usage'][ $usage_key ];
					}
				}

				if ( empty( $result['tool_calls'] ) ) {
					return array(
						'text'     => $result['text'],
						'usage'    => $total_usage,
						'messages' => $messages,
					);
				}

				$messages[] = array(
					'role'       => 'assistant',
					'content'    => $result['text'],
					'tool_calls' => $result['tool_calls'],
				);

				foreach ( $result['tool_calls'] as $tool_call ) {
					$name     = isset( $tool_call['function']['name'] ) ? $tool_call['function']['name'] : '';
					$raw_args = isset( $tool_call['function']['arguments'] ) ? $tool_call['function']['arguments'] : '{}';
					$args     = json_decode( $raw_args, true );

					if ( ! is_array( $args ) ) {
						$args = array();
					}

					$tool_output = call_user_func( $tool_dispatcher, $name, $args );

					$messages[] = array(
						'role'         => 'tool',
						'tool_call_id' => isset( $tool_call['id'] ) ? $tool_call['id'] : '',
						'content'      => is_string( $tool_output ) ? $tool_output : wp_json_encode( $tool_output ),
					);
				}
			}

			return new WP_Error(
				'tool_loop_exhausted',
				'The AI made too many consecutive tool calls without producing a final answer.',
				array( 'status' => 502 )
			);
		}

		/**
		 * Injects retrieved context (RAG) into the conversation as a system
		 * message ahead of the latest turn. This transport layer doesn't
		 * perform retrieval itself — callers pass in already-retrieved
		 * snippets (site content, docs, prior user history, etc.) and this
		 * keeps the formatting consistent and separate from the model's own
		 * system prompt, which is left untouched.
		 *
		 * @param array  $messages     Conversation so far.
		 * @param array  $context_docs List of strings or { title, content } arrays.
		 * @param string $label        Heading used to introduce the context block.
		 * @return array Messages with a context system message inserted.
		 */
		public static function inject_retrieved_context( $messages, $context_docs, $label = 'Relevant context' ) {
			if ( empty( $context_docs ) ) {
				return $messages;
			}

			$chunks = array();

			foreach ( $context_docs as $doc ) {
				if ( is_array( $doc ) ) {
					$title    = isset( $doc['title'] ) ? $doc['title'] : '';
					$content  = isset( $doc['content'] ) ? $doc['content'] : '';
					$chunks[] = trim( $title . "\n" . $content );
				} elseif ( is_string( $doc ) ) {
					$chunks[] = $doc;
				}
			}

			if ( empty( $chunks ) ) {
				return $messages;
			}

			$context_message = array(
				'role'    => 'system',
				'content' => $label . ":\n\n" . implode( "\n\n---\n\n", $chunks ),
			);

			// Insert after any leading system message(s) so it doesn't
			// override the model's core instructions, but still precedes
			// user turns.
			$insert_at = 0;

			foreach ( $messages as $i => $message ) {
				if ( isset( $message['role'] ) && 'system' === $message['role'] ) {
					$insert_at = $i + 1;
				} else {
					break;
				}
			}

			array_splice( $messages, $insert_at, 0, array( $context_message ) );

			return $messages;
		}

		/**
		 * Extracts assistant text from common OpenAI-compatible response shapes.
		 *
		 * @param mixed $data Decoded provider response.
		 * @return string|null Usable response text, or null.
		 */
		private static function extract_chat_content( $data ) {
			if ( ! is_array( $data ) || ! isset( $data['choices'][0] ) || ! is_array( $data['choices'][0] ) ) {
				return null;
			}

			$choice = $data['choices'][0];
			$raw    = null;

			if ( isset( $choice['message']['content'] ) ) {
				$raw = $choice['message']['content'];
			} elseif ( isset( $choice['text'] ) ) {
				$raw = $choice['text'];
			} elseif ( isset( $choice['delta']['content'] ) ) {
				$raw = $choice['delta']['content'];
			}

			if ( is_string( $raw ) && '' !== trim( $raw ) ) {
				return $raw;
			}

			// Some providers return content as an array of text parts.
			if ( is_array( $raw ) ) {
				$text = '';

				foreach ( $raw as $part ) {
					if ( is_array( $part ) && isset( $part['text'] ) && is_string( $part['text'] ) ) {
						$text .= $part['text'];
					} elseif ( is_string( $part ) ) {
						$text .= $part;
					}
				}

				if ( '' !== trim( $text ) ) {
					return $text;
				}
			}

			// Last resort for reasoning-heavy models that put the answer in
			// reasoning_content instead of content.
			if (
				isset( $choice['message']['reasoning_content'] )
				&& is_string( $choice['message']['reasoning_content'] )
				&& '' !== trim( $choice['message']['reasoning_content'] )
			) {
				return $choice['message']['reasoning_content'];
			}

			return null;
		}

		/**
		 * ═══════════════════════════════════════════════════════════════════════
		 * SYNTAX CHECKER - Multi-Language Code Validation
		 * ═══════════════════════════════════════════════════════════════════════
		 * 
		 * Validates code syntax for PHP, JavaScript, Python, HTML, CSS, JSON, 
		 * SQL, and Bash when users request full code snippets.
		 */

		/**
		 * Validate code syntax for multiple languages
		 *
		 * @param string $code     The code to validate
		 * @param string $language The language (php, javascript, python, etc.)
		 * @return array {
		 *     @type bool   $valid       Whether syntax is valid
		 *     @type array  $errors      Array of error messages
		 *     @type array  $warnings    Array of warning messages
		 *     @type string $report      Formatted report
		 *     @type int    $error_count Total errors found
		 * }
		 */
		public static function validate_code_syntax( $code, $language = 'php' ) {
			$language = strtolower( trim( $language ) );
			
			switch ( $language ) {
				case 'php':
					return self::validate_php( $code );
				case 'javascript':
				case 'js':
					return self::validate_javascript( $code );
				case 'python':
				case 'py':
					return self::validate_python( $code );
				case 'html':
					return self::validate_html( $code );
				case 'css':
					return self::validate_css( $code );
				case 'json':
					return self::validate_json( $code );
				case 'sql':
					return self::validate_sql( $code );
				case 'bash':
				case 'shell':
				case 'sh':
					return self::validate_bash( $code );
				default:
					return array(
						'valid'       => false,
						'errors'      => array( "Unsupported language: '$language'" ),
						'warnings'    => array(),
						'report'      => "Language '$language' is not yet supported.",
						'error_count' => 1,
					);
			}
		}

		/**
		 * PHP syntax validation
		 */
		private static function validate_php( $code ) {
			$errors   = array();
			$warnings = array();
			$valid    = true;

			// Check for common PHP issues
			$issues = array(
				array(
					'pattern' => '/^\s*\$[a-zA-Z_]\w*\s*=[^;]*$/m',
					'message' => 'Missing semicolon at end of statement',
					'type'    => 'error',
				),
				array(
					'pattern' => '/if\s*\([^)]*\)\s*(?!{|:)/i',
					'message' => 'Missing braces or colon after if statement',
					'type'    => 'warning',
				),
				array(
					'pattern' => '/function\s+\w+\s*\([^)]*\)\s*(?!{)/i',
					'message' => 'Missing braces after function declaration',
					'type'    => 'error',
				),
				array(
					'pattern' => '/foreach\s*\([^)]*\)\s*(?!{|:)/i',
					'message' => 'Missing braces or colon after foreach',
					'type'    => 'warning',
				),
				array(
					'pattern' => '/echo\s+[^;]*(?<!;)\s*$/m',
					'message' => 'Missing semicolon after echo statement',
					'type'    => 'error',
				),
			);

			foreach ( $issues as $check ) {
				if ( preg_match_all( $check['pattern'], $code, $matches, PREG_OFFSET_CAPTURE ) ) {
					foreach ( $matches[0] as $match ) {
						$line_num = substr_count( $code, "\n", 0, $match[1] ) + 1;
						$message  = sprintf( '%s (Line %d)', $check['message'], $line_num );
						
						if ( 'error' === $check['type'] ) {
							$errors[] = $message;
							$valid    = false;
						} else {
							$warnings[] = $message;
						}
					}
				}
			}

			// Check for unclosed braces, brackets, parentheses
			$balance_checks = self::check_balance( $code, 'php' );
			if ( ! empty( $balance_checks['errors'] ) ) {
				$errors = array_merge( $errors, $balance_checks['errors'] );
				$valid  = false;
			}

			// Check for security issues
			if ( preg_match( '/eval\s*\(/', $code ) ) {
				$errors[] = 'SECURITY: Use of eval() detected - major security risk!';
				$valid    = false;
			}
			if ( preg_match( '/mysql_/', $code ) ) {
				$errors[] = 'Deprecated mysql_* functions detected - use MySQLi or PDO instead';
				$valid    = false;
			}
			if ( preg_match( '/\$\$/', $code ) ) {
				$warnings[] = 'Variable variables ($$) detected - may reduce code readability';
			}

			return array(
				'valid'       => $valid,
				'errors'      => $errors,
				'warnings'    => $warnings,
				'report'      => self::generate_report( $errors, $warnings ),
				'error_count' => count( $errors ),
			);
		}

		/**
		 * JavaScript syntax validation
		 */
		private static function validate_javascript( $code ) {
			$errors   = array();
			$warnings = array();
			$valid    = true;

			$issues = array(
				array(
					'pattern' => '/var\s+\w+/i',
					'message' => 'Use of "var" detected - prefer "const" or "let" (ES6+)',
					'type'    => 'warning',
				),
				array(
					'pattern' => '/function\s+\w+\s*\([^)]*\)\s*(?!{)/i',
					'message' => 'Missing braces after function declaration',
					'type'    => 'error',
				),
			);

			foreach ( $issues as $check ) {
				if ( preg_match_all( $check['pattern'], $code, $matches, PREG_OFFSET_CAPTURE ) ) {
					foreach ( $matches[0] as $match ) {
						$line_num = substr_count( $code, "\n", 0, $match[1] ) + 1;
						$message  = sprintf( '%s (Line %d)', $check['message'], $line_num );
						
						if ( 'error' === $check['type'] ) {
							$errors[] = $message;
							$valid    = false;
						} else {
							$warnings[] = $message;
						}
					}
				}
			}

			// Check bracket balance
			$balance_checks = self::check_balance( $code, 'javascript' );
			if ( ! empty( $balance_checks['errors'] ) ) {
				$errors = array_merge( $errors, $balance_checks['errors'] );
				$valid  = false;
			}

			// Check for common JS mistakes
			if ( preg_match( '/==(?!=)/', $code ) ) {
				$warnings[] = 'Use of loose equality (==) - consider using strict equality (===)';
			}
			if ( preg_match( '/document\.write/', $code ) ) {
				$warnings[] = 'document.write() detected - can be problematic in modern code';
			}

			return array(
				'valid'       => $valid,
				'errors'      => $errors,
				'warnings'    => $warnings,
				'report'      => self::generate_report( $errors, $warnings ),
				'error_count' => count( $errors ),
			);
		}

		/**
		 * Python syntax validation
		 */
		private static function validate_python( $code ) {
			$errors   = array();
			$warnings = array();
			$valid    = true;

			$lines = explode( "\n", $code );
			foreach ( $lines as $line_num => $line ) {
				if ( empty( trim( $line ) ) || 0 === strpos( trim( $line ), '#' ) ) {
					continue;
				}

				$indent = strlen( $line ) - strlen( ltrim( $line ) );
				if ( 0 !== $indent % 4 && 0 !== $indent % 2 ) {
					$errors[] = sprintf( 'Inconsistent indentation (Line %d)', $line_num + 1 );
					$valid    = false;
				}
			}

			// Check for Python 2 print statement
			if ( preg_match( '/print\s+[^(]/', $code ) ) {
				$errors[] = 'Python 2 print statement detected - use print() function';
				$valid    = false;
			}

			$balance_checks = self::check_balance( $code, 'python' );
			if ( ! empty( $balance_checks['errors'] ) ) {
				$errors = array_merge( $errors, $balance_checks['errors'] );
				$valid  = false;
			}

			return array(
				'valid'       => $valid,
				'errors'      => $errors,
				'warnings'    => $warnings,
				'report'      => self::generate_report( $errors, $warnings ),
				'error_count' => count( $errors ),
			);
		}

		/**
		 * HTML syntax validation
		 */
		private static function validate_html( $code ) {
			$errors   = array();
			$warnings = array();
			$valid    = true;

			$self_closing = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' );
			$opened_tags  = array();

			preg_match_all( '/<(\w+)[^>]*(?<!\/)\s*>/i', $code, $opening_tags, PREG_OFFSET_CAPTURE );
			
			if ( ! empty( $opening_tags[1] ) ) {
				foreach ( $opening_tags[1] as $match ) {
					$tag = strtolower( $match[0] );
					if ( ! in_array( $tag, $self_closing, true ) ) {
						$opened_tags[ $tag ] = ( $opened_tags[ $tag ] ?? 0 ) + 1;
					}
				}
			}

			preg_match_all( '/<\/(\w+)>/i', $code, $closing_tags );
			
			if ( ! empty( $closing_tags[1] ) ) {
				foreach ( $closing_tags[1] as $tag ) {
					$tag = strtolower( $tag );
					if ( isset( $opened_tags[ $tag ] ) && $opened_tags[ $tag ] > 0 ) {
						$opened_tags[ $tag ]--;
					} elseif ( ! in_array( $tag, $self_closing, true ) ) {
						$errors[] = "Closing tag for '$tag' found without opening tag";
						$valid    = false;
					}
				}
			}

			foreach ( $opened_tags as $tag => $count ) {
				if ( $count > 0 ) {
					$errors[] = "Unclosed '$tag' tag (count: $count)";
					$valid    = false;
				}
			}

			if ( ! preg_match( '/<!DOCTYPE/i', $code ) ) {
				$warnings[] = 'Missing DOCTYPE declaration';
			}

			return array(
				'valid'       => $valid,
				'errors'      => $errors,
				'warnings'    => $warnings,
				'report'      => self::generate_report( $errors, $warnings ),
				'error_count' => count( $errors ),
			);
		}

		/**
		 * CSS syntax validation
		 */
		private static function validate_css( $code ) {
			$errors   = array();
			$warnings = array();
			$valid    = true;

			$balance_checks = self::check_balance( $code, 'css' );
			if ( ! empty( $balance_checks['errors'] ) ) {
				$errors = array_merge( $errors, $balance_checks['errors'] );
				$valid  = false;
			}

			preg_match_all( '/\{[^}]*\}/', $code, $blocks, PREG_OFFSET_CAPTURE );
			foreach ( $blocks[0] as $block ) {
				$content = $block[0];
				if ( preg_match( '/:\s*[^;}\n]+\s*}$/', $content ) ) {
					$warnings[] = 'Missing semicolon after property value';
				}
			}

			return array(
				'valid'       => $valid,
				'errors'      => $errors,
				'warnings'    => $warnings,
				'report'      => self::generate_report( $errors, $warnings ),
				'error_count' => count( $errors ),
			);
		}

		/**
		 * JSON syntax validation
		 */
		private static function validate_json( $code ) {
			$errors   = array();
			$warnings = array();
			$valid    = true;

			json_decode( $code );
			$json_error = json_last_error();

			if ( JSON_ERROR_NONE !== $json_error ) {
				$valid    = false;
				$errors[] = 'JSON Error: ' . json_last_error_msg();

				if ( preg_match( '/[\'"]:\s*undefined/', $code ) ) {
					$errors[] = 'Suggestion: undefined is not valid in JSON (use null instead)';
				}
				if ( preg_match( '/,\s*[}\]]/', $code ) ) {
					$errors[] = 'Suggestion: Trailing comma before closing bracket';
				}
			}

			return array(
				'valid'       => $valid,
				'errors'      => $errors,
				'warnings'    => $warnings,
				'report'      => self::generate_report( $errors, $warnings ),
				'error_count' => count( $errors ),
			);
		}

		/**
		 * SQL syntax validation
		 */
		private static function validate_sql( $code ) {
			$errors   = array();
			$warnings = array();
			$valid    = true;

			$sql_keywords = array( 'SELECT', 'FROM', 'WHERE', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER' );
			$has_keyword  = false;

			foreach ( $sql_keywords as $keyword ) {
				if ( preg_match( '/\b' . preg_quote( $keyword, '/' ) . '\b/i', $code ) ) {
					$has_keyword = true;
					break;
				}
			}

			if ( ! $has_keyword ) {
				$warnings[] = 'No recognized SQL keywords found';
			}

			if ( preg_match( '/SELECT\s+\*/i', $code ) && ! preg_match( '/FROM/i', $code ) ) {
				$errors[] = 'SELECT * without FROM clause';
				$valid    = false;
			}

			if ( preg_match( '/WHERE\s*1\s*=\s*1/i', $code ) ) {
				$warnings[] = 'WHERE 1=1 is redundant';
			}

			$balance_checks = self::check_balance( $code, 'sql' );
			if ( ! empty( $balance_checks['errors'] ) ) {
				$errors = array_merge( $errors, $balance_checks['errors'] );
				$valid  = false;
			}

			return array(
				'valid'       => $valid,
				'errors'      => $errors,
				'warnings'    => $warnings,
				'report'      => self::generate_report( $errors, $warnings ),
				'error_count' => count( $errors ),
			);
		}

		/**
		 * Bash/Shell syntax validation
		 */
		private static function validate_bash( $code ) {
			$errors   = array();
			$warnings = array();
			$valid    = true;

			$issues = array(
				array(
					'pattern' => '/if\s+\[(?!\s)/',
					'message' => 'Missing space after [',
					'type'    => 'error',
				),
				array(
					'pattern' => '/(?<!\s)\]\s*;?\s*then/',
					'message' => 'Missing space before ]',
					'type'    => 'error',
				),
			);

			foreach ( $issues as $check ) {
				if ( preg_match_all( $check['pattern'], $code, $matches, PREG_OFFSET_CAPTURE ) ) {
					foreach ( $matches[0] as $match ) {
						$line_num = substr_count( $code, "\n", 0, $match[1] ) + 1;
						$message  = sprintf( '%s (Line %d)', $check['message'], $line_num );
						
						if ( 'error' === $check['type'] ) {
							$errors[] = $message;
							$valid    = false;
						} else {
							$warnings[] = $message;
						}
					}
				}
			}

			$balance_checks = self::check_balance( $code, 'bash' );
			if ( ! empty( $balance_checks['errors'] ) ) {
				$errors = array_merge( $errors, $balance_checks['errors'] );
				$valid  = false;
			}

			return array(
				'valid'       => $valid,
				'errors'      => $errors,
				'warnings'    => $warnings,
				'report'      => self::generate_report( $errors, $warnings ),
				'error_count' => count( $errors ),
			);
		}

		/**
		 * Check for balanced brackets, braces, and parentheses
		 */
		private static function check_balance( $code, $language = 'php' ) {
			$errors = array();
			$pairs  = array(
				'(' => ')',
				'[' => ']',
				'{' => '}',
			);

			$stack = array();
			$cleaned_code = preg_replace( '/"(?:\\\\.|[^"\\\\])*"/', '""', $code );
			$cleaned_code = preg_replace( "/\'(?:\\\\.|[^'\\\\])*\'/", "''", $cleaned_code );
			
			if ( 'php' === $language || 'javascript' === $language ) {
				$cleaned_code = preg_replace( '/\/\/.*$/', '', $cleaned_code, -1, PREG_OFFSET_CAPTURE );
				$cleaned_code = preg_replace( '/\/\*[\s\S]*?\*\//', '', $cleaned_code );
			}

			for ( $i = 0; $i < strlen( $cleaned_code ); $i++ ) {
				$char = $cleaned_code[ $i ];

				if ( isset( $pairs[ $char ] ) ) {
					$stack[] = array(
						'open'   => $char,
						'close'  => $pairs[ $char ],
						'line'   => substr_count( $cleaned_code, "\n", 0, $i ) + 1,
					);
				} elseif ( in_array( $char, array( ')', ']', '}' ), true ) ) {
					if ( empty( $stack ) ) {
						$line   = substr_count( $cleaned_code, "\n", 0, $i ) + 1;
						$errors[] = "Unexpected closing '$char' (Line $line)";
					} else {
						$last = end( $stack );
						if ( $last['close'] === $char ) {
							array_pop( $stack );
						} else {
							$line   = substr_count( $cleaned_code, "\n", 0, $i ) + 1;
							$errors[] = "Mismatched bracket - expected '{$last['close']}' but got '$char' (Line $line)";
						}
					}
				}
			}

			foreach ( $stack as $unclosed ) {
				$errors[] = "Unclosed '{$unclosed['open']}' (opened at Line {$unclosed['line']})";
			}

			return array( 'errors' => $errors );
		}

		/**
		 * Generate formatted validation report
		 */
		private static function generate_report( $errors, $warnings ) {
			$report = '';

			if ( empty( $errors ) && empty( $warnings ) ) {
				return '✓ Syntax is valid!';
			}

			if ( ! empty( $errors ) ) {
				$report .= "❌ ERRORS (" . count( $errors ) . "):\n";
				foreach ( $errors as $error ) {
					$report .= "  • $error\n";
				}
				$report .= "\n";
			}

			if ( ! empty( $warnings ) ) {
				$report .= "⚠ WARNINGS (" . count( $warnings ) . "):\n";
				foreach ( $warnings as $warning ) {
					$report .= "  • $warning\n";
				}
			}

			return $report;
		}

		/**
		 * Detect code language from content
		 */
		public static function detect_code_language( $code ) {
			if ( preg_match( '/<\?php|\$[a-zA-Z_]|function\s+\w+\s*\(/', $code ) ) {
				return 'php';
			}
			if ( preg_match( '/^(def|class|import|from)\s+|^\s{4,}(?!.*:$).*$|print\s*\(/m', $code ) ) {
				return 'python';
			}
			if ( preg_match( '/var\s+\w+|function\s+\w+|const\s+\w+|let\s+\w+|=>/', $code ) ) {
				return 'javascript';
			}
			if ( preg_match( '/<(html|head|body|div|span|p)[\s>]/', $code ) ) {
				return 'html';
			}
			if ( preg_match( '/\.[\w-]+\s*{|#[\w-]+\s*{|:\s*(?:hover|focus|active)/', $code ) ) {
				return 'css';
			}
			if ( preg_match( '/^[\s\n]*\{|\{[^}]*"/', $code ) ) {
				return 'json';
			}
			if ( preg_match( '/\b(SELECT|INSERT|UPDATE|DELETE|CREATE|DROP|ALTER)\b/i', $code ) ) {
				return 'sql';
			}
			if ( preg_match( '/^#!/bin/bash|^\s*(if|for|while|do|done)\b/m', $code ) ) {
				return 'bash';
			}
			return 'unknown';
		}

		/**
		 * Extract code blocks from markdown response
		 */
		public static function extract_code_blocks( $text ) {
			$blocks = array();
			
			if ( preg_match_all( '/```(\w+)?\s*\n(.*?)\n```/s', $text, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$blocks[] = array(
						'language' => ! empty( $match[1] ) ? strtolower( $match[1] ) : 'auto',
						'code'     => $match[2],
					);
				}
			}
			
			return $blocks;
		}

		/**
		 * Process AI response and add syntax validation reports
		 *
		 * Call this on chat responses to automatically validate any code
		 */
		public static function validate_response_code( $response_text ) {
			$code_blocks = self::extract_code_blocks( $response_text );

			if ( empty( $code_blocks ) ) {
				return $response_text;
			}

			$enhanced_response = $response_text;

			foreach ( $code_blocks as $block ) {
				$code     = $block['code'];
				$language = $block['language'];
				
				if ( 'auto' === $language ) {
					$language = self::detect_code_language( $code );
				}

				$validation = self::validate_code_syntax( $code, $language );

				if ( ! $validation['valid'] ) {
					$enhanced_response .= "\n\n**Syntax Check (" . ucfirst( $language ) . "):**\n```\n" 
										. $validation['report'] 
										. "```";
				}
			}

			return $enhanced_response;
		}
	}
}
