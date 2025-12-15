<?php
/**
 * Knowledge test executor.
 *
 * @package WordPress\AI_Benchmark
 */

declare(strict_types=1);

namespace WordPress\AI_Benchmark;

/**
 * Executes MMLU-style knowledge tests.
 *
 * Handles multiple choice and short answer questions,
 * with objective scoring based on exact/regex matching.
 */
class Executor_Knowledge {

	/**
	 * Model client for AI requests.
	 */
	private Model_Client $model_client;

	/**
	 * Constructor.
	 *
	 * @param Model_Client $model_client Model client instance.
	 */
	public function __construct( Model_Client $model_client ) {
		$this->model_client = $model_client;
	}

	/**
	 * Execute a knowledge test.
	 *
	 * @param array<string, mixed> $test  Test definition.
	 * @param string               $model Model identifier (e.g., 'openai:gpt-4.1').
	 *
	 * @return Test_Result
	 */
	public function execute( array $test, string $model ): Test_Result {
		$start = microtime( true );

		try {
			$prompt   = $this->build_prompt( $test );
			$response = $this->model_client->generate(
				prompt: $prompt,
				model: $model,
				temperature: 0.0,
			);

			$model_answer = $this->extract_answer( $response, $test['type'] );
			$score        = $this->score_answer( $model_answer, $test );

			$result = Test_Result::knowledge_result(
				test_id: $test['id'],
				score: $score,
				model_answer: $model_answer,
				correct_answer: $test['correct_answer'],
				category: $test['category'] ?? '',
			);

		} catch ( \Throwable $e ) {
			$result = Test_Result::error_result(
				test_id: $test['id'],
				type: 'knowledge',
				error: $e->getMessage(),
				category: $test['category'] ?? '',
			);
		}

		$result->set_duration_ms( ( microtime( true ) - $start ) * 1000 );

		return $result;
	}

	/**
	 * Build the prompt for a knowledge test.
	 *
	 * @param array<string, mixed> $test Test definition.
	 *
	 * @return string Complete prompt.
	 */
	private function build_prompt( array $test ): string {
		$prompt = $test['prompt'];

		if ( $test['type'] === 'multiple_choice' && isset( $test['choices'] ) ) {
			$prompt .= "\n\n";
			foreach ( $test['choices'] as $choice ) {
				$prompt .= "{$choice['key']}. {$choice['text']}\n";
			}
			$prompt .= "\nAnswer with only the letter (A, B, C, or D) corresponding to the correct answer. Do not include any explanation.";
		} else {
			$prompt .= "\n\nProvide a concise, direct answer. Do not include explanations or additional context.";
		}

		return $prompt;
	}

	/**
	 * Extract the answer from model response.
	 *
	 * @param string $response Raw model response.
	 * @param string $type     Test type ('multiple_choice' or 'short_answer').
	 *
	 * @return string Extracted answer.
	 */
	private function extract_answer( string $response, string $type ): string {
		$response = trim( $response );

		if ( $type === 'multiple_choice' ) {
			return $this->extract_multiple_choice_answer( $response );
		}

		// For short answer, return cleaned response.
		// Remove common prefixes like "Answer:" or "The answer is".
		$response = preg_replace( '/^(?:the\s+)?answer(?:\s+is)?[:\s]*/i', '', $response );

		return trim( $response );
	}

	/**
	 * Extract multiple choice answer letter from response.
	 *
	 * @param string $response Model response.
	 *
	 * @return string Single letter (A-D) or original response if not found.
	 */
	private function extract_multiple_choice_answer( string $response ): string {
		// Pattern 1: Just a single letter (with optional period).
		if ( preg_match( '/^([A-D])\.?$/i', $response, $matches ) ) {
			return strtoupper( $matches[1] );
		}

		// Pattern 2: "The answer is X" or "Answer: X".
		if ( preg_match( '/(?:answer|choice|option)\s*(?:is\s*)?[:.]?\s*([A-D])\b/i', $response, $matches ) ) {
			return strtoupper( $matches[1] );
		}

		// Pattern 3: Letter at start followed by punctuation or space.
		if ( preg_match( '/^([A-D])[.:\s\)]/i', $response, $matches ) ) {
			return strtoupper( $matches[1] );
		}

		// Pattern 4: Letter in parentheses.
		if ( preg_match( '/\(([A-D])\)/i', $response, $matches ) ) {
			return strtoupper( $matches[1] );
		}

		// Pattern 5: First letter if starts with A-D.
		if ( preg_match( '/^([A-D])/i', $response, $matches ) ) {
			return strtoupper( $matches[1] );
		}

		// Fallback: return original response for scoring.
		return $response;
	}

