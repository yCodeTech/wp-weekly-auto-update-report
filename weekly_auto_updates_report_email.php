<?php

/**
 * WP Weekly Auto Updates Report Email
 *
 * Licensed under The MIT License
 *
 * @author     Stuart Norman - @yCodeTech <stuart-norman@hotmail.com>
 * @copyright  2025 yCodeTech
 * @license    The MIT License
 * @version    1.0.0
 */


/****************************
 *!    For testing ONLY     *
 *! Don't use in production *
 ****************************/

// Force an auto update in 1 minute, set with a unique arg to override any other update.
// wp_schedule_single_event(time() + MINUTE_IN_SECONDS, 'wp_maybe_auto_update', ["sfasgfasdg"]);

// Force an email to be sent.
// wp_schedule_single_event(time(), 'send_weekly_report_hook', ["kelekekea"]);

/****************************
 *       End testing        *
 ****************************/


/******************************
 * Weekly Auto Updates Report *
 ******************************/

new WeeklyUpdatesReport();

/**
 * Class to setup a weekly report email for auto updates.
 *
 * If WordPress Core, a 3rd party theme, or a plugin is auto-updated,
 * each update item's info gets logged into an array in a file.
 * A cron job is setup to run once a week, that generates and sends an email report
 * from the data in the file.
 */
class WeeklyUpdatesReport {
	/**
	 * The logs directory in the active theme.
	 * @var string
	 */
	private string $logs_dir;

	/**
	 * The log file.
	 * @var string
	 */
	private string $file;

	/**
	 * Email address(es) to send the email to.
	 * @var array
	 */
	private array $email_to;

	/**
	 * Extra email addresses to send the email to.
	 * @var array
	 */
	private array $extra_email_addresses = [];

	/**
	 * The email headers
	 * @var array
	 */
	private array $email_headers = ['Content-Type: text/html; charset=UTF-8'];

	/**
	 * The email subject
	 * @var string
	 */
	private string $email_subject;

	public function __construct() {
		$this->logs_dir = get_stylesheet_directory() . "/logs";
		$this->file = "$this->logs_dir/auto_updated_items.json";
		$this->email_subject = "[". get_bloginfo('name') ."] Weekly auto-update report";

		$this->setup_hooks();
		$this->setup_weekly_cron();
		$this->get_email_addresses();
	}

	/**
	 * Setup action and filter hooks.
	 */
	private function setup_hooks() {

		/**
		 * Disable Auto theme updates
		 * (shouldn't really be activated for custom themes).
		 */
		add_filter('auto_update_theme', '__return_false');

		/**
		 * Disable auto-update emails.
		 * We'll send a weekly email report instead.
		 */
		// Disable core update emails
		add_filter('auto_core_update_send_email', '__return_false');
		// Disable plugin update emails
		add_filter('auto_plugin_update_send_email', '__return_false');
		// Disable theme update emails
		add_filter('auto_theme_update_send_email', '__return_false');

		/**
		 * The main action hook.
		 *
		 * Before an auto update happens, collect each update item info in a file,
		 * to use for the weekly email summary.
		 */
		add_action('pre_auto_update', [$this, 'store_updated_items'], 10, 3);

		/**
		 * The action hook for the cron job to execute.
		 *
		 * Sends the weekly email report.
		 */
		add_action("send_weekly_report_hook", [$this, "send_weekly_report"]);
	}

	/**
	 * Collect each update item's info in a file, to be able to generate the weekly summary email.
	 *
	 * Implements the `pre_auto_update` action hook.
	 *
	 * @param string $type The type of update being checked: 'core', 'theme', 'plugin', or 'translation'.
	 *
	 * @param object $item An object of information about the item that is being updated.
	 *
	 * @param string $context The absolute path where the item lives, eg. for plugins this is `.../wp-content/plugins`
	 *
	 * @public To enable usage by the cron action hook.
	 */
	public function store_updated_items($type, $item, $context) {

		// If type is core...
		if ($type === "core") {
			$name = "wordpress_core";
			$display_name = "WordPress Core";
			$current_version = wp_get_wp_version();
			$new_version = $item->version;
		}
		// If type is plugin...
		elseif ($type === 'plugin') {
			$name = $item->plugin;

			// Get an array of the current plugin data before it's updated, using the plugin's main
			// file path. eg. `.../plugin-name-directory/plugin-name.php`.
			$plugin_info = get_plugin_data($context . '/' . $name, false, false);

			$display_name = $plugin_info['Name'];
			$current_version = $plugin_info["Version"];
			$new_version = $item->new_version;
		}
		// If type is theme...
		elseif ($type === "theme") {
			$name = $item->theme;
			$display_name = wp_get_theme($name)->get("Name");
			$current_version = wp_get_theme($name)->get('Version');
			$new_version = $item->new_version;
		}
		// Otherwise it's a translation update.
		// We don't need to keep record of these, so just return.
		else {
			return;
		}

		// Create an array with the update item's data.
		$data = [
			"date" => date('d/m/Y h:ia'),
			"type" => ucfirst($type),
			"name" => $name,
			"display_name" => $display_name,
			"version_from" => $current_version ?? "",
			"version_to" => $new_version ?? "",
			"url" => $item->url ?? ""
		];

		// Create an associative array with the key "updated" set to an empty array.
		$array = ["updated" => []];

		$this->ensure_dir_exists();

		$file_contents = $this->read_file();

		// Set the old data into the new json array.
		$array["updated"] = $file_contents["updated"];

		// Push the new data into the array.
		array_push($array["updated"], $data);

		// Write the new contents to the file.
		$this->write_file($array);
	}

