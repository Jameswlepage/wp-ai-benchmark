<?php
/**
 * Benchmark runner.
 *
 * @package WordPress\AI_Benchmark
 */

declare(strict_types=1);

namespace WordPress\AI_Benchmark;

use WordPress\AI_Benchmark\Result\Test_Result;
use WordPress\AI_Benchmark\Test\Execution_Test;
use WordPress\AI_Benchmark\Test\Knowledge_Test;

/**
 * Orchestrates benchmark execution across test suites.
 *
 * Coordinates knowledge and execution tests, aggregates scores,
 * and manages multiple runs for statistical averaging.
 */
class Runner {

	/**
	 * Suite loader.
	 *
	 * @var Suite_Loader
	 */
	private Suite_Loader $suite_loader;

	/**
	 * Knowledge test executor.
	 *
	 * @var Executor_Knowledge
	 */
	private Executor_Knowledge $knowledge_executor;

	/**
	 * Execution test executor.
	 *
	 * @var Executor_Execution
	 */
	private Executor_Execution $execution_executor;

	/**
	 * Score weights for overall calculation.
	 *
	 * @var array<string, float>
	 */
	private array $score_weights = [
		'knowledge'             => 0.3,
		'execution_correctness' => 0.4,
		'execution_quality'     => 0.3,
	];

	/**
	 * Constructor.
	 *
	 * @param Suite_Loader            $suite_loader        Suite loader instance.
	 * @param Model_Client            $model_client        Model client instance.
	 * @param Executor_Knowledge|null $knowledge_executor  Knowledge executor.
	 * @param Executor_Execution|null $execution_executor  Execution executor.
	 */
	public function __construct(
		Suite_Loader $suite_loader,
		Model_Client $model_client,
		?Executor_Knowledge $knowledge_executor = null,
		?Executor_Execution $execution_executor = null,
	) {
		$this->suite_loader       = $suite_loader;
		$this->knowledge_executor = $knowledge_executor ?? new Executor_Knowledge( $model_client );
		$this->execution_executor = $execution_executor ?? new Executor_Execution( $model_client );
	}

	/**
	 * Run a complete benchmark suite.
	 *
	 * @param string        $suite_name        Suite identifier (e.g., 'wp-core-v1').
	 * @param string        $model             Model identifier (e.g., 'openai:gpt-4.1').
	 * @param string        $judge_model       Judge model identifier.
	 * @param int           $runs              Number of runs for averaging.
	 * @param callable|null $progress_callback Called after each test with (result, type, run).
	 * @param int           $concurrency       Number of parallel workers (1 = sequential).
	 *
	 * @return array{
	 *   suite: string,
	 *   model: string,
	 *   judge_model: string,
	 *   runs: int,
	 *   scores: array{knowledge: float, execution_correctness: float, execution_quality: float, overall: float},
	 *   category_scores: array<string, array{knowledge: array{score: float, count: int}, execution: array{score: float, count: int}, total: array{score: float, count: int}}>,
	 *   stats: array<string, array{mean: float, stddev: float, min: float, max: float, runs: int}>,
	 *   test_results: array<Test_Result>,
	 *   metadata: array{duration_seconds: float, total_tests: int, knowledge_tests: int, execution_tests: int, wp_version: string, php_version: string, benchmark_version: string}
	 * }
	 */
	public function run(
		string $suite_name,
		string $model,
		string $judge_model,
		int $runs = 1,
		?callable $progress_callback = null,
		int $concurrency = 1,
	): array {
		$suite       = $this->suite_loader->load( $suite_name );
		$all_results = [];
		$start_time  = microtime( true );

		// Use parallel execution if concurrency > 1.
		if ( $concurrency > 1 ) {
			$parallel_runner = new Parallel_Runner( $concurrency );

			for ( $run = 1; $run <= $runs; $run++ ) {
				$run_results = $parallel_runner->run(
					$suite['knowledge_tests'],
					$suite['execution_tests'],
					$model,
					$judge_model,
					$run,
					$progress_callback
				);
				$all_results = array_merge( $all_results, $run_results );
			}
		} else {
			// Sequential execution.
			for ( $run = 1; $run <= $runs; $run++ ) {
				// Run knowledge tests.
				foreach ( $suite['knowledge_tests'] as $test ) {
					$result = $this->knowledge_executor->execute( $test, $model );
					$result->set_run_number( $run );
					$all_results[] = $result;

					if ( $progress_callback ) {
						$progress_callback( $result, 'knowledge', $run );
					}
				}

				// Run execution tests.
				foreach ( $suite['execution_tests'] as $test ) {
					$result = $this->execution_executor->execute( $test, $model, $judge_model );
					$result->set_run_number( $run );
					$all_results[] = $result;

					if ( $progress_callback ) {
						$progress_callback( $result, 'execution', $run );
					}
				}
			}
		}

		$scores          = $this->calculate_aggregate_scores( $all_results );
		$category_scores = $this->calculate_category_scores( $all_results );
		$stats           = $runs > 1 ? $this->calculate_stats( $all_results, $runs ) : [];

		return [
			'suite'           => $suite_name,
			'model'           => $model,
			'judge_model'     => $judge_model,
			'runs'            => $runs,
			'scores'          => $scores,
			'category_scores' => $category_scores,
			'stats'           => $stats,
			'test_results'    => $all_results,
			'metadata'        => [
				'duration_seconds'  => microtime( true ) - $start_time,
				'total_tests'       => count( $all_results ),
				'knowledge_tests'   => count( $suite['knowledge_tests'] ),
				'execution_tests'   => count( $suite['execution_tests'] ),
				'wp_version'        => get_bloginfo( 'version' ),
				'php_version'       => PHP_VERSION,
				'benchmark_version' => WP_AI_BENCH_VERSION,
			],
		];
	}

