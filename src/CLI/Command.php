<?php
/**
 * WP-CLI commands for AI benchmarking.
 *
 * @package WordPress\AI_Benchmark
 *
 * @phpstan-type BenchmarkScores array{knowledge: float, execution_correctness: float, execution_quality: float, overall: float}
 * @phpstan-type BenchmarkMetadata array{duration_seconds: float, total_tests: int, knowledge_tests: int, execution_tests: int, wp_version: string, php_version: string, benchmark_version: string}
 * @phpstan-type CategoryTypeScore array{score: float, count: int}
 * @phpstan-type CategoryScore array{knowledge: CategoryTypeScore, execution: CategoryTypeScore, total: CategoryTypeScore}
 * @phpstan-type BenchmarkResults array{suite: string, model: string, judge_model: string, runs: int, scores: BenchmarkScores, category_scores: array<string, CategoryScore>, stats: array<string, array{mean: float, stddev: float, min: float, max: float, runs: int}>, metadata: BenchmarkMetadata, test_results: list<Test_Result>}
 */

declare(strict_types=1);

namespace WordPress\AI_Benchmark\CLI;

use WordPress\AI_Benchmark\Runner;
use WordPress\AI_Benchmark\Suite_Loader;
use WordPress\AI_Benchmark\Model_Client;
use WordPress\AI_Benchmark\Result\Test_Result;
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
 * @package WordPress\AI_Benchmark
 */
class Command {

	/**
	 * Suite loader instance.
	 *
	 * @var Suite_Loader
	 */
	private Suite_Loader $suite_loader;

	/**
	 * Model client instance.
	 *
	 * @var Model_Client
	 */
	private Model_Client $model_client;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->suite_loader = new Suite_Loader();
		$this->model_client = new Model_Client();
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
	 * [--concurrency=<n>]
	 * : Number of parallel test workers. Default: 5.
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
		$verbose     = (bool) Utils\get_flag_value( $assoc_args, 'verbose', false );
		$format      = $assoc_args['format'] ?? 'table';
		$skip_judge  = (bool) Utils\get_flag_value( $assoc_args, 'skip-judge', false );
		$concurrency = (int) ( $assoc_args['concurrency'] ?? 5 );

		// Validate required arguments.
		if ( ! $suite || ! $model ) {
			WP_CLI::error( 'Required arguments: --suite and --model' );
		}

		// $judge_model defaults to $model, so it's guaranteed non-null after validation.
		$judge_model = $judge_model ?? $model;

		// Validate runs.
		if ( $runs < 1 ) {
			WP_CLI::error( '--runs must be at least 1' );
		}

