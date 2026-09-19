<?php
/**
 * Plugin Name: Vigilant Rank Math Oxygen Meta
 * Description: Generates Rank Math SEO meta from Oxygen builder content when default variables such as %excerpt% return empty values.
 * Version: 1.3.0
 * Author: CI Web Studio
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
	exit;
}

final class Vigilant_Rank_Math_Oxygen_Meta {
	private const DESCRIPTION_LENGTH = 160;
	private const ADMIN_NONCE_ACTION = 'vrmom_bulk_update';
	private const ADMIN_PAGE_SLUG = 'vigilant-rank-math-oxygen-meta';
	private const BACKUP_META_KEYS = array(
		'rank_math_title',
		'rank_math_description',
		'rank_math_focus_keyword',
		'rank_math_facebook_title',
		'rank_math_facebook_description',
		'rank_math_twitter_title',
		'rank_math_twitter_description',
	);

	public static function init(): void {
		add_filter('get_the_excerpt', array(__CLASS__, 'filter_wordpress_excerpt'), 20, 2);

		add_filter('rank_math/frontend/title', array(__CLASS__, 'filter_title'), 20);
		add_filter('rank_math/frontend/description', array(__CLASS__, 'filter_description'), 20);
		add_filter('rank_math/opengraph/facebook/og_description', array(__CLASS__, 'filter_description'), 20);
		add_filter('rank_math/opengraph/twitter/twitter_description', array(__CLASS__, 'filter_description'), 20);

		add_action('rank_math/vars/register_extra_replacements', array(__CLASS__, 'register_rank_math_variables'));
		add_action('admin_menu', array(__CLASS__, 'register_admin_page'));
		add_action('admin_notices', array(__CLASS__, 'render_bulk_action_notice'));
		add_action('save_post', array(__CLASS__, 'handle_save_post'), 20, 3);
			add_action('wp_ajax_vrmom_create_backup', array(__CLASS__, 'handle_create_backup_ajax'));
			add_action('wp_ajax_vrmom_restore_backup', array(__CLASS__, 'handle_restore_backup_ajax'));
			add_action('wp_ajax_vrmom_delete_backup', array(__CLASS__, 'handle_delete_backup_ajax'));
			add_action('admin_post_vrmom_bulk_update', array(__CLASS__, 'handle_bulk_update_request'));
		add_action('admin_init', array(__CLASS__, 'register_bulk_actions_for_post_types'));
	}

	public static function filter_wordpress_excerpt($excerpt, $post = null): string {
		$excerpt = self::clean_text((string) $excerpt, self::DESCRIPTION_LENGTH);

		if ($excerpt !== '') {
			return $excerpt;
		}

		$post = $post instanceof WP_Post ? $post : self::get_current_post();

		if (!$post || !is_singular($post->post_type)) {
			return '';
		}

		return self::get_generated_description_for_post($post);
	}

	public static function handle_save_post(int $post_id, WP_Post $post, bool $update): void {
		if (
			wp_is_post_revision($post_id)
			|| wp_is_post_autosave($post_id)
			|| (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
			|| in_array($post->post_status, array('auto-draft', 'inherit'), true)
		) {
			return;
		}

		self::add_missing_image_alt_text($post);
	}

	public static function filter_title($title): string {
		$title = self::replace_known_variables((string) $title);
		$title = self::clean_text($title, 70);

		if ($title !== '') {
			return $title;
		}

		$post = self::get_current_post();

		if (!$post) {
			return '';
		}

		return self::clean_text(get_the_title($post), 70);
	}

	public static function filter_description($description): string {
		$description = self::replace_known_variables((string) $description);
		$description = self::clean_text($description, self::DESCRIPTION_LENGTH);

		if ($description !== '' && !self::looks_unresolved($description)) {
			return $description;
		}

		return self::get_generated_description();
	}

	public static function register_rank_math_variables(): void {
		if (!function_exists('rank_math_register_var_replacement')) {
			return;
		}

		rank_math_register_var_replacement(
			'vigilant_oxygen_excerpt',
			array(
				'name'        => esc_html__('Vigilant Oxygen Excerpt', 'vigilant-rank-math-oxygen-meta'),
				'description' => esc_html__('First clean text found in Oxygen builder content for the current post.', 'vigilant-rank-math-oxygen-meta'),
				'variable'    => 'vigilant_oxygen_excerpt',
				'example'     => self::get_generated_description(),
			),
			array(__CLASS__, 'get_generated_description')
		);
	}

	public static function get_generated_description(): string {
		$post = self::get_current_post();

		if (!$post) {
			return '';
		}

		return self::get_generated_description_for_post($post);
	}

	public static function register_admin_page(): void {
		add_management_page(
			__('Vigilant SEO Bulk Update', 'vigilant-rank-math-oxygen-meta'),
			__('Vigilant SEO Bulk Update', 'vigilant-rank-math-oxygen-meta'),
			'manage_options',
			self::ADMIN_PAGE_SLUG,
			array(__CLASS__, 'render_admin_page')
		);
	}

	public static function render_admin_page(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'vigilant-rank-math-oxygen-meta'));
		}

		$result = isset($_GET['vrmom_result']) ? sanitize_text_field(wp_unslash($_GET['vrmom_result'])) : '';
		$error = isset($_GET['vrmom_error']) ? sanitize_text_field(wp_unslash($_GET['vrmom_error'])) : '';
		$backup_nonce = wp_create_nonce(self::ADMIN_NONCE_ACTION);
		$post_types = self::get_supported_post_types();
		$latest_backups = self::get_latest_backup_batches();
		?>
			<div class="wrap">
				<h1><?php echo esc_html__('Vigilant SEO Bulk Update', 'vigilant-rank-math-oxygen-meta'); ?></h1>
				<p><?php echo esc_html__('Generate saved Rank Math SEO titles and descriptions for Oxygen pages so the WordPress admin list shows resolved text instead of template variables.', 'vigilant-rank-math-oxygen-meta'); ?></p>
				<p><strong><?php echo esc_html__('Safe URL note:', 'vigilant-rank-math-oxygen-meta'); ?></strong> <?php echo esc_html__('This tool does not change permalinks, slugs, canonical URLs, redirects, or any ranked Google URL. It only updates Rank Math meta fields.', 'vigilant-rank-math-oxygen-meta'); ?></p>

			<?php if ($result !== '') : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html($result); ?></p>
				</div>
			<?php endif; ?>

			<?php if ($error !== '') : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html($error); ?></p>
				</div>
			<?php endif; ?>

			<div class="card" style="max-width: 860px; margin-top: 16px;">
				<h2><?php echo esc_html__('Step 1: Backup current Rank Math meta', 'vigilant-rank-math-oxygen-meta'); ?></h2>
				<p><?php echo esc_html__('Create a database backup batch before writing new SEO titles/descriptions. The backup table keeps the current Rank Math values for rollback/reference.', 'vigilant-rank-math-oxygen-meta'); ?></p>
				<p>
					<button type="button" class="button button-secondary" id="vrmom-create-backup">
						<?php echo esc_html__('Create Backup by AJAX', 'vigilant-rank-math-oxygen-meta'); ?>
					</button>
					<span id="vrmom-backup-status" style="margin-left: 10px;"></span>
				</p>
			</div>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php wp_nonce_field(self::ADMIN_NONCE_ACTION); ?>
				<input type="hidden" name="action" value="vrmom_bulk_update">
				<input type="hidden" name="backup_batch" id="vrmom-backup-batch" value="">

				<h2><?php echo esc_html__('Step 2: Run bulk update', 'vigilant-rank-math-oxygen-meta'); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__('Post types', 'vigilant-rank-math-oxygen-meta'); ?></th>
							<td>
								<?php foreach ($post_types as $post_type => $label) : ?>
									<label style="display:inline-block;margin:0 16px 8px 0;">
										<input type="checkbox" class="vrmom-post-type" name="post_types[]" value="<?php echo esc_attr($post_type); ?>" checked>
										<?php echo esc_html($label); ?>
									</label>
								<?php endforeach; ?>
								<p class="description"><?php echo esc_html__('Includes Posts, Pages, and public custom post types registered by ACF or other plugins.', 'vigilant-rank-math-oxygen-meta'); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__('Fields', 'vigilant-rank-math-oxygen-meta'); ?></th>
							<td>
								<label>
									<input type="checkbox" name="update_title" value="1" checked>
									<?php echo esc_html__('Save generated Rank Math titles', 'vigilant-rank-math-oxygen-meta'); ?>
								</label>
								<br>
								<label>
									<input type="checkbox" name="update_description" value="1" checked>
									<?php echo esc_html__('Save generated Rank Math descriptions', 'vigilant-rank-math-oxygen-meta'); ?>
								</label>
								<br>
								<label>
									<input type="checkbox" name="update_focus_keyword" value="1" checked>
									<?php echo esc_html__('Save generated Focus Keywords', 'vigilant-rank-math-oxygen-meta'); ?>
								</label>
								<br>
								<label>
									<input type="checkbox" name="update_social_meta" value="1" checked>
									<?php echo esc_html__('Save Facebook/Twitter SEO titles and descriptions', 'vigilant-rank-math-oxygen-meta'); ?>
								</label>
								<p class="description"><?php echo esc_html__('These fields improve Rank Math analysis data, but the final score still depends on the page content, headings, links, image alt text, and Rank Math recalculation.', 'vigilant-rank-math-oxygen-meta'); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__('Overwrite mode', 'vigilant-rank-math-oxygen-meta'); ?></th>
							<td>
								<label>
									<input type="checkbox" name="overwrite_descriptions" value="1">
									<?php echo esc_html__('Overwrite existing custom descriptions that already contain real text', 'vigilant-rank-math-oxygen-meta'); ?>
								</label>
								<p class="description"><?php echo esc_html__('Leave unchecked to update only empty or unresolved descriptions such as %excerpt%.', 'vigilant-rank-math-oxygen-meta'); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button(__('Run Bulk Update', 'vigilant-rank-math-oxygen-meta'), 'primary', 'submit', true, array('id' => 'vrmom-run-bulk-update', 'disabled' => 'disabled')); ?>
			</form>

			<div class="card" style="max-width: 860px; margin-top: 16px;">
				<h2><?php echo esc_html__('Restore from backup', 'vigilant-rank-math-oxygen-meta'); ?></h2>
				<p><?php echo esc_html__('Restore saved Rank Math titles and descriptions from a backup batch.', 'vigilant-rank-math-oxygen-meta'); ?></p>
				<p>
					<label for="vrmom-restore-batch"><?php echo esc_html__('Backup batch', 'vigilant-rank-math-oxygen-meta'); ?></label>
					<input type="text" id="vrmom-restore-batch" class="regular-text" placeholder="20260903120000-AbCd1234">
					<button type="button" class="button button-secondary" id="vrmom-restore-backup">
						<?php echo esc_html__('Restore Backup by AJAX', 'vigilant-rank-math-oxygen-meta'); ?>
					</button>
					<span id="vrmom-restore-status" style="margin-left: 10px;"></span>
				</p>

				<?php if (!empty($latest_backups)) : ?>
					<table class="widefat striped" style="margin-top:12px;">
						<thead>
							<tr>
								<th><?php echo esc_html__('Recent batch', 'vigilant-rank-math-oxygen-meta'); ?></th>
								<th><?php echo esc_html__('Records', 'vigilant-rank-math-oxygen-meta'); ?></th>
								<th><?php echo esc_html__('Created', 'vigilant-rank-math-oxygen-meta'); ?></th>
								<th><?php echo esc_html__('Actions', 'vigilant-rank-math-oxygen-meta'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($latest_backups as $backup) : ?>
								<tr>
									<td><code><?php echo esc_html($backup['backup_batch']); ?></code></td>
									<td><?php echo esc_html((string) $backup['records']); ?></td>
									<td><?php echo esc_html($backup['created_at']); ?></td>
									<td>
										<button type="button" class="button button-small vrmom-use-backup" data-batch="<?php echo esc_attr($backup['backup_batch']); ?>">
											<?php echo esc_html__('Use', 'vigilant-rank-math-oxygen-meta'); ?>
										</button>
										<button type="button" class="button button-small vrmom-delete-backup" data-batch="<?php echo esc_attr($backup['backup_batch']); ?>">
											<?php echo esc_html__('Delete', 'vigilant-rank-math-oxygen-meta'); ?>
										</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

					<script>
					(function() {
						var button = document.getElementById('vrmom-create-backup');
						var status = document.getElementById('vrmom-backup-status');
						var batchInput = document.getElementById('vrmom-backup-batch');
						var runButton = document.getElementById('vrmom-run-bulk-update');
							var restoreButton = document.getElementById('vrmom-restore-backup');
							var restoreInput = document.getElementById('vrmom-restore-batch');
							var restoreStatus = document.getElementById('vrmom-restore-status');

						if (!button || !status || !batchInput || !runButton || !restoreButton || !restoreInput || !restoreStatus) {
							return;
						}

						function setStatus(message, isError) {
							status.textContent = message;
							status.style.color = isError ? '#b32d2e' : '#1d2327';
						}

						button.addEventListener('click', function() {
							var data = new window.FormData();
							data.append('action', 'vrmom_create_backup');
							data.append('_ajax_nonce', '<?php echo esc_js($backup_nonce); ?>');
							Array.prototype.slice.call(document.querySelectorAll('.vrmom-post-type:checked')).forEach(function(input) {
								data.append('post_types[]', input.value);
							});

							button.disabled = true;
							runButton.disabled = true;
							batchInput.value = '';
							setStatus('<?php echo esc_js(__('Creating backup...', 'vigilant-rank-math-oxygen-meta')); ?>', false);

						window.fetch(ajaxurl, {
							method: 'POST',
							credentials: 'same-origin',
							body: data
						}).then(function(response) {
							return response.json();
						}).then(function(response) {
							if (!response || !response.success) {
								throw new Error(response && response.data && response.data.message ? response.data.message : 'Backup failed');
							}

								batchInput.value = response.data.batch;
								restoreInput.value = response.data.batch;
								runButton.disabled = false;
								setStatus(response.data.message, false);
						}).catch(function(error) {
							setStatus(error.message, true);
						}).finally(function() {
							button.disabled = false;
						});
						});

						Array.prototype.slice.call(document.querySelectorAll('.vrmom-use-backup')).forEach(function(useButton) {
							useButton.addEventListener('click', function() {
								restoreInput.value = useButton.getAttribute('data-batch') || '';
							});
						});

						Array.prototype.slice.call(document.querySelectorAll('.vrmom-delete-backup')).forEach(function(deleteButton) {
							deleteButton.addEventListener('click', function() {
								var batch = deleteButton.getAttribute('data-batch') || '';

								if (!batch || !window.confirm('<?php echo esc_js(__('Delete this backup batch permanently?', 'vigilant-rank-math-oxygen-meta')); ?>')) {
									return;
								}

								var data = new window.FormData();
								data.append('action', 'vrmom_delete_backup');
								data.append('_ajax_nonce', '<?php echo esc_js($backup_nonce); ?>');
								data.append('backup_batch', batch);

								deleteButton.disabled = true;
								restoreStatus.textContent = '<?php echo esc_js(__('Deleting backup...', 'vigilant-rank-math-oxygen-meta')); ?>';
								restoreStatus.style.color = '#1d2327';

								window.fetch(ajaxurl, {
									method: 'POST',
									credentials: 'same-origin',
									body: data
								}).then(function(response) {
									return response.json();
								}).then(function(response) {
									if (!response || !response.success) {
										throw new Error(response && response.data && response.data.message ? response.data.message : 'Delete failed');
									}

									restoreStatus.textContent = response.data.message;
									deleteButton.closest('tr').remove();
								}).catch(function(error) {
									restoreStatus.textContent = error.message;
									restoreStatus.style.color = '#b32d2e';
									deleteButton.disabled = false;
								});
							});
						});

						restoreButton.addEventListener('click', function() {
							var batch = restoreInput.value.trim();

								if (!batch) {
									restoreStatus.textContent = '<?php echo esc_js(__('Enter a backup batch first.', 'vigilant-rank-math-oxygen-meta')); ?>';
									restoreStatus.style.color = '#b32d2e';
									return;
								}

								if (!window.confirm('<?php echo esc_js(__('Restore Rank Math meta from this backup batch?', 'vigilant-rank-math-oxygen-meta')); ?>')) {
									return;
								}

							var data = new window.FormData();
							data.append('action', 'vrmom_restore_backup');
							data.append('_ajax_nonce', '<?php echo esc_js($backup_nonce); ?>');
							data.append('backup_batch', batch);

							restoreButton.disabled = true;
							restoreStatus.textContent = '<?php echo esc_js(__('Restoring backup...', 'vigilant-rank-math-oxygen-meta')); ?>';
							restoreStatus.style.color = '#1d2327';

							window.fetch(ajaxurl, {
								method: 'POST',
								credentials: 'same-origin',
								body: data
							}).then(function(response) {
								return response.json();
							}).then(function(response) {
								if (!response || !response.success) {
									throw new Error(response && response.data && response.data.message ? response.data.message : 'Restore failed');
								}

								restoreStatus.textContent = response.data.message;
							}).catch(function(error) {
								restoreStatus.textContent = error.message;
								restoreStatus.style.color = '#b32d2e';
							}).finally(function() {
								restoreButton.disabled = false;
							});
						});
					})();
					</script>
			</div>
			<?php
		}

		public static function handle_create_backup_ajax(): void {
			if (!current_user_can('manage_options')) {
				wp_send_json_error(array('message' => __('You do not have permission to create backups.', 'vigilant-rank-math-oxygen-meta')));
			}

			check_ajax_referer(self::ADMIN_NONCE_ACTION);

			$post_types = self::sanitize_post_types_from_request($_POST);
			$result = self::create_backup_for_post_types($post_types);

			wp_send_json_success(
				array(
					'batch' => $result['batch'],
					'checked' => $result['checked'],
					'message' => sprintf(
						'Backup created. Batch %s saved %d records across %d post types.',
						$result['batch'],
						$result['checked'],
						count($post_types)
					),
				)
			);
		}

	public static function handle_restore_backup_ajax(): void {
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('You do not have permission to restore backups.', 'vigilant-rank-math-oxygen-meta')));
			}

			check_ajax_referer(self::ADMIN_NONCE_ACTION);

			$backup_batch = isset($_POST['backup_batch']) ? sanitize_text_field(wp_unslash($_POST['backup_batch'])) : '';
			$result = self::restore_backup_batch($backup_batch);

			if (!$result['checked']) {
				wp_send_json_error(array('message' => __('No backup records found for that batch.', 'vigilant-rank-math-oxygen-meta')));
			}

			wp_send_json_success(
				array(
					'message' => sprintf(
						'Restore complete. Batch %s restored %d titles, %d descriptions, %d focus keywords, and %d social meta sets.',
						$backup_batch,
						$result['titles'],
						$result['descriptions'],
						$result['focus_keywords'],
						$result['social_meta']
					),
				)
			);
	}

	public static function handle_delete_backup_ajax(): void {
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('You do not have permission to delete backups.', 'vigilant-rank-math-oxygen-meta')));
		}

		check_ajax_referer(self::ADMIN_NONCE_ACTION);

		$backup_batch = isset($_POST['backup_batch']) ? sanitize_text_field(wp_unslash($_POST['backup_batch'])) : '';
		$deleted = self::delete_backup_batch($backup_batch);

		if ($deleted <= 0) {
			wp_send_json_error(array('message' => __('No backup records were deleted.', 'vigilant-rank-math-oxygen-meta')));
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					'Deleted backup batch %s with %d records.',
					$backup_batch,
					$deleted
				),
			)
		);
	}

	public static function handle_bulk_update_request(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to run this update.', 'vigilant-rank-math-oxygen-meta'));
		}

		check_admin_referer(self::ADMIN_NONCE_ACTION);

		$post_types = self::sanitize_post_types_from_request($_POST);
		$update_title = !empty($_POST['update_title']);
		$update_description = !empty($_POST['update_description']);
		$update_focus_keyword = !empty($_POST['update_focus_keyword']);
		$update_social_meta = !empty($_POST['update_social_meta']);
		$overwrite_descriptions = !empty($_POST['overwrite_descriptions']);
		$backup_batch = isset($_POST['backup_batch']) ? sanitize_text_field(wp_unslash($_POST['backup_batch'])) : '';

		if (!self::backup_batch_exists($backup_batch)) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page' => self::ADMIN_PAGE_SLUG,
						'vrmom_error' => __('Please create a backup batch before running the bulk update.', 'vigilant-rank-math-oxygen-meta'),
					),
					admin_url('tools.php')
				)
			);
			exit;
		}

		$result = self::bulk_update_rank_math_meta($post_types, $update_title, $update_description, $update_focus_keyword, $update_social_meta, true, $overwrite_descriptions);
		$message = sprintf(
			'Backup %s confirmed. Checked %d records across %d post types. Updated %d titles, %d descriptions, %d focus keywords, %d social meta sets, and added ALT text to %d images.',
			$backup_batch,
			$result['checked'],
			count($post_types),
			$result['titles'],
			$result['descriptions'],
			$result['focus_keywords'],
			$result['social_meta'],
			$result['image_alts']
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => self::ADMIN_PAGE_SLUG,
					'vrmom_result' => $message,
				),
				admin_url('tools.php')
			)
		);
		exit;
	}

	public static function register_bulk_actions_for_post_types(): void {
		foreach (array_keys(self::get_supported_post_types()) as $post_type) {
			add_filter("bulk_actions-edit-{$post_type}", array(__CLASS__, 'register_pages_bulk_action'));
			add_filter("handle_bulk_actions-edit-{$post_type}", array(__CLASS__, 'handle_pages_bulk_action'), 10, 3);
		}
	}

	public static function register_pages_bulk_action(array $actions): array {
		$actions['vrmom_generate_rank_math_meta'] = __('Generate Rank Math SEO from Oxygen', 'vigilant-rank-math-oxygen-meta');

		return $actions;
	}

	public static function handle_pages_bulk_action(string $redirect_to, string $action, array $post_ids): string {
		if ($action !== 'vrmom_generate_rank_math_meta') {
			return $redirect_to;
		}

		if (!current_user_can('edit_posts') && !current_user_can('edit_pages')) {
			return $redirect_to;
		}

		$backup = self::create_backup_for_posts($post_ids);
		$result = self::bulk_update_rank_math_meta_for_posts($post_ids, true, true, true, true, true, false);

		return add_query_arg(
			array(
				'vrmom_backup' => $backup['batch'],
				'vrmom_checked' => $result['checked'],
					'vrmom_titles' => $result['titles'],
					'vrmom_descriptions' => $result['descriptions'],
					'vrmom_focus_keywords' => $result['focus_keywords'],
					'vrmom_social_meta' => $result['social_meta'],
					'vrmom_image_alts' => $result['image_alts'],
				),
				$redirect_to
		);
	}

	public static function render_bulk_action_notice(): void {
		if (
			!isset($_GET['vrmom_checked'], $_GET['vrmom_titles'], $_GET['vrmom_descriptions'])
			|| (
				isset($_GET['post_type'])
				&& !array_key_exists(sanitize_key(wp_unslash($_GET['post_type'])), self::get_supported_post_types())
			)
		) {
			return;
		}

		$checked = absint($_GET['vrmom_checked']);
		$titles = absint($_GET['vrmom_titles']);
		$descriptions = absint($_GET['vrmom_descriptions']);
		$focus_keywords = isset($_GET['vrmom_focus_keywords']) ? absint($_GET['vrmom_focus_keywords']) : 0;
		$social_meta = isset($_GET['vrmom_social_meta']) ? absint($_GET['vrmom_social_meta']) : 0;
		$image_alts = isset($_GET['vrmom_image_alts']) ? absint($_GET['vrmom_image_alts']) : 0;
		$backup = isset($_GET['vrmom_backup']) ? sanitize_text_field(wp_unslash($_GET['vrmom_backup'])) : '';
		?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
					echo esc_html(
							sprintf(
								'Vigilant SEO Bulk Update backup %s checked %d records. Updated %d titles, %d descriptions, %d focus keywords, %d social meta sets, and added ALT text to %d images.',
								$backup ?: 'auto',
								$checked,
								$titles,
								$descriptions,
								$focus_keywords,
								$social_meta,
								$image_alts
							)
						);
				?>
			</p>
		</div>
		<?php
	}

	private static function get_supported_post_types(): array {
		$post_types = get_post_types(
			array(
				'public' => true,
				'show_ui' => true,
			),
			'objects'
		);
		$supported = array();

		foreach ($post_types as $post_type => $object) {
			if ($post_type === 'attachment') {
				continue;
			}

			$supported[$post_type] = $object->labels->name ?: $object->label ?: $post_type;
		}

		if (empty($supported)) {
			$supported = array_filter(
				array(
					'post' => post_type_exists('post') ? __('Posts', 'vigilant-rank-math-oxygen-meta') : '',
					'page' => post_type_exists('page') ? __('Pages', 'vigilant-rank-math-oxygen-meta') : '',
				)
			);
		}

		return $supported;
	}

	private static function sanitize_post_types_from_request(array $source): array {
		$supported = self::get_supported_post_types();
		$raw = isset($source['post_types']) ? (array) wp_unslash($source['post_types']) : array();
		$post_types = array();

		foreach ($raw as $post_type) {
			$post_type = sanitize_key((string) $post_type);

			if (array_key_exists($post_type, $supported)) {
				$post_types[] = $post_type;
			}
		}

		$post_types = array_values(array_unique($post_types));

		return !empty($post_types) ? $post_types : array_keys($supported);
	}

	private static function create_backup_for_post_types(array $post_types): array {
		if (empty($post_types)) {
			return self::create_backup_for_posts(array());
		}

		$posts = get_posts(
			array(
				'post_type' => $post_types,
				'post_status' => array('publish', 'draft', 'pending', 'future', 'private'),
				'posts_per_page' => -1,
				'fields' => 'ids',
				'orderby' => 'ID',
				'order' => 'ASC',
				'no_found_rows' => true,
			)
		);

		return self::create_backup_for_posts($posts);
	}

	private static function create_backup_for_posts(array $post_ids): array {
		global $wpdb;

		self::ensure_backup_table();

		$batch = gmdate('YmdHis') . '-' . wp_generate_password(8, false, false);
		$checked = 0;

		foreach ($post_ids as $post_id) {
			$post = get_post((int) $post_id);

			if (!$post instanceof WP_Post) {
				continue;
			}

			$generated_title = self::get_generated_title_for_post($post);
			$generated_description = self::get_generated_description_for_post($post);
			$generated_focus_keyword = self::get_generated_focus_keyword_for_post($post);
			$checked++;
			$wpdb->insert(
				self::get_backup_table_name(),
				array(
					'backup_batch' => $batch,
					'post_id' => (int) $post->ID,
					'post_type' => (string) $post->post_type,
					'post_status' => (string) $post->post_status,
					'post_title' => (string) get_the_title($post),
					'old_rank_math_title' => (string) get_post_meta($post->ID, 'rank_math_title', true),
					'old_rank_math_title_exists' => metadata_exists('post', $post->ID, 'rank_math_title') ? 1 : 0,
					'old_rank_math_description' => (string) get_post_meta($post->ID, 'rank_math_description', true),
					'old_rank_math_description_exists' => metadata_exists('post', $post->ID, 'rank_math_description') ? 1 : 0,
					'old_rank_math_focus_keyword' => (string) get_post_meta($post->ID, 'rank_math_focus_keyword', true),
					'old_rank_math_focus_keyword_exists' => metadata_exists('post', $post->ID, 'rank_math_focus_keyword') ? 1 : 0,
					'old_rank_math_facebook_title' => (string) get_post_meta($post->ID, 'rank_math_facebook_title', true),
					'old_rank_math_facebook_title_exists' => metadata_exists('post', $post->ID, 'rank_math_facebook_title') ? 1 : 0,
					'old_rank_math_facebook_description' => (string) get_post_meta($post->ID, 'rank_math_facebook_description', true),
					'old_rank_math_facebook_description_exists' => metadata_exists('post', $post->ID, 'rank_math_facebook_description') ? 1 : 0,
					'old_rank_math_twitter_title' => (string) get_post_meta($post->ID, 'rank_math_twitter_title', true),
					'old_rank_math_twitter_title_exists' => metadata_exists('post', $post->ID, 'rank_math_twitter_title') ? 1 : 0,
					'old_rank_math_twitter_description' => (string) get_post_meta($post->ID, 'rank_math_twitter_description', true),
					'old_rank_math_twitter_description_exists' => metadata_exists('post', $post->ID, 'rank_math_twitter_description') ? 1 : 0,
					'generated_rank_math_title' => $generated_title,
					'generated_rank_math_description' => $generated_description,
					'generated_rank_math_focus_keyword' => $generated_focus_keyword,
					'generated_rank_math_facebook_title' => $generated_title,
					'generated_rank_math_facebook_description' => $generated_description,
					'generated_rank_math_twitter_title' => $generated_title,
					'generated_rank_math_twitter_description' => $generated_description,
					'created_at' => current_time('mysql'),
					'user_id' => get_current_user_id(),
				),
				array('%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d')
			);
		}

		return array(
			'batch' => $batch,
			'checked' => $checked,
		);
	}

	private static function ensure_backup_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = self::get_backup_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			backup_batch varchar(40) NOT NULL,
				post_id bigint(20) unsigned NOT NULL,
				post_type varchar(40) NOT NULL,
				post_status varchar(20) NOT NULL,
				post_title text NOT NULL,
				old_rank_math_title longtext NULL,
				old_rank_math_title_exists tinyint(1) unsigned NOT NULL DEFAULT 0,
				old_rank_math_description longtext NULL,
				old_rank_math_description_exists tinyint(1) unsigned NOT NULL DEFAULT 0,
				old_rank_math_focus_keyword longtext NULL,
				old_rank_math_focus_keyword_exists tinyint(1) unsigned NOT NULL DEFAULT 0,
				old_rank_math_facebook_title longtext NULL,
				old_rank_math_facebook_title_exists tinyint(1) unsigned NOT NULL DEFAULT 0,
				old_rank_math_facebook_description longtext NULL,
				old_rank_math_facebook_description_exists tinyint(1) unsigned NOT NULL DEFAULT 0,
				old_rank_math_twitter_title longtext NULL,
				old_rank_math_twitter_title_exists tinyint(1) unsigned NOT NULL DEFAULT 0,
				old_rank_math_twitter_description longtext NULL,
				old_rank_math_twitter_description_exists tinyint(1) unsigned NOT NULL DEFAULT 0,
				generated_rank_math_title longtext NULL,
				generated_rank_math_description longtext NULL,
				generated_rank_math_focus_keyword longtext NULL,
				generated_rank_math_facebook_title longtext NULL,
				generated_rank_math_facebook_description longtext NULL,
				generated_rank_math_twitter_title longtext NULL,
				generated_rank_math_twitter_description longtext NULL,
				created_at datetime NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY backup_batch (backup_batch),
			KEY post_id (post_id)
			) {$charset_collate};";

		dbDelta($sql);
	}

	private static function backup_table_exists(): bool {
		global $wpdb;

		$table = self::get_backup_table_name();

		return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
	}

	private static function get_latest_backup_batches(): array {
		global $wpdb;

		if (!self::backup_table_exists()) {
			return array();
		}

		$rows = $wpdb->get_results(
			'SELECT backup_batch, COUNT(*) AS records, MAX(created_at) AS created_at FROM ' . self::get_backup_table_name() . ' GROUP BY backup_batch ORDER BY MAX(created_at) DESC LIMIT 10',
			ARRAY_A
		);

		return is_array($rows) ? $rows : array();
	}

	private static function backup_batch_exists(string $batch): bool {
		global $wpdb;

		if ($batch === '') {
			return false;
		}

		self::ensure_backup_table();

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::get_backup_table_name() . ' WHERE backup_batch = %s',
				$batch
			)
		);

		return $count > 0;
	}

	private static function restore_backup_batch(string $batch): array {
		global $wpdb;

		$result = array(
			'checked' => 0,
			'titles' => 0,
			'descriptions' => 0,
			'focus_keywords' => 0,
			'social_meta' => 0,
		);

		if ($batch === '' || !self::backup_table_exists()) {
			return $result;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::get_backup_table_name() . ' WHERE backup_batch = %s ORDER BY id ASC',
				$batch
			),
			ARRAY_A
		);

		if (!is_array($rows)) {
			return $result;
		}

		foreach ($rows as $row) {
			$post_id = isset($row['post_id']) ? (int) $row['post_id'] : 0;

			if ($post_id <= 0 || !get_post($post_id)) {
				continue;
			}

			$result['checked']++;

			foreach (self::BACKUP_META_KEYS as $meta_key) {
				$value_column = 'old_' . $meta_key;
				$exists_column = 'old_' . $meta_key . '_exists';
				$exists = !empty($row[$exists_column]) || (isset($row[$value_column]) && (string) $row[$value_column] !== '');

				if ($exists) {
					update_post_meta($post_id, $meta_key, isset($row[$value_column]) ? (string) $row[$value_column] : '');
				} else {
					delete_post_meta($post_id, $meta_key);
				}
			}

			$result['titles']++;
			$result['descriptions']++;
			$result['focus_keywords']++;
			$result['social_meta']++;
		}

		return $result;
	}

	private static function delete_backup_batch(string $batch): int {
		global $wpdb;

		if ($batch === '' || !self::backup_table_exists()) {
			return 0;
		}

		return (int) $wpdb->delete(
			self::get_backup_table_name(),
			array('backup_batch' => $batch),
			array('%s')
		);
	}

	private static function get_backup_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'vrmom_rank_math_meta_backup';
	}

	private static function bulk_update_rank_math_meta(
		array $post_types,
		bool $update_title,
		bool $update_description,
		bool $update_focus_keyword,
		bool $update_social_meta,
		bool $overwrite_titles,
		bool $overwrite_descriptions
	): array {
		$posts = get_posts(
			array(
				'post_type' => $post_types,
				'post_status' => array('publish', 'draft', 'pending', 'future', 'private'),
				'posts_per_page' => -1,
				'fields' => 'ids',
				'orderby' => 'ID',
				'order' => 'ASC',
				'no_found_rows' => true,
			)
		);

		return self::bulk_update_rank_math_meta_for_posts($posts, $update_title, $update_description, $update_focus_keyword, $update_social_meta, $overwrite_titles, $overwrite_descriptions);
	}

	private static function bulk_update_rank_math_meta_for_posts(
		array $post_ids,
		bool $update_title,
		bool $update_description,
		bool $update_focus_keyword,
		bool $update_social_meta,
		bool $overwrite_titles,
		bool $overwrite_descriptions
	): array {
		$result = array(
			'checked' => 0,
			'titles' => 0,
			'descriptions' => 0,
			'focus_keywords' => 0,
			'social_meta' => 0,
			'image_alts' => 0,
		);

		foreach ($post_ids as $post_id) {
			$post = get_post((int) $post_id);

			if (!$post instanceof WP_Post) {
				continue;
			}

			$result['checked']++;
			$result['image_alts'] += self::add_missing_image_alt_text($post);

			if ($update_title) {
				$title = self::get_generated_title_for_post($post);
				$current_title = (string) get_post_meta($post->ID, 'rank_math_title', true);

				if ($title !== '' && ($overwrite_titles || $current_title === '' || self::looks_unresolved($current_title))) {
					update_post_meta($post->ID, 'rank_math_title', $title);
					$result['titles']++;
				}
			}

			if ($update_description) {
				$description = self::get_generated_description_for_post($post);
				$current_description = (string) get_post_meta($post->ID, 'rank_math_description', true);

				if ($description !== '' && ($overwrite_descriptions || $current_description === '' || self::looks_unresolved($current_description))) {
					update_post_meta($post->ID, 'rank_math_description', $description);
					$result['descriptions']++;
				}
			}

			if ($update_focus_keyword) {
				$focus_keyword = self::get_generated_focus_keyword_for_post($post);
				$current_focus_keyword = (string) get_post_meta($post->ID, 'rank_math_focus_keyword', true);

				if ($focus_keyword !== '' && ($current_focus_keyword === '' || self::looks_unresolved($current_focus_keyword))) {
					update_post_meta($post->ID, 'rank_math_focus_keyword', $focus_keyword);
					$result['focus_keywords']++;
				}
			}

			if ($update_social_meta) {
				$title = self::get_generated_title_for_post($post);
				$description = self::get_generated_description_for_post($post);
				$social_updated = false;

				if ($title !== '') {
					update_post_meta($post->ID, 'rank_math_facebook_title', $title);
					update_post_meta($post->ID, 'rank_math_twitter_title', $title);
					$social_updated = true;
				}

				if ($description !== '') {
					$current_facebook_description = (string) get_post_meta($post->ID, 'rank_math_facebook_description', true);
					$current_twitter_description = (string) get_post_meta($post->ID, 'rank_math_twitter_description', true);

					if ($overwrite_descriptions || $current_facebook_description === '' || self::looks_unresolved($current_facebook_description)) {
						update_post_meta($post->ID, 'rank_math_facebook_description', $description);
						$social_updated = true;
					}

					if ($overwrite_descriptions || $current_twitter_description === '' || self::looks_unresolved($current_twitter_description)) {
						update_post_meta($post->ID, 'rank_math_twitter_description', $description);
						$social_updated = true;
					}
				}

				if ($social_updated) {
					$result['social_meta']++;
				}
			}
		}

		return $result;
	}

	private static function get_generated_description_for_post(WP_Post $post): string {
		static $cache = array();
		$post_id = (int) $post->ID;

		if (isset($cache[$post_id])) {
			return $cache[$post_id];
		}

		$candidates = array(
			$post->post_excerpt,
			self::extract_text_from_oxygen_data($post_id),
			$post->post_content,
		);

		foreach ($candidates as $candidate) {
			$description = self::clean_text((string) $candidate, self::DESCRIPTION_LENGTH);

			if ($description !== '' && !self::looks_unresolved($description)) {
				$cache[$post_id] = $description;
				return $description;
			}
		}

		$cache[$post_id] = self::clean_text(get_the_title($post), self::DESCRIPTION_LENGTH);

		return $cache[$post_id];
	}

	private static function add_missing_image_alt_text(WP_Post $post): int {
		$attachment_ids = array();
		self::collect_image_attachment_ids($post->post_content, $attachment_ids);
		self::collect_image_attachment_ids(get_post_meta($post->ID, '_oxygen_data', true), $attachment_ids);

		if (empty($attachment_ids)) {
			return 0;
		}

		$page_title = self::clean_text((string) get_the_title($post), 160);
		$updated = 0;

		foreach (array_keys($attachment_ids) as $attachment_id) {
			if (!wp_attachment_is_image($attachment_id) || trim((string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true)) !== '') {
				continue;
			}

			$file = get_attached_file($attachment_id);
			$image_name = $file ? pathinfo($file, PATHINFO_FILENAME) : get_the_title($attachment_id);
			$image_name = self::clean_text((string) $image_name, 160);

			if ($page_title === '' || $image_name === '') {
				continue;
			}

			update_post_meta($attachment_id, '_wp_attachment_image_alt', sprintf('%s - %s', $page_title, $image_name));
			$updated++;
		}

		return $updated;
	}

	private static function collect_image_attachment_ids($value, array &$attachment_ids, string $key = ''): void {
		if (is_array($value)) {
			foreach ($value as $child_key => $child_value) {
				self::collect_image_attachment_ids($child_value, $attachment_ids, strtolower((string) $child_key));
			}

			return;
		}

		if (!is_string($value) || trim($value) === '') {
			if (is_numeric($value) && preg_match('/(?:attachment|image)[-_]?id$/', $key)) {
				$attachment_ids[absint($value)] = true;
			}

			return;
		}

		if (preg_match_all('/wp-image-(\d+)/i', $value, $matches)) {
			foreach ($matches[1] as $attachment_id) {
				$attachment_ids[absint($attachment_id)] = true;
			}
		}

		if (preg_match_all('/<(?:img|source)\b[^>]+(?:src|data-src)\s*=\s*["\']([^"\']+)["\']/i', $value, $matches)) {
			foreach ($matches[1] as $image_url) {
				$attachment_id = attachment_url_to_postid(html_entity_decode($image_url, ENT_QUOTES, 'UTF-8'));

				if ($attachment_id) {
					$attachment_ids[$attachment_id] = true;
				}
			}
		}

		if ($key === 'tree_json_string') {
			$decoded = json_decode($value, true);

			if (is_array($decoded)) {
				self::collect_image_attachment_ids($decoded, $attachment_ids);
			}
		}

		if (preg_match('/(?:attachment|image)[-_]?id$/', $key) && is_numeric($value)) {
			$attachment_ids[absint($value)] = true;
		}

		if (in_array($key, array('src', 'url', 'image', 'image_url', 'source'), true) && preg_match('#^https?://#i', $value)) {
			$attachment_id = attachment_url_to_postid($value);

			if ($attachment_id) {
				$attachment_ids[$attachment_id] = true;
			}
		}
	}

	private static function get_generated_title_for_post(WP_Post $post): string {
		return self::clean_text(sprintf('%s %s %s', get_the_title($post), self::get_separator(), get_bloginfo('name')), 70);
	}

	private static function get_generated_focus_keyword_for_post(WP_Post $post): string {
		$title = html_entity_decode((string) get_the_title($post), ENT_QUOTES, 'UTF-8');
		$title = preg_replace('/\s+[' . preg_quote(self::get_separator(), '/') . '|]\s+.*$/u', '', $title);
		$title = self::clean_text((string) $title, 80);

		if ($title === '') {
			return '';
		}

		$words = preg_split('/\s+/u', $title, -1, PREG_SPLIT_NO_EMPTY);

		if (!is_array($words) || empty($words)) {
			return $title;
		}

		$stop_words = array(
			'a',
			'an',
			'and',
			'are',
			'as',
			'at',
			'for',
			'from',
			'in',
			'is',
			'of',
			'on',
			'or',
			'the',
			'to',
			'with',
		);
		$keywords = array();

		foreach ($words as $word) {
			$word = trim($word, " \t\n\r\0\x0B.,:;!?()[]{}\"'");

			if ($word === '' || in_array(strtolower($word), $stop_words, true)) {
				continue;
			}

			$keywords[] = $word;

			if (count($keywords) >= 5) {
				break;
			}
		}

		if (empty($keywords)) {
			$keywords = array_slice($words, 0, 5);
		}

		return self::clean_text(implode(' ', $keywords), 60);
	}

	private static function replace_known_variables(string $text): string {
		if ($text === '') {
			return '';
		}

		$post = self::get_current_post();
		$replacements = array(
			'%sep%'       => self::get_separator(),
			'%separator%' => self::get_separator(),
			'%sitename%'  => get_bloginfo('name'),
			'%sitedesc%'  => get_bloginfo('description'),
		);

		if ($post) {
			$replacements['%title%'] = get_the_title($post);
			$replacements['%excerpt%'] = self::get_generated_description();
			$replacements['%seo_description%'] = self::get_generated_description();
		}

		$text = strtr($text, $replacements);

		if (strpos($text, '[') !== false) {
			$text = do_shortcode($text);
		}

		return $text;
	}

	private static function extract_text_from_oxygen_data(int $post_id): string {
		$raw = get_post_meta($post_id, '_oxygen_data', true);

		if (!$raw) {
			return '';
		}

		$outer = is_array($raw) ? $raw : json_decode((string) $raw, true);

		if (!is_array($outer) || empty($outer['tree_json_string'])) {
			return '';
		}

		$tree = json_decode((string) $outer['tree_json_string'], true);

		if (!is_array($tree) || empty($tree['root']) || !is_array($tree['root'])) {
			return '';
		}

		$parts = array();
		self::walk_oxygen_node($tree['root'], $parts);

		return implode(' ', $parts);
	}

	private static function walk_oxygen_node(array $node, array &$parts): void {
		$type = isset($node['data']['type']) ? (string) $node['data']['type'] : '';
		$properties = isset($node['data']['properties']) && is_array($node['data']['properties'])
			? $node['data']['properties']
			: array();
		$content = isset($properties['content']['content']) && is_array($properties['content']['content'])
			? $properties['content']['content']
			: array();

		if (!self::is_noise_oxygen_type($type)) {
			self::collect_text_values($content, $parts);
		}

		if (!empty($node['children']) && is_array($node['children'])) {
			foreach ($node['children'] as $child) {
				if (is_array($child)) {
					self::walk_oxygen_node($child, $parts);
				}
			}
		}
	}

	private static function collect_text_values($value, array &$parts): void {
		if (is_string($value)) {
			self::collect_text_from_string($value, $parts);
			return;
		}

		if (!is_array($value)) {
			return;
		}

		foreach ($value as $key => $item) {
			$key = is_string($key) ? strtolower($key) : '';

			if (in_array($key, array('url', 'src', 'href', 'image', 'icon', 'custom_css', 'css', 'javascript_code', 'php_code'), true)) {
				continue;
			}

			self::collect_text_values($item, $parts);
		}
	}

	private static function collect_text_from_string(string $value, array &$parts): void {
		if ($value === '' || self::looks_like_asset_or_code($value)) {
			return;
		}

		if (preg_match_all('/data="([^"]+)"/', $value, $matches)) {
			foreach ($matches[1] as $encoded) {
				$decoded = base64_decode(html_entity_decode($encoded, ENT_QUOTES, 'UTF-8'), true);

				if ($decoded !== false) {
					self::collect_text_values(json_decode($decoded, true), $parts);
				}
			}
		}

		$value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
		$value = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $value);
		$value = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $value);
		$value = preg_replace('/\[contact-form-7[^\]]*\]/i', ' ', $value);
		$value = preg_replace('/\[[a-z0-9_\-]+(?:\s+[^\]]*)?\]/i', ' ', $value);
		$value = wp_strip_all_tags($value, true);
		$value = self::normalize_whitespace($value);

		if ($value !== '' && !self::looks_unresolved($value)) {
			$parts[] = $value;
		}
	}

	private static function clean_text(string $text, int $length): string {
		$text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
		$text = do_shortcode($text);
		$text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $text);
		$text = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $text);
		$text = wp_strip_all_tags($text, true);
		$text = self::normalize_whitespace($text);
		$text = trim($text, " \t\n\r\0\x0B-–—|•");

		if ($text === '' || self::looks_unresolved($text)) {
			return '';
		}

		return wp_html_excerpt($text, $length, '...');
	}

	private static function normalize_whitespace(string $text): string {
		$text = preg_replace('/[\x{00A0}\s]+/u', ' ', $text);

		return trim((string) $text);
	}

	private static function looks_unresolved(string $text): bool {
		$plain = trim($text);

		if ($plain === '') {
			return true;
		}

		if (preg_match('/%[a-z0-9_\-]+%/i', $plain)) {
			return true;
		}

		if (preg_match('/^\[[a-z0-9_\-]+(?:\s+[^\]]*)?\]$/i', $plain)) {
			return true;
		}

		return false;
	}

	private static function looks_like_asset_or_code(string $value): bool {
		$value = trim($value);

		if ($value === '') {
			return true;
		}

		if (preg_match('#^(https?:)?//#i', $value) || preg_match('/\.(?:jpe?g|png|gif|webp|svg|avif|css|js|mp4|webm)(?:\?.*)?$/i', $value)) {
			return true;
		}

		if (preg_match('/^\s*(?:function\s*\(|var\s+|let\s+|const\s+|\.|#|@media\b)/i', $value)) {
			return true;
		}

		return false;
	}

	private static function is_noise_oxygen_type(string $type): bool {
		return in_array(
			$type,
			array(
				'OxygenElements\\CssCode',
				'OxygenElements\\JavaScriptCode',
				'OxygenElements\\PhpCode',
				'OxygenElements\\Image',
				'OxygenElements\\SvgIcon',
				'OxygenElements\\Html5Video',
			),
			true
		);
	}

	private static function get_current_post(): ?WP_Post {
		$post = get_post();

		if ($post instanceof WP_Post) {
			return $post;
		}

		$queried = get_queried_object();

		return $queried instanceof WP_Post ? $queried : null;
	}

	private static function get_separator(): string {
		$options = get_option('rank-math-options-titles');
		$separator = is_array($options) && !empty($options['title_separator']) ? (string) $options['title_separator'] : '-';

		return $separator;
	}
}

Vigilant_Rank_Math_Oxygen_Meta::init();
