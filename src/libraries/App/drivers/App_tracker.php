<?php

/**
 * CI_App_tracker Class
 *
 * Lightweight user presence tracker for CiSkeleton.
 * Tracks online users (guests, members, bots) Redis or Memcached if available,
 * falls back to using JSON file.
 *
 * @package     CiSkeleton\Plugins
 * @subpackage  Visitors\Libraries
 * @category    App\Drivers
 * @author      Kader Bouyakoub <bkader[at]mail[dot]com>
 * @copyright   2018-present, Kader Bouyakoub
 * @license     MIT
 * @link        https://opensource.org/license/mit
 *
 * SPDX-License-Identifier: MIT
 */

final class CI_App_tracker extends CI_Driver
{
	/**
	 * Unique identifier stored in the session to identify the current visitor.
	 * Used solely for anonymous visitor tracking during their session.
	 *
	 * @var string
	 */
	private $session_key = '__ci_trace_id';

	/**
	 * Unique identifier stored in a cookie to persist the trace ID across
	 * requests when sessions are unavailable (e.g., API, AJAX).
	 *
	 * Used solely for anonymous visitor identification.
	 *
	 * @var string
	 */
	private $cookie_key = 'ci_trace';

	/**
	 * User session timeout (in seconds).
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Current visitor unique identifier (user ID or IP).
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Array of active users loaded from storage.
	 *
	 * @var array
	 */
	private $users;

	/**
	 * Total number of visitors.
	 *
	 * @var int|null
	 */
	private $total;

	/**
	 * Total number or members.
	 *
	 * @var int|null
	 */
	private $members;

	/**
	 * Total number of guests.
	 *
	 * @var int|null
	 */
	private $guests;

	/**
	 * Total number of bots.
	 *
	 * @var int|null
	 */
	private $bots;

	/**
	 * Whether tracker is disabled (background or no Redis).
	 *
	 * @var bool
	 */
	private $disabled = false;

	/**
	 * Storage handler for tracking online users.
	 *
	 * @var CI_Storage
	 */
	private $storage;

	// --------------------------------------------------------------------

	/**
	 * Initialize the tracker.
	 * Called automatically by `CI_App` when initializing active drivers.
	 *
	 * @return void
	 */
	public function initialize()
	{
		// Mark it as disabled for non-UI-requests.
		if (is_non_ui_request()) {
			$this->disabled = true;
			return;
		}

		// Skip initialization if storage already instantiated.
		if (isset($this->storage)) {
			return;
		}

		// Instantiate storage instance.
		$this->storage = new CI_Storage([
			'prefix' => $this->ci->config->item('cookie_prefix'),
			'namespace' => 'cache:visitors',
			'file' => APPPATH.'storage/cache/visitors.json',
		]);

		// Set timeout to 5 minutes.
		$this->timeout = MINUTE_IN_SECONDS * 5;

		// Resolve visitor identity (user ID or IP).
		$this->id = $this->resolve_identity();

		// Load existing users and update presence.
		$this->load_users();
		$this->update_presence();
	}

	// --------------------------------------------------------------------

	/**
	 * Resolves a unique identifier for the current user.
	 * Prefer user ID when logged in, fallback to IP address.
	 *
	 * @return string
	 */
	private function resolve_identity()
	{
		$visitor_id = null;
		$has_session = $this->ci->session->has('session_id');
		$accept_cookies = $this->ci->cookie->accepted();

		// 1. Try session first.
		if ($has_session) {
			$visitor_id = $this->ci->session->get($this->session_key);
		}

		// 2. If not found in session and cookies are allowed, try cookie.
		if (!$visitor_id && $accept_cookies) {
			$visitor_id = $this->ci->cookie->get($this->cookie_key, true);
		}

		// 3. Still not found? Generate a new one.
		if (!$visitor_id) {
			$visitor_id = sha1(
				($has_session ? $this->ci->session->get('session_id') : uniqid('', true)).
				ip_address().
				($_SERVER['HTTP_USER_AGENT'] ?? '')
			);

			// Save to session if available.
			if ($has_session) {
				$this->ci->session->set($this->session_key, $visitor_id);
			}

			// Save to cookie only if allowed.
			if ($accept_cookies) {
				$this->ci->cookie->set($this->cookie_key, $visitor_id, CI_REQUEST_TIME + 3600);
			}
		}

		return $visitor_id;
	}

