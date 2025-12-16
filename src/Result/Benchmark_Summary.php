<?php
/**
 * Benchmark summary value object.
 *
 * @package WordPress\AI_Benchmark\Result
 */

declare(strict_types=1);

namespace WordPress\AI_Benchmark\Result;

/**
 * Represents the complete result of a benchmark run.
 */
final class Benchmark_Summary {

	/**
	 * Constructor.
	 *
	 * @param string                            $suite           Suite name.
	 * @param string                            $model           Model used for generation.
	 * @param string                            $judge_model     Model used for judging.
	 * @param int                               $runs            Number of runs.
	 * @param Aggregate_Scores                  $scores          Aggregate scores.
	 * @param array<string, Category_Breakdown> $category_scores Scores by category.
	 * @param array<string, Score_Stats>        $stats           Statistics (for multi-run).
	 * @param list<Test_Result>                 $test_results    Individual test results.
	 * @param Benchmark_Metadata                $metadata        Run metadata.
	 */
	public function __construct(
		private readonly string $suite,
		private readonly string $model,
		private readonly string $judge_model,
		private readonly int $runs,
		private readonly Aggregate_Scores $scores,
		private readonly array $category_scores,
		private readonly array $stats,
		private readonly array $test_results,
		private readonly Benchmark_Metadata $metadata,
	) {
	}

	/**
	 * Get suite name.
	 *
	 * @return string
	 */
	public function get_suite(): string {
		return $this->suite;
	}

	/**
	 * Get model identifier.
	 *
	 * @return string
	 */
	public function get_model(): string {
		return $this->model;
	}

	/**
	 * Get judge model identifier.
	 *
	 * @return string
	 */
	public function get_judge_model(): string {
		return $this->judge_model;
	}

	/**
	 * Get number of runs.
	 *
	 * @return int
	 */
	public function get_runs(): int {
		return $this->runs;
	}

	/**
	 * Get aggregate scores.
	 *
	 * @return Aggregate_Scores
	 */
	public function get_scores(): Aggregate_Scores {
		return $this->scores;
	}

	/**
	 * Get category scores.
	 *
	 * @return array<string, Category_Breakdown>
	 */
	public function get_category_scores(): array {
		return $this->category_scores;
	}

	/**
	 * Get statistics.
	 *
	 * @return array<string, Score_Stats>
	 */
	public function get_stats(): array {
		return $this->stats;
	}

	/**
	 * Get test results.
	 *
	 * @return list<Test_Result>
	 */
	public function get_test_results(): array {
		return $this->test_results;
	}

	/**
	 * Get metadata.
	 *
	 * @return Benchmark_Metadata
	 */
	public function get_metadata(): Benchmark_Metadata {
		return $this->metadata;
	}

	/**
	 * Get results that had errors.
	 *
	 * @return list<Test_Result>
	 */
	public function get_errors(): array {
		return array_values(
			array_filter(
				$this->test_results,
				static fn( Test_Result $r ): bool => $r->has_error()
			)
		);
	}

	/**
	 * Convert to array for serialization.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$category_scores = [];
		foreach ( $this->category_scores as $category => $breakdown ) {
			$category_scores[ $category ] = $breakdown->to_array();
		}

		$stats = [];
		foreach ( $this->stats as $test_id => $score_stats ) {
			$stats[ $test_id ] = $score_stats->to_array();
		}

		return [
			'suite'           => $this->suite,
			'model'           => $this->model,
			'judge_model'     => $this->judge_model,
			'runs'            => $this->runs,
			'scores'          => $this->scores->to_array(),
			'category_scores' => $category_scores,
			'stats'           => $stats,
			'test_results'    => array_map(
				static fn( Test_Result $r ): array => $r->to_array(),
				$this->test_results
			),
			'metadata'        => $this->metadata->to_array(),
		];
	}
}
