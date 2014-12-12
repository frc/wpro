<?php

if (!defined('ABSPATH')) exit();

class WPRO_Admin {

	function __construct() {

		if (!defined('WPRO_ON') || !WPRO_ON) { // Settings in constants. Don't show admin.
			add_action('init', array($this, 'admin_init'));

		}
	}

	function admin_form() {
		if (!$this->is_trusted()) {
			wp_die ( __ ('You do not have sufficient permissions to access this page.'));
		}

		$wproService = wpro()->options->get('wpro-service');

		?>
			<div class="wrap wpro-admin">
				<form method="post" action="<?php echo(admin_url('options.php')); ?>">
					<h2>WPRO</h2>
					<input type="hidden" name="action" value="wpro_settings_POST" />
					<table class="form-table">
						<tr valign="top">
							<th scope="row">Storage backend</th>
							<td>
								<input id="wpro_backend__radio" type="radio" name="wpro-service" value="" /> <label for="wpro_backend__radio">Plugin inactive</label><br />
								<?php foreach (wpro()->backends->backend_names() as $backend): ?>
									<?php $radio_id = 'wpro_backend_' . sanitize_title($backend) . '_radio'; ?>
									<input id="<?php echo($radio_id); ?>" type="radio" name="wpro-service" value="<?php echo($backend); ?>" /> <label for="<?php echo($radio_id); ?>"><?php echo($backend); ?></label><br />
								<?php endforeach; ?>
							</td>
						</tr>
					</table>
					<?php foreach(wpro()->backends->backend_names() as $backend): ?>
						<?php $div_id = 'wpro_backend_' . sanitize_title($backend) . '_div'; ?>
						<div class="wpro-form-block" id="<?php echo($div_id); ?>">
							<?php $backend = wpro()->backends->backend_by_name($backend); ?>
							<?php if (method_exists($backend, 'admin_form')) { $backend->admin_form(); }; ?>
						</div>
					<?php endforeach; ?>
					<div class="wpro-form-block" id="wpro-form-general-settings">
						<h3>General settings</h3>
						<table class="form-table">
							<tr valign="top">
								<th scope="row">Add subfolder to all paths/urls</th>
								<td>
									<input type="text" name="wpro-folder" />
									<p class="description">
										Example: If you set this to "<b>MyBlog</b>", your URLs may become something like:<br />
										http://s3-eu-west-1.amazonaws.com/amazonbucket/<b>MyBlog</b>/2014/05/image.jpg
									</p>
								</td>
							</tr>
							<?php if (is_multisite()): ?>
								<tr valign="top">
									<th scope="row">Each mulitsite blog has it's own subdirectory</th>
									<td>
										<input type="checkbox" name="wpro-mu-subdirs" />
										<p class="description">
											Normally, you want this checked. However, for backwards compatibility reasons, you may want to uncheck this box.
										</p>
									</td>
								</tr>
							<?php endif; ?>
							<tr valign="top">
								<th scope="row">Temporary directory</th>
								<td>
									<input type="text" name="wpro-folder" value="<?php echo(wpro()->tmpdir->sysTmpDir()); ?>" />
									<p class="description">
										This directory must be writeable for the web server.
										It will be used for temporary storing files during uploads/edits, so it can be on non-persistent storage.
									</p>
								</td>
							</tr>
						</table>
					</div>
					<p class="submit">
						<input type="submit" name="submit" id="submit" class="button button-primary" value="Save settings">
					</p>
				</form>
			</div>
		<?php
	}


	function admin_init() {

		wp_enqueue_script('wpro_admin', plugins_url('/wpro/js/admin.js'), array('jquery'));

		if ($this->is_trusted()) { // TODO: Check if this is too late to add network_admin_menu hook?
			if (is_multisite()) {
				add_action('network_admin_menu', array($this, 'network_admin_menu'));
			} else {
				add_action('admin_menu', array($this, 'admin_menu')); // Will add the settings menu.
			}
			add_action('admin_post_wpro_settings_POST', array($this, 'admin_post')); // Gets called from plugin admin page POST request.
		}
	}

	function admin_menu() {
		add_options_page('WPRO Plugin Settings', 'WPRO Settings', 'manage_options', 'wpro', array($this, 'admin_form'));
	}

	function admin_post() {
		// We are handling the POST settings stuff ourselves, instead of using the Settings API.
		// This is because the Settings API has no way of storing network wide options in multisite installs.
		if (!$this->is_trusted()) return false;
		if ($_POST['action'] != 'wpro_settings_POST') return false;
		foreach ($_POST as $key => $val) {
			if (wpro()->options->is_an_option($key)) {
				wpro()->options->set($val);
			}
		}
		if (is_multisite()) {
			header('Location: ' . admin_url('network/settings.php?page=wpro&updated=true'));
		} else {
			header('Location: ' . admin_url('options-general.php?page=wpro&updated=true'));
		}
		exit();
	}

	function is_trusted() {
		if (is_multisite()) {
			if (is_super_admin()) {
				return true;
			}
		} else {
			if (current_user_can('manage_options')) {
				return true;
			}
		}
		return false;
	}

	function network_admin_menu() {
		add_submenu_page('settings.php', 'WPRO Plugin Settings', 'WPRO Settings', 'manage_options', 'wpro', array($this, 'admin_form'));
	}

}