	/**
	 * Run a single test by ID (for debugging).
	 *
	 * @param string $test_id     Test identifier.
	 * @param string $model       Model identifier.
	 * @param string $judge_model Judge model identifier.
	 *
	 * @return Test_Result
	 */
	public function run_single_test(
		string $test_id,
		string $model,
		string $judge_model,
	): Test_Result {
		$test_info = $this->suite_loader->find_test_by_id( $test_id );

		if ( 'knowledge' === $test_info['type'] ) {
			// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Type hint.
			/** @var Knowledge_Test $test */
			$test = $test_info['test'];
			return $this->knowledge_executor->execute( $test, $model );
		}

		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Type hint.
		/** @var Execution_Test $test */
		$test = $test_info['test'];
		return $this->execution_executor->execute( $test, $model, $judge_model );
	}

	/**
	 * Calculate aggregate scores from all test results.
	 *
	 * @param array<Test_Result> $results Test results.
	 *
	 * @return array{knowledge: float, execution_correctness: float, execution_quality: float, overall: float}
	 */
	private function calculate_aggregate_scores( array $results ): array {
		$knowledge_scores   = [];
		$correctness_scores = [];
		$quality_scores     = [];

		foreach ( $results as $result ) {
			if ( $result->get_type() === 'knowledge' ) {
				$knowledge_scores[] = $result->get_score();
			} else {
				$correctness_scores[] = $result->get_correctness_score();
				$quality_scores[]     = $result->get_quality_score();
			}
		}

		$knowledge_avg   = $this->safe_average( $knowledge_scores );
		$correctness_avg = $this->safe_average( $correctness_scores );
		$quality_avg     = $this->safe_average( $quality_scores );

		// Calculate weighted overall score.
		$overall = (
			$knowledge_avg * $this->score_weights['knowledge'] +
			$correctness_avg * $this->score_weights['execution_correctness'] +
			$quality_avg * $this->score_weights['execution_quality']
		);

		return [
			'knowledge'             => round( $knowledge_avg, 4 ),
			'execution_correctness' => round( $correctness_avg, 4 ),
			'execution_quality'     => round( $quality_avg, 4 ),
			'overall'               => round( $overall, 4 ),
		];
	}

