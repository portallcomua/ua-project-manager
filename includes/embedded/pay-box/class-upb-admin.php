<?php
namespace UAPM\PayBox;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UPB_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_uapm_upb_save_service', array( __CLASS__, 'save_service' ) );
		add_action( 'admin_post_uapm_upb_delete_service', array( __CLASS__, 'delete_service' ) );
		add_action( 'admin_post_uapm_upb_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_notices', array( __CLASS__, 'missing_url_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( UPB_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=uapm-paybox-services' ) ) . '">Послуги</a>' );
		return $links;
	}

	/** Нагадування про послуги без повної конфігурації Freemius. */
	public static function missing_url_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$missing = array();
		foreach ( UPB_Services::all() as $s ) {
			$freemius_ready = ! empty( $s['freemius_product_id'] ) && ! empty( $s['freemius_plan_id'] ) && ! empty( $s['freemius_secret_key'] );
			if ( ! empty( $s['enabled'] ) && ! $freemius_ready ) {
				$missing[] = esc_html( $s['name'] );
			}
		}
		if ( $missing ) {
			printf(
				'<div class="notice notice-warning"><p><strong>UA Pay Box:</strong> заповніть Freemius Product ID, Plan ID і Product Secret Key для: %s. <a href="%s">Відкрити послуги</a></p></div>',
				implode( ', ', $missing ), // phpcs:ignore -- уже екрановано вище
				esc_url( admin_url( 'admin.php?page=uapm-paybox-services' ) )
			);
		}
	}

	public static function menu() {
		add_submenu_page( 'uapm', 'Оплати — послуги Pay Box', 'Оплати: послуги', 'manage_options', 'uapm-paybox-services', array( __CLASS__, 'page_services' ) );
		add_submenu_page( 'uapm', 'PRO ліцензії Freemius', 'Оплати: PRO ліцензії', 'manage_options', 'uapm-paybox-premiums', array( __CLASS__, 'page_premiums' ) );
		add_submenu_page( 'uapm', 'Налаштування Pay Box', 'Оплати: налаштування', 'manage_options', 'uapm-paybox-settings', array( __CLASS__, 'page_settings' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( $hook, 'upb' ) && false === strpos( $hook, 'uapm-paybox-' ) ) {
			return;
		}
		wp_enqueue_style( 'upb-admin', UPB_URL . 'assets/css/admin.css', array(), UPB_VERSION );
	}

	private static function url( $page, array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . $page ) );
	}

	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Недостатньо прав.' );
		}
	}

	private static function redirect_back( $fallback_page, $notice, array $extra = array() ) {
		$back = wp_get_referer();
		if ( ! $back ) {
			$back = self::url( $fallback_page );
		}
		$back = remove_query_arg( array( 'upb_notice', 'action', 'id' ), $back );
		wp_safe_redirect( add_query_arg( array_merge( array( 'upb_notice' => $notice ), $extra ), $back ) );
		exit;
	}

	private static function notice() {
		$map = array(
						'saved'         => array( 'updated', 'Послугу збережено.' ),
			'deleted'       => array( 'updated', 'Послугу видалено.' ),
			'settings'      => array( 'updated', 'Налаштування збережено.' ),
			'err_required'  => array( 'error', 'Заповніть назву послуги, Freemius Product ID, Plan ID та Product Secret Key.' ),
			'save_failed'  => array( 'error', 'Помилка збереження послуги. Дані не підтверджені після запису в базу WordPress. Перевірте права бази даних або журнал помилок.' ),
		);
		$key = isset( $_GET['upb_notice'] ) ? sanitize_key( wp_unslash( $_GET['upb_notice'] ) ) : '';
		if ( isset( $map[ $key ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( 'error' === $map[ $key ][0] ? 'error' : 'success' ), esc_html( $map[ $key ][1] ) );
		}
	}

	/* ================================================================== */
	/* Контроль Преміумів                                                  */
	/* ================================================================== */
	public static function page_premiums() {
		self::guard();
		global $wpdb;
		$table = $wpdb->prefix . 'upb_licenses';
		$services = UPB_Services::all();
		$per = 50;
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$s = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$where = '1=1';
		$args = array();
		if ( '' !== $s ) {
			$like = '%' . $wpdb->esc_like( $s ) . '%';
			$where = '(email LIKE %s OR license_id LIKE %s)';
			$args = array( $like, $like );
		}
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) );
		$list_sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d";
		$list_args = array_merge( $args, array( $per, ( $paged - 1 ) * $per ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_args ) );
		$pages = (int) ceil( $total / $per );
		?>
		<div class="wrap upb-wrap">
			<h1>Ліцензії Freemius</h1>
			<?php self::notice(); ?>
			<p>Це актуальні записи ліцензій, отримані від Freemius через підписані webhook-повідомлення. Доступ до PRO визначається лише чинним статусом ліцензії Freemius. Ручне блокування через Pay Box більше не використовується.</p>
			<form method="get" class="upb-search">
				<input type="hidden" name="page" value="upb-premiums">
				<input type="search" name="s" value="<?php echo esc_attr( $s ); ?>" placeholder="Email або ID ліцензії">
				<button class="button">Пошук</button>
				<?php if ( '' !== $s ) : ?><a class="button-link" href="<?php echo esc_url( self::url( 'upb-premiums' ) ); ?>">Скинути</a><?php endif; ?>
			</form>
			<table class="widefat striped upb-table">
				<thead><tr><th>Оновлено</th><th>Послуга</th><th>Email покупця</th><th>ID ліцензії</th><th>Діє до</th><th>Статус Freemius</th></tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?><tr><td colspan="6">Ліцензій Freemius поки немає. Вони з’являться після надходження webhook від Freemius.</td></tr><?php endif; ?>
				<?php foreach ( $rows as $r ) :
					$sid = (int) $r->service_id;
					$service_name = isset( $services[ $sid ]['name'] ) ? $services[ $sid ]['name'] : 'Послуга #' . $sid;
					$status = 'active' === $r->status ? 'Активна' : ( 'revoked' === $r->status ? 'Скасована / відкликана' : esc_html( $r->status ) );
				?>
				<tr>
					<td><?php echo esc_html( $r->updated_at ); ?></td>
					<td><?php echo esc_html( $service_name ); ?> <span class="upb-id">ID <?php echo $sid; ?></span></td>
					<td><?php echo esc_html( $r->email ? $r->email : '—' ); ?></td>
					<td><code><?php echo esc_html( $r->license_id ); ?></code></td>
					<td><?php echo (int) $r->expires ? esc_html( UPB_Util::fmt_ts( (int) $r->expires ) ) : 'Безстроково'; ?></td>
					<td><?php echo esc_html( $status ); ?></td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $pages > 1 ) : ?><div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages, 'prev_text' => '«', 'next_text' => '»' ) ) ); ?></div></div><?php endif; ?>
		</div>
		<?php
	}

	/* ================================================================== */
	/* Послуги                                                             */
	/* ================================================================== */
	public static function page_services() {
		self::guard();
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( 'add' === $action || 'edit' === $action ) {
			self::service_form( 'edit' === $action && isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
			return;
		}
		$services = UPB_Services::all();
		ksort( $services );
		// Collapse exact duplicate service names in the UI without deleting either record or its licenses.
		$groups = array();
		foreach ( $services as $service_id => $service ) {
			$name_key = strtolower( trim( preg_replace( '/\\s+/u', ' ', (string) ( $service['name'] ?? '' ) ) ) );
			if ( '' === $name_key ) { $name_key = 'service-' . (int) $service_id; }
			if ( ! isset( $groups[ $name_key ] ) ) { $groups[ $name_key ] = array(); }
			$groups[ $name_key ][ (int) $service_id ] = $service;
		}
		$services = array();
		foreach ( $groups as $group ) {
			$primary_id = (int) array_key_first( $group );
			foreach ( $group as $candidate_id => $candidate ) {
				$candidate_ready = ! empty( $candidate['freemius_product_id'] ) && ! empty( $candidate['freemius_plan_id'] ) && ! empty( $candidate['freemius_secret_key'] );
				$primary = $group[ $primary_id ];
				$primary_ready = ! empty( $primary['freemius_product_id'] ) && ! empty( $primary['freemius_plan_id'] ) && ! empty( $primary['freemius_secret_key'] );
				if ( $candidate_ready && ! $primary_ready ) { $primary_id = (int) $candidate_id; }
			}
			$primary = $group[ $primary_id ];
			$duplicate_ids = array_values( array_diff( array_map( 'intval', array_keys( $group ) ), array( $primary_id ) ) );
			if ( $duplicate_ids ) { $primary['_uapm_duplicate_ids'] = $duplicate_ids; }
			$services[ $primary_id ] = $primary;
		}
		ksort( $services );
		?>
		<div class="wrap upb-wrap">
			<h1 class="wp-heading-inline">Послуги</h1>
			<a href="<?php echo esc_url( self::url( 'uapm-paybox-services', array( 'action' => 'add' ) ) ); ?>" class="page-title-action">Додати послугу</a>
			<hr class="wp-header-end">
			<?php self::notice(); ?>

			<table class="widefat striped">
				<thead><tr><th>ID</th><th>Назва / плагін</th><th>Призначення</th><th>Вартість</th><th>Платіжна система</th><th>Шорткод</th><th>PHP-перевірка</th><th>Статус</th><th></th></tr></thead>
				<tbody>
				<?php if ( ! $services ) : ?>
					<tr><td colspan="9">Послуг ще немає. Натисніть «Додати послугу».</td></tr>
				<?php endif; ?>
				<?php foreach ( $services as $id => $s ) : ?>
					<tr>
						<td><strong><?php echo (int) $id; ?></strong></td>
						<td><?php echo esc_html( $s['name'] ); ?><?php if ( ! empty( $s['slug'] ) ) : ?><br><span class="upb-id"><?php echo esc_html( $s['slug'] ); ?></span><?php endif; ?><?php if ( empty( $s['freemius_product_id'] ) || empty( $s['freemius_plan_id'] ) || empty( $s['freemius_secret_key'] ) ) : ?><br><strong style="color:#b32d2e">⚠️ Freemius не налаштовано</strong><?php endif; ?><?php if ( ! empty( $s['_uapm_duplicate_ids'] ) ) : ?><br><small>Збережені дублікати (дані не видалено): <?php foreach ( $s['_uapm_duplicate_ids'] as $duplicate_id ) : ?><a href="<?php echo esc_url( self::url( 'uapm-paybox-services', array( 'action' => 'edit', 'id' => (int) $duplicate_id ) ) ); ?>">#<?php echo (int) $duplicate_id; ?></a> <?php endforeach; ?></small><?php endif; ?></td>
						<td><?php echo esc_html( ( $s['service_type'] ?? 'license' ) === 'advertising' ? 'Реклама' : 'PRO / ліцензія' ); ?></td>
						<td><?php echo esc_html( $s['price'] ); ?></td>
						<td>Freemius</td>
						<td><input type="text" readonly class="upb-code" value="[universal_pay_box id=&quot;<?php echo (int) $id; ?>&quot;]" onclick="this.select()"></td>
						<td><input type="text" readonly class="upb-code" value="wcc_check_user_premium($user_id, <?php echo (int) $id; ?>)" onclick="this.select()"></td>
						<td><?php echo ! empty( $s['enabled'] ) ? '🟢 Увімкнено' : '⚪ Вимкнено'; ?></td>
						<td class="upb-actions">
							<a href="<?php echo esc_url( self::url( 'uapm-paybox-services', array( 'action' => 'edit', 'id' => $id ) ) ); ?>">Редагувати</a> |
							<a class="upb-del" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=uapm_upb_delete_service&id=' . (int) $id ), 'uapm_upb_delete_' . (int) $id ) ); ?>" onclick="return confirm('Видалити послугу? Користувачі, які вже мають PRO, збережуть його до кінця терміну.');">Видалити</a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function service_form( $id ) {
		$svc = $id ? UPB_Services::get( $id ) : null;
		if ( $id && ! $svc ) {
			echo '<div class="wrap"><p>Послугу не знайдено.</p></div>';
			return;
		}
		$v = $svc ? wp_parse_args( $svc, UPB_Services::defaults() ) : UPB_Services::defaults();
		$pricing = $id ? UPB_Freemius::service_pricing( $v ) : array();
		$freemius_pricing = ! empty( $v['freemius_product_id'] ) && ! empty( $v['freemius_plan_id'] ) && ! empty( $v['freemius_api_token'] );
		if ( $freemius_pricing && ! empty( $pricing['usd_price'] ) ) {
			$v['price'] = '$' . number_format( (float) $pricing['usd_price'], 2, '.', '' ) . ( ! empty( $pricing['uah_price'] ) ? ' / ' . number_format( (float) $pricing['uah_price'], 0, '.', ' ' ) . ' грн' : '' );
		}
		?>
		<div class="wrap upb-wrap">
			<h1><?php echo $id ? 'Редагувати послугу #' . (int) $id : 'Нова послуга'; ?></h1>
			<?php self::notice(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="uapm_upb_save_service">
				<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
				<?php wp_nonce_field( 'uapm_upb_save_service_' . (int) $id ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th><label for="upb-service-type">Призначення послуги</label></th>
						<td><select id="upb-service-type" name="svc[service_type]"><option value="license" <?php selected( $v['service_type'] ?? 'license', 'license' ); ?>>PRO / ліцензія розширення</option><option value="advertising" <?php selected( $v['service_type'] ?? 'license', 'advertising' ); ?>>Розміщення реклами</option></select><p class="description">Послуги PRO і рекламні платежі зберігаються окремо. Для рекламного блоку обирайте «Розміщення реклами».</p></td>
					</tr>
					<tr>
						<th><label for="upb-slug">Slug плагіна</label></th>
						<td><input id="upb-slug" name="svc[slug]" type="text" class="regular-text code" value="<?php echo esc_attr( $v['slug'] ); ?>" placeholder="voice-add-woocommerce">
						<p class="description">Ідентифікатор для підключення плагіна (латиницею). Плагіни Voice ADD реєструють проект самі — тут він уже буде заповнений.</p></td>
					</tr>
					
					<tr>
						<th><label for="upb-name">Назва послуги *</label></th>
						<td><input id="upb-name" name="svc[name]" type="text" class="regular-text" required value="<?php echo esc_attr( $v['name'] ); ?>" placeholder="Шахи ПРО: Пожиттєвий"></td>
					</tr>
					<tr>
						<th><label for="upb-price">Вартість (текст)</label></th>
						<td><input id="upb-price" name="svc[price]" type="text" class="regular-text" value="<?php echo esc_attr( $v['price'] ); ?>" placeholder="Автоматично з Freemius" <?php echo $freemius_pricing ? 'readonly' : ''; ?>>
						<?php if ( $freemius_pricing ) : ?><p class="description">Ціна автоматично синхронізується з довічним планом Freemius. Показано USD та перерахунок у гривню за курсом НБУ (округлення вгору до 10 грн).</p><?php endif; ?></td>
					</tr>
					<tr>
						<th><label for="upb-desc">Короткий опис (на картці)</label></th>
						<td><textarea id="upb-desc" name="svc[description]" rows="3" class="large-text"><?php echo esc_textarea( $v['description'] ); ?></textarea></td>
					</tr>
					
					<tr>
						<th colspan="2"><h2>Freemius Checkout — єдиний спосіб оплати</h2><p class="description">Користувач переходить до Freemius. PRO відкривається лише після отримання та перевірки підписаного webhook про ліцензію. Натискання кнопки або повернення з Checkout не активує доступ.</p></th>
					</tr>
					<tr>
						<th><label for="upb-fs-product">Freemius Product ID</label></th>
						<td><input id="upb-fs-product" name="svc[freemius_product_id]" type="text" class="regular-text code" value="<?php echo esc_attr( $v['freemius_product_id'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="upb-fs-plan">Freemius Plan ID</label></th>
						<td><input id="upb-fs-plan" name="svc[freemius_plan_id]" type="text" class="regular-text code" value="<?php echo esc_attr( $v['freemius_plan_id'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="upb-fs-checkout">Freemius Checkout URL</label></th>
						<td><input id="upb-fs-checkout" name="svc[freemius_checkout_url]" type="url" class="large-text" value="<?php echo esc_attr( $v['freemius_checkout_url'] ); ?>" placeholder="https://checkout.freemius.com/..."></td>
					</tr>
					<tr>
						<th><label for="upb-fs-api-token">Freemius API Bearer Token</label></th>
						<td><input id="upb-fs-api-token" name="svc[freemius_api_token]" type="password" class="large-text code" value="" autocomplete="new-password" placeholder="<?php echo ! empty( $v['freemius_api_token'] ) ? 'API Token збережено — залиште порожнім, щоб не змінювати' : 'Вставте API Bearer Token'; ?>">
						<p class="description">Окремий токен Freemius → Product Settings → API &amp; Keys. Потрібен для автоматичного читання ціни плану. Зберігається тільки в Pay Box.</p></td>
					</tr>
					<tr>
						<th><label for="upb-fs-secret">Freemius Product Secret Key</label></th>
						<td><input id="upb-fs-secret" name="svc[freemius_secret_key]" type="password" class="large-text code" value="" autocomplete="new-password" placeholder="<?php echo ! empty( $v['freemius_secret_key'] ) ? 'Ключ збережено — залиште порожнім, щоб не змінювати' : 'Вставте Product Secret Key'; ?>">
						<p class="description">Зберігається тільки на сервері Pay Box. Ніколи не потрапляє в код для плагінів або додатків.</p></td>
					</tr>
					<?php if ( empty( $v['freemius_product_id'] ) || empty( $v['freemius_plan_id'] ) ) : ?>
					<tr>
						<th><label for="upb-days">Термін дії (днів)</label></th>
						<td><input id="upb-days" name="svc[days]" type="number" min="1" max="9999" value="<?php echo (int) $v['days']; ?>">
						<p class="description">30 = місяць, 365 = рік, <strong>9999 = безліміт</strong>.</p></td>
					</tr>
					<?php else : ?>
					<tr><th>Термін дії</th><td><strong>Керується Freemius</strong><p class="description">Для довічного плану Freemius поле днів не використовується.</p></td></tr>
					<?php endif; ?>
					
					
					
					
					
					<tr>
						<th>Статус</th>
						<td><label><input type="checkbox" name="svc[enabled]" value="1" <?php checked( ! empty( $v['enabled'] ) ); ?>> Послуга увімкнена (доступна для купівлі)</label></td>
					</tr>
				</table>
				<?php submit_button( 'Зберегти послугу' ); ?>
				<a href="<?php echo esc_url( self::url( 'uapm-paybox-services' ) ); ?>">← До списку</a>
			</form>

			<?php if ( $id && $svc ) : $svc['id'] = $id; ?>
				<hr>
				<h2>🔌 Код інтеграції для цього проекту</h2>
				<p>Вставте в головний файл будь-якого вашого WordPress-плагіна (плагіни Voice ADD вже мають його вбудованим). Код сформовано автоматично саме для «<?php echo esc_html( $svc['name'] ); ?>».</p>
				<textarea readonly rows="22" class="large-text code" onclick="this.select()"><?php echo esc_textarea( UPB_Snippet::php( $svc ) ); ?></textarea>
				<h3>HTML / JavaScript (гра, фронтенд)</h3>
				<textarea readonly rows="4" class="large-text code" onclick="this.select()"><?php echo esc_textarea( UPB_Snippet::html( $svc ) ); ?></textarea>
				<h3>REST (Android, Chrome-розширення, інші сайти)</h3>
				<textarea readonly rows="2" class="large-text code" onclick="this.select()"><?php echo esc_textarea( rest_url( 'upb/v1/check' ) . '?service=' . ( ! empty( $svc['slug'] ) ? $svc['slug'] : $id ) . '&username=LOGIN&api_key=' . UPB_Rest::api_key() ); ?></textarea>
				<?php if ( ! empty( $svc['freemius_checkout_url'] ) && ! empty( $svc['freemius_product_id'] ) && ! empty( $svc['freemius_plan_id'] ) && ! empty( $svc['freemius_secret_key'] ) ) : ?>
					<hr>
					<h2>🔐 Freemius — код перевірки ліцензії</h2>
					<p>Ці фрагменти не містять Product Secret Key. Користувач вводить ліцензійний ключ, який отримав після купівлі у Freemius.</p>
					<h3>WordPress / PHP</h3>
					<textarea readonly rows="22" class="large-text code" onclick="this.select()"><?php echo esc_textarea( UPB_Snippet::freemius_php( $svc ) ); ?></textarea>
					<h3>Android / Kotlin</h3>
					<textarea readonly rows="18" class="large-text code" onclick="this.select()"><?php echo esc_textarea( UPB_Snippet::android_kotlin( $svc ) ); ?></textarea>
					<h3>Chrome Extension / JavaScript</h3>
					<textarea readonly rows="14" class="large-text code" onclick="this.select()"><?php echo esc_textarea( UPB_Snippet::chrome_js( $svc ) ); ?></textarea>
					<p><strong>REST URL:</strong> <code><?php echo esc_html( rest_url( 'upb/v1/license/status' ) ); ?></code></p>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( $id && ! empty( $svc['freemius_product_id'] ) && ! empty( $svc['freemius_plan_id'] ) && ! empty( $svc['freemius_secret_key'] ) ) : ?>
				<h3>Freemius Webhook URL</h3>
				<p><input type="text" readonly class="large-text code" onclick="this.select()" value="<?php echo esc_attr( rest_url( 'upb/v1/freemius/webhook/' . (int) $id ) ); ?>"></p>
				<p class="description">Додайте цей URL у Webhooks Freemius для подій ліцензій. Pay Box перевіряє підпис x-signature через Product Secret Key.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function save_service() {
		self::guard();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'uapm_upb_save_service_' . $id );

		$raw = isset( $_POST['svc'] ) && is_array( $_POST['svc'] ) ? wp_unslash( $_POST['svc'] ) : array();
		$old = $id ? UPB_Services::get( $id ) : null;
		$data = UPB_Services::sanitize( $raw );

		// Preserve existing values when a browser or proxy omits an unchanged field.
		if ( $old ) {
			$data = array_merge( UPB_Services::defaults(), $old, $data );
		}
		// Remove deprecated Privat/Monobank fields from the saved service record.
		unset( $data['url'], $data['id_mode'], $data['block_subject'], $data['block_body'], $data['success_message'], $data['email_subject'], $data['email_body'] );
		if ( '' === $data['freemius_secret_key'] && $old && ! empty( $old['freemius_secret_key'] ) ) {
			$data['freemius_secret_key'] = $old['freemius_secret_key'];
		}

		$freemius_ready = '' !== $data['freemius_product_id'] && '' !== $data['freemius_plan_id'] && '' !== $data['freemius_secret_key'];
		if ( '' === $data['freemius_checkout_url'] && $freemius_ready ) { $data['freemius_checkout_url'] = UPB_Freemius::checkout_url( $data ); }
		if ( '' === $data['freemius_api_token'] && $old && ! empty( $old['freemius_api_token'] ) ) { $data['freemius_api_token'] = $old['freemius_api_token']; }
		if ( '' === $data['name'] || ! $freemius_ready ) {
			wp_safe_redirect( self::url( 'uapm-paybox-services', $id ? array( 'action' => 'edit', 'id' => $id, 'upb_notice' => 'err_required' ) : array( 'action' => 'add', 'upb_notice' => 'err_required' ) ) );
			exit;
		}

		if ( $id && ! $old ) {
			$id = 0;
		}
		$saved_id = UPB_Services::save( $id, $data );
		$saved = UPB_Services::get( $saved_id );

		// Verify that the critical Freemius fields survived the database round-trip.
		$verified = is_array( $saved )
			&& (string) $saved['freemius_product_id'] === (string) $data['freemius_product_id']
			&& (string) $saved['freemius_plan_id'] === (string) $data['freemius_plan_id']
			&& (string) $saved['freemius_checkout_url'] === (string) $data['freemius_checkout_url']
			&& (string) $saved['freemius_secret_key'] === (string) $data['freemius_secret_key'];

		if ( ! $verified ) {
			wp_safe_redirect( self::url( 'uapm-paybox-services', array( 'action' => 'edit', 'id' => $saved_id, 'upb_notice' => 'save_failed' ) ) );
			exit;
		}

		// Notify the central manager so project-to-service links are refreshed immediately.
		do_action( 'uapm_paybox_service_saved', $saved_id, $saved );
		// Return to the same service so the administrator can immediately confirm saved values.
		wp_safe_redirect( self::url( 'uapm-paybox-services', array( 'action' => 'edit', 'id' => $saved_id, 'upb_notice' => 'saved' ) ) );
		exit;
	}

	public static function delete_service() {
		self::guard();
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'uapm_upb_delete_' . $id );
		UPB_Services::delete( $id );
		wp_safe_redirect( self::url( 'uapm-paybox-services', array( 'upb_notice' => 'deleted' ) ) );
		exit;
	}

	/* ================================================================== */
	/* Налаштування + довідка                                              */
	/* ================================================================== */
	public static function page_settings() {
		self::guard();
		$o = UPB_Settings::all();
		?>
		<div class="wrap upb-wrap">
			<h1>Налаштування Pay Box</h1>
			<?php self::notice(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="uapm_upb_save_settings">
				<?php wp_nonce_field( 'uapm_upb_save_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="upb-warn">Попереджати за (днів)</label></th>
						<td><input id="upb-warn" type="number" min="1" max="60" name="opt[warn_days]" value="<?php echo (int) $o['warn_days']; ?>">
						<p class="description">Показувати попередження в картці Pay Box перед завершенням ліцензії.</p></td>
					</tr>
					<tr>
						<th>Скрипти на всіх сторінках</th>
						<td><label><input type="checkbox" name="opt[load_everywhere]" value="1" <?php checked( ! empty( $o['load_everywhere'] ) ); ?>> Завантажувати JS/CSS на всьому сайті</label>
						<p class="description">Потрібно, якщо кнопки <code>data-upb-buy</code> стоять усередині гри/іншого плагіна, а не в шорткоді. Інакше можна викликати <code>upb_enqueue_assets()</code> у коді.</p></td>
					</tr>
					<tr>
						<th>Видалення плагіна</th>
						<td><label><input type="checkbox" name="opt[delete_data]" value="1" <?php checked( ! empty( $o['delete_data'] ) ); ?>> Видалити ВСІ дані (послуги та ліцензії Freemius) при видаленні плагіна</label></td>
					</tr>
				</table>
				<?php submit_button( 'Зберегти' ); ?>
			</form>

			<hr>
			<h2>Ключ REST API</h2>
			<p><input type="text" readonly class="regular-text code" value="<?php echo esc_attr( UPB_Rest::api_key() ); ?>" onclick="this.select()"> — для перевірки PRO зовнішніми клієнтами: <code>/wp-json/upb/v1/check</code>.</p>
			<hr>
			<h2>Довідка для розробників</h2>
			<p><strong>Перевірка PRO в іншому плагіні (PHP):</strong></p>
			<pre class="upb-pre">if ( function_exists( 'wcc_check_user_premium' ) &amp;&amp; wcc_check_user_premium( get_current_user_id(), 1 ) ) {
    // користувач має PRO по послузі ID 1
}
// Кнопка «Купити PRO» (працює і в wp-admin):
echo upb_buy_button( 1, 'Купити PRO' );</pre>
			<p>Готовий код для кожного проекту: <strong>UA Pay Box → Послуги → Редагувати</strong> (блок «Код інтеграції»).</p>
			<p><strong>Кнопка «Купити» всередині гри / будь-якого HTML:</strong></p>
			<pre class="upb-pre">&lt;button data-upb-buy="1"&gt;Купити PRO&lt;/button&gt;
&lt;script&gt; UPB.open(1); &lt;/script&gt;   // або викликом з JS</pre>
			<p>Доступ до PRO перевіряється за чинною ліцензією Freemius через фільтр <code>upb_user_is_premium</code>. Також є <code>wcc_get_user_premium_expiry($user_id, $service_id)</code>.</p>
		</div>
		<?php
	}

	public static function save_settings() {
		self::guard();
		check_admin_referer( 'uapm_upb_save_settings' );
		$in   = isset( $_POST['opt'] ) && is_array( $_POST['opt'] ) ? wp_unslash( $_POST['opt'] ) : array();
		$warn = isset( $in['warn_days'] ) ? absint( $in['warn_days'] ) : 10;
		$warn = min( 60, max( 1, $warn ) );
		update_option(
			'upb_settings',
			array(
				'warn_days'       => $warn,
				'load_everywhere' => ! empty( $in['load_everywhere'] ) ? 1 : 0,
				'delete_data'     => ! empty( $in['delete_data'] ) ? 1 : 0,
			),
			false
		);
		wp_safe_redirect( self::url( 'uapm-paybox-settings', array( 'upb_notice' => 'settings' ) ) );
		exit;
	}
}
