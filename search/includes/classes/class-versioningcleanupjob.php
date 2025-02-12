<?php

namespace Automattic\VIP\Search;

class VersioningCleanupJob {

	/**
	 * The name of the scheduled cron event to run the versioning cleanup.
	 */
	const CRON_EVENT_NAME = 'vip_search_versioning_cleanup';

	const SEARCH_ALERT_SLACK_CHAT = '#vip-go-es-alerts';

  public $indexables;
  public $versioning;

	public function __construct( $indexables, $versioning ) {
		$this->indexables = $indexables;
		$this->versioning = $versioning;
	}

	/**
	 * Initialize the job class.
	 *
	 * @access  public
	 */
	public function init() {
		if ( ! defined( 'VIP_GO_APP_ENVIRONMENT' ) ) {
			return;
		}

		add_action( self::CRON_EVENT_NAME, [ $this, 'versioning_cleanup' ] );

		$this->schedule_job();
	}

	/**
	 * Schedule class versioning job.
	 *
	 * Add the event name to WP cron schedule and then add the action.
	 */
	public function schedule_job() {
		if ( ! wp_next_scheduled( self::CRON_EVENT_NAME ) ) {
			// phpcs:disable WordPress.WP.AlternativeFunctions.rand_mt_rand
			wp_schedule_event( time() + ( mt_rand( 1, 7 ) * DAY_IN_SECONDS ), 'weekly', self::CRON_EVENT_NAME );
		}
	}

	/**
	 * Process versioning cleanup.
	 */
	public function versioning_cleanup() {
		$indexables = $this->indexables->get_all();

		foreach ( $indexables as $indexable ) {
			$inactive_versions = $this->get_stale_inactive_versions( $indexable );

			foreach ( $inactive_versions as $version ) {
				if ( ! ( $version['active'] ?? false ) ) {
					// double check that it is not active
					$this->delete_stale_inactive_version( $indexable, $version['number'] );
				}
			}
		}
	}


	/**
	 * Retrieve inactive versions to be deleted
	 *
	 * @param \ElasticPress\Indexable $indexable The Indexable for which to retrieve index versions
	 * @return array Array of inactive index versions
	 */
	public function get_stale_inactive_versions( \ElasticPress\Indexable $indexable ) {
		$versions = $this->versioning->get_versions( $indexable );

		if ( ! $versions && ! is_array( $versions ) ) {
			return [];
		}

		$active_version = $this->versioning->get_active_version( $indexable );

		if ( ! $active_version || ! $active_version['activated_time'] ) {
			// No active version or active version doesn't have activated time making it impossible to determine if it was activated recently or not
			return [];
		}

		$breakpoint_time        = defined( 'VIP_GO_APP_ENVIRONMENT' ) && constant( 'VIP_GO_APP_ENVIRONMENT' ) === 'production' ? 2 * \WEEK_IN_SECONDS : 1 * \WEEK_IN_SECONDS;
		$time_ago_breakpoint    = time() - $breakpoint_time;
		$was_activated_recently = $active_version['activated_time'] > $time_ago_breakpoint;
		if ( $was_activated_recently ) {
			// We want to keep the old version for a period of time, even if it's older than the cutoff time period, while the new version proves stable
			return [];
		}

		$inactive_versions = [];
		foreach ( $versions as $version ) {
			if ( $version['active'] ?? false ) {
				continue;
			}

			if ( ! isset( $version['created_time'] ) && 1 === $version['number'] ) {
				// If the inactive version doesn't have a created_time and it's 1, we'll use the active version's activation time for cut-off
				$version['created_time'] = $active_version['activated_time'];
			}

			if ( ( $version['created_time'] ?? time() ) > $time_ago_breakpoint ) {
				// If the version was created within within the cutoff period, it is not inactive
				continue;
			}

			array_push( $inactive_versions, $version );
		}

		return $inactive_versions;
	}

	/**
	 * Delete stale inactive version
	 *
	 * @param \ElasticPress\Indexable $indexable The Indexable
	 * @param int $version The version number of the Indexable we are deleting
	 */
	public function delete_stale_inactive_version( $indexable, $version ) {
		$delete_version = $this->versioning->delete_version( $indexable, $version );

		if ( is_wp_error( $delete_version ) ) {
			$message = sprintf(
				"Application %d - %s Unsuccessfully deleted inactive index version %s for '%s' indexable: %s",
				FILES_CLIENT_SITE_ID,
				home_url(),
				$version,
				$indexable->slug,
				$delete_version->get_error_message()
			);
			\Automattic\VIP\Utils\Alerts::chat( self::SEARCH_ALERT_SLACK_CHAT, $message );
		} else {
			\Automattic\VIP\Logstash\log2logstash(
				array(
					'severity' => 'info',
					'feature'  => 'search_versioning',
					'message'  => 'Successfully deleted inactive index version',
					'extra'    => [
						'homeurl'         => home_url(),
						'version_deleted' => $version,
						'indexable'       => $indexable->slug,
					],
				)
			);
		}
	}
}
