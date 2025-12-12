<?php
/**
 * Execution test executor.
 *
 * @package WP_AI_Benchmarks
 */

declare(strict_types=1);

namespace WP_AI_Benchmarks;

/**
 * Executes HumanEval-style code generation tests with three-layer evaluation.
 *
 * Layer 1: Static checks (regex patterns)
 * Layer 2: Runtime checks (execute and verify)
 * Layer 3: AI judge (quality evaluation)
 */
class AI_Bench_Executor_Execution {

	/**
	 * Model client for AI requests.
	 */
	private AI_Bench_Model_Client $model_client;

	/**
	 * Static code checker.
	 */
	private AI_Bench_Static_Checker $static_checker;

	/**
	 * Runtime environment.
	 */
	private AI_Bench_Environment $environment;

	/**
	 * AI judge for quality evaluation.
	 */
	private AI_Bench_Judge $judge;

	/**
	 * Suite loader for rubrics.
	 */
	private AI_Bench_Suite_Loader $suite_loader;

	/**
	 * Constructor.
	 *
	 * @param AI_Bench_Model_Client       $model_client   Model client instance.
	 * @param AI_Bench_Static_Checker|null $static_checker Static checker instance.
	 * @param AI_Bench_Environment|null    $environment    Environment instance.
	 * @param AI_Bench_Judge|null          $judge          Judge instance.
	 * @param AI_Bench_Suite_Loader|null   $suite_loader   Suite loader instance.
	 */
	public function __construct(
		AI_Bench_Model_Client $model_client,
		?AI_Bench_Static_Checker $static_checker = null,
		?AI_Bench_Environment $environment = null,
		?AI_Bench_Judge $judge = null,
		?AI_Bench_Suite_Loader $suite_loader = null,
	) {
		$this->model_client   = $model_client;
		$this->static_checker = $static_checker ?? new AI_Bench_Static_Checker();
		$this->environment    = $environment ?? new AI_Bench_Environment();
		$this->judge          = $judge ?? new AI_Bench_Judge( $model_client );
		$this->suite_loader   = $suite_loader ?? new AI_Bench_Suite_Loader();
	}

	/**
	 * Execute an execution test with three-layer evaluation.
	 *
	 * @param array<string, mixed> $test        Test definition.
	 * @param string               $model       Model identifier for code generation.
	 * @param string               $judge_model Model identifier for judge evaluation.
	 *
	 * @return AI_Bench_Test_Result
	 */
	public function execute( array $test, string $model, string $judge_model ): AI_Bench_Test_Result {
		$start = microtime( true );

		try {
			// Generate code from model.
			$prompt   = $this->build_prompt( $test );
			$response = $this->model_client->generate(
				prompt: $prompt,
				model: $model,
				temperature: 0.0,
			);

			$code = $this->extract_code( $response );

			if ( empty( $code ) ) {
				$result = AI_Bench_Test_Result::error_result(
					test_id: $test['id'],
					type: 'execution',
					error: 'No code extracted from model response',
					category: $test['category'] ?? '',
				);
				$result->set_duration_ms( ( microtime( true ) - $start ) * 1000 );
				return $result;
			}

			// Layer 1: Static checks.
			$static_result = $this->run_static_checks( $code, $test );

			// Layer 2: Runtime checks.
			$runtime_result = $this->run_runtime_checks( $code, $test );

			// Layer 3: AI Judge evaluation.
			$judge_result = $this->run_judge_evaluation( $code, $test, $judge_model );

			$result = AI_Bench_Test_Result::execution_result(
				test_id: $test['id'],
				generated_code: $code,
				static_score: $static_result['score'],
				runtime_score: $runtime_result['score'],
				quality_score: $judge_result['score'],
				static_details: $static_result['details'],
				runtime_details: $runtime_result['details'],
				judge_details: $judge_result['details'],
				category: $test['category'] ?? '',
			);

		} catch ( \Throwable $e ) {
			$result = AI_Bench_Test_Result::error_result(
				test_id: $test['id'],
				type: 'execution',
				error: $e->getMessage(),
				category: $test['category'] ?? '',
			);
		}

		$result->set_duration_ms( ( microtime( true ) - $start ) * 1000 );

		return $result;
	}

	/**
	 * Build the prompt for code generation.
	 *
	 * @param array<string, mixed> $test Test definition.
	 *
	 * @return string Complete prompt.
	 */
	private function build_prompt( array $test ): string {
		$prompt = "You are an expert WordPress developer. " . $test['prompt'];

		if ( ! empty( $test['requirements'] ) ) {
			$prompt .= "\n\nRequirements:\n";
			foreach ( $test['requirements'] as $req ) {
				$prompt .= "- {$req}\n";
			}
		}

		if ( ! empty( $test['context']['existing_code'] ) ) {
			$prompt .= "\n\nExisting code context:\n```php\n{$test['context']['existing_code']}\n```";
		}

		$prompt .= "\n\nProvide only the PHP code solution. Wrap your code in ```php code blocks. Do not include explanations.";

		return $prompt;
	}

