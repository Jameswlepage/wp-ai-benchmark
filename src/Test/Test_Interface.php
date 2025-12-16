<?php
/**
 * Test interface.
 *
 * @package WordPress\AI_Benchmark\Test
 */

declare(strict_types=1);

namespace WordPress\AI_Benchmark\Test;

/**
 * Interface for benchmark test definitions.
 *
 * Provides common properties shared by both knowledge and execution tests.
 */
interface Test_Interface {

	/**
	 * Get the test identifier.
	 *
	 * @return string Unique test ID (e.g., 'k-hooks-001', 'e-shortcode-001').
	 */
	public function get_id(): string;

	/**
	 * Get the test category.
	 *
	 * @return string Category name (e.g., 'hooks', 'shortcodes').
	 */
	public function get_category(): string;

	/**
	 * Get the test prompt.
	 *
	 * @return string The question or task description.
	 */
	public function get_prompt(): string;

	/**
	 * Get the test difficulty level.
	 *
	 * @return string|null Difficulty level ('basic', 'intermediate', 'advanced') or null.
	 */
	public function get_difficulty(): ?string;

	/**
	 * Get the test type identifier.
	 *
	 * @return string 'knowledge' or 'execution'.
	 */
	public function get_type(): string;

	/**
	 * Convert the test to an array representation.
	 *
	 * @return array<string, mixed> Test data as associative array.
	 */
	public function to_array(): array;
}
