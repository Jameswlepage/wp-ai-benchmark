<?php
/**
 * Category breakdown value object.
 *
 * @package WordPress\AI_Benchmark\Result
 */

declare(strict_types=1);

namespace WordPress\AI_Benchmark\Result;

/**
 * Represents score breakdown for a single category.
 */
final class Category_Breakdown {

	/**
	 * Constructor.
	 *
	 * @param Category_Score $knowledge Knowledge test scores for this category.
	 * @param Category_Score $execution Execution test scores for this category.
	 * @param Category_Score $total     Combined scores for this category.
	 */
	public function __construct(
		private readonly Category_Score $knowledge,
		private readonly Category_Score $execution,
		private readonly Category_Score $total,
	) {
	}

	/**
	 * Get knowledge scores.
	 *
	 * @return Category_Score
	 */
	public function get_knowledge(): Category_Score {
		return $this->knowledge;
	}

	/**
	 * Get execution scores.
	 *
	 * @return Category_Score
	 */
	public function get_execution(): Category_Score {
		return $this->execution;
	}

	/**
	 * Get total scores.
	 *
	 * @return Category_Score
	 */
	public function get_total(): Category_Score {
		return $this->total;
	}

	/**
	 * Convert to array for serialization.
	 *
	 * @return array{knowledge: array{score: float, count: int}, execution: array{score: float, count: int}, total: array{score: float, count: int}}
	 */
	public function to_array(): array {
		return [
			'knowledge' => $this->knowledge->to_array(),
			'execution' => $this->execution->to_array(),
			'total'     => $this->total->to_array(),
		];
	}
}
