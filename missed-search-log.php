<?php
/*
Plugin Name: Missed Search Log
Version: 0.0.1
Plugin URI: https://www.theproperweb.com
Description: Logs searches that did not find a result
Author: PROPER Web Development
Author URI: http://www.theproperweb.com
Text Domain: missedsearch
License: GPL v3
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

// Constants, for consistency

define( 'MISSED_SEARCH_LOG_WP_URL', plugin_dir_url( __FILE__ ) );
define( 'MISSED_SEARCH_LOG_VERSION', '0.0.1' );
define( 'MISSED_SEARCH_LOG_CAPABILITY', 'publish_posts' );


/**
 * Log not found site search queries
 */
function msl_log_missed_search() {

	global $wp_query;

	// Only run if no results on search page

	if ( ! is_search() || ! empty( $wp_query->found_posts ) ) {
		return;
	}

	$not_found_log = get_option( 'msl_missed_searches', array() );
	$search_term   = get_query_var( 's' );

	if ( ! array_key_exists( $search_term, $not_found_log ) ) {
		$not_found_log[ $search_term ] = array(
			'count' => 1,
			'latest' => time()
		);
	} else {
		$not_found_log[ $search_term ]['count'] ++;
		$not_found_log[ $search_term ]['latest'] = time();
	}

	update_option( 'msl_missed_searches', $not_found_log );

}

add_action( 'template_redirect', 'msl_log_missed_search' );

/**
 * Add the reporting page
 */
function msl_display_log_menu() {

	add_management_page(
		__( 'Missed Searches', 'missedsearch' ),
		__( 'Missed Searches', 'missedsearch' ),
		MISSED_SEARCH_LOG_CAPABILITY,
		'missed-search-log',
		'msl_display_log_output'
	);
}

add_action( 'admin_menu', 'msl_display_log_menu' );


/**
 * Missed search log output, tied to add_management_page() call
 *
 * @see msl_display_log_menu()
 */

function msl_display_log_output() {

	$missed_search = msl_get_sorted_missed_searches();

	$remove_button = '<p><a class="button button-primary button-disabled js-msl-bulk-remove-search js-confirm-removal">
		Remove All Checked</a></p>';

	?>
	<div class="wrap">
		<h2>Missed Search Terms</h2>

		<?php echo $remove_button; ?>

		<table class="report-table-basic" id="js-missed-search-table">
			<thead>
			<tr>
				<th>Rank</th>
				<th>Term</th>
				<th>Total Searches</th>
				<th>Last Search</th>
				<th>Remove</th>
				<th>Bulk Remove</th>
			</tr>
			</thead>
			<tbody>
			<?php
			if ( ! empty( $missed_search ) ) {
				$rank = 1;
				foreach ( $missed_search as $term => $data ) :
					?>

					<tr>

						<td><?php echo $rank ?></td>
						<td><?php echo $term ?></td>
						<td><?php echo $data['count'] ?></td>
						<td><?php echo date( 'n/j/Y', $data['latest'] ) ?></td>
						<td><a class="js-confirm-removal" href="<?php echo wp_nonce_url(
								admin_url( 'tools.php?page=missed-search-log&msl-search-term=' . $rank ),
								'msl-missed-search-nonce', 'msl-nonce'
							); ?>"><span class="dashicons dashicons-no"></span></a></td>

						<td><input type="checkbox" name="msl-bulk-remove-search" data-term-rank="<?php
							echo $rank ?>"></td>
					</tr>

					<?php
					$rank ++;
				endforeach;
			} else {
				?>

				<tr>
					<td colspan="6"><em><?php _e( 'No missed searches', 'missedsearch' ) ?></em></td>
				</tr>

				<?php
			}

			?>
			</tbody>
		</table>

		<?php echo $remove_button; ?>
	</div>

<?php
}

/**
 * Remove a missed search term
 */
function msl_search_term_remove() {


	// Need to be on the correct page

	if ( empty( $_GET['page'] ) || 'missed-search-log' !== $_GET['page'] ) {
		return;
	}

	// Need to have a search term

	if ( empty( $_GET['msl-search-term'] ) ) {
		return;
	}

	// Need to be authorized

	if ( ! current_user_can( MISSED_SEARCH_LOG_CAPABILITY ) ) {
		return;
	}

	// Need a valid nonce

	if ( empty( $_GET['msl-nonce'] ) || ! wp_verify_nonce( $_GET['msl-nonce'], 'msl-missed-search-nonce' ) ) {
		return;
	}

	// Look for bulk term removal, make sure we have an array

	if ( strpos( $_GET['msl-search-term'], ',' ) !== FALSE ) {
		$remove_term_rank = array_map( 'absint', explode( ',', $_GET['msl-search-term'] ) );
	} else {
		$remove_term_rank = [ absint( $_GET['msl-search-term'] ) ];
	}

	// Get missed searches sorted by default (date)

	$missed_search = msl_get_sorted_missed_searches();

	$count         = 1;
	$removed_count = 0;

	foreach ( $missed_search as $term => $data ) {

		// Remove term at the same position, keep track of how many we dumped

		if ( in_array( $count, $remove_term_rank ) ) {
			unset( $missed_search[ $term ] );
			$removed_count ++;
		}
		$count ++;
	}

	// New search term list

	update_option( 'msl_missed_searches', $missed_search );

	// Redirect to avoid duplicate submissions

	wp_redirect( admin_url( 'tools.php?page=missed-search-log&msl-term-removed=' . $removed_count ) );

}

