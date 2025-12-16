<?php
/**
 * Execution test executor.
 *
 * @package WordPress\AI_Benchmark
 */

declare(strict_types=1);

namespace WordPress\AI_Benchmark;

use WordPress\AI_Benchmark\Result\Test_Result;
use WordPress\AI_Benchmark\Test\Execution_Test;
use WordPress\AI_Benchmark\Test\Enum\Test_Type;

/**
 * Executes HumanEval-style code generation tests with three-layer evaluation.
 *
 * Layer 1: Static checks (regex patterns)
 * Layer 2: Runtime checks (execute and verify)
 * Layer 3: AI judge (quality evaluation)
 */
class Executor_Execution {

	/**
	 * Model client for AI requests.
	 *
	 * @var Model_Client
	 */
	private Model_Client $model_client;

	/**
	 * Static code checker.
	 *
	 * @var Static_Checker
	 */
	private Static_Checker $static_checker;

	/**
	 * Runtime environment.
	 *
	 * @var Environment
	 */
	private Environment $environment;

	/**
	 * AI judge for quality evaluation.
	 *
	 * @var Judge
	 */
	private Judge $judge;

	/**
	 * Suite loader for rubrics.
	 *
	 * @var Suite_Loader
	 */
	private Suite_Loader $suite_loader;

	/**
	 * Constructor.
	 *
	 * @param Model_Client        $model_client   Model client instance.
	 * @param Static_Checker|null $static_checker Static checker instance.
	 * @param Environment|null    $environment    Environment instance.
	 * @param Judge|null          $judge          Judge instance.
	 * @param Suite_Loader|null   $suite_loader   Suite loader instance.
	 */
	public function __construct(
		Model_Client $model_client,
		?Static_Checker $static_checker = null,
		?Environment $environment = null,
		?Judge $judge = null,
		?Suite_Loader $suite_loader = null,
	) {
		$this->model_client   = $model_client;
		$this->static_checker = $static_checker ?? new Static_Checker();
		$this->environment    = $environment ?? new Environment();
		$this->judge          = $judge ?? new Judge( $model_client );
		$this->suite_loader   = $suite_loader ?? new Suite_Loader();
	}

	/**
	 * Execute an execution test with three-layer evaluation.
	 *
	 * @param Execution_Test $test        Test definition.
	 * @param string         $model       Model identifier for code generation.
	 * @param string         $judge_model Model identifier for judge evaluation.
	 *
	 * @return Test_Result
	 */
	public function execute( Execution_Test $test, string $model, string $judge_model ): Test_Result {
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
				$result = Test_Result::error_result(
					test_id: $test->get_id(),
					type: Test_Type::EXECUTION,
					error: 'No code extracted from model response',
					category: $test->get_category(),
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

			$result = Test_Result::execution_result(
				test_id: $test->get_id(),
				generated_code: $code,
				static_score: $static_result['score'],
				runtime_score: $runtime_result['score'],
				quality_score: $judge_result['score'],
				static_details: $static_result['details'],
				runtime_details: $runtime_result['details'],
				judge_details: $judge_result['details'],
				category: $test->get_category(),
			);

		} catch ( \Throwable $e ) {
			$result = Test_Result::error_result(
				test_id: $test->get_id(),
				type: Test_Type::EXECUTION,
				error: $e->getMessage(),
				category: $test->get_category(),
			);
		}

		$result->set_duration_ms( ( microtime( true ) - $start ) * 1000 );

		return $result;
	}

