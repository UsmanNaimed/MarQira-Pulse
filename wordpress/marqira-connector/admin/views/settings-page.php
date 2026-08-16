<?php
/**
 * Settings page view.
 *
 * Expects the following variables in scope (provided by Marqira_Admin):
 *
 * @var array      $settings     Current plugin settings.
 * @var array      $diagnostics  Diagnostics data.
 * @var array|null $test_result  Test configuration result, if any.
 * @var array      $notices      Stored admin notices.
 * @var string     $save_url     admin-post.php URL for form submission.
 * @var array      $recent_logs  Recent log entries from Marqira_Logger::get_recent().
 *
 * @package Marqira_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$allowed_ips_text = '';
if ( ! empty( $settings['allowed_ips'] ) && is_array( $settings['allowed_ips'] ) ) {
	$allowed_ips_text = implode( "\n", $settings['allowed_ips'] );
}

$allowed_ips_display = ! empty( $settings['allowed_ips'] ) && is_array( $settings['allowed_ips'] )
	? implode( ', ', $settings['allowed_ips'] )
	: '—';

/**
 * Return a human-readable label for a log event key.
 *
 * @param string $event Machine-readable event key.
 * @return string
 */
function marqira_event_label( $event ) {
	$labels = array(
		'app_password_allowed'  => 'App Password Allowed',
		'app_password_denied'   => 'App Password Denied',
		'rest_denied'           => 'REST API Denied',
		'plugin_activated'      => 'Plugin Activated',
		'plugin_deactivated'    => 'Plugin Deactivated',
		'settings_saved'        => 'Settings Saved',
		'settings_invalid_ip'   => 'Invalid IP Rejected',
	);
	$event = (string) $event;
	return isset( $labels[ $event ] ) ? $labels[ $event ] : ucwords( str_replace( '_', ' ', $event ) );
}
?>
<div class="wrap marqira-wrap">

	<!-- Header -->
	<div class="marqira-header">
		<div class="marqira-header-mark">M</div>
		<div class="marqira-header-text">
			<h1><?php esc_html_e( 'MarQira Connector', 'marqira-connector' ); ?></h1>
			<p><?php esc_html_e( 'Centralized monitoring &amp; automation for your WordPress site.', 'marqira-connector' ); ?></p>
		</div>
		<div class="marqira-header-version">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: plugin version number */
					__( 'v%s', 'marqira-connector' ),
					MARQIRA_CONNECTOR_VERSION
				)
			);
			?>
		</div>
	</div>

	<!-- Admin notices -->
	<?php foreach ( $notices as $notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
			<p><?php echo wp_kses_post( $notice['message'] ); ?></p>
		</div>
	<?php endforeach; ?>

	<!-- Test-configuration result banner -->
	<?php if ( is_array( $test_result ) ) : ?>
		<?php if ( ! $test_result['protection'] ) : ?>
			<div class="notice notice-info is-dismissible">
				<p><?php esc_html_e( 'Application Password protection is currently disabled — all Application Password requests are allowed.', 'marqira-connector' ); ?></p>
			</div>
		<?php elseif ( $test_result['would_allow'] ) : ?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: IP address */
							__( 'Your current IP (%s) is ALLOWED. Application Password authentication would succeed from this address.', 'marqira-connector' ),
							$test_result['ip']
						)
					);
					?>
				</p>
			</div>
		<?php else : ?>
			<div class="notice notice-error is-dismissible">
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: IP address */
							__( 'Your current IP (%s) is DENIED. Application Password authentication would be blocked from this address.', 'marqira-connector' ),
							$test_result['ip']
						)
					);
					?>
				</p>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<!-- Two-column layout: main (settings + test) + sidebar (diagnostics) -->
	<div class="marqira-grid">

		<!-- ======================== MAIN COLUMN ======================== -->
		<div class="marqira-col-main">

			<!-- Settings form -->
			<div class="marqira-card">
				<h2 class="marqira-section-title"><?php esc_html_e( 'Settings', 'marqira-connector' ); ?></h2>

				<form method="post" action="<?php echo esc_url( $save_url ); ?>">
					<input type="hidden" name="action" value="marqira_save_settings" />
					<?php wp_nonce_field( 'marqira_save_settings', 'marqira_settings_nonce' ); ?>

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'Application Password Protection', 'marqira-connector' ); ?></th>
								<td>
									<label class="marqira-toggle-label">
										<input
											type="checkbox"
											name="protection_enabled"
											value="1"
											<?php checked( ! empty( $settings['protection_enabled'] ) ); ?>
										/>
										<?php esc_html_e( 'Restrict Application Password authentication to approved IPs', 'marqira-connector' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'When enabled, only requests from the allowed MarQira IPs below can authenticate using Application Passwords. Normal wp-admin login and the public REST API are not affected.', 'marqira-connector' ); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e( 'REST API IP Restriction', 'marqira-connector' ); ?></th>
								<td>
									<label class="marqira-toggle-label">
										<input
											type="checkbox"
											name="rest_restriction_enabled"
											value="1"
											<?php checked( ! empty( $settings['rest_restriction_enabled'] ) ); ?>
										/>
										<?php esc_html_e( 'Block REST API access from unapproved sources', 'marqira-connector' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'When enabled, REST API requests are only allowed from: logged-in users, requests arriving through Cloudflare (normal CDN-fronted visitors), and the allowed MarQira IPs below. Everything else receives HTTP 403.', 'marqira-connector' ); ?>
									</p>
									<p class="description marqira-warning-text">
										<strong><?php esc_html_e( 'Caution:', 'marqira-connector' ); ?></strong>
										<?php esc_html_e( 'On sites that are NOT behind Cloudflare, real visitors reach the origin directly and would be blocked from any front-end feature that uses the REST API (search, forms, block editor previews, etc.). Only enable this on Cloudflare-fronted sites where front-end pages do not rely on the public REST API.', 'marqira-connector' ); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="marqira_allowed_ips">
										<?php esc_html_e( 'Allowed MarQira IPs', 'marqira-connector' ); ?>
									</label>
								</th>
								<td>
									<textarea
										id="marqira_allowed_ips"
										name="allowed_ips"
										rows="8"
										class="large-text code"
										placeholder="187.77.136.105&#10;203.0.113.0/24&#10;2606:4700::/32"
									><?php echo esc_textarea( $allowed_ips_text ); ?></textarea>
									<p class="description">
										<?php esc_html_e( 'One entry per line. Supports IPv4, IPv6, and CIDR notation (e.g. 192.168.1.0/24). Lines beginning with # are treated as comments.', 'marqira-connector' ); ?>
									</p>
								</td>
							</tr>
						</tbody>
					</table>

					<?php submit_button( __( 'Save Settings', 'marqira-connector' ) ); ?>
				</form>
			</div>

			<!-- Test Configuration -->
			<div class="marqira-card">
				<h2 class="marqira-section-title"><?php esc_html_e( 'Test Configuration', 'marqira-connector' ); ?></h2>
				<p><?php esc_html_e( 'Check whether the IP you are currently browsing from would be allowed to authenticate using an Application Password.', 'marqira-connector' ); ?></p>

				<form method="post" action="<?php echo esc_url( $save_url ); ?>">
					<input type="hidden" name="action" value="marqira_test_configuration" />
					<?php wp_nonce_field( 'marqira_test_configuration', 'marqira_test_nonce' ); ?>
					<?php submit_button( __( 'Test Current IP', 'marqira-connector' ), 'secondary', 'submit', false ); ?>
				</form>

				<?php if ( is_array( $test_result ) ) : ?>
					<div class="marqira-test-result">
						<table class="marqira-diagnostics-table">
							<tbody>
								<tr>
									<th><?php esc_html_e( 'Detected IP', 'marqira-connector' ); ?></th>
									<td><code><?php echo esc_html( '' !== $test_result['ip'] ? $test_result['ip'] : '—' ); ?></code></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Detection Source', 'marqira-connector' ); ?></th>
									<td><?php echo esc_html( $test_result['source'] ); ?></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Cloudflare', 'marqira-connector' ); ?></th>
									<td>
										<?php if ( $test_result['cloudflare'] ) : ?>
											<span class="marqira-badge marqira-badge-orange"><?php esc_html_e( 'Detected', 'marqira-connector' ); ?></span>
										<?php else : ?>
											<span class="marqira-badge marqira-badge-grey"><?php esc_html_e( 'Not Detected', 'marqira-connector' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Result', 'marqira-connector' ); ?></th>
									<td>
										<?php if ( ! $test_result['protection'] ) : ?>
											<span class="marqira-badge marqira-badge-grey"><?php esc_html_e( 'Protection Disabled', 'marqira-connector' ); ?></span>
										<?php elseif ( $test_result['would_allow'] ) : ?>
											<span class="marqira-badge marqira-badge-green"><?php esc_html_e( 'Allowed', 'marqira-connector' ); ?></span>
										<?php else : ?>
											<span class="marqira-badge marqira-badge-red"><?php esc_html_e( 'Denied', 'marqira-connector' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>

		</div><!-- /.marqira-col-main -->

		<!-- ======================== SIDEBAR ======================== -->
		<div class="marqira-col-side">

			<!-- Diagnostics -->
			<div class="marqira-card">
				<h2 class="marqira-section-title"><?php esc_html_e( 'Diagnostics', 'marqira-connector' ); ?></h2>
				<table class="marqira-diagnostics-table">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'App Password Protection', 'marqira-connector' ); ?></th>
							<td>
								<?php if ( $diagnostics['protection_enabled'] ) : ?>
									<span class="marqira-badge marqira-badge-green"><?php esc_html_e( 'Enabled', 'marqira-connector' ); ?></span>
								<?php else : ?>
									<span class="marqira-badge marqira-badge-grey"><?php esc_html_e( 'Disabled', 'marqira-connector' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'REST API IP Restriction', 'marqira-connector' ); ?></th>
							<td>
								<?php if ( $diagnostics['rest_restriction_enabled'] ) : ?>
									<span class="marqira-badge marqira-badge-green"><?php esc_html_e( 'Enabled', 'marqira-connector' ); ?></span>
								<?php else : ?>
									<span class="marqira-badge marqira-badge-grey"><?php esc_html_e( 'Disabled', 'marqira-connector' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Allowed MarQira IPs', 'marqira-connector' ); ?></th>
							<td><code><?php echo esc_html( $allowed_ips_display ); ?></code></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Detected Client IP', 'marqira-connector' ); ?></th>
							<td><code><?php echo esc_html( '' !== $diagnostics['detected_ip'] ? $diagnostics['detected_ip'] : '—' ); ?></code></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'IP Detection Source', 'marqira-connector' ); ?></th>
							<td><?php echo esc_html( $diagnostics['ip_source'] ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Cloudflare', 'marqira-connector' ); ?></th>
							<td>
								<?php if ( $diagnostics['cloudflare_detected'] ) : ?>
									<span class="marqira-badge marqira-badge-orange"><?php esc_html_e( 'Detected', 'marqira-connector' ); ?></span>
								<?php else : ?>
									<span class="marqira-badge marqira-badge-grey"><?php esc_html_e( 'Not Detected', 'marqira-connector' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'WordPress Version', 'marqira-connector' ); ?></th>
							<td><?php echo esc_html( $diagnostics['wp_version'] ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'PHP Version', 'marqira-connector' ); ?></th>
							<td><?php echo esc_html( $diagnostics['php_version'] ); ?></td>
						</tr>
						<tr>
							<th>
								<?php esc_html_e( 'Server Address (internal)', 'marqira-connector' ); ?>
								<span
									class="marqira-hint"
									title="<?php esc_attr_e( 'PHP SERVER_ADDR — the server\'s own network interface. On shared/containerized hosting this is usually a private NAT IP (e.g. 10.x.x.x), NOT the public origin IP. The verified public origin IP is detected in a later MarQira phase.', 'marqira-connector' ); ?>"
								>&#9432;</span>
							</th>
							<td><code><?php echo esc_html( $diagnostics['server_addr'] ); ?></code></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Server Hostname', 'marqira-connector' ); ?></th>
							<td><code><?php echo esc_html( $diagnostics['server_hostname'] ); ?></code></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Plugin Version', 'marqira-connector' ); ?></th>
							<td><?php echo esc_html( $diagnostics['plugin_version'] ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Log Table Cap', 'marqira-connector' ); ?></th>
							<td>
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: maximum log rows */
										__( '%d rows max', 'marqira-connector' ),
										$diagnostics['log_cap']
									)
								);
								?>
							</td>
						</tr>
					</tbody>
				</table>
				<p class="description marqira-note">
					<?php esc_html_e( '"Server Address (internal)" is the host\'s own interface IP (PHP SERVER_ADDR). On shared hosting this is a private/NAT address and is not the public origin IP. Verified public origin IP detection arrives in a later MarQira phase.', 'marqira-connector' ); ?>
				</p>
			</div>

		</div><!-- /.marqira-col-side -->

	</div><!-- /.marqira-grid -->

	<!-- ==================== RECENT ACTIVITY (full width) ==================== -->
	<div class="marqira-card marqira-card-full">
		<h2 class="marqira-section-title">
			<?php esc_html_e( 'Recent Activity', 'marqira-connector' ); ?>
			<span class="marqira-section-subtitle">
				<?php esc_html_e( '(last 20 security-relevant events)', 'marqira-connector' ); ?>
			</span>
		</h2>

		<?php if ( empty( $recent_logs ) ) : ?>
			<p class="marqira-empty-log">
				<?php esc_html_e( 'No log entries yet. Events are recorded once Application Password authentication is attempted or when plugin settings are changed.', 'marqira-connector' ); ?>
			</p>
		<?php else : ?>
			<div class="marqira-log-scroll">
				<table class="marqira-log-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time (UTC)', 'marqira-connector' ); ?></th>
							<th><?php esc_html_e( 'Level', 'marqira-connector' ); ?></th>
							<th><?php esc_html_e( 'Event', 'marqira-connector' ); ?></th>
							<th><?php esc_html_e( 'IP', 'marqira-connector' ); ?></th>
							<th><?php esc_html_e( 'User', 'marqira-connector' ); ?></th>
							<th><?php esc_html_e( 'Message', 'marqira-connector' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent_logs as $row ) : ?>
							<tr>
								<td class="marqira-log-time">
									<code><?php echo esc_html( isset( $row['created_at'] ) ? $row['created_at'] : '' ); ?></code>
								</td>
								<td>
									<?php
									$level = isset( $row['level'] ) ? $row['level'] : 'info';
									$badge_class = 'marqira-badge-blue';
									if ( 'warning' === $level ) {
										$badge_class = 'marqira-badge-orange';
									} elseif ( 'error' === $level ) {
										$badge_class = 'marqira-badge-red';
									}
									?>
									<span class="marqira-badge <?php echo esc_attr( $badge_class ); ?>">
										<?php echo esc_html( strtoupper( $level ) ); ?>
									</span>
								</td>
								<td class="marqira-log-event">
									<?php echo esc_html( marqira_event_label( isset( $row['event'] ) ? $row['event'] : '' ) ); ?>
								</td>
								<td>
									<?php
									$ip = isset( $row['ip_address'] ) && '' !== $row['ip_address'] ? $row['ip_address'] : '—';
									echo '<code>' . esc_html( $ip ) . '</code>';
									?>
								</td>
								<td><?php echo esc_html( isset( $row['username'] ) && '' !== $row['username'] ? $row['username'] : '—' ); ?></td>
								<td class="marqira-log-message"><?php echo esc_html( isset( $row['message'] ) ? $row['message'] : '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>

	<!-- Footer -->
	<div class="marqira-footer">
		<?php esc_html_e( 'MarQira Connector', 'marqira-connector' ); ?> &middot;
		<a href="https://marqira.com" target="_blank" rel="noopener noreferrer">marqira.com</a>
	</div>

</div><!-- /.marqira-wrap -->