add_action( 'admin_init', 'msl_search_term_remove' );

/**
 * 
 */
 
function msl_admin_notice() {
	if (
		! empty( $_GET['page'] ) &&
		'missed-search-log' === $_GET['page'] &&
		! empty( $_GET['msl-term-removed'] )

	) {
		?>
		<div class="updated">
			<p>Removed <?php echo absint( $_GET['msl-term-removed'] ) ?> search terms</p>
		</div>
	<?php
	}
}

add_action( 'admin_notices', 'msl_admin_notice' );

/**
 * Get the missed search term list and sort based on function arg
 *
 * @param string $sort_by
 *
 * @return array
 */

function msl_get_sorted_missed_searches( $sort_by = 'date' ) {

	// Get the array option, not sorted

	$missed_search = get_option( 'msl_missed_searches', [ ] );

	// Sort based on function arg

	switch ( $sort_by ) {

		case 'date':

			// Sort by latest term, newest first

			uasort( $missed_search, function ( $a, $b ) {
				return $a['latest'] < $b['latest'];
			} );
			break;

		case 'count':

			// Sort by total term use, higher first

			uasort( $missed_search, function ( $a, $b ) {
				return $a['count'] < $b['count'];
			} );
			break;

		case 'alpha':

			// Sort alphabetically by search term

			ksort( $missed_search );
			break;
	}

	return $missed_search;
}

/**
 * Oh yeah, no, I know, separate files and all. 
 */

function msl_admin_js_output () {
	?>

	<style type="text/css">

		.card_style, .report-table-basic {
			background: #ffffff;
			-moz-box-shadow: 0 1px 1px #c8bec7;
			-webkit-box-shadow: 0 1px 1px #c8bec7;
			box-shadow: 0 1px 1px #c8bec7;
			border: 1px solid #ffffff;
			text-decoration: none;
			box-sizing: border-box;
			vertical-align: middle;
			border-top-color: #f8f6f8;
		}

		.card_style:hover, .report-table-basic:hover {
			border: 1px solid #4981bc;
		}

		.report-table-basic {
			width: 100%;
			background: #ffffff;
			border: none;
			border-collapse: collapse;
		}

		.report-table-basic:hover {
			border: none;
		}

		.report-table-basic td,
		.report-table-basic th {
			border: none;
			padding: 0.6em;
		}

		.report-table-basic th {
			font-size: 1.1em;
			border-bottom: 1px solid #dedcde;
			text-align: left;
		}

		.report-table-basic tbody tr:hover {
			background: #e2ebf4;
		}

		.report-table-basic a .dashicons-no {
			color: red;
			text-decoration: none;
			cursor: pointer;
		}

	</style>

	<script type="text/javascript">

		var mslRemoveTermUrl = '<?php echo admin_url( 'tools.php?page=missed-search-log&msl-nonce=' .
				wp_create_nonce( 'msl-missed-search-nonce' ).'&msl-search-term=' ); ?>';

		//
		// Search term removal
		//

		// Confirm single term removal

		jQuery('#wpbody').on('click', '.js-confirm-removal', function (e) {

			if (jQuery(this).hasClass('button-disabled')) {
				e.preventDefault();
				alert('Please choose at least one search term');
				return false;
			} else {
				return window.confirm('Remove search term(s)?');
			}
		});

		// Turn the bulk remove button on or off

		var removeBtns = jQuery('a.js-msl-bulk-remove-search');

		prepareBulkTermRemove();

		jQuery('input[name=msl-bulk-remove-search]').change(function () {
			prepareBulkTermRemove();
		});

		function prepareBulkTermRemove() {
			var btnEnabled = false;
			var removeTerms = [];

			jQuery('input[name=msl-bulk-remove-search]').each(function (index) {

				var $el = jQuery(this);

				if ($el.prop('checked')) {
					btnEnabled = true;
					removeTerms.push($el.attr('data-term-rank'));
				}
			});

			if (btnEnabled) {

				removeBtns.each(function (index) {
					jQuery(this)
						.removeClass('button-disabled')
						.attr('href', mslRemoveTermUrl + removeTerms.toString())
				});
			} else {

				removeBtns.each(function (index) {
					jQuery(this)
						.addClass('button-disabled')
						.removeAttr('href');
				});
			}
		}
	</script>

	<?php
}

add_action( 'admin_footer', 'msl_admin_js_output', 1000 );