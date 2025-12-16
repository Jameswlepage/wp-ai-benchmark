<?php
/**
 * Test suite loader.
 *
 * @package WordPress\AI_Benchmark
 */

declare(strict_types=1);

namespace WordPress\AI_Benchmark;

use JsonException;
use WordPress\AI_Benchmark\Test\Execution_Test;
use WordPress\AI_Benchmark\Test\Knowledge_Test;
use WordPress\AI_Benchmark\Test\Test_Interface;

/**
 * Loads and validates test suites from JSON files.
 *
 * Test suites are organized in:
 * - tests/knowledge/{suite-name}.json for knowledge tests
 * - tests/execution/{suite-name}.json for execution tests
 * - judges/{rubric-id}.json for judge rubrics
 */
class Suite_Loader {

	/**
	 * Path to tests directory.
	 *
	 * @var string
	 */
	private string $tests_path;

	/**
	 * Path to judges directory.
	 *
	 * @var string
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
	 *   knowledge_tests: array<Knowledge_Test>,
	 *   execution_tests: array<Execution_Test>,
	 *   metadata: array<string, mixed>
	 * }
	 *
	 * @throws \Exception If suite not found or JSON is invalid.
	 */
	public function load( string $suite_name ): array {
		if ( isset( $this->suite_cache[ $suite_name ] ) ) {
			/** @var array{knowledge_tests: array<Knowledge_Test>, execution_tests: array<Execution_Test>, metadata: array<string, mixed>} */
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

			/** @var list<array<string, mixed>> $knowledge_test_data */
			$knowledge_test_data = $knowledge_data['tests'];
			$knowledge_tests     = array_map(
				static fn( array $data ): Knowledge_Test => Knowledge_Test::from_array( $data ),
				$knowledge_test_data
			);

			$meta_value     = $knowledge_data['metadata'] ?? [];
			$knowledge_meta = is_array( $meta_value ) ? $meta_value : [];
		}

		// Load execution tests if file exists.
		if ( file_exists( $execution_file ) ) {
			$execution_data = $this->load_json_file( $execution_file );
			$this->validate_execution_suite( $execution_data );

			/** @var list<array<string, mixed>> $execution_test_data */
			$execution_test_data = $execution_data['tests'];
			$execution_tests     = array_map(
				static fn( array $data ): Execution_Test => Execution_Test::from_array( $data ),
				$execution_test_data
			);

			$meta_value     = $execution_data['metadata'] ?? [];
			$execution_meta = is_array( $meta_value ) ? $meta_value : [];
		}

		// Ensure at least one type exists.
		if ( empty( $knowledge_tests ) && empty( $execution_tests ) ) {
			throw new \InvalidArgumentException(
				sprintf( "Suite '%s' not found or contains no tests.", esc_html( $suite_name ) )
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
		$knowledge_files   = glob( $knowledge_pattern );
		foreach ( false !== $knowledge_files ? $knowledge_files : [] as $file ) {
			$name          = basename( $file, '.json' );
			$seen[ $name ] = true;

			try {
				$data            = $this->load_json_file( $file );
				$tests           = $data['tests'] ?? [];
				$tests_count     = is_array( $tests ) ? count( $tests ) : 0;
				$metadata        = $data['metadata'] ?? [];
				$description     = is_array( $metadata ) && isset( $metadata['description'] ) && is_string( $metadata['description'] )
					? $metadata['description']
					: '';
				$suites[ $name ] = [
					'name'            => $name,
					'types'           => [ 'knowledge' ],
					'knowledge_count' => $tests_count,
					'execution_count' => 0,
					'description'     => $description,
				];
			} catch ( \Throwable $e ) {
				// Skip invalid files.
				continue;
			}
		}

		// Scan execution directory and merge.
		$execution_pattern = $this->tests_path . 'execution/*.json';
		$execution_files   = glob( $execution_pattern );
		foreach ( false !== $execution_files ? $execution_files : [] as $file ) {
			$name = basename( $file, '.json' );

			try {
				$data        = $this->load_json_file( $file );
				$tests       = $data['tests'] ?? [];
				$exec_count  = is_array( $tests ) ? count( $tests ) : 0;
				$metadata    = $data['metadata'] ?? [];
				$description = is_array( $metadata ) && isset( $metadata['description'] ) && is_string( $metadata['description'] )
					? $metadata['description']
					: '';

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
						'description'     => $description,
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
	 * @return array{type: string, test: Test_Interface, suite: string}
	 *
	 * @throws \InvalidArgumentException If test not found.
	 */
	public function find_test_by_id( string $test_id ): array {
		// Search knowledge tests.
		$knowledge_pattern   = $this->tests_path . 'knowledge/*.json';
		$knowledge_files_arr = glob( $knowledge_pattern );
		foreach ( false !== $knowledge_files_arr ? $knowledge_files_arr : [] as $file ) {
			try {
				$data  = $this->load_json_file( $file );
				$tests = $data['tests'] ?? [];
				if ( ! is_array( $tests ) ) {
					continue;
				}
				foreach ( $tests as $test_data ) {
					if ( ! is_array( $test_data ) ) {
						continue;
					}
					$id = $test_data['id'] ?? '';
					if ( is_string( $id ) && $test_id === $id ) {
						return [
							'type'  => 'knowledge',
							'test'  => Knowledge_Test::from_array( $test_data ),
							'suite' => basename( $file, '.json' ),
						];
					}
				}
			} catch ( \Throwable $e ) {
				continue;
			}
		}

		// Search execution tests.
		$execution_pattern   = $this->tests_path . 'execution/*.json';
		$execution_files_arr = glob( $execution_pattern );
		foreach ( false !== $execution_files_arr ? $execution_files_arr : [] as $file ) {
			try {
				$data  = $this->load_json_file( $file );
				$tests = $data['tests'] ?? [];
				if ( ! is_array( $tests ) ) {
					continue;
				}
				foreach ( $tests as $test_data ) {
					if ( ! is_array( $test_data ) ) {
						continue;
					}
					$id = $test_data['id'] ?? '';
					if ( is_string( $id ) && $test_id === $id ) {
						return [
							'type'  => 'execution',
							'test'  => Execution_Test::from_array( $test_data ),
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
		$files   = glob( $pattern );

		foreach ( false !== $files ? $files : [] as $file ) {
			try {
				$data        = $this->load_json_file( $file );
				$metadata    = $data['metadata'] ?? [];
				$default_id  = basename( $file, '.json' );
				$name        = is_array( $metadata ) && isset( $metadata['name'] ) && is_string( $metadata['name'] )
					? $metadata['name']
					: $default_id;
				$description = is_array( $metadata ) && isset( $metadata['description'] ) && is_string( $metadata['description'] )
					? $metadata['description']
					: '';
				$rubrics[]   = [
					'id'          => $default_id,
					'name'        => $name,
					'description' => $description,
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
			$cat                = $test->get_category();
			$categories[ $cat ] = ( $categories[ $cat ] ?? 0 ) + 1;
		}

		foreach ( $suite['execution_tests'] as $test ) {
			$cat                = $test->get_category();
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
	 * @throws \RuntimeException If file cannot be read or decoded data is not an array.
	 */
	private function load_json_file( string $path ): array {
		$content = file_get_contents( $path );

		if ( false === $content ) {
			throw new \RuntimeException(
				sprintf( 'Cannot read file: %s', esc_html( $path ) )
			);
		}

		$decoded = json_decode( $content, true, 512, JSON_THROW_ON_ERROR );

		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException(
				sprintf( 'JSON file must contain an object or array: %s', esc_html( $path ) )
			);
		}

		return $decoded;
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
						sprintf( 'Knowledge test %s missing required field: %s', esc_html( (string) $i ), esc_html( $field ) )
					);
				}
			}

			// Multiple choice requires choices.
			if ( 'multiple_choice' === $test['type'] && ! isset( $test['choices'] ) ) {
				throw new \InvalidArgumentException(
					sprintf( "Multiple choice test %s missing 'choices' field.", esc_html( (string) $i ) )
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
						sprintf( 'Execution test %s missing required field: %s', esc_html( (string) $i ), esc_html( $field ) )
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
						sprintf( 'Rubric criterion %s missing required field: %s', esc_html( (string) $i ), esc_html( $field ) )
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