	// --------------------------------------------------------------------

	/**
	 * Loads the list of active users from the cache.
	 *
	 * @return void
	 */
	private function load_users()
	{
		// Tracking is disabled? Just return an empty array.
		if ($this->disabled) {
			$this->users ??= [];
			return;
		}

		// Load users array from storage.
		$this->users = $this->storage->load();
	}

	// --------------------------------------------------------------------

	/**
	 * Updates the current visitor's presence and prunes stale users.
	 *
	 * @return void
	 */
	private function update_presence()
	{
		if ($this->disabled) {
			return;
		}

		// Special case for '/media' URIs.
		$current_uri = current_uri();
		if (strpos($current_uri, 'media') === 0) {
			return;
		}

		$now = CI_REQUEST_TIME;
		$timeout = $now - $this->timeout;

		// Remove expired users.
		$this->prune_expired($timeout);

		// Register or update current user presence.
		$this->users[$this->id] ??= [];
		$this->users[$this->id]['user_id'] = $this->ci->hub->user_id();
		$this->users[$this->id]['uri'] = $current_uri;
		$this->users[$this->id]['last_active'] = $now;
		$this->users[$this->id]['bot'] ??= is_robot();

		// Update storage.
		$this->storage->save($this->users);
	}

	// --------------------------------------------------------------------

	/**
	 * Removes expired user presences.
	 *
	 * @param int $timeout
	 * @return bool
	 */
	private function prune_expired($timeout)
	{
		$pruned = false;

		foreach ($this->users as $id => $user) {
			if (!isset($user['last_active']) || $user['last_active'] < $timeout) {
				unset($this->users[$id]);
				$pruned  = true;
			}
		}

		return $pruned ? $this->storage->save($this->users) : false;
	}

	// --------------------------------------------------------------------
	// Public API
	// --------------------------------------------------------------------

	/**
	 * Returns TRUE if online visitors tracking is enabled.
	 *
	 * @return bool
	 */
	public function enabled()
	{
		return !$this->disabled;
	}

	// --------------------------------------------------------------------

	/**
	 * Returns the total number of users currently online.
	 *
	 * @return int
	 */
	public function total()
	{
		return $this->total ??= ($this->disabled ? 0 : count($this->users));
	}

	// --------------------------------------------------------------------

	/**
	 * Returns the number of logged-in members online.
	 *
	 * @return int
	 */
	public function members()
	{
		// Already cached?
		if (isset($this->members)) {
			return $this->members;
		}

		// Disabled?
		if ($this->disabled) {
			return $this->members = 0;
		}

		return $this->members = count(array_filter(
			$this->users,
			static fn($user) => $user['user_id'] > 0
		));
	}

	// --------------------------------------------------------------------

	/**
	 * Returns the number of guest users online.
	 *
	 * @return int
	 */
	public function guests()
	{
		// Already cached?
		if (isset($this->guests)) {
			return $this->guests;
		}

		// Disabled?
		if ($this->disabled) {
			return $this->guests = 0;
		}

		return $this->guests = count(array_filter(
			$this->users,
			static fn($user) => $user['user_id'] <= 0
		));
	}

	// --------------------------------------------------------------------

	/**
	 * Returns the number of bots online (if detected).
	 *
	 * @return int
	 */
	public function bots()
	{
		// Already cached?
		if (isset($this->bots)) {
			return $this->bots;
		}

		// Disabled?
		if ($this->disabled) {
			return $this->bots = 0;
		}

		return $this->bots = count(array_filter($this->users, static fn($user) => $user['bot']));
	}

	// --------------------------------------------------------------------

	/**
	 * Returns the complete list of users currently online.
	 *
	 * @return array
	 */
	public function all()
	{
		return $this->users;
	}

}