	/**
	 * Ensure the logs directory exists. If it doesn't, then create the directory.
	 */
	private function ensure_dir_exists() {
		if (!is_dir($this->logs_dir)) {
			mkdir($this->logs_dir);
		}
	}

	/**
	 * Read the data in the file, decoding JSON to an associative array.
	 *
	 * @return array An associative array of the contents of the JSON file.
	 * If the file doesn't exist, returns an empty default array.
	 */
	private function read_file() {
		// If file exists...
		if (file_exists($this->file)) {
			// Get the contents of the file and decode it's json into an associative array.
			return json_decode(file_get_contents($this->file), true);
		}
		// Otherwise, return the default empty array.
		return ["updated" => []];
	}

	/**
	 * Write the data to the file, encoding it to JSON.
	 * @param array $data The data to write.
	 */
	private function write_file($data) {
		// Encode the array as json and write it to the file.
		file_put_contents($this->file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
	}

	/**
	 * Deletes the file and the logs directory.
	 */
	private function delete_file() {
		// Delete the file as it's no longer needed.
		unlink($this->file);
		// Delete the logs directory.
		rmdir($this->logs_dir);
	}

	/**
	 * Setup weekly cron job.
	 *
	 * @link https://developer.wordpress.org/reference/functions/wp_schedule_event/
	 */
	private function setup_weekly_cron() {
		if (! wp_next_scheduled('send_weekly_report_hook')) {
			wp_schedule_event(time(), 'weekly', 'send_weekly_report_hook');
		}
	}

	/**
	 * Send weekly report email.
	 *
	 * Implements the `send_weekly_report_hook` action hook.
	 *
	 * @public To enable usage by the cron action hook.
	 */
	public function send_weekly_report() {

		// If file doesn't exist, just return.
		if (!file_exists($this->file)) {
			return;
		}

		$data = $this->read_file();

		// If there's no data, just return.
		if (empty($data['updated'])) {
			return;
		}

		$html = $this->create_email_html($data);

		$has_email_sent = wp_mail($this->email_to, $this->email_subject, $html, $this->email_headers);

		// If email was sent successfully...
		if ($has_email_sent) {
			$this->delete_file();
		}
	}

	/**
	 * Get Admin and Dev email addresses.
	 */
	private function get_email_addresses() {
		$admin = get_option('admin_email');

		$extra_email_addresses = $this->extra_email_addresses;

		// If extra_email_addresses array has more than 0 items (not empty)...
		if (count($extra_email_addresses) > 0) {
			// Loop through the array...
			foreach ($extra_email_addresses as $key => $value) {
				// If `@` symbol doesn't exist in the value, then it's a username...
				if (strpos($value, '@') === false) {
					// Get the user's email address by their username and set the email address
					// in to the extra_email_addresses property replacing the initial username.
					$this->extra_email_addresses[$key] = get_user_by('login', $value)->user_email;
				}
			}
		}

		// Merge the admin email address with the extra ones, and set into the email_to property.
		$this->email_to = array_merge([$admin], $this->extra_email_addresses);
	}

	/**
	 * Create the Email HTML.
	 * @param array $data The data to create the email with.
	 *
	 * @return string The email HTML.
	 */
	private function create_email_html($data) {
		$border = "border-bottom: 1px solid #dbdbdb;";
		$padding = "padding: 1rem;";

		$html = "Your site has automatically updated its plugins, themes, or core files to their latest versions.";

		$html .= "<p>This report lists what was updated this week:</p>";

		$html .= "<br>";
		$html .= "<table style='border-collapse: collapse; text-align: left;'>";
		$html .= "<tbody style='vertical-align: baseline;'>";

		$html .= "<tr style='$border'>";
		$html .= 	"<th style='$padding'>Date Updated</th>";
		$html .= 	"<th style='$padding'>Type</th>";
		$html .= 	"<th style='$padding'>Name</th>";
		$html .= 	"<th style='$padding'>Version From</th>";
		$html .= 	"<th style='$padding'>Version To</th>";

		foreach ($data['updated'] as $update) {
			$html .= "<tr style='$border'>";

			$html .= 	"<td style='$padding'>". $update['date'] . "</td>";
			$html .= 	"<td style='$padding'>". $update['type'] . "</td>";
			$html .= 	"<td style='$padding'>". $update['display_name'] . "</td>";
			$html .= 	"<td style='$padding'>". $update['version_from'] . "</td>";
			$html .= 	"<td style='$padding'>". $update['version_to'] . "</td>";

			$html .= "</tr>";
		}
		$html .= "</tbody></table>";

		return $html;
	}
}