		// Validate concurrency.
		if ( $concurrency < 1 ) {
			WP_CLI::error( '--concurrency must be at least 1' );
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
		WP_CLI::log( sprintf( '  Concurrency: %d', $concurrency ) );
		WP_CLI::log( '' );

		$runner = new Runner(
			$this->suite_loader,
			$this->model_client,
		);

		$test_count = 0;

		// Progress callback.
		$progress_callback = function ( $result, $type, $run ) use ( $verbose, &$test_count ): void {
			++$test_count;

			if ( $verbose ) {
				$status = $result->has_error() ? 'ERROR' : 'OK';
				$score  = 'knowledge' === $type
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
				concurrency: $concurrency,
			);

			if ( 'json' === $format ) {
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
			static fn( array $suite ): array => [
				'name'            => $suite['name'],
				'types'           => implode( ', ', $suite['types'] ),
				'knowledge_tests' => $suite['knowledge_count'],
				'execution_tests' => $suite['execution_count'],
				'description'     => substr( $suite['description'], 0, 50 ),
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

		// $judge_model defaults to $model, so it's guaranteed non-null after validation.
		$judge_model = $judge_model ?? $model;

		$runner = new Runner(
			$this->suite_loader,
			$this->model_client,
		);

		try {
			// Only show header for non-JSON output.
			if ( 'json' !== $format ) {
				WP_CLI::log( sprintf( 'Running test: %s', $test_id ) );
				WP_CLI::log( sprintf( 'Model: %s', $model ) );
				WP_CLI::log( '' );
			}

			$result = $runner->run_single_test( $test_id, $model, $judge_model );

			if ( 'json' === $format ) {
				// Output only JSON for machine parsing (used by parallel runner).
				WP_CLI::line( $this->json_encode( $result->to_array() ) );
			} else {
				$this->display_single_result_detailed( $result );
			}
		} catch ( \Throwable $e ) {
			if ( 'json' === $format ) {
				// Output error as JSON for machine parsing.
				WP_CLI::line(
					$this->json_encode(
						[
							'test_id' => $test_id,
							'error'   => $e->getMessage(),
						]
					)
				);
				exit( 1 );
			}
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
	 * @param array<string, string> $assoc_args Associative arguments (unused).
	 */
	public function show_test( array $args, array $assoc_args ): void {
		unset( $assoc_args ); // Unused but required by WP-CLI signature.
		$test_id = $args[0] ?? null;

		if ( ! $test_id ) {
			WP_CLI::error( 'Required: <test-id>' );
		}

		try {
			$test_info = $this->suite_loader->find_test_by_id( $test_id );

			WP_CLI::log( sprintf( 'Test ID: %s', $test_id ) );
			WP_CLI::log( sprintf( 'Type: %s', $test_info['type'] ) );
			WP_CLI::log( sprintf( 'Suite: %s', $test_info['suite'] ) );
			WP_CLI::log( '' );
			WP_CLI::log( 'Definition:' );
			WP_CLI::log( $this->json_encode( $test_info['test']->to_array(), JSON_PRETTY_PRINT ) );

		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Display results as JSON.
	 *
	 * @phpstan-param array{suite: string, model: string, judge_model: string, runs: int, scores: array{knowledge: float, execution_correctness: float, execution_quality: float, overall: float}, category_scores: array<string, array{knowledge: array{score: float, count: int}, execution: array{score: float, count: int}, total: array{score: float, count: int}}>, metadata: array{duration_seconds: float, total_tests: int, knowledge_tests: int, execution_tests: int, wp_version: string, php_version: string, benchmark_version: string}, test_results: list<Test_Result>} $results
	 *
	 * @param array<string, mixed> $results Benchmark results from Runner::run().
	 */
	private function display_json_results( array $results ): void {
		// Convert test results to arrays for JSON output.
		$output                 = $results;
		$output['test_results'] = array_map(
			static fn( Test_Result $r ): array => $r->to_array(),
			$results['test_results']
		);

		WP_CLI::log( $this->json_encode( $output, JSON_PRETTY_PRINT ) );
	}

	/**
	 * Display results as formatted tables.
	 *
	 * @phpstan-param array{suite: string, model: string, judge_model: string, runs: int, scores: array{knowledge: float, execution_correctness: float, execution_quality: float, overall: float}, category_scores: array<string, array{knowledge: array{score: float, count: int}, execution: array{score: float, count: int}, total: array{score: float, count: int}}>, metadata: array{duration_seconds: float, total_tests: int, knowledge_tests: int, execution_tests: int, wp_version: string, php_version: string, benchmark_version: string}, test_results: list<Test_Result>} $results
	 *
	 * @param array<string, mixed> $results Benchmark results from Runner::run().
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
		WP_CLI::log(
			sprintf(
				'Tests: %d total (%d knowledge, %d execution)',
				$results['metadata']['total_tests'],
				$results['metadata']['knowledge_tests'],
				$results['metadata']['execution_tests']
			)
		);
		WP_CLI::log( '' );

		// Scores table with category breakdown.
		WP_CLI::log( 'SCORES:' );
		WP_CLI::log( '' );
		WP_CLI::log( sprintf( '  %-16s %14s %14s %14s', 'Category', 'Knowledge', 'Execution', 'Total' ) );
		WP_CLI::log( '  ' . str_repeat( '-', 60 ) );

		if ( ! empty( $results['category_scores'] ) ) {
			foreach ( $results['category_scores'] as $category => $data ) {
				$knowledge_str = $this->format_category_score( $data['knowledge'] );
				$execution_str = $this->format_category_score( $data['execution'] );
				$total_str     = $this->format_category_score( $data['total'] );

				WP_CLI::log(
					sprintf(
						'  %-16s %14s %14s %14s',
						$category,
						$knowledge_str,
						$execution_str,
						$total_str
					)
				);
			}
		}

		// Totals row.
		WP_CLI::log( '' );
		WP_CLI::log(
			sprintf(
				'  %-16s %14s %14s %14s',
				'TOTAL',
				sprintf( '%.2f (%d)', $results['scores']['knowledge'], $results['metadata']['knowledge_tests'] ),
				sprintf( '%.2f (%d)', $results['scores']['execution_correctness'], $results['metadata']['execution_tests'] ),
				sprintf( '%.2f (%d)', $results['scores']['overall'], $results['metadata']['total_tests'] )
			)
		);
		WP_CLI::log( '' );

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
		if ( ! empty( $errors ) && ! $verbose ) {
			WP_CLI::warning( sprintf( '%d test(s) had errors. Use --verbose for details.', count( $errors ) ) );
		}
	}

	/**
	 * Display brief single result.
	 *
	 * @param Test_Result $result Test result.
	 */
	private function display_single_result_brief( Test_Result $result ): void {
		$id   = $result->get_test_id();
		$type = $result->get_type();

		if ( $result->has_error() ) {
			WP_CLI::log( sprintf( '  [%s] ERROR: %s', $id, $result->get_error() ?? 'Unknown error' ) );
			return;
		}

		if ( 'knowledge' === $type ) {
			$score  = $result->get_score();
			$status = $score >= 1.0 ? 'PASS' : 'FAIL';
			WP_CLI::log( sprintf( '  [%s] %s - Score: %.2f', $id, $status, $score ) );
			WP_CLI::log(
				sprintf(
					'    Answer: %s (Expected: %s)',
					$result->get_model_answer() ?? '',
					$result->get_correct_answer() ?? ''
				)
			);
		} else {
			WP_CLI::log( sprintf( '  [%s]', $id ) );
			WP_CLI::log( sprintf( '    Static:  %.2f', $result->get_static_score() ?? 0.0 ) );
			WP_CLI::log( sprintf( '    Runtime: %.2f', $result->get_runtime_score() ?? 0.0 ) );
			WP_CLI::log( sprintf( '    Quality: %.2f', $result->get_quality_score() ) );
		}
		WP_CLI::log( '' );
	}

	/**
	 * Display detailed single result.
	 *
	 * @param Test_Result $result Test result.
	 */
	private function display_single_result_detailed( Test_Result $result ): void {
		WP_CLI::log( sprintf( 'Test ID: %s', $result->get_test_id() ) );
		WP_CLI::log( sprintf( 'Type: %s', $result->get_type() ) );
		WP_CLI::log( sprintf( 'Category: %s', $result->get_category() ) );
		WP_CLI::log( sprintf( 'Duration: %.2fms', $result->get_duration_ms() ) );
		WP_CLI::log( '' );

		if ( $result->has_error() ) {
			WP_CLI::warning( sprintf( 'Error: %s', $result->get_error() ?? 'Unknown error' ) );
			return;
		}

		if ( 'knowledge' === $result->get_type() ) {
			WP_CLI::log( sprintf( 'Score: %.2f', $result->get_score() ) );
			WP_CLI::log( sprintf( 'Model Answer: %s', $result->get_model_answer() ?? '' ) );
			WP_CLI::log( sprintf( 'Correct Answer: %s', $result->get_correct_answer() ?? '' ) );
		} else {
			WP_CLI::log( 'SCORES:' );
			WP_CLI::log( sprintf( '  Static Score:      %.4f', $result->get_static_score() ?? 0.0 ) );
			WP_CLI::log( sprintf( '  Runtime Score:     %.4f', $result->get_runtime_score() ?? 0.0 ) );
			WP_CLI::log( sprintf( '  Quality Score:     %.4f', $result->get_quality_score() ) );
			WP_CLI::log( sprintf( '  Correctness Score: %.4f', $result->get_correctness_score() ) );
			WP_CLI::log( '' );

			WP_CLI::log( 'GENERATED CODE:' );
			WP_CLI::log( '```php' );
			WP_CLI::log( $result->get_generated_code() ?? '' );
			WP_CLI::log( '```' );
			WP_CLI::log( '' );

			$static_details = $result->get_static_details();
			if ( ! empty( $static_details ) ) {
				WP_CLI::log( 'STATIC CHECK DETAILS:' );
				WP_CLI::log( $this->json_encode( $static_details, JSON_PRETTY_PRINT ) );
				WP_CLI::log( '' );
			}

			$runtime_details = $result->get_runtime_details();
			if ( ! empty( $runtime_details ) ) {
				WP_CLI::log( 'RUNTIME CHECK DETAILS:' );
				WP_CLI::log( $this->json_encode( $runtime_details, JSON_PRETTY_PRINT ) );
				WP_CLI::log( '' );
			}

			$judge_details = $result->get_judge_details();
			if ( ! empty( $judge_details ) ) {
				WP_CLI::log( 'JUDGE EVALUATION:' );
				WP_CLI::log( $this->json_encode( $judge_details, JSON_PRETTY_PRINT ) );
			}
		}
	}

	/**
	 * Format a category score for table display.
	 *
	 * @param array{score: float, count: int} $data Score data with score and count.
	 *
	 * @return string Formatted string like "0.85 (3)" or "-" if no tests.
	 */
	private function format_category_score( array $data ): string {
		if ( 0 === $data['count'] ) {
			return '-';
		}

		return sprintf( '%.2f (%d)', $data['score'], $data['count'] );
	}

	/**
	 * Encode data as JSON string.
	 *
	 * @param mixed $data  Data to encode.
	 * @param int   $flags JSON encode flags.
	 *
	 * @return string JSON string or error message.
	 */
	private function json_encode( mixed $data, int $flags = 0 ): string {
		$json = wp_json_encode( $data, $flags );
		return false !== $json ? $json : '{"error": "JSON encoding failed"}';
	}
}
