<?php
/**
 * Test suite loader.
 *
 * @package WP_AI_Benchmarks
 */

declare(strict_types=1);

namespace WP_AI_Benchmarks;

use JsonException;

/**
 * Loads and validates test suites from JSON files.
 *
 * Test suites are organized in:
 * - tests/knowledge/{suite-name}.json for knowledge tests
 * - tests/execution/{suite-name}.json for execution tests
 * - judges/{rubric-id}.json for judge rubrics
 */
class AI_Bench_Suite_Loader {

	/**
	 * Path to tests directory.
	 */
	private string $tests_path;

	/**
	 * Path to judges directory.
	 */
	private string $judges_path;

	/**
	 * Cache of loaded suites.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $suite_cache = [];

	/**
	 * Cache of loaded rubrics.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $rubric_cache = [];

	/**
	 * Constructor.
	 *
	 * @param string|null $tests_path  Path to tests directory.
	 * @param string|null $judges_path Path to judges directory.
	 */
	public function __construct(
		?string $tests_path = null,
		?string $judges_path = null,
	) {
		$this->tests_path  = $tests_path ?? WP_AI_BENCH_PATH . 'tests/';
		$this->judges_path = $judges_path ?? WP_AI_BENCH_PATH . 'judges/';
	}

	/**
	 * Load a complete test suite.
	 *
	 * @param string $suite_name Suite identifier (e.g., 'wp-core-v1').
	 *
	 * @return array{
	 *   knowledge_tests: array<array<string, mixed>>,
	 *   execution_tests: array<array<string, mixed>>,
	 *   metadata: array<string, mixed>
	 * }
	 *
	 * @throws \InvalidArgumentException If suite not found.
	 * @throws JsonException If JSON is invalid.
	 */
	public function load( string $suite_name ): array {
		if ( isset( $this->suite_cache[ $suite_name ] ) ) {
			return $this->suite_cache[ $suite_name ];
		}

		$knowledge_file = $this->tests_path . "knowledge/{$suite_name}.json";
		$execution_file = $this->tests_path . "execution/{$suite_name}.json";

		$knowledge_tests = [];
		$execution_tests = [];
		$knowledge_meta  = [];
		$execution_meta  = [];

		// Load knowledge tests if file exists.
		if ( file_exists( $knowledge_file ) ) {
			$knowledge_data = $this->load_json_file( $knowledge_file );
			$this->validate_knowledge_suite( $knowledge_data );
			$knowledge_tests = $knowledge_data['tests'];
			$knowledge_meta  = $knowledge_data['metadata'] ?? [];
		}

		// Load execution tests if file exists.
		if ( file_exists( $execution_file ) ) {
			$execution_data = $this->load_json_file( $execution_file );
			$this->validate_execution_suite( $execution_data );
			$execution_tests = $execution_data['tests'];
			$execution_meta  = $execution_data['metadata'] ?? [];
		}

		// Ensure at least one type exists.
		if ( empty( $knowledge_tests ) && empty( $execution_tests ) ) {
			throw new \InvalidArgumentException(
				sprintf( "Suite '%s' not found or contains no tests.", $suite_name )
			);
		}

		$suite = [
			'knowledge_tests' => $knowledge_tests,
			'execution_tests' => $execution_tests,
			'metadata'        => [
				'suite_name'      => $suite_name,
				'knowledge_count' => count( $knowledge_tests ),
				'execution_count' => count( $execution_tests ),
				'knowledge_meta'  => $knowledge_meta,
				'execution_meta'  => $execution_meta,
			],
		];

		$this->suite_cache[ $suite_name ] = $suite;

		return $suite;
	}

