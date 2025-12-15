<?php
/**
 * WP AI Client wrapper for benchmark operations.
 *
 * @package WordPress\AI_Benchmark
 */

declare(strict_types=1);

namespace WordPress\AI_Benchmark;

use WordPress\AI_Client\AI_Client;

/**
 * Wrapper around WP AI Client SDK for consistent model access.
 *
 * Provides a simplified interface for text and JSON generation,
 * handling model parsing and error management.
 */
class Model_Client {

	/**
	 * Generate text from a prompt.
	 *
	 * @param string                    $prompt      The prompt to send to the model.
	 * @param string                    $model       Model identifier in 'provider:model' format (e.g., 'openai:gpt-4.1').
	 * @param float                     $temperature Temperature for generation (0.0 = deterministic).
	 * @param array<string, mixed>|null $json_schema Optional JSON schema for structured output.
	 *
	 * @return string Generated text response.
	 *
	 * @throws \RuntimeException On API errors or unavailable model.
	 * @throws \InvalidArgumentException On invalid model format.
	 */
	public function generate(
		string $prompt,
		string $model,
		float $temperature = 0.0,
		?array $json_schema = null,
	): string {
		$this->ensure_ai_client_available();

		// Parse model string.
		[ $provider, $model_id ] = $this->parse_model_string( $model );

		// Build the prompt.
		$builder = AI_Client::prompt( $prompt )
			->using_model_preference( [ $provider, $model_id ] )
			->using_temperature( $temperature );

		// Add JSON schema if provided.
		if ( $json_schema !== null ) {
			$builder = $builder->as_json_response( $json_schema );
		}

		// Check if model is available.
		if ( ! $builder->is_supported_for_text_generation() ) {
			throw new \RuntimeException(
				sprintf(
					'Model %s is not available for text generation. Check provider credentials in Settings > AI Credentials.',
					$model
				)
			);
		}

		try {
			return $builder->generate_text();
		} catch ( \Throwable $e ) {
			throw new \RuntimeException(
				'AI generation failed: ' . $e->getMessage(),
				0,
				$e
			);
		}
	}

	/**
	 * Generate text and parse as JSON.
	 *
	 * Convenience method that generates text with a JSON schema
	 * and decodes the response.
	 *
	 * @param string               $prompt      The prompt to send.
	 * @param string               $model       Model identifier.
	 * @param array<string, mixed> $json_schema JSON schema for the response.
	 * @param float                $temperature Temperature for generation.
	 *
	 * @return array<string, mixed> Decoded JSON response.
	 *
	 * @throws \RuntimeException On API or JSON parsing errors.
	 */
	public function generate_json(
		string $prompt,
		string $model,
		array $json_schema,
		float $temperature = 0.0,
	): array {
		$response = $this->generate( $prompt, $model, $temperature, $json_schema );

		try {
			$decoded = json_decode( $response, true, 512, JSON_THROW_ON_ERROR );
			return $decoded;
		} catch ( \JsonException $e ) {
			throw new \RuntimeException(
				'Failed to parse JSON response: ' . $e->getMessage(),
				0,
				$e
			);
		}
	}

	/**
	 * Check if a model is available for text generation.
	 *
	 * @param string $model Model identifier in 'provider:model' format.
	 *
	 * @return bool True if model is available.
	 */
	public function is_model_available( string $model ): bool {
		if ( ! $this->is_ai_client_available() ) {
			return false;
		}

		try {
			[ $provider, $model_id ] = $this->parse_model_string( $model );

			return AI_Client::prompt( 'test' )
				->using_model_preference( [ $provider, $model_id ] )
				->is_supported_for_text_generation();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Get list of available providers.
	 *
	 * @return array<string> List of provider identifiers.
	 */
	public function get_available_providers(): array {
		// Common providers supported by WP AI Client.
		return [
			'openai',
			'anthropic',
			'google',
		];
	}

	/**
	 * Parse model string into provider and model ID.
	 *
	 * @param string $model Model string in 'provider:model' format.
	 *
	 * @return array{0: string, 1: string} Array of [provider, model_id].
	 *
	 * @throws \InvalidArgumentException If format is invalid.
	 */
	private function parse_model_string( string $model ): array {
		$parts = explode( ':', $model, 2 );

		if ( count( $parts ) !== 2 || empty( $parts[0] ) || empty( $parts[1] ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					"Invalid model format '%s'. Expected 'provider:model' (e.g., 'openai:gpt-4.1')",
					$model
				)
			);
		}

		return $parts;
	}

	/**
	 * Check if AI_Client class is available.
	 *
	 * @return bool True if available.
	 */
	private function is_ai_client_available(): bool {
		return class_exists( AI_Client::class );
	}

	/**
	 * Ensure AI_Client class is available, throw if not.
	 *
	 * @throws \RuntimeException If AI_Client is not available.
	 */
	private function ensure_ai_client_available(): void {
		if ( ! $this->is_ai_client_available() ) {
			throw new \RuntimeException(
				'WP AI Client SDK not available. Run "composer install" in the plugin directory.'
			);
		}
	}
}