	/**
	 * Extract PHP code from model response.
	 *
	 * @param string $response Raw model response.
	 *
	 * @return string Extracted PHP code.
	 */
	private function extract_code( string $response ): string {
		// Try to extract from ```php code blocks.
		if ( preg_match( '/```php\s*([\s\S]*?)```/', $response, $matches ) ) {
			return trim( $matches[1] );
		}

		// Try to extract from generic code blocks.
		if ( preg_match( '/```\s*([\s\S]*?)```/', $response, $matches ) ) {
			$code = trim( $matches[1] );
			// Only return if it looks like PHP.
			if ( preg_match( '/<\?php|function\s+\w+|class\s+\w+|\$\w+/', $code ) ) {
				return $code;
			}
		}

		// Try to extract standalone PHP code.
		if ( preg_match( '/<\?php([\s\S]*?)(?:\?>|$)/', $response, $matches ) ) {
			return '<?php' . $matches[1];
		}

		// Check if response looks like PHP code without tags.
		$response = trim( $response );
		if ( preg_match( '/^(?:function\s+\w+|class\s+\w+|add_action|add_filter|add_shortcode)/', $response ) ) {
			return $response;
		}

		// Return trimmed response as fallback.
		return $response;
	}

	/**
	 * Run static code checks.
	 *
	 * @param string               $code Generated code.
	 * @param array<string, mixed> $test Test definition.
	 *
	 * @return array{score: float, details: array<string, mixed>}
	 */
	private function run_static_checks( string $code, array $test ): array {
		$checks = $test['static_checks'] ?? [];

		if ( empty( $checks ) ) {
			return [
				'score'   => 1.0,
				'details' => [ 'message' => 'No static checks defined' ],
			];
		}

		return $this->static_checker->check( $code, $checks );
	}

	/**
	 * Run runtime verification checks.
	 *
	 * @param string               $code Generated code.
	 * @param array<string, mixed> $test Test definition.
	 *
	 * @return array{score: float, details: array<string, mixed>}
	 */
	private function run_runtime_checks( string $code, array $test ): array {
		$checks = $test['runtime_checks'] ?? [];

		if ( empty( $checks ) || empty( $checks['assertions'] ) ) {
			return [
				'score'   => 1.0,
				'details' => [ 'message' => 'No runtime checks defined' ],
			];
		}

		return $this->environment->execute_and_verify( $code, $checks );
	}

	/**
	 * Run AI judge quality evaluation.
	 *
	 * @param string               $code        Generated code.
	 * @param array<string, mixed> $test        Test definition.
	 * @param string               $judge_model Judge model identifier.
	 *
	 * @return array{score: float, details: array<string, mixed>}
	 */
	private function run_judge_evaluation( string $code, array $test, string $judge_model ): array {
		$judge_config = $test['judge_config'] ?? [];

		// Check if judging is disabled for this test.
		if ( isset( $judge_config['enabled'] ) && $judge_config['enabled'] === false ) {
			return [
				'score'   => 0.5, // Neutral score.
				'details' => [ 'message' => 'Judge evaluation disabled for this test' ],
			];
		}

		$rubric_id = $judge_config['rubric_id'] ?? 'wp-judge-rubric-v1';

		try {
			$rubric = $this->suite_loader->load_rubric( $rubric_id );
		} catch ( \InvalidArgumentException $e ) {
			return [
				'score'   => 0.5, // Neutral score if rubric not found.
				'details' => [ 'error' => $e->getMessage() ],
			];
		}

		// Apply any criterion weight overrides from test config.
		if ( ! empty( $judge_config['criteria_weights'] ) ) {
			foreach ( $rubric['criteria'] as &$criterion ) {
				$id = $criterion['id'] ?? '';
				if ( isset( $judge_config['criteria_weights'][ $id ] ) ) {
					$criterion['weight'] = $judge_config['criteria_weights'][ $id ];
				}
			}
			unset( $criterion );
		}

		return $this->judge->evaluate(
			code: $code,
			task: $test['prompt'],
			requirements: $test['requirements'] ?? [],
			rubric: $rubric,
			model: $judge_model,
			context: $judge_config['context_for_judge'] ?? null,
		);
	}

	/**
	 * Run only static and runtime checks (skip judge).
	 *
	 * Useful for faster iteration during development.
	 *
	 * @param array<string, mixed> $test  Test definition.
	 * @param string               $model Model identifier.
	 *
	 * @return AI_Bench_Test_Result
	 */
	public function execute_without_judge( array $test, string $model ): AI_Bench_Test_Result {
		$start = microtime( true );

		try {
			$prompt   = $this->build_prompt( $test );
			$response = $this->model_client->generate(
				prompt: $prompt,
				model: $model,
				temperature: 0.0,
			);

			$code = $this->extract_code( $response );

			if ( empty( $code ) ) {
				$result = AI_Bench_Test_Result::error_result(
					test_id: $test['id'],
					type: 'execution',
					error: 'No code extracted from model response',
					category: $test['category'] ?? '',
				);
				$result->set_duration_ms( ( microtime( true ) - $start ) * 1000 );
				return $result;
			}

			$static_result  = $this->run_static_checks( $code, $test );
			$runtime_result = $this->run_runtime_checks( $code, $test );

			$result = AI_Bench_Test_Result::execution_result(
				test_id: $test['id'],
				generated_code: $code,
				static_score: $static_result['score'],
				runtime_score: $runtime_result['score'],
				quality_score: 0.5, // Neutral.
				static_details: $static_result['details'],
				runtime_details: $runtime_result['details'],
				judge_details: [ 'message' => 'Judge evaluation skipped' ],
				category: $test['category'] ?? '',
			);

		} catch ( \Throwable $e ) {
			$result = AI_Bench_Test_Result::error_result(
				test_id: $test['id'],
				type: 'execution',
				error: $e->getMessage(),
				category: $test['category'] ?? '',
			);
		}

		$result->set_duration_ms( ( microtime( true ) - $start ) * 1000 );

		return $result;
	}
}
