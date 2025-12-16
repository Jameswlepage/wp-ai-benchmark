<?php
/**
 * AI Judge for code quality evaluation.
 *
 * @package WordPress\AI_Benchmark
 */

declare(strict_types=1);

namespace WordPress\AI_Benchmark;

/**
 * LLM-as-Judge implementation for code quality evaluation.
 *
 * Uses a separate AI model to evaluate generated code quality
 * based on a structured rubric with multiple criteria.
 */
class Judge {

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
	 * Evaluate code quality using AI judge.
	 *
	 * @param string               $code         Generated code to evaluate.
	 * @param string               $task         Original task description.
	 * @param array<string>        $requirements List of requirements.
	 * @param array<string, mixed> $rubric       Judge rubric with criteria.
	 * @param string               $model        Judge model identifier.
	 * @param string|null          $context      Additional context for the judge.
	 *
	 * @return array{score: float, details: array<string, mixed>}
	 */
	public function evaluate(
		string $code,
		string $task,
		array $requirements,
		array $rubric,
		string $model,
		?string $context = null,
	): array {
		$prompt                = $this->build_evaluation_prompt( $code, $task, $requirements, $rubric, $context );
		$response_schema_value = $rubric['response_schema'] ?? null;
		/** @var array<string, mixed> $response_schema */
		$response_schema = is_array( $response_schema_value ) ? $response_schema_value : $this->get_default_response_schema( $rubric );

		try {
			$response = $this->model_client->generate(
				prompt: $prompt,
				model: $model,
				temperature: 0.0,
				json_schema: $response_schema,
			);

			// Strip markdown code blocks if present.
			$json_response = $this->extract_json( $response );
			$decoded       = json_decode( $json_response, true, 512, JSON_THROW_ON_ERROR );

			if ( ! is_array( $decoded ) ) {
				throw new \JsonException( 'Expected JSON object response' );
			}

			/** @var array<string, mixed> $judgment */
			$judgment = $decoded;

			// Normalize score to 0-1 range (judge uses 0-5).
			$raw_score_value  = $judgment['overall_score'] ?? 0;
			$raw_score        = is_numeric( $raw_score_value ) ? (float) $raw_score_value : 0.0;
			$normalized_score = min( 1.0, max( 0.0, $raw_score / 5.0 ) );

			$criteria_scores = $judgment['criteria_scores'] ?? [];
			$summary         = $judgment['summary'] ?? '';
			$issues          = $judgment['issues'] ?? [];
			$strengths       = $judgment['strengths'] ?? [];

			return [
				'score'   => round( $normalized_score, 4 ),
				'details' => [
					'raw_score'       => $raw_score,
					'criteria_scores' => is_array( $criteria_scores ) ? $criteria_scores : [],
					'summary'         => is_string( $summary ) ? $summary : '',
					'issues'          => is_array( $issues ) ? $issues : [],
					'strengths'       => is_array( $strengths ) ? $strengths : [],
				],
			];

		} catch ( \JsonException $e ) {
			return [
				'score'   => 0.5, // Neutral on parse failure.
				'details' => [
					'error'        => 'Failed to parse judge response: ' . $e->getMessage(),
					'raw_response' => $response,
				],
			];

		} catch ( \Throwable $e ) {
			return [
				'score'   => 0.5,
				'details' => [ 'error' => $e->getMessage() ],
			];
		}
	}