	/**
	 * Build the prompt for code generation.
	 *
	 * @param Execution_Test $test Test definition.
	 *
	 * @return string Complete prompt.
	 */
	private function build_prompt( Execution_Test $test ): string {
		$prompt = 'You are an expert WordPress developer. ' . $test->get_prompt();

		$requirements = $test->get_requirements();
		if ( ! empty( $requirements ) ) {
			$prompt .= "\n\nRequirements:\n";
			foreach ( $requirements as $req ) {
				$prompt .= "- {$req}\n";
			}
		}

		$existing_code = $test->get_existing_code();
		if ( null !== $existing_code ) {
			$prompt .= "\n\nExisting code context:\n```php\n{$existing_code}\n```";
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
	 * @param string         $code Generated code.
	 * @param Execution_Test $test Test definition.
	 *
	 * @return array{score: float, details: array<string, mixed>}
	 */
	private function run_static_checks( string $code, Execution_Test $test ): array {
		$checks = $test->get_static_checks();

		if ( ! $test->has_static_checks() || null === $checks ) {
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
	 * @param string         $code Generated code.
	 * @param Execution_Test $test Test definition.
	 *
	 * @return array{score: float, details: array<string, mixed>}
	 */
	private function run_runtime_checks( string $code, Execution_Test $test ): array {
		$checks = $test->get_runtime_checks();

		if ( ! $test->has_runtime_checks() || null === $checks ) {
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
	 * @param string         $code        Generated code.
	 * @param Execution_Test $test        Test definition.
	 * @param string         $judge_model Judge model identifier.
	 *
	 * @return array{score: float, details: array<string, mixed>}
	 */
	private function run_judge_evaluation( string $code, Execution_Test $test, string $judge_model ): array {
		// Check if judging is disabled for this test.
		if ( ! $test->is_judge_enabled() ) {
			return [
				'score'   => 0.5, // Neutral score.
				'details' => [ 'message' => 'Judge evaluation disabled for this test' ],
			];
		}

		$rubric_id = $test->get_rubric_id();

		try {
			$rubric = $this->suite_loader->load_rubric( $rubric_id );
		} catch ( \InvalidArgumentException $e ) {
			return [
				'score'   => 0.5, // Neutral score if rubric not found.
				'details' => [ 'error' => $e->getMessage() ],
			];
		}

		// Apply any criterion weight overrides from test config.
		$judge_config     = $test->get_judge_config();
		$criteria_weights = $judge_config['criteria_weights'] ?? null;
		if ( null !== $judge_config && is_array( $criteria_weights ) && ! empty( $criteria_weights ) && isset( $rubric['criteria'] ) && is_array( $rubric['criteria'] ) ) {
			/** @var array<int, array{id?: string, weight?: float}> $criteria */
			$criteria = $rubric['criteria'];
			foreach ( $criteria as $index => $criterion ) {
				$id = $criterion['id'] ?? '';
				if ( isset( $criteria_weights[ $id ] ) ) {
					$rubric['criteria'][ $index ]['weight'] = $criteria_weights[ $id ];
				}
			}
		}

		return $this->judge->evaluate(
			code: $code,
			task: $test->get_prompt(),
			requirements: $test->get_requirements(),
			rubric: $rubric,
			model: $judge_model,
			context: $test->get_judge_context(),
		);
	}

	/**
	 * Run only static and runtime checks (skip judge).
	 *
	 * Useful for faster iteration during development.
	 *
	 * @param Execution_Test $test  Test definition.
	 * @param string         $model Model identifier.
	 *
	 * @return Test_Result
	 */
	public function execute_without_judge( Execution_Test $test, string $model ): Test_Result {
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
				$result = Test_Result::error_result(
					test_id: $test->get_id(),
					type: Test_Type::EXECUTION,
					error: 'No code extracted from model response',
					category: $test->get_category(),
				);
				$result->set_duration_ms( ( microtime( true ) - $start ) * 1000 );
				return $result;
			}

			$static_result  = $this->run_static_checks( $code, $test );
			$runtime_result = $this->run_runtime_checks( $code, $test );

			$result = Test_Result::execution_result(
				test_id: $test->get_id(),
				generated_code: $code,
				static_score: $static_result['score'],
				runtime_score: $runtime_result['score'],
				quality_score: 0.5, // Neutral.
				static_details: $static_result['details'],
				runtime_details: $runtime_result['details'],
				judge_details: [ 'message' => 'Judge evaluation skipped' ],
				category: $test->get_category(),
			);

		} catch ( \Throwable $e ) {
			$result = Test_Result::error_result(
				test_id: $test->get_id(),
				type: Test_Type::EXECUTION,
				error: $e->getMessage(),
				category: $test->get_category(),
			);
		}

		$result->set_duration_ms( ( microtime( true ) - $start ) * 1000 );

		return $result;
	}
}
