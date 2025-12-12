<?php
/**
 * WP-CLI commands for AI benchmarking.
 *
 * @package WP_AI_Benchmarks
 *
 * @phpstan-type BenchmarkScores array{knowledge: float, execution_correctness: float, execution_quality: float, overall: float}
 * @phpstan-type BenchmarkMetadata array{duration_seconds: float, total_tests: int, knowledge_tests: int, execution_tests: int}
 * @phpstan-type CategoryScore array{score: float, count: int}
 * @phpstan-type BenchmarkResults array{suite: string, model: string, judge_model: string, runs: int, scores: BenchmarkScores, category_scores: array<string, CategoryScore>, metadata: BenchmarkMetadata, test_results: array<\WP_AI_Benchmarks\AI_Bench_Test_Result>}
 */

declare(strict_types=1);

namespace WP_AI_Benchmarks\CLI;

use WP_AI_Benchmarks\AI_Bench_Runner;
use WP_AI_Benchmarks\AI_Bench_Suite_Loader;
use WP_AI_Benchmarks\AI_Bench_Model_Client;
use WP_AI_Benchmarks\AI_Bench_Test_Result;
use WP_CLI;
use WP_CLI\Utils;

/**
 * Benchmark LLM performance on WordPress-specific tasks.
 *
 * ## EXAMPLES
 *
 *     # Run benchmark suite
 *     wp ai-bench run --suite=wp-core-v1 --model=openai:gpt-4.1
 *
 *     # List available suites
 *     wp ai-bench list
 *
 *     # Run single test for debugging
 *     wp ai-bench run-test k-hooks-001 --model=openai:gpt-4.1
 *
 * @package WP_AI_Benchmarks
 */
class AI_Bench_Command {

	/**
	 * Suite loader instance.
	 */
	private AI_Bench_Suite_Loader $suite_loader;