	/**
	 * Build the evaluation prompt from rubric template.
	 *
	 * @param string               $code         Code to evaluate.
	 * @param string               $task         Task description.
	 * @param array<string>        $requirements Requirements list.
	 * @param array<string, mixed> $rubric       Rubric data.
	 * @param string|null          $context      Additional context.
	 *
	 * @return string Complete evaluation prompt.
	 */
	private function build_evaluation_prompt(
		string $code,
		string $task,
		array $requirements,
		array $rubric,
		?string $context,
	): string {
		$system_prompt_value = $rubric['system_prompt'] ?? null;
		$system_prompt       = is_string( $system_prompt_value ) ? $system_prompt_value : $this->get_default_system_prompt();
		$template_value      = $rubric['evaluation_prompt_template'] ?? null;
		$template            = is_string( $template_value ) ? $template_value : $this->get_default_template();

		// Build criteria section.
		$criteria_value = $rubric['criteria'] ?? [];
		/** @var list<array<string, mixed>> $criteria */
		$criteria      = is_array( $criteria_value ) ? $criteria_value : [];
		$criteria_text = $this->format_criteria( $criteria );

		// Format requirements.
		$requirements_text = implode( "\n", array_map( static fn( $r ) => "- {$r}", $requirements ) );

		// Format context.
		$context_text = $context ? "\n## Additional Context\n{$context}\n" : '';

		// Apply template substitutions.
		$prompt = str_replace(
			[ '{{code}}', '{{task}}', '{{requirements}}', '{{criteria}}', '{{context}}' ],
			[ $code, $task, $requirements_text, $criteria_text, $context_text ],
			$template
		);

		return $system_prompt . "\n\n" . $prompt;
	}

	/**
	 * Format criteria for inclusion in prompt.
	 *
	 * @param array<array<string, mixed>> $criteria Criteria definitions.
	 *
	 * @return string Formatted criteria text.
	 */
	private function format_criteria( array $criteria ): string {
		$text = '';

		foreach ( $criteria as $criterion ) {
			if ( ! is_array( $criterion ) ) {
				continue;
			}
			$name_value        = $criterion['name'] ?? $criterion['id'] ?? 'unknown';
			$name              = is_string( $name_value ) ? $name_value : 'unknown';
			$weight_value      = $criterion['weight'] ?? 1.0;
			$weight            = is_numeric( $weight_value ) ? (float) $weight_value : 1.0;
			$description_value = $criterion['description'] ?? '';
			$description       = is_string( $description_value ) ? $description_value : '';

			$text .= "\n### {$name} (weight: {$weight})\n";
			$text .= "{$description}\n\n";

			$scoring_guide = $criterion['scoring_guide'] ?? [];
			if ( is_array( $scoring_guide ) && ! empty( $scoring_guide ) ) {
				$text .= "Scoring guide:\n";
				foreach ( $scoring_guide as $score => $guide ) {
					$guide_str = is_string( $guide ) ? $guide : '';
					$text     .= "  {$score}: {$guide_str}\n";
				}
			}

			$text .= "\n";
		}

		return $text;
	}

	/**
	 * Get default system prompt for the judge.
	 *
	 * @return string System prompt.
	 */
	private function get_default_system_prompt(): string {
		return <<<'PROMPT'
You are an expert WordPress developer and code reviewer acting as a judge for AI-generated code.
Your task is to objectively evaluate code quality based on specific criteria.
Be fair, consistent, and provide specific examples from the code to justify your scores.
Always respond with valid JSON matching the required schema.
PROMPT;
	}

	/**
	 * Get default evaluation template.
	 *
	 * @return string Template with placeholders.
	 */
	private function get_default_template(): string {
		return <<<'TEMPLATE'
## Code Evaluation Task

You are evaluating AI-generated WordPress code.

### Original Task
{{task}}

### Requirements
{{requirements}}

### Generated Code
```php
{{code}}
```
{{context}}

### Evaluation Criteria
{{criteria}}

### Instructions
Evaluate the code above against each criterion. For each criterion:
1. Assign a score from 0-5 based on the scoring guide
2. Provide specific reasoning citing code examples

Then provide:
- An overall weighted score (0-5)
- A brief summary of the code quality
- List of issues found (if any)
- List of strengths (if any)

IMPORTANT: Respond with valid JSON matching the schema. Do not include any text outside the JSON object.
TEMPLATE;
	}