	/**
	 * Calculate scores broken down by category, with knowledge/execution split.
	 *
	 * @param array<Test_Result> $results Test results.
	 *
	 * @return array<string, array{knowledge: array{score: float, count: int}, execution: array{score: float, count: int}, total: array{score: float, count: int}}>
	 */
	private function calculate_category_scores( array $results ): array {
		$categories = [];

		foreach ( $results as $result ) {
			$category_val = $result->get_category();
			$category     = '' !== $category_val ? $category_val : 'uncategorized';
			$type         = $result->get_type();

			if ( ! isset( $categories[ $category ] ) ) {
				$categories[ $category ] = [
					'knowledge' => [],
					'execution' => [],
				];
			}

			if ( 'knowledge' === $type ) {
				$categories[ $category ]['knowledge'][] = $result->get_score();
			} else {
				$categories[ $category ]['execution'][] = $result->get_correctness_score();
			}
		}

		$category_scores = [];
		foreach ( $categories as $category => $data ) {
			$knowledge_scores = $data['knowledge'];
			$execution_scores = $data['execution'];
			$all_scores       = array_merge( $knowledge_scores, $execution_scores );

			$category_scores[ $category ] = [
				'knowledge' => [
					'score' => round( $this->safe_average( $knowledge_scores ), 4 ),
					'count' => count( $knowledge_scores ),
				],
				'execution' => [
					'score' => round( $this->safe_average( $execution_scores ), 4 ),
					'count' => count( $execution_scores ),
				],
				'total'     => [
					'score' => round( $this->safe_average( $all_scores ), 4 ),
					'count' => count( $all_scores ),
				],
			];
		}

		return $category_scores;
	}

	/**
	 * Calculate statistics for multiple runs.
	 *
	 * @param array<Test_Result> $results All results across runs.
	 * @param int                $runs    Number of runs (unused, kept for API compatibility).
	 *
	 * @return array<string, array{mean: float, stddev: float, min: float, max: float, runs: int}>
	 */
	private function calculate_stats( array $results, int $runs ): array {
		unset( $runs ); // Unused parameter, kept for API compatibility.
		// Group results by test ID.
		$by_test = [];
		foreach ( $results as $result ) {
			$test_id = $result->get_test_id();
			if ( ! isset( $by_test[ $test_id ] ) ) {
				$by_test[ $test_id ] = [];
			}
			$by_test[ $test_id ][] = $result;
		}

		$stats = [];
		foreach ( $by_test as $test_id => $test_results ) {
			$scores = array_map(
				fn( $r ) => $r->get_type() === 'knowledge' ? $r->get_score() : $r->get_correctness_score(),
				$test_results
			);

			$mean   = $this->safe_average( $scores );
			$stddev = $this->calculate_stddev( $scores, $mean );

			$stats[ $test_id ] = [
				'mean'   => round( $mean, 4 ),
				'stddev' => round( $stddev, 4 ),
				'min'    => round( min( $scores ), 4 ),
				'max'    => round( max( $scores ), 4 ),
				'runs'   => count( $scores ),
			];
		}

		return $stats;
	}

	/**
	 * Calculate safe average of scores.
	 *
	 * @param array<float> $scores Scores to average.
	 *
	 * @return float Average or 0.0 if empty.
	 */
	private function safe_average( array $scores ): float {
		if ( empty( $scores ) ) {
			return 0.0;
		}
		return array_sum( $scores ) / count( $scores );
	}

	/**
	 * Calculate standard deviation.
	 *
	 * @param array<float> $scores Scores.
	 * @param float        $mean   Pre-calculated mean.
	 *
	 * @return float Standard deviation.
	 */
	private function calculate_stddev( array $scores, float $mean ): float {
		if ( count( $scores ) < 2 ) {
			return 0.0;
		}

		$sum_squared_diff = 0.0;
		foreach ( $scores as $score ) {
			$sum_squared_diff += ( $score - $mean ) ** 2;
		}

		return sqrt( $sum_squared_diff / ( count( $scores ) - 1 ) );
	}

	/**
	 * Set custom score weights.
	 *
	 * @param array<string, float> $weights Weight configuration.
	 */
	public function set_score_weights( array $weights ): void {
		$this->score_weights = array_merge( $this->score_weights, $weights );
	}

	/**
	 * Get current score weights.
	 *
	 * @return array<string, float>
	 */
	public function get_score_weights(): array {
		return $this->score_weights;
	}
}
