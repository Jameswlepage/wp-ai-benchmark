<?php
/**
 * Benchmark metadata value object.
 *
 * @package WordPress\AI_Benchmark\Result
 */

declare(strict_types=1);

namespace WordPress\AI_Benchmark\Result;

/**
 * Represents metadata from a benchmark run.
 */
final class Benchmark_Metadata {

	/**
	 * Constructor.
	 *
	 * @param float  $duration_seconds  Total duration in seconds.
	 * @param int    $total_tests       Total number of test results.
	 * @param int    $knowledge_tests   Number of knowledge tests in suite.
	 * @param int    $execution_tests   Number of execution tests in suite.
	 * @param string $wp_version        WordPress version.
	 * @param string $php_version       PHP version.
	 * @param string $benchmark_version Benchmark plugin version.
	 */
	public function __construct(
		private readonly float $duration_seconds,
		private readonly int $total_tests,
		private readonly int $knowledge_tests,
		private readonly int $execution_tests,
		private readonly string $wp_version,
		private readonly string $php_version,
		private readonly string $benchmark_version,
	) {
	}

	/**
	 * Get duration in seconds.
	 *
	 * @return float
	 */
	public function get_duration_seconds(): float {
		return $this->duration_seconds;
	}

	/**
	 * Get total test count.
	 *
	 * @return int
	 */
	public function get_total_tests(): int {
		return $this->total_tests;
	}

	/**
	 * Get knowledge test count.
	 *
	 * @return int
	 */
	public function get_knowledge_tests(): int {
		return $this->knowledge_tests;
	}

	/**
	 * Get execution test count.
	 *
	 * @return int
	 */
	public function get_execution_tests(): int {
		return $this->execution_tests;
	}

	/**
	 * Get WordPress version.
	 *
	 * @return string
	 */
	public function get_wp_version(): string {
		return $this->wp_version;
	}

	/**
	 * Get PHP version.
	 *
	 * @return string
	 */
	public function get_php_version(): string {
		return $this->php_version;
	}

	/**
	 * Get benchmark version.
	 *
	 * @return string
	 */
	public function get_benchmark_version(): string {
		return $this->benchmark_version;
	}

	/**
	 * Convert to array for serialization.
	 *
	 * @return array{duration_seconds: float, total_tests: int, knowledge_tests: int, execution_tests: int, wp_version: string, php_version: string, benchmark_version: string}
	 */
	public function to_array(): array {
		return [
			'duration_seconds'  => $this->duration_seconds,
			'total_tests'       => $this->total_tests,
			'knowledge_tests'   => $this->knowledge_tests,
			'execution_tests'   => $this->execution_tests,
			'wp_version'        => $this->wp_version,
			'php_version'       => $this->php_version,
			'benchmark_version' => $this->benchmark_version,
		];
	}
}
