<?php
/**
 * Check result value object.
 *
 * @package WordPress\AI_Benchmark\Result
 */

declare(strict_types=1);

namespace WordPress\AI_Benchmark\Result;

/**
 * Represents the result of a static, runtime, or judge evaluation check.
 *
 * @phpstan-type CheckDetails array<string, mixed>
 */
final class Check_Result {

	/**
	 * Constructor.
	 *
	 * @param float                $score   Score between 0.0 and 1.0.
	 * @param array<string, mixed> $details Detailed results.
	 */
	public function __construct(
		private readonly float $score,
		private readonly array $details = [],
	) {
	}

	/**
	 * Create a passing result.
	 *
	 * @param array<string, mixed> $details Optional details.
	 *
	 * @return self
	 */
	public static function pass( array $details = [] ): self {
		return new self( 1.0, $details );
	}

	/**
	 * Create a failing result.
	 *
	 * @param array<string, mixed> $details Optional details.
	 *
	 * @return self
	 */
	public static function fail( array $details = [] ): self {
		return new self( 0.0, $details );
	}

	/**
	 * Create a neutral/skipped result.
	 *
	 * @param string $message Reason for skipping.
	 *
	 * @return self
	 */
	public static function skipped( string $message ): self {
		return new self( 1.0, [ 'message' => $message ] );
	}

	/**
	 * Create an error result.
	 *
	 * @param string $error Error message.
	 *
	 * @return self
	 */
	public static function error( string $error ): self {
		return new self( 0.5, [ 'error' => $error ] );
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
	 * Get the details.
	 *
	 * @return array<string, mixed>
	 */
	public function get_details(): array {
		return $this->details;
	}

	/**
	 * Check if this result passed (score >= threshold).
	 *
	 * @param float $threshold Pass threshold (default 0.5).
	 *
	 * @return bool
	 */
	public function passed( float $threshold = 0.5 ): bool {
		return $this->score >= $threshold;
	}

	/**
	 * Convert to array for serialization.
	 *
	 * @return array{score: float, details: array<string, mixed>}
	 */
	public function to_array(): array {
		return [
			'score'   => $this->score,
			'details' => $this->details,
		];
	}
}