	/**
	 * List all available test suites.
	 *
	 * @return array<array{
	 *   name: string,
	 *   types: array<string>,
	 *   knowledge_count: int,
	 *   execution_count: int,
	 *   description: string
	 * }>
	 */
	public function list_suites(): array {
		$suites = [];
		$seen   = [];

		// Scan knowledge directory.
		$knowledge_pattern = $this->tests_path . 'knowledge/*.json';
		foreach ( glob( $knowledge_pattern ) ?: [] as $file ) {
			$name         = basename( $file, '.json' );
			$seen[ $name ] = true;

			try {
				$data = $this->load_json_file( $file );
				$suites[ $name ] = [
					'name'            => $name,
					'types'           => [ 'knowledge' ],
					'knowledge_count' => count( $data['tests'] ?? [] ),
					'execution_count' => 0,
					'description'     => $data['metadata']['description'] ?? '',
				];
			} catch ( \Throwable $e ) {
				// Skip invalid files.
				continue;
			}
		}

		// Scan execution directory and merge.
		$execution_pattern = $this->tests_path . 'execution/*.json';
		foreach ( glob( $execution_pattern ) ?: [] as $file ) {
			$name = basename( $file, '.json' );

			try {
				$data       = $this->load_json_file( $file );
				$exec_count = count( $data['tests'] ?? [] );

				if ( isset( $seen[ $name ] ) ) {
					// Merge with existing suite.
					$suites[ $name ]['types'][]         = 'execution';
					$suites[ $name ]['execution_count'] = $exec_count;
				} else {
					$suites[ $name ] = [
						'name'            => $name,
						'types'           => [ 'execution' ],
						'knowledge_count' => 0,
						'execution_count' => $exec_count,
						'description'     => $data['metadata']['description'] ?? '',
					];
				}
			} catch ( \Throwable $e ) {
				// Skip invalid files.
				continue;
			}
		}

		return array_values( $suites );
	}

	/**
	 * Find a specific test by ID across all suites.
	 *
	 * @param string $test_id Test identifier.
	 *
	 * @return array{type: string, data: array<string, mixed>, suite: string}
	 *
	 * @throws \InvalidArgumentException If test not found.
	 */
	public function find_test_by_id( string $test_id ): array {
		// Search knowledge tests.
		$knowledge_pattern = $this->tests_path . 'knowledge/*.json';
		foreach ( glob( $knowledge_pattern ) ?: [] as $file ) {
			try {
				$data = $this->load_json_file( $file );
				foreach ( $data['tests'] ?? [] as $test ) {
					if ( ( $test['id'] ?? '' ) === $test_id ) {
						return [
							'type'  => 'knowledge',
							'data'  => $test,
							'suite' => basename( $file, '.json' ),
						];
					}
				}
			} catch ( \Throwable $e ) {
				continue;
			}
		}

		// Search execution tests.
		$execution_pattern = $this->tests_path . 'execution/*.json';
		foreach ( glob( $execution_pattern ) ?: [] as $file ) {
			try {
				$data = $this->load_json_file( $file );
				foreach ( $data['tests'] ?? [] as $test ) {
					if ( ( $test['id'] ?? '' ) === $test_id ) {
						return [
							'type'  => 'execution',
							'data'  => $test,
							'suite' => basename( $file, '.json' ),
						];
					}
				}
			} catch ( \Throwable $e ) {
				continue;
			}
		}

		throw new \InvalidArgumentException(
			sprintf( "Test '%s' not found in any suite.", $test_id )
		);
	}

	/**
	 * Load a judge rubric.
	 *
	 * @param string $rubric_id Rubric identifier.
	 *
	 * @return array<string, mixed> Rubric data.
	 *
	 * @throws \InvalidArgumentException If rubric not found.
	 * @throws JsonException If JSON is invalid.
	 */
	public function load_rubric( string $rubric_id ): array {
		if ( isset( $this->rubric_cache[ $rubric_id ] ) ) {
			return $this->rubric_cache[ $rubric_id ];
		}

		$file = $this->judges_path . "{$rubric_id}.json";

		if ( ! file_exists( $file ) ) {
			throw new \InvalidArgumentException(
				sprintf( "Rubric '%s' not found.", $rubric_id )
			);
		}

		$rubric = $this->load_json_file( $file );
		$this->validate_rubric( $rubric );

		$this->rubric_cache[ $rubric_id ] = $rubric;

		return $rubric;
	}

	/**
	 * List available rubrics.
	 *
	 * @return array<array{id: string, name: string, description: string}>
	 */
	public function list_rubrics(): array {
		$rubrics = [];
		$pattern = $this->judges_path . '*.json';

		foreach ( glob( $pattern ) ?: [] as $file ) {
			try {
				$data      = $this->load_json_file( $file );
				$rubrics[] = [
					'id'          => basename( $file, '.json' ),
					'name'        => $data['metadata']['name'] ?? basename( $file, '.json' ),
					'description' => $data['metadata']['description'] ?? '',
				];
			} catch ( \Throwable $e ) {
				continue;
			}
		}

		return $rubrics;
	}