	/**
	 * Model client instance.
	 */
	private AI_Bench_Model_Client $model_client;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->suite_loader = new AI_Bench_Suite_Loader();
		$this->model_client = new AI_Bench_Model_Client();
	}

	/**
	 * Run AI benchmark suite.
	 *
	 * ## OPTIONS
	 *
	 * --suite=<suite>
	 * : Test suite to run (e.g., 'wp-core-v1').
	 *
	 * --model=<model>
	 * : Model to benchmark in 'provider:model' format (e.g., 'openai:gpt-4.1').
	 *
	 * [--judge-model=<model>]
	 * : Model for AI judge evaluation. Defaults to same as --model.
	 *
	 * [--runs=<n>]
	 * : Number of runs for statistical averaging. Default: 1.
	 *
	 * [--verbose]
	 * : Show detailed per-test results.
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, json. Default: table.
	 *
	 * [--skip-judge]
	 * : Skip AI judge evaluation (faster, but no quality scores).
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-bench run --suite=wp-core-v1 --model=openai:gpt-4.1
	 *
	 *     wp ai-bench run --suite=wp-core-v1 --model=openai:gpt-4.1 --judge-model=anthropic:claude-sonnet --runs=3
	 *
	 *     wp ai-bench run --suite=wp-core-v1 --model=openai:gpt-4.1 --format=json
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function run( array $args, array $assoc_args ): void {
		$suite       = $assoc_args['suite'] ?? null;
		$model       = $assoc_args['model'] ?? null;
		$judge_model = $assoc_args['judge-model'] ?? $model;
		$runs        = (int) ( $assoc_args['runs'] ?? 1 );
		$verbose     = Utils\get_flag_value( $assoc_args, 'verbose', false );
		$format      = $assoc_args['format'] ?? 'table';
		$skip_judge  = Utils\get_flag_value( $assoc_args, 'skip-judge', false );

		// Validate required arguments.
		if ( ! $suite || ! $model ) {
			WP_CLI::error( 'Required arguments: --suite and --model' );
		}

		// Validate runs.
		if ( $runs < 1 ) {
			WP_CLI::error( '--runs must be at least 1' );
		}

		// Check model availability.
		if ( ! $this->model_client->is_model_available( $model ) ) {
			WP_CLI::error(
				sprintf(
					"Model '%s' is not available. Check provider credentials in Settings > AI Credentials.",
					$model
				)
			);
		}

		if ( $judge_model !== $model && ! $skip_judge && ! $this->model_client->is_model_available( $judge_model ) ) {
			WP_CLI::error(
				sprintf( "Judge model '%s' is not available.", $judge_model )
			);
		}

		// Display configuration.
		WP_CLI::log( 'Starting benchmark...' );
		WP_CLI::log( sprintf( '  Suite: %s', $suite ) );
		WP_CLI::log( sprintf( '  Model: %s', $model ) );
		WP_CLI::log( sprintf( '  Judge: %s', $skip_judge ? '(skipped)' : $judge_model ) );
		WP_CLI::log( sprintf( '  Runs: %d', $runs ) );
		WP_CLI::log( '' );

		$runner = new AI_Bench_Runner(
			$this->suite_loader,
			$this->model_client,
		);

		$test_count = 0;

		// Progress callback.
		$progress_callback = function ( $result, $type, $run ) use ( $verbose, &$test_count ): void {
			++$test_count;

			if ( $verbose ) {
				$status = $result->has_error() ? 'ERROR' : 'OK';
				$score  = $type === 'knowledge'
					? $result->get_score()
					: $result->get_correctness_score();

				WP_CLI::log(
					sprintf(
						'  [Run %d] %s: %s (%.2f)',
						$run,
						$result->get_test_id(),
						$status,
						$score
					)
				);
			} else {
				// Simple progress indicator.
				WP_CLI::log( sprintf( '  Completed: %d tests...', $test_count ) );
			}
		};

		try {
			$results = $runner->run(
				suite_name: $suite,
				model: $model,
				judge_model: $judge_model,
				runs: $runs,
				progress_callback: $progress_callback,
			);

			if ( $format === 'json' ) {
				$this->display_json_results( $results );
			} else {
				$this->display_table_results( $results, $verbose );
			}

		} catch ( \Throwable $e ) {
			WP_CLI::error( 'Benchmark failed: ' . $e->getMessage() );
		}
	}

	/**
	 * List available test suites.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, json, csv. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-bench list
	 *
	 *     wp ai-bench list --format=json
	 *
	 * @subcommand list
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function list_suites( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';
		$suites = $this->suite_loader->list_suites();

		if ( empty( $suites ) ) {
			WP_CLI::warning( 'No test suites found.' );
			return;
		}

		$table_data = array_map(
			fn( $suite ) => [
				'name'            => $suite['name'],
				'types'           => implode( ', ', $suite['types'] ),
				'knowledge_tests' => $suite['knowledge_count'],
				'execution_tests' => $suite['execution_count'],
				'description'     => substr( $suite['description'] ?? '', 0, 50 ),
			],
			$suites
		);

		Utils\format_items(
			$format,
			$table_data,
			[ 'name', 'types', 'knowledge_tests', 'execution_tests', 'description' ]
		);
	}

	/**
	 * Run a single test by ID (for debugging).
	 *
	 * ## OPTIONS
	 *
	 * <test-id>
	 * : The test ID to run (e.g., 'k-hooks-001' or 'e-shortcode-001').
	 *
	 * --model=<model>
	 * : Model to use in 'provider:model' format.
	 *
	 * [--judge-model=<model>]
	 * : Model for AI judge (execution tests only). Defaults to --model.
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-bench run-test k-hooks-001 --model=openai:gpt-4.1
	 *
	 *     wp ai-bench run-test e-shortcode-001 --model=openai:gpt-4.1 --format=json
	 *
	 * @subcommand run-test
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function run_test( array $args, array $assoc_args ): void {
		$test_id     = $args[0] ?? null;
		$model       = $assoc_args['model'] ?? null;
		$judge_model = $assoc_args['judge-model'] ?? $model;
		$format      = $assoc_args['format'] ?? 'table';

		if ( ! $test_id || ! $model ) {
			WP_CLI::error( 'Required: <test-id> and --model' );
		}

		$runner = new AI_Bench_Runner(
			$this->suite_loader,
			$this->model_client,
		);

		try {
			WP_CLI::log( sprintf( 'Running test: %s', $test_id ) );
			WP_CLI::log( sprintf( 'Model: %s', $model ) );
			WP_CLI::log( '' );

			$result = $runner->run_single_test( $test_id, $model, $judge_model );

			if ( $format === 'json' ) {
				WP_CLI::log( wp_json_encode( $result->to_array(), JSON_PRETTY_PRINT ) );
			} else {
				$this->display_single_result_detailed( $result );
			}

		} catch ( \Throwable $e ) {
			WP_CLI::error( 'Test failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Show information about a specific test.
	 *
	 * ## OPTIONS
	 *
	 * <test-id>
	 * : The test ID to show.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-bench show-test k-hooks-001
	 *
	 * @subcommand show-test
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function show_test( array $args, array $assoc_args ): void {
		$test_id = $args[0] ?? null;

		if ( ! $test_id ) {
			WP_CLI::error( 'Required: <test-id>' );
		}

		try {
			$test = $this->suite_loader->find_test_by_id( $test_id );

			WP_CLI::log( sprintf( 'Test ID: %s', $test_id ) );
			WP_CLI::log( sprintf( 'Type: %s', $test['type'] ) );
			WP_CLI::log( sprintf( 'Suite: %s', $test['suite'] ) );
			WP_CLI::log( '' );
			WP_CLI::log( 'Definition:' );
			WP_CLI::log( wp_json_encode( $test['data'], JSON_PRETTY_PRINT ) );

		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Display results as JSON.
	 *
	 * @param array<string, mixed> $results Benchmark results.
	 */
	private function display_json_results( array $results ): void {
		// Convert test results to arrays.
		$results['test_results'] = array_map(
			fn( $r ) => $r->to_array(),
			$results['test_results']
		);

		WP_CLI::log( wp_json_encode( $results, JSON_PRETTY_PRINT ) );
	}

	/**
	 * Display results as formatted tables.
	 *
	 * @param array<string, mixed> $results Benchmark results.
	 * @param bool                 $verbose Show detailed per-test results.
	 */
	private function display_table_results( array $results, bool $verbose ): void {
		WP_CLI::log( '' );
		WP_CLI::log( '========================================' );
		WP_CLI::log( 'BENCHMARK RESULTS' );
		WP_CLI::log( '========================================' );
		WP_CLI::log( '' );

		// Metadata.
		WP_CLI::log( sprintf( 'Suite: %s', $results['suite'] ) );
		WP_CLI::log( sprintf( 'Model: %s', $results['model'] ) );
		WP_CLI::log( sprintf( 'Judge: %s', $results['judge_model'] ) );
		WP_CLI::log( sprintf( 'Runs: %d', $results['runs'] ) );
		WP_CLI::log( sprintf( 'Duration: %.2fs', $results['metadata']['duration_seconds'] ) );
		WP_CLI::log( sprintf( 'Tests: %d total (%d knowledge, %d execution)',
			$results['metadata']['total_tests'],
			$results['metadata']['knowledge_tests'],
			$results['metadata']['execution_tests']
		) );
		WP_CLI::log( '' );

		// Main scores.
		WP_CLI::log( 'SCORES:' );
		WP_CLI::log( sprintf( '  Knowledge Score:            %.4f', $results['scores']['knowledge'] ) );
		WP_CLI::log( sprintf( '  Execution Correctness:      %.4f', $results['scores']['execution_correctness'] ) );
		WP_CLI::log( sprintf( '  Execution Quality:          %.4f', $results['scores']['execution_quality'] ) );
		WP_CLI::log( '  ----------------------------------------' );
		WP_CLI::log( sprintf( '  OVERALL SCORE:              %.4f', $results['scores']['overall'] ) );
		WP_CLI::log( '' );

		// Category breakdown.
		if ( ! empty( $results['category_scores'] ) ) {
			WP_CLI::log( 'CATEGORY BREAKDOWN:' );
			foreach ( $results['category_scores'] as $category => $data ) {
				WP_CLI::log( sprintf(
					'  %-20s %.4f (%d tests)',
					$category . ':',
					$data['score'],
					$data['count']
				) );
			}
			WP_CLI::log( '' );
		}

		// Verbose per-test results.
		if ( $verbose ) {
			WP_CLI::log( 'DETAILED RESULTS:' );
			WP_CLI::log( '' );

			foreach ( $results['test_results'] as $result ) {
				$this->display_single_result_brief( $result );
			}
		}

		// Error summary.
		$errors = array_filter( $results['test_results'], fn( $r ) => $r->has_error() );
		if ( ! empty( $errors ) ) {
			WP_CLI::warning( sprintf( '%d test(s) had errors. Use --verbose for details.', count( $errors ) ) );
		}
	}

	/**
	 * Display brief single result.
	 *
	 * @param AI_Bench_Test_Result $result Test result.
	 */
	private function display_single_result_brief( AI_Bench_Test_Result $result ): void {
		$id   = $result->get_test_id();
		$type = $result->get_type();

		if ( $result->has_error() ) {
			WP_CLI::log( sprintf( '  [%s] ERROR: %s', $id, $result->get_error() ) );
			return;
		}

		if ( $type === 'knowledge' ) {
			$score  = $result->get_score();
			$status = $score >= 1.0 ? 'PASS' : 'FAIL';
			WP_CLI::log( sprintf( '  [%s] %s - Score: %.2f', $id, $status, $score ) );
			WP_CLI::log( sprintf( '    Answer: %s (Expected: %s)',
				$result->get_model_answer(),
				$result->get_correct_answer()
			) );
		} else {
			$data = $result->to_array();
			WP_CLI::log( sprintf( '  [%s]', $id ) );
			WP_CLI::log( sprintf( '    Static:  %.2f', $data['static_score'] ) );
			WP_CLI::log( sprintf( '    Runtime: %.2f', $data['runtime_score'] ) );
			WP_CLI::log( sprintf( '    Quality: %.2f', $data['quality_score'] ) );
		}
		WP_CLI::log( '' );
	}

	/**
	 * Display detailed single result.
	 *
	 * @param AI_Bench_Test_Result $result Test result.
	 */
	private function display_single_result_detailed( AI_Bench_Test_Result $result ): void {
		WP_CLI::log( sprintf( 'Test ID: %s', $result->get_test_id() ) );
		WP_CLI::log( sprintf( 'Type: %s', $result->get_type() ) );
		WP_CLI::log( sprintf( 'Category: %s', $result->get_category() ) );
		WP_CLI::log( sprintf( 'Duration: %.2fms', $result->get_duration_ms() ) );
		WP_CLI::log( '' );

		if ( $result->has_error() ) {
			WP_CLI::error( sprintf( 'Error: %s', $result->get_error() ), false );
			return;
		}

		if ( $result->get_type() === 'knowledge' ) {
			WP_CLI::log( sprintf( 'Score: %.2f', $result->get_score() ) );
			WP_CLI::log( sprintf( 'Model Answer: %s', $result->get_model_answer() ) );
			WP_CLI::log( sprintf( 'Correct Answer: %s', $result->get_correct_answer() ) );
		} else {
			$data = $result->to_array();

			WP_CLI::log( 'SCORES:' );
			WP_CLI::log( sprintf( '  Static Score:      %.4f', $data['static_score'] ) );
			WP_CLI::log( sprintf( '  Runtime Score:     %.4f', $data['runtime_score'] ) );
			WP_CLI::log( sprintf( '  Quality Score:     %.4f', $data['quality_score'] ) );
			WP_CLI::log( sprintf( '  Correctness Score: %.4f', $data['correctness_score'] ) );
			WP_CLI::log( '' );

			WP_CLI::log( 'GENERATED CODE:' );
			WP_CLI::log( '```php' );
			WP_CLI::log( $result->get_generated_code() );
			WP_CLI::log( '```' );
			WP_CLI::log( '' );

			if ( ! empty( $data['static_details'] ) ) {
				WP_CLI::log( 'STATIC CHECK DETAILS:' );
				WP_CLI::log( wp_json_encode( $data['static_details'], JSON_PRETTY_PRINT ) );
				WP_CLI::log( '' );
			}

			if ( ! empty( $data['runtime_details'] ) ) {
				WP_CLI::log( 'RUNTIME CHECK DETAILS:' );
				WP_CLI::log( wp_json_encode( $data['runtime_details'], JSON_PRETTY_PRINT ) );
				WP_CLI::log( '' );
			}

			if ( ! empty( $data['judge_details'] ) ) {
				WP_CLI::log( 'JUDGE EVALUATION:' );
				WP_CLI::log( wp_json_encode( $data['judge_details'], JSON_PRETTY_PRINT ) );
			}
		}
	}
}
