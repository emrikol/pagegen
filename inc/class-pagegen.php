<?php
/**
 * Main file for Pagegen class.
 *
 * @package WordPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The primary class for Pagegen.
 */
class Pagegen {
	/**
	 * The unique instance of the plugin.
	 *
	 * @var Pagegen
	 */
	private static $instance;

	/**
	 * The widget ID for the dashboard.
	 *
	 * @string WIDGET_ID
	 */
	const WIDGET_ID = 'pagegen_chart';

	/**
	 * The different types of stats we can report on.
	 *
	 * @array STATS_TYPES
	 */
	const STATS_TYPES = array(
		'pub'   => array( 'Public', '#0073AA' ),
		'admin' => array( 'Admin', '#826EB4' ),
		'rest'  => array( 'REST API', '#46B450' ),
		'cron'  => array( 'Cron', '#00A0D2' ),
		'all'   => array( 'All', '#191E23' ),
	);

	/**
	 * The database table name that stores our data.
	 */
	const TABLE_NAME = 'pagegen';

	/**
	 * Gets an instance of our plugin.
	 *
	 * @return Pagegen
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initializes hooks for admin screen.
	 */
	public function init_hooks() {
		add_action( 'shutdown', array( $this, 'shutdown' ), PHP_INT_MIN ); // Run ASAP, we don't care about the rest of the shutdown actions.
		add_action( 'wp_dashboard_setup', array( $this, 'wp_dashboard_setup' ) );
		add_action( 'init', array( $this, 'register_cron' ) );
		add_action( 'pagegen_purge', array( $this, 'pagegen_purge' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
	}
	/**
	 * Registers ChartJS.
	 */
	public function admin_enqueue_scripts() {
		wp_register_script( 'chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4.4.0', true );
	}

	/**
	 * Creates the pagegen table.
	 *
	 * @return void
	 */
	private function create_table(): void {
		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			$sql = "CREATE TABLE `$table_name` (
				`ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				`timestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`time` float NOT NULL,
				`url` text COLLATE {$wpdb->collate} NOT NULL,
				`is_user_logged_in` tinyint(1) NOT NULL,
				`is_admin` tinyint(1) NOT NULL,
				`is_rest` tinyint(1) NOT NULL,
				`is_cron` tinyint(1) NOT NULL,
				PRIMARY KEY (`ID`),
				KEY `timestamp` (`timestamp`)
			) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET={$wpdb->charset} COLLATE={$wpdb->collate}";
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );
		}
		update_option( 'pagegen_table', '1', false );
	}

	/**
	 * Records data about page generation, and optionally creates the database table.
	 *
	 * TODO: Move table creating to activation hook.
	 *
	 * @return void
	 */
	public function shutdown(): void {
		global $wpdb;

		// Check to make sure we've got a table.  Create if not.
		$has_pagegen_table = get_option( 'pagegen_table' );
		$table_name        = $wpdb->prefix . self::TABLE_NAME;

		if ( '1' !== $has_pagegen_table ) {
			self::create_table();
		}

		$data = array(
			'time'              => microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'], // phpcs:ignore Generic.PHP.Syntax.PHPSyntax,WordPress.Security.ValidatedSanitizedInput.InputNotValidated
			'url'               => esc_url_raw( ( is_ssl() ? 'https' : 'http' ) . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'is_user_logged_in' => (bool) is_user_logged_in(),
			'is_admin'          => (bool) is_admin(),
			'is_rest'           => defined( 'REST_REQUEST' ) ? (bool) REST_REQUEST : false,
			'is_cron'           => (bool) wp_doing_cron(),
		);
		$wpdb->insert( $table_name, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * Sets up the dashboard widget.
	 */
	public function wp_dashboard_setup() {
		wp_add_dashboard_widget( self::WIDGET_ID, esc_html__( 'Page Generation Time', 'pagegen' ), array( $this, 'generate_widget' ), array( $this, 'pagegen_dashboard_widget_control' ) );
	}

	/**
	 * Outputs the dashboard widget.
	 */
	public function generate_widget() {
		$datasets      = array();
		$options       = self::get_dashboard_widget_options( self::WIDGET_ID );
		$grand_average = array();

		if ( empty( $options ) ) {
			return;
		}

		foreach ( self::STATS_TYPES as $stats_type => $stats_type_data ) {
			if ( ! $options[ $stats_type ] ) {
				continue;
			}

			$stats   = self::get_data( $stats_type );
			$labels  = array();
			$data    = array();
			$average = array();
			$views   = array();

			foreach ( $stats as $stat ) {
				$labels[]        = gmdate( 'n-j G', strtotime( $stat->day ) );
				$average[]       = $stat->average;
				$views[]         = $stat->views;
				$grand_average[] = $stat->average;
			}

			$label = $stats_type_data[0];

			// Add unique averages to labels if there are more than one.
			if ( count( array_filter( $options ) ) > 1 ) {
				$label .= ' (' . number_format( array_sum( $average ) / count( $average ), 2 ) . 's)';
			}

			$datasets[] = array(
				'label'                  => $label,
				'backgroundColor'        => $stats_type_data[1],
				'borderColor'            => $stats_type_data[1],
				'cubicInterpolationMode' => 'monotone',
				'fill'                   => false,
				'data'                   => $average,
			);
		}

		if ( empty( $grand_average ) ) {
			echo '<p>No data yet!</p>';
			return;
		}

		wp_enqueue_script( 'chartjs' );
		?>

		<script>
			jQuery(document).ready(function($){
				var $toggle = $('#js-toggle-pagegen_dashboard_widget_control');

				$toggle.parent().prev().append( $toggle );
				$toggle.show().click(function(e){
					e.preventDefault();
					e.stopImmediatePropagation();
					$(this).parent().toggleClass('controlVisible');
					$('#pagegen_dashboard_widget_control').slideToggle();
				});
			});
		</script>

		<canvas id="<?php echo esc_attr( self::WIDGET_ID ); ?>_canvas" style="height: 250px; width: 100%"></canvas>
		<p><strong>Average:</strong> <code><?php echo esc_html( number_format( array_sum( $grand_average ) / count( $grand_average ), 2 ) ); ?>s</code></p>
		<p><strong>Note:</strong> Page generation time is the server time including uncached database calls and any remote data fetched server side. It is not page load time, nor does the average include full HTML cached views.</p>
		<script>
			jQuery( function() {
				var chart = new Chart( document.getElementById( '<?php echo esc_attr( self::WIDGET_ID ); ?>_canvas' ).getContext('2d'), {
					type: 'line',
					data: {
						labels: <?php echo wp_json_encode( $labels ); ?>,
						datasets: <?php echo wp_json_encode( $datasets ); ?>
					},
				} );
			} );
		</script>
		<?php
	}

	/**
	 * Grabs page generation data from the database.
	 *
	 * @param string $stats_type The type of stats to generate.
	 * @param string $time_stamp_start The starting timestamp.
	 * @param string $time_stamp_end The ending timestamp.
	 */
	public function get_data( $stats_type = 'pub', $time_stamp_start = null, $time_stamp_end = null ) {
		global $wpdb;

		$last_time  = $time_stamp_end ? $time_stamp_end : time();
		$first_time = $time_stamp_start ? $time_stamp_start : $last_time - ( DAY_IN_SECONDS * 30 );
		$cache_key  = $stats_type . '-' . gmdate( 'Y-m-d-H', $first_time ) . '-' . gmdate( 'Y-m-d-H', $last_time );
		$stats      = wp_cache_get( $cache_key, 'pagegen' );
		$table_name = $wpdb->prefix . self::TABLE_NAME;

		if ( false === $stats ) {
			switch ( $stats_type ) {
				case 'pub':
					$where_and = ' AND `is_admin`=false AND `is_rest`=false AND `is_cron`=false';
					break;
				case 'admin':
					$where_and = ' AND `is_admin`=true AND `is_cron`=false';
					break;
				case 'rest':
					$where_and = ' AND `is_rest`=true AND `is_cron`=false';
					break;
				case 'cron':
					$where_and = ' AND `is_cron`=true';
					break;
				case 'all':
					$where_and = '';
					break;
			}
			$sql   = "SELECT AVG( `time` ) as `average`, COUNT( `time` ) as `views`, MAX( `time` ) as `max`, MIN( `time` ) as `min`, DATE_FORMAT( `timestamp`, '%Y-%m-%d' ) as `day` FROM `$table_name` WHERE `timestamp` BETWEEN '" . esc_sql( gmdate( 'Y-m-d H:i:s', $first_time ) ) . "' AND '" . esc_sql( gmdate( 'Y-m-d H:i:s', $last_time ) ) . "'" . $where_and . ' GROUP BY DATE( `timestamp` );';
			$stats = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			wp_cache_set( $cache_key, $stats, 'pagegen', HOUR_IN_SECONDS );
		}

		return $stats;
	}

	/**
	 * Scheduled an hourly cron event to purge old data.
	 */
	public function register_cron() {
		if ( ! wp_next_scheduled( 'pagegen_purge' ) ) {
			wp_schedule_event( time(), 'hourly', 'pagegen_purge' );
		}
	}

	/**
	 * Purges data older than 30 days.
	 */
	public function pagegen_purge() {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM $table_name WHERE UNIX_TIMESTAMP(timestamp) < UNIX_TIMESTAMP(NOW() - INTERVAL ( 24 * 30 ) HOUR)" );
	}

	/**
	 * Pagegen Dashboard Widget Control.
	 *
	 * @access public
	 * @return void
	 */
	public function pagegen_dashboard_widget_control() {
		$options = array();
		foreach ( self::STATS_TYPES as $stats_type => $stats_type_data ) {
			if ( 'pub' === $stats_type ) {
				// pub defaults to true.
				$options[ $stats_type ] = self::get_dashboard_widget_option( self::WIDGET_ID, $stats_type, true );
			} else {
				// all others false.
				$options[ $stats_type ] = self::get_dashboard_widget_option( self::WIDGET_ID, $stats_type, false );
			}

			if ( isset( $_POST['pagegen_stats_type'] ) ) {
				check_admin_referer( 'pagegen_dashboard_widget_control' );
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
				if ( in_array( $stats_type, $_POST['pagegen_stats_type'], true ) ) {
					$options[ $stats_type ] = true;
				} else {
					$options[ $stats_type ] = false;
				}
			}
			?>
			<p>
				<input type="checkbox" <?php checked( $options[ $stats_type ], true ); ?> name="pagegen_stats_type[]" value="<?php echo esc_attr( $stats_type ); ?>" id="pagegen_stats_type_<?php echo esc_attr( $stats_type ); ?>">
				<label for="pagegen_stats_type_<?php echo esc_attr( $stats_type ); ?>">Show <?php echo esc_html( $stats_type_data[0] ); ?></label>
			</p>
			<?php
		}
		wp_nonce_field( 'pagegen_dashboard_widget_control' );

		if ( isset( $_POST['pagegen_stats_type'] ) ) {
			self::update_dashboard_widget_options( self::WIDGET_ID, $options );
		}
	}

	/**
	 * Gets the options for a widget of the specified name.
	 *
	 * @param string $widget_id Optional. If provided, will only get options for the specified widget.
	 *
	 * @return array An associative array containing the widget's options and values.
	 */
	public static function get_dashboard_widget_options( string $widget_id = '' ): array {
		// Fetch ALL dashboard widget options from the db.
		$opts = get_option( 'dashboard_widget_options', array() );

		// If we request a widget and it exists, return it.
		if ( isset( $opts[ $widget_id ] ) ) {
			return (array) $opts[ $widget_id ];
		}

		return $opts;
	}

	/**
	 * Gets one specific option for the specified widget.
	 *
	 * @param string $widget_id   The widget ID to grab options for.
	 * @param string $option      The option to grab.
	 * @param mixed  $default_val The default option to return if none found.
	 *
	 * @return mixed
	 */
	public static function get_dashboard_widget_option( string $widget_id, string $option, $default_val = null ) {
		$opts = self::get_dashboard_widget_options( $widget_id );

		// If widget opts don't exist, return default value.
		if ( ! isset( $opts[ $option ] ) ) {
			return $default_val;
		}

		return $opts[ $option ];
	}

	/**
	 * Saves an array of options for a single dashboard widget to the database.
	 * Can also be used to define default values for a widget.
	 *
	 * @param string $widget_id The name of the widget being updated.
	 * @param array  $args An associative array of options being saved.
	 *
	 * @return bool Whether or not options were updated.
	 */
	public static function update_dashboard_widget_options( string $widget_id, array $args = array() ): bool {
		if ( empty( $widget_id ) ) {
			return false;
		}

		// Fetch ALL dashboard widget options.
		$opts = self::get_dashboard_widget_options();

		// Merge new args with existing ones.
		$opts[ $widget_id ] = array_merge( (array) ( $opts[ $widget_id ] ?? array() ), $args );

		// Save the entire widgets array back to the db.
		return update_option( 'dashboard_widget_options', $opts, false );
	}
}