	/**
	 * Score the answer against the correct answer.
	 *
	 * @param string               $model_answer Extracted model answer.
	 * @param array<string, mixed> $test         Test definition.
	 *
	 * @return float Score (0.0 or 1.0).
	 */
	private function score_answer( string $model_answer, array $test ): float {
		$correct     = $test['correct_answer'];
		$answer_type = $test['answer_type'] ?? 'exact';

		// Multiple choice: simple letter comparison.
		if ( $test['type'] === 'multiple_choice' ) {
			return strtoupper( trim( $model_answer ) ) === strtoupper( trim( $correct ) )
				? 1.0
				: 0.0;
		}

		// Short answer: depends on answer_type.
		return match ( $answer_type ) {
			'exact'    => $this->score_exact_match( $model_answer, $correct ),
			'regex'    => $this->score_regex_match( $model_answer, $correct ),
			'contains' => $this->score_contains_match( $model_answer, $correct ),
			default    => 0.0,
		};
	}

	/**
	 * Score using exact match (case-insensitive, trimmed).
	 *
	 * @param string $model_answer Model's answer.
	 * @param string $correct      Correct answer.
	 *
	 * @return float 1.0 if match, 0.0 otherwise.
	 */
	private function score_exact_match( string $model_answer, string $correct ): float {
		$model_normalized   = $this->normalize_answer( $model_answer );
		$correct_normalized = $this->normalize_answer( $correct );

		return $model_normalized === $correct_normalized ? 1.0 : 0.0;
	}

	/**
	 * Score using regex pattern match.
	 *
	 * @param string $model_answer Model's answer.
	 * @param string $pattern      Regex pattern.
	 *
	 * @return float 1.0 if matches, 0.0 otherwise.
	 */
	private function score_regex_match( string $model_answer, string $pattern ): float {
		// Ensure pattern has delimiters.
		if ( ! preg_match( '/^[\/\#\~\@]/', $pattern ) ) {
			$pattern = '/' . $pattern . '/i';
		}

		// Suppress warnings for invalid patterns.
		set_error_handler( fn() => null );

		try {
			$result = preg_match( $pattern, $model_answer );
			restore_error_handler();
			return $result === 1 ? 1.0 : 0.0;
		} catch ( \Throwable $e ) {
			restore_error_handler();
			return 0.0;
		}
	}

	/**
	 * Score using substring containment.
	 *
	 * @param string $model_answer Model's answer.
	 * @param string $needle       String to find.
	 *
	 * @return float 1.0 if contains, 0.0 otherwise.
	 */
	private function score_contains_match( string $model_answer, string $needle ): float {
		return stripos( $model_answer, $needle ) !== false ? 1.0 : 0.0;
	}

	/**
	 * Normalize an answer for comparison.
	 *
	 * @param string $answer Answer string.
	 *
	 * @return string Normalized answer.
	 */
	private function normalize_answer( string $answer ): string {
		// Strip markdown inline code backticks.
		$answer = preg_replace( '/^`+|`+$/', '', $answer );

		// Lowercase.
		$answer = strtolower( $answer );

		// Trim whitespace.
		$answer = trim( $answer );

		// Remove trailing punctuation.
		$answer = rtrim( $answer, '.,:;!?' );

		// Collapse multiple spaces.
		$answer = preg_replace( '/\s+/', ' ', $answer );

		return $answer;
	}
}
