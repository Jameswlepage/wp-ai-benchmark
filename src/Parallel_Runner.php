<?php
/**
 * Parallel benchmark runner using worker processes.
 *
 * @package WordPress\AI_Benchmark
 */

declare(strict_types=1);

namespace WordPress\AI_Benchmark;

use Symfony\Component\Process\Process;

/**
 * Runs benchmark tests in parallel using worker processes.
 *
 * Spawns concurrent sub-processes that execute individual tests via WP-CLI,
 * collecting results as JSON and aggregating them.
 */
class Parallel_Runner {

	/**
	 * Maximum number of concurrent worker processes.
	 *
	 * @var int
	 */
	private int $concurrency;

	/**
	 * Pending tests to run.
	 *
	 * @var array<array{id: string, type: string}>
	 */
	private array $pending = [];

	/**
	 * Currently running processes.
	 *
	 * @var array<string, array{process: Process, test_id: string, type: string}>
	 */
	private array $running = [];

	/**
	 * Collected test results.
	 *
	 * @var array<Test_Result>
	 */
	private array $results = [];

	/**
	 * Model to benchmark.
	 *
	 * @var string
	 */
	private string $model;

	/**
	 * Judge model for execution tests.
	 *
	 * @var string
	 */
	private string $judge_model;

	/**
	 * Current run number.
	 *
	 * @var int
	 */
	private int $current_run = 1;

	/**
	 * Progress callback.
	 *
	 * @var callable|null
	 */
	private $progress_callback;

	/**
	 * Constructor.
	 *
	 * @param int $concurrency Maximum concurrent workers.
	 */
	public function __construct( int $concurrency = 5 ) {
		$this->concurrency = $concurrency;
	}

	/**
	 * Run tests in parallel.
	 *
	 * @param array<array<string, mixed>> $knowledge_tests Knowledge test definitions.
	 * @param array<array<string, mixed>> $execution_tests Execution test definitions.
	 * @param string                      $model           Model to benchmark.
	 * @param string                      $judge_model     Judge model for execution tests.
	 * @param int                         $run_number      Current run number.
	 * @param callable|null               $progress_callback Called after each test completes.
	 *
	 * @return array<Test_Result> Test results.
	 */
	public function run(
		array $knowledge_tests,
		array $execution_tests,
		string $model,
		string $judge_model,
		int $run_number = 1,
		?callable $progress_callback = null,
	): array {
		$this->model             = $model;
		$this->judge_model       = $judge_model;
		$this->current_run       = $run_number;
		$this->progress_callback = $progress_callback;
		$this->results           = [];
		$this->running           = [];

		// Queue all tests.
		$this->pending = [];
		foreach ( $knowledge_tests as $test ) {
			$this->pending[] = [
				'id'       => $test['id'],
				'type'     => 'knowledge',
				'category' => $test['category'] ?? '',
			];
		}
		foreach ( $execution_tests as $test ) {
			$this->pending[] = [
				'id'       => $test['id'],
				'type'     => 'execution',
				'category' => $test['category'] ?? '',
			];
		}

		// Process all tests.
		while ( ! empty( $this->pending ) || ! empty( $this->running ) ) {
			$this->spawn_workers();
			$this->collect_results();

			// Small sleep to avoid busy waiting.
			usleep( 50000 ); // 50ms
		}

		return $this->results;
	}

	/**
	 * Spawn worker processes up to concurrency limit.
	 */
	private function spawn_workers(): void {
		$running_count = count( $this->running );
		while ( $running_count < $this->concurrency && ! empty( $this->pending ) ) {
			$test = array_shift( $this->pending );
			$this->spawn_single_worker( $test );
			++$running_count;
		}
	}

	/**
	 * Spawn a single worker process for a test.
	 *
	 * @param array{id: string, type: string, category: string} $test Test info.
	 */
	private function spawn_single_worker( array $test ): void {
		// We're already inside the wp-env container, so call wp directly.
		$command = [
			'wp',
			'ai-bench',
			'run-test',
			$test['id'],
			'--model=' . $this->model,
			'--judge-model=' . $this->judge_model,
			'--format=json',
		];

		$process = new Process( $command );
		$process->setTimeout( 300 ); // 5 minute timeout per test.
		$process->start();

		$this->running[ $test['id'] ] = [
			'process'  => $process,
			'test_id'  => $test['id'],
			'type'     => $test['type'],
			'category' => $test['category'],
		];
	}

