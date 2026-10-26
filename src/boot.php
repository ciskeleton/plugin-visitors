<?php

/**
 * Online Visitors
 *
 * Displays a live count of online visitors on the dashboard root page's sidebar.
 *
 * This plugin only works when the cache driver is using either Redis or Memcached.
 *
 * @package     CiSkeleton\Plugins
 * @subpackage  Visitors
 * @category    Analytics
 * @author      Kader Bouyakoub <bkader[at]mail[dot]com>
 * @copyright   2018-present, Kader Bouyakoub
 * @license     MIT
 * @link        https://opensource.org/license/mit
 *
 * SPDX-License-Identifier: MIT
 *
 * @hook        'plugin_boot_*'.
 */
once_action('plugin_boot_visitors', static function () {
	// Register needed class with `Autoloader`.
	Autoloader::add_class('CI_App_tracker', __DIR__.'/libraries/App/drivers/App_tracker.php');

	// Register our driver.
	once_filter('app_drivers', static function ($drivers) {
		$drivers[] = 'tracker';
		return $drivers;
	});

	// Register initial 'init' action.
	once_action('init', static function ($ci) {
		$ci->app->tracker->initialize();
	});


	// Register online visitors hooks only on dashboard.
	once_action('admin_users_online', static function ($ci = null) {
		$ci ??= CI_Controller::get_instance();

		// Only display counters for admins+.
		if (!$ci->user?->in_dashboard(UserLevel::ADMIN) || !CI_ROOT) {
			return;
		}

		// Prepare total online users count and card header title.
		$total_count = $ci->app->tracker->total();
		$total_label = $ci->lang->line('users_visitors');

		// Prepare member, guest, and bot counts.
		$visitors = [
			'members' => $ci->app->tracker->members(),
			'guests' => $ci->app->tracker->guests(),
			'bots' => $ci->app->tracker->bots()
		];

		// Build Output.
		$rows = '';
		$should_show = false;

		foreach ($visitors as $key => $count) {
			if ($count === 0) {
				continue;
			}

			$should_show = true;

			$rows .= '<tr>';
			$rows .= '<th scope="row">'.$ci->lang->line('users_'.$key).'</th>';
			$rows .= '<td class="text-end">'.$count.'</td>';
			$rows .= '</tr>';
		}

		// Bail early if we have nothing to display.
		if (!$should_show) {
			return;
		}

		echo <<<HTML
<div class="card card-sm">
	<div class="card-header bg-body-secondary border-bottom-0">
		<h3 class="card-title d-flex align-items-stretch h4 mb-0">
			<span class="flex-fill">
				<i class="fas fa-fw fa-user-group me-2" aria-hidden="true"></i>
				{$total_label}
			</span>
			<span class="align-self-end">{$total_count}</span>
		</h3>
	</div><!--/.card-header-->

	<div class="card-body table-responsive p-0">
		<table class="table table-striped">
			<tbody>
				{$rows}
			</tbody>
		</table>
	</div><!--/.card-body-->
</div><!--/.card-->
HTML;

	});
});
