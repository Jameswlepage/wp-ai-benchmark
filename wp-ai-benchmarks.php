<?php
/**
 * Plugin Name: WP AI Benchmarks
 * Plugin URI: https://github.com/WordPress/wp-ai-benchmarks
 * Description: Benchmark LLM performance on WordPress-specific knowledge and code generation tasks
 * Version: 1.0.0
 * Requires at least: 6.9
 * Requires PHP: 8.4
 * Author: WordPress Community
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-ai-benchmarks
 */

declare(strict_types=1);

namespace WP_AI_Benchmarks;

use WordPress\AI_Client\AI_Client;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'WP_AI_BENCH_VERSION', '1.0.0' );
define( 'WP_AI_BENCH_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP_AI_BENCH_URL', plugin_dir_url( __FILE__ ) );

// Load Composer autoloader.
if ( file_exists( WP_AI_BENCH_PATH . 'vendor/autoload.php' ) ) {
	require_once WP_AI_BENCH_PATH . 'vendor/autoload.php';
} else {
	add_action( 'admin_notices', function (): void {
		?>
		<div class="notice notice-error">
			<p>
				<?php
				esc_html_e(
					'WP AI Benchmarks: Composer dependencies not installed. Run "composer install" in the plugin directory.',
					'wp-ai-benchmarks'
				);
				?>
			</p>
		</div>
		<?php
	} );
	return;
}

/**
 * Autoloader for plugin classes.
 *
 * Follows WordPress naming convention: Class_Name -> class-class-name.php
 */
spl_autoload_register( function ( string $class ): void {
	$prefix = 'WP_AI_Benchmarks\\';

	// Check if class uses our namespace.
	if ( strpos( $class, $prefix ) !== 0 ) {
		return;
	}

	// Get relative class name.
	$relative_class = substr( $class, strlen( $prefix ) );

	// Check for CLI namespace.
	if ( strpos( $relative_class, 'CLI\\' ) === 0 ) {
		$relative_class = substr( $relative_class, 4 );
		$file           = WP_AI_BENCH_PATH . 'cli/class-' .
			strtolower( str_replace( '_', '-', $relative_class ) ) . '.php';
	} else {
		$file = WP_AI_BENCH_PATH . 'inc/class-' .
			strtolower( str_replace( '_', '-', $relative_class ) ) . '.php';
	}

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

/**
 * Initialize the AI Client.
 *
 * This sets up HTTP integration and registers the admin settings screen.
 */
function init_ai_client(): void {
	if ( class_exists( AI_Client::class ) ) {
		AI_Client::init();
	}
}
add_action( 'init', __NAMESPACE__ . '\\init_ai_client' );

/**
 * Check plugin dependencies.
 *
 * @return bool True if all dependencies are met.
 */
function check_dependencies(): bool {
	if ( ! class_exists( AI_Client::class ) ) {
		add_action( 'admin_notices', function (): void {
			?>
			<div class="notice notice-error">
				<p>
					<?php
					esc_html_e(
						'WP AI Benchmarks: AI Client not available. Run "composer install" in the plugin directory.',
						'wp-ai-benchmarks'
					);
					?>
				</p>
			</div>
			<?php
		} );
		return false;
	}

	return true;
}

/**
 * Initialize the plugin.
 */
function init(): void {
	// Check dependencies on admin.
	if ( is_admin() ) {
		check_dependencies();
	}
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\init' );

// Register WP-CLI commands.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WP_AI_BENCH_PATH . 'cli/class-ai-bench-command.php';
	\WP_CLI::add_command( 'ai-bench', CLI\AI_Bench_Command::class );
}
