<?php
/**
 * Aggregate scores value object.
 *
 * @package WordPress\AI_Benchmark\Result
 */

declare(strict_types=1);

namespace WordPress\AI_Benchmark\Result;

/**
 * Represents the aggregate scores from a benchmark run.
 */
final class Aggregate_Scores {

	/**
	 * Constructor.
	 *
	 * @param float $knowledge             Knowledge test score (0.0-1.0).
	 * @param float $execution_correctness Execution correctness score (0.0-1.0).
	 * @param float $execution_quality     Execution quality score (0.0-1.0).
	 * @param float $overall               Weighted overall score (0.0-1.0).
	 */
	public function __construct(
		private readonly float $knowledge,
		private readonly float $execution_correctness,
		private readonly float $execution_quality,
		private readonly float $overall,
	) {
	}

	/**
	 * Get knowledge score.
	 *
	 * @return float
	 */
	public function get_knowledge(): float {
		return $this->knowledge;
	}

	/**
	 * Get execution correctness score.
	 *
	 * @return float
	 */
	public function get_execution_correctness(): float {
		return $this->execution_correctness;
	}

	/**
	 * Get execution quality score.
	 *
	 * @return float
	 */
	public function get_execution_quality(): float {
		return $this->execution_quality;
	}

	/**
	 * Get overall score.
	 *
	 * @return float
	 */
	public function get_overall(): float {
		return $this->overall;
	}

	/**
	 * Convert to array for serialization.
	 *
	 * @return array{knowledge: float, execution_correctness: float, execution_quality: float, overall: float}
	 */
	public function to_array(): array {
		return [
			'knowledge'             => $this->knowledge,
			'execution_correctness' => $this->execution_correctness,
			'execution_quality'     => $this->execution_quality,
			'overall'               => $this->overall,
		];
	}
}
