<?php
/**
 * Inject API credentials from wp-config constants.
 *
 * This mu-plugin reads API keys from constants (defined via wp-env config)
 * and injects them into the WP AI Client credentials option.
 *
 * @package WP_AI_Benchmarks
 */

declare(strict_types=1);

add_action(
	'plugins_loaded',
	static function (): void {
		$constant_map = [
			'openai'    => 'OPENAI_API_KEY',
			'anthropic' => 'ANTHROPIC_API_KEY',
			'google'    => 'GOOGLE_API_KEY',
			'mistral'   => 'MISTRAL_API_KEY',
			'cohere'    => 'COHERE_API_KEY',
		];

		$credentials = [];

		foreach ( $constant_map as $provider => $constant_name ) {
			if ( defined( $constant_name ) ) {
				$key = constant( $constant_name );
				if ( is_string( $key ) && $key !== '' ) {
					$credentials[ $provider ] = $key;
				}
			}
		}

		if ( empty( $credentials ) ) {
			return;
		}

		// Merge with existing credentials (constants take precedence).
		$existing = get_option( 'wp_ai_client_provider_credentials', [] );
		if ( ! is_array( $existing ) ) {
			$existing = [];
		}

		$merged = array_merge( $existing, $credentials );
		update_option( 'wp_ai_client_provider_credentials', $merged );
	},
	1 // Early priority, before WP AI Client initializes.
);
