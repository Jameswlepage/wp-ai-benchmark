<?php
/**
 * Score statistics value object.
 *
 * @package WordPress\AI_Benchmark\Result
 */

declare(strict_types=1);

namespace WordPress\AI_Benchmark\Result;

/**
 * Represents statistical data for multiple test runs.
 */
final class Score_Stats {

	/**
	 * Constructor.
	 *
	 * @param float $mean   Mean score.
	 * @param float $stddev Standard deviation.
	 * @param float $min    Minimum score.
	 * @param float $max    Maximum score.
	 * @param int   $runs   Number of runs.
	 */
	public function __construct(
		private readonly float $mean,
		private readonly float $stddev,
		private readonly float $min,
		private readonly float $max,
		private readonly int $runs,
	) {
	}

	/**
	 * Create from a list of scores.
	 *
	 * @param list<float> $scores List of scores from multiple runs.
	 *
	 * @return self
	 */
	public static function from_scores( array $scores ): self {
		$count = count( $scores );
		if ( 0 === $count ) {
			return new self( 0.0, 0.0, 0.0, 0.0, 0 );
		}

		$mean = array_sum( $scores ) / $count;

		// Calculate standard deviation.
		$stddev = 0.0;
		if ( $count > 1 ) {
			$sum_squared_diff = 0.0;
			foreach ( $scores as $score ) {
				$sum_squared_diff += ( $score - $mean ) ** 2;
			}
			$stddev = sqrt( $sum_squared_diff / ( $count - 1 ) );
		}

		return new self(
			mean: round( $mean, 4 ),
			stddev: round( $stddev, 4 ),
			min: round( min( $scores ), 4 ),
			max: round( max( $scores ), 4 ),
			runs: $count,
		);
	}

	/**
	 * Get the mean.
	 *
	 * @return float
	 */
	public function get_mean(): float {
		return $this->mean;
	}

	/**
	 * Get the standard deviation.
	 *
	 * @return float
	 */
	public function get_stddev(): float {
		return $this->stddev;
	}

	/**
	 * Get the minimum.
	 *
	 * @return float
	 */
	public function get_min(): float {
		return $this->min;
	}

	/**
	 * Get the maximum.
	 *
	 * @return float
	 */
	public function get_max(): float {
		return $this->max;
	}

	/**
	 * Get the number of runs.
	 *
	 * @return int
	 */
	public function get_runs(): int {
		return $this->runs;
	}

	/**
	 * Convert to array for serialization.
	 *
	 * @return array{mean: float, stddev: float, min: float, max: float, runs: int}
	 */
	public function to_array(): array {
		return [
			'mean'   => $this->mean,
			'stddev' => $this->stddev,
			'min'    => $this->min,
			'max'    => $this->max,
			'runs'   => $this->runs,
		];
	}
}