	/**
	 * Check running processes and collect completed results.
	 */
	private function collect_results(): void {
		foreach ( $this->running as $test_id => $info ) {
			$process = $info['process'];

			if ( ! $process->isRunning() ) {
				$result = $this->parse_process_output( $process, $info );
				$result->set_run_number( $this->current_run );
				$this->results[] = $result;

				// Call progress callback.
				if ( $this->progress_callback ) {
					call_user_func(
						$this->progress_callback,
						$result,
						$info['type'],
						$this->current_run
					);
				}

				unset( $this->running[ $test_id ] );
			}
		}
	}

	/**
	 * Parse process output into a Test_Result.
	 *
	 * @param Process                                                                  $process Process that completed.
	 * @param array{process: Process, test_id: string, type: string, category: string} $info    Test info.
	 *
	 * @return Test_Result
	 */
	private function parse_process_output( Process $process, array $info ): Test_Result {
		$output   = trim( $process->getOutput() );
		$stderr   = trim( $process->getErrorOutput() );
		$test_id  = $info['test_id'];
		$type     = $info['type'];
		$category = $info['category'];

		// Check for process failure.
		if ( ! $process->isSuccessful() ) {
			$error = sprintf(
				'Process exited with code %d. Stderr: %s. Stdout: %s',
				$process->getExitCode(),
				! empty( $stderr ) ? $stderr : '(empty)',
				! empty( $output ) ? substr( $output, 0, 500 ) : '(empty)'
			);
			return Test_Result::error_result( $test_id, $type, $error, $category );
		}

		// Parse JSON output.
		if ( empty( $output ) ) {
			return Test_Result::error_result( $test_id, $type, 'Empty output from worker', $category );
		}

		try {
			$data = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			return Test_Result::error_result(
				$test_id,
				$type,
				'Failed to parse JSON: ' . $e->getMessage() . ' - Output: ' . substr( $output, 0, 200 ),
				$category
			);
		}

		// Check for error in JSON response.
		if ( isset( $data['error'] ) && ! isset( $data['type'] ) ) {
			return Test_Result::error_result( $test_id, $type, $data['error'], $category );
		}

		// Reconstruct Test_Result from JSON data.
		return $this->array_to_result( $data );
	}

	/**
	 * Convert array data back to Test_Result object.
	 *
	 * @param array<string, mixed> $data Result data from JSON.
	 *
	 * @return Test_Result
	 */
	private function array_to_result( array $data ): Test_Result {
		$test_id  = $data['test_id'] ?? '';
		$type     = $data['type'] ?? 'unknown';
		$category = $data['category'] ?? '';

		// Check for error.
		if ( ! empty( $data['error'] ) ) {
			return Test_Result::error_result( $test_id, $type, $data['error'], $category );
		}

		if ( 'knowledge' === $type ) {
			$result = Test_Result::knowledge_result(
				$test_id,
				(float) ( $data['score'] ?? 0.0 ),
				(string) ( $data['model_answer'] ?? '' ),
				(string) ( $data['correct_answer'] ?? '' ),
				$category
			);
		} else {
			$result = Test_Result::execution_result(
				$test_id,
				(string) ( $data['generated_code'] ?? '' ),
				(float) ( $data['static_score'] ?? 0.0 ),
				(float) ( $data['runtime_score'] ?? 0.0 ),
				(float) ( $data['quality_score'] ?? 0.0 ),
				$data['static_details'] ?? [],
				$data['runtime_details'] ?? [],
				$data['judge_details'] ?? [],
				$category
			);
		}

		if ( isset( $data['duration_ms'] ) ) {
			$result->set_duration_ms( (float) $data['duration_ms'] );
		}

		return $result;
	}
}