	/**
	 * Get categories across all tests in a suite.
	 *
	 * @param string $suite_name Suite identifier.
	 *
	 * @return array<string, int> Category counts.
	 */
	public function get_suite_categories( string $suite_name ): array {
		$suite      = $this->load( $suite_name );
		$categories = [];

		foreach ( $suite['knowledge_tests'] as $test ) {
			$cat = $test['category'] ?? 'uncategorized';
			$categories[ $cat ] = ( $categories[ $cat ] ?? 0 ) + 1;
		}

		foreach ( $suite['execution_tests'] as $test ) {
			$cat = $test['category'] ?? 'uncategorized';
			$categories[ $cat ] = ( $categories[ $cat ] ?? 0 ) + 1;
		}

		return $categories;
	}

	/**
	 * Load and parse a JSON file.
	 *
	 * @param string $path File path.
	 *
	 * @return array<string, mixed> Decoded JSON data.
	 *
	 * @throws JsonException If JSON is invalid.
	 * @throws \RuntimeException If file cannot be read.
	 */
	private function load_json_file( string $path ): array {
		$content = file_get_contents( $path );

		if ( $content === false ) {
			throw new \RuntimeException(
				sprintf( "Cannot read file: %s", $path )
			);
		}

		return json_decode( $content, true, 512, JSON_THROW_ON_ERROR );
	}

	/**
	 * Validate knowledge suite structure.
	 *
	 * @param array<string, mixed> $data Suite data.
	 *
	 * @throws \InvalidArgumentException If structure is invalid.
	 */
	private function validate_knowledge_suite( array $data ): void {
		if ( ! isset( $data['tests'] ) || ! is_array( $data['tests'] ) ) {
			throw new \InvalidArgumentException(
				'Knowledge suite must have "tests" array.'
			);
		}

		$required = [ 'id', 'category', 'prompt', 'type', 'correct_answer' ];

		foreach ( $data['tests'] as $i => $test ) {
			foreach ( $required as $field ) {
				if ( ! isset( $test[ $field ] ) ) {
					throw new \InvalidArgumentException(
						sprintf( 'Knowledge test %d missing required field: %s', $i, $field )
					);
				}
			}

			// Multiple choice requires choices.
			if ( $test['type'] === 'multiple_choice' && ! isset( $test['choices'] ) ) {
				throw new \InvalidArgumentException(
					sprintf( "Multiple choice test %d missing 'choices' field.", $i )
				);
			}
		}
	}

	/**
	 * Validate execution suite structure.
	 *
	 * @param array<string, mixed> $data Suite data.
	 *
	 * @throws \InvalidArgumentException If structure is invalid.
	 */
	private function validate_execution_suite( array $data ): void {
		if ( ! isset( $data['tests'] ) || ! is_array( $data['tests'] ) ) {
			throw new \InvalidArgumentException(
				'Execution suite must have "tests" array.'
			);
		}

		$required = [ 'id', 'category', 'prompt', 'expected_behavior' ];

		foreach ( $data['tests'] as $i => $test ) {
			foreach ( $required as $field ) {
				if ( ! isset( $test[ $field ] ) ) {
					throw new \InvalidArgumentException(
						sprintf( 'Execution test %d missing required field: %s', $i, $field )
					);
				}
			}
		}
	}

	/**
	 * Validate rubric structure.
	 *
	 * @param array<string, mixed> $data Rubric data.
	 *
	 * @throws \InvalidArgumentException If structure is invalid.
	 */
	private function validate_rubric( array $data ): void {
		if ( ! isset( $data['criteria'] ) || ! is_array( $data['criteria'] ) ) {
			throw new \InvalidArgumentException(
				'Rubric must have "criteria" array.'
			);
		}

		$required = [ 'id', 'name', 'weight' ];

		foreach ( $data['criteria'] as $i => $criterion ) {
			foreach ( $required as $field ) {
				if ( ! isset( $criterion[ $field ] ) ) {
					throw new \InvalidArgumentException(
						sprintf( 'Rubric criterion %d missing required field: %s', $i, $field )
					);
				}
			}
		}
	}

	/**
	 * Clear the suite cache.
	 *
	 * Useful for testing or when files have changed.
	 */
	public function clear_cache(): void {
		$this->suite_cache  = [];
		$this->rubric_cache = [];
	}
}
