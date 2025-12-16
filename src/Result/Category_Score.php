<?php
/**
 * Category score value object.
 *
 * @package WordPress\AI_Benchmark\Result
 */

declare(strict_types=1);

namespace WordPress\AI_Benchmark\Result;

/**
 * Represents a score for a category with a count of tests.
 */
final class Category_Score {

	/**
	 * Constructor.
	 *
	 * @param float $score Average score for this category (0.0-1.0).
	 * @param int   $count Number of tests in this category.
	 */
	public function __construct(
		private readonly float $score,
		private readonly int $count,
	) {
	}

	/**
	 * Create from a list of scores.
	 *
	 * @param float[] $scores List of individual test scores.
	 *
	 * @return self
	 */
	public static function from_scores( array $scores ): self {
		$count = count( $scores );
		if ( 0 === $count ) {
			return new self( 0.0, 0 );
		}

		$average = array_sum( $scores ) / $count;
		return new self( round( $average, 4 ), $count );
	}

	/**
	 * Get the score.
	 *
	 * @return float
	 */
	public function get_score(): float {
		return $this->score;
	}

	/**
	 * Get the count.
	 *
	 * @return int
	 */
	public function get_count(): int {
		return $this->count;
	}

	/**
	 * Convert to array for serialization.
	 *
	 * @return array{score: float, count: int}
	 */
	public function to_array(): array {
		return [
			'score' => $this->score,
			'count' => $this->count,
		];
	}
}
