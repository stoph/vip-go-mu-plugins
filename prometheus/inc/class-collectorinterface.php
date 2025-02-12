<?php

namespace Automattic\VIP\Prometheus;

use Prometheus\RegistryInterface;

interface CollectorInterface {
	public function initialize( RegistryInterface $registry ): void;

	/**
	 * Last chance to collect metrics before sending them to the scraper.
	 * 
	 * This can be useful, for, for example, gauges which measure something external wrt the application (e.g., APC cache stats)
	 */
	public function collect_metrics(): void;

	/**
	 * Process metrics off the web request path, this is useful for metrics that are expensive to calculate on every request.
	 * @todo Disabled until after the initial production deploy. See https://github.com/Automattic/vip-go-mu-plugins/pull/4109#discussion_r1122342349
	 */
	// phpcs:ignore Squiz.PHP.CommentedOutCode.Found
	// public function process_metrics(): void;
}