	/**
	 * Get default response schema for structured output.
	 *
	 * @param array<string, mixed> $rubric Rubric data to extract criteria IDs.
	 *
	 * @return array<string, mixed> JSON schema.
	 */
	private function get_default_response_schema( array $rubric ): array {
		$criteria_properties = [];
		$criteria_value      = $rubric['criteria'] ?? [];
		$criteria            = is_array( $criteria_value ) ? $criteria_value : [];

		foreach ( $criteria as $criterion ) {
			if ( ! is_array( $criterion ) ) {
				continue;
			}
			$id_value                   = $criterion['id'] ?? 'unknown';
			$id                         = is_string( $id_value ) ? $id_value : 'unknown';
			$criteria_properties[ $id ] = [
				'type'       => 'object',
				'required'   => [ 'score', 'reasoning' ],
				'properties' => [
					'score'     => [
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 5,
					],
					'reasoning' => [ 'type' => 'string' ],
				],
			];
		}

		return [
			'type'       => 'object',
			'required'   => [ 'overall_score', 'criteria_scores', 'summary' ],
			'properties' => [
				'overall_score'   => [
					'type'    => 'number',
					'minimum' => 0,
					'maximum' => 5,
				],
				'criteria_scores' => [
					'type'       => 'object',
					'properties' => $criteria_properties,
				],
				'summary'         => [ 'type' => 'string' ],
				'issues'          => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'severity'       => [
								'type' => 'string',
								'enum' => [ 'critical', 'major', 'minor' ],
							],
							'category'       => [ 'type' => 'string' ],
							'description'    => [ 'type' => 'string' ],
							'line_reference' => [ 'type' => 'string' ],
						],
					],
				],
				'strengths'       => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
			],
		];
	}

	/**
	 * Extract JSON from a response that may be wrapped in markdown code blocks.
	 *
	 * @param string $response Raw response text.
	 *
	 * @return string Extracted JSON string.
	 */
	private function extract_json( string $response ): string {
		$response = trim( $response );

		// Try to extract from markdown code block (```json ... ``` or ``` ... ```).
		if ( preg_match( '/```(?:json)?\s*([\s\S]*?)```/', $response, $matches ) ) {
			return trim( $matches[1] );
		}

		// If response starts with { or [, assume it's already JSON.
		if ( preg_match( '/^\s*[\[{]/', $response ) ) {
			return $response;
		}

		// Try to find JSON object in the response.
		if ( preg_match( '/(\{[\s\S]*\})/', $response, $matches ) ) {
			return $matches[1];
		}

		// Return as-is and let json_decode handle the error.
		return $response;
	}

	/**
	 * Calculate weighted average from criteria scores.
	 *
	 * @param array<string, array{score: int|float, reasoning?: string}> $criteria_scores Scores by criterion ID.
	 * @param array<string, mixed>                                       $rubric          Rubric with weight definitions.
	 *
	 * @return float Weighted average score (0-5).
	 */
	public function calculate_weighted_score( array $criteria_scores, array $rubric ): float {
		$total_weight   = 0.0;
		$weighted_sum   = 0.0;
		$criteria_value = $rubric['criteria'] ?? [];
		$criteria       = is_array( $criteria_value ) ? $criteria_value : [];

		foreach ( $criteria as $criterion ) {
			if ( ! is_array( $criterion ) ) {
				continue;
			}
			$id_value     = $criterion['id'] ?? '';
			$id           = is_string( $id_value ) ? $id_value : '';
			$weight_value = $criterion['weight'] ?? 0;
			$weight       = is_numeric( $weight_value ) ? (float) $weight_value : 0.0;

			if ( isset( $criteria_scores[ $id ]['score'] ) ) {
				$score         = (float) $criteria_scores[ $id ]['score'];
				$weighted_sum += $score * $weight;
				$total_weight += $weight;
			}
		}

		if ( $total_weight === 0.0 ) {
			return 0.0;
		}

		return $weighted_sum / $total_weight;
	}
}
