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
 *
 * @package WordPress\AI_Benchmark
 */

declare(strict_types=1);

namespace WordPress\AI_Benchmark;

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
	add_action(
		'admin_notices',
		function (): void {
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
		}
	);
	return;
}

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
		add_action(
			'admin_notices',
			function (): void {
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
			}
		);
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
	\WP_CLI::add_command( 'ai-bench', CLI\Command::class );
}
