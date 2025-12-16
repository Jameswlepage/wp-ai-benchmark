<?php
/**
 * Parallel benchmark runner using worker processes.
 *
 * @package WordPress\AI_Benchmark
 */

declare(strict_types=1);

namespace WordPress\AI_Benchmark;

use Symfony\Component\Process\Process;
use WordPress\AI_Benchmark\Result\Test_Result;
use WordPress\AI_Benchmark\Test\Execution_Test;
use WordPress\AI_Benchmark\Test\Knowledge_Test;

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
	 * @var array<array{id: string, type: string, category: string}>
	 */
	private array $pending = [];

	/**
	 * Currently running processes.
	 *
	 * @var array<string, array{process: Process, test_id: string, type: string, category: string}>
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
	 * @param array<Knowledge_Test> $knowledge_tests Knowledge test definitions.
	 * @param array<Execution_Test> $execution_tests Execution test definitions.
	 * @param string                $model           Model to benchmark.
	 * @param string                $judge_model     Judge model for execution tests.
	 * @param int                   $run_number      Current run number.
	 * @param callable|null         $progress_callback Called after each test completes.
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
				'id'       => $test->get_id(),
				'type'     => 'knowledge',
				'category' => $test->get_category(),
			];
		}
		foreach ( $execution_tests as $test ) {
			$this->pending[] = [
				'id'       => $test->get_id(),
				'type'     => 'execution',
				'category' => $test->get_category(),
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
			return Test_Result::error_result_from_string( $test_id, $type, $error, $category );
		}

		// Parse JSON output.
		if ( empty( $output ) ) {
			return Test_Result::error_result_from_string( $test_id, $type, 'Empty output from worker', $category );
		}

		try {
			$decoded = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			return Test_Result::error_result_from_string(
				$test_id,
				$type,
				'Failed to parse JSON: ' . $e->getMessage() . ' - Output: ' . substr( $output, 0, 200 ),
				$category
			);
		}

		if ( ! is_array( $decoded ) ) {
			return Test_Result::error_result_from_string( $test_id, $type, 'Invalid JSON response: expected array', $category );
		}

		/** @var array<string, mixed> $data */
		$data = $decoded;

		// Check for error in JSON response.
		if ( isset( $data['error'] ) && ! isset( $data['type'] ) ) {
			$error_msg = is_string( $data['error'] ) ? $data['error'] : 'Unknown error';
			return Test_Result::error_result_from_string( $test_id, $type, $error_msg, $category );
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
		$test_id_value = $data['test_id'] ?? '';
		$test_id       = is_string( $test_id_value ) ? $test_id_value : '';

		$type_value = $data['type'] ?? 'unknown';
		$type       = is_string( $type_value ) ? $type_value : 'unknown';

		$category_value = $data['category'] ?? '';
		$category       = is_string( $category_value ) ? $category_value : '';

		// Check for error.
		if ( ! empty( $data['error'] ) ) {
			$error_msg = is_string( $data['error'] ) ? $data['error'] : 'Unknown error';
			return Test_Result::error_result_from_string( $test_id, $type, $error_msg, $category );
		}

		if ( 'knowledge' === $type ) {
			$score_value          = $data['score'] ?? 0.0;
			$model_answer_value   = $data['model_answer'] ?? '';
			$correct_answer_value = $data['correct_answer'] ?? '';

			$result = Test_Result::knowledge_result(
				$test_id,
				is_numeric( $score_value ) ? (float) $score_value : 0.0,
				is_string( $model_answer_value ) ? $model_answer_value : '',
				is_string( $correct_answer_value ) ? $correct_answer_value : '',
				$category
			);
		} else {
			$generated_code_value = $data['generated_code'] ?? '';
			$static_score_value   = $data['static_score'] ?? 0.0;
			$runtime_score_value  = $data['runtime_score'] ?? 0.0;
			$quality_score_value  = $data['quality_score'] ?? 0.0;

			/** @var array<string, mixed> $static_details */
			$static_details = isset( $data['static_details'] ) && is_array( $data['static_details'] ) ? $data['static_details'] : [];

			/** @var array<string, mixed> $runtime_details */
			$runtime_details = isset( $data['runtime_details'] ) && is_array( $data['runtime_details'] ) ? $data['runtime_details'] : [];

			/** @var array<string, mixed> $judge_details */
			$judge_details = isset( $data['judge_details'] ) && is_array( $data['judge_details'] ) ? $data['judge_details'] : [];

			$result = Test_Result::execution_result(
				$test_id,
				is_string( $generated_code_value ) ? $generated_code_value : '',
				is_numeric( $static_score_value ) ? (float) $static_score_value : 0.0,
				is_numeric( $runtime_score_value ) ? (float) $runtime_score_value : 0.0,
				is_numeric( $quality_score_value ) ? (float) $quality_score_value : 0.0,
				$static_details,
				$runtime_details,
				$judge_details,
				$category
			);
		}

		if ( isset( $data['duration_ms'] ) && is_numeric( $data['duration_ms'] ) ) {
			$result->set_duration_ms( (float) $data['duration_ms'] );
		}

		return $result;
	}
}
