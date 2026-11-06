<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\AdminPortal;

use D6N\LPM\Core\Contracts\ICache;
use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Modules\AdminPortal\Controllers\CountryCurrencyController;
use D6N\LPM\Modules\AdminPortal\Controllers\ExchangeRatesController;
use D6N\LPM\Modules\AdminPortal\Controllers\LogViewerController;
use D6N\LPM\Modules\AdminPortal\Controllers\SettingsController;
use D6N\LPM\Modules\AdminPortal\Metaboxes\CategoryMetabox;
use D6N\LPM\Modules\AdminPortal\Metaboxes\ProductMetabox;
use D6N\LPM\Modules\AdminPortal\Pages\CountriesTab;
use D6N\LPM\Modules\AdminPortal\Pages\ExchangeRateProviderNotice;
use D6N\LPM\Modules\AdminPortal\Pages\GeolocationProviderNotice;
use D6N\LPM\Modules\AdminPortal\Pages\LogsPage;
use D6N\LPM\Modules\AdminPortal\Pages\RatesTab;
use D6N\LPM\Modules\AdminPortal\Pages\SettingsPage;
use D6N\LPM\Modules\AdminPortal\Rest\RestController;
use D6N\LPM\Modules\AdminPortal\Settings\ApiKeyGateway;
use D6N\LPM\Modules\AdminPortal\Settings\FieldRenderer;
use D6N\LPM\Modules\AdminPortal\Settings\SettingsStore;
use D6N\LPM\Modules\AdminPortal\Support\CountryReference;
use D6N\LPM\Modules\Cache\Services\PriceCacheService;
use D6N\LPM\Modules\DiagnosticLog\Repositories\LogRepository;
use D6N\LPM\Modules\ExchangeRates\ExchangeRatesModule;
use D6N\LPM\Modules\Geolocation\GeolocationModule;
use D6N\LPM\Modules\PricingEngine\PricingEngineModule;

defined( 'ABSPATH' ) || exit;

/**
 * Admin Portal bounded-context module bootstrapper.
 */
class AdminPortalModule implements IHookable {

	/**
	 * Screens that actually ship a JavaScript bundle.
	 *
	 * @var array<int, string>
	 */
	private const SCRIPT_BUNDLES = array(
		'settings-general',
		'settings-exchange-rates',
		'settings-geolocation',
		'settings-countries',
		'product-metabox',
		'category-metabox',
	);

	private SettingsController $settings_controller;
	private LogViewerController $log_controller;
	private CountryCurrencyController $country_controller;
	private ExchangeRatesController $exchange_rates_controller;
	private SettingsPage $settings_page;
	private LogsPage $logs_page;
	private GeolocationProviderNotice $geolocation_notice;
	private ExchangeRateProviderNotice $exchange_rate_notice;
	private ProductMetabox $product_metabox;
	private CategoryMetabox $category_metabox;

	/**
	 * Constructor.
	 *
	 * @param LogRepository       $log_repository Log repository instance.
	 * @param ICache              $cache          Cache adapter.
	 * @param PriceCacheService   $price_cache    Resolved-price cache, the owner of its key scheme.
	 * @param ILogger             $logger         Logger instance.
	 * @param PricingEngineModule $pricing_engine Pricing engine module, shared with the storefront.
	 * @param ExchangeRatesModule $exchange_rates Exchange rates module, shared with the storefront.
	 * @param GeolocationModule   $geolocation    Geolocation module, shared with the storefront.
	 */
	public function __construct(
		LogRepository $log_repository,
		ICache $cache,
		PriceCacheService $price_cache,
		ILogger $logger,
		PricingEngineModule $pricing_engine,
		ExchangeRatesModule $exchange_rates,
		GeolocationModule $geolocation
	) {
		$rates_service = $exchange_rates->get_service();
		$store         = new SettingsStore();
		$api_keys      = new ApiKeyGateway( $exchange_rates->get_registry(), $geolocation->get_api_key_service() );
		$reference     = new CountryReference();

		$this->settings_controller       = new SettingsController( $store, $api_keys, $cache, $logger, $rates_service );
		$this->log_controller            = new LogViewerController( $log_repository );
		$this->country_controller        = new CountryCurrencyController(
			$pricing_engine->get_country_currency_repository(),
			$pricing_engine->get_country_group_repository(),
			$pricing_engine->get_country_currency_service(),
			$reference,
			$logger
		);
		$this->exchange_rates_controller = new ExchangeRatesController( $exchange_rates->get_repository(), $logger, $rates_service );

		$this->geolocation_notice   = new GeolocationProviderNotice( $geolocation->get_provider_queue() );
		$this->exchange_rate_notice = new ExchangeRateProviderNotice( $exchange_rates->get_registry() );

		$this->logs_page     = new LogsPage( $log_repository );
		$this->settings_page = new SettingsPage(
			$this->settings_controller,
			new FieldRenderer(),
			new CountriesTab( $pricing_engine->get_country_group_repository(), $reference ),
			$this->logs_page,
			new RatesTab( $exchange_rates->get_repository(), $rates_service )
		);

		$this->product_metabox  = new ProductMetabox( $pricing_engine->get_country_pricing_repository(), $price_cache, $logger, $reference, $pricing_engine->get_country_pricing_rule_guard() );
		$this->category_metabox = new CategoryMetabox( $pricing_engine->get_category_pricing_repository(), $cache, $logger, $reference );
	}

	/**
	 * Register module hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_hooks();
	}

	/**
	 * Register module hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_pages' ), 20 );
		add_action( 'admin_notices', array( $this, 'render_queued_notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_filter( 'rest_endpoints', array( $this, 'hide_private_endpoints' ) );

		$this->settings_controller->register_hooks();
		$this->log_controller->register_hooks();
		$this->country_controller->register_hooks();
		$this->exchange_rates_controller->register_hooks();
		$this->geolocation_notice->register_hooks();
		$this->exchange_rate_notice->register_hooks();
		$this->product_metabox->register_hooks();
		$this->category_metabox->register_hooks();
	}

	/**
	 * Register the plugin's one admin page under the WooCommerce menu.
	 *
	 * @return void
	 */
	public function add_menu_pages(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Localized Price', 'localized-price-manager' ),
			__( 'Localized Price', 'localized-price-manager' ),
			Core::get_admin_capability(),
			SettingsPage::PAGE_SLUG,
			array( $this->settings_page, 'render' )
		);
	}

	/**
	 * Drain the plugin's notice queue onto the current admin screen.
	 *
	 * @return void
	 */
	public function render_queued_notices(): void {
		if ( ! current_user_can( Core::get_admin_capability() ) ) {
			return;
		}

		Core::display_admin_notices();
	}

	/**
	 * Remove the plugin namespace from the route index for callers that could
	 * not use it anyway.
	 *
	 * @param array<string, mixed> $endpoints Registered endpoints.
	 * @return array<string, mixed> Filtered endpoints.
	 */
	public function hide_private_endpoints( array $endpoints ): array {
		if ( current_user_can( Core::get_admin_capability() ) ) {
			return $endpoints;
		}

		$prefix = '/' . RestController::REST_NAMESPACE;

		foreach ( array_keys( $endpoints ) as $route ) {
			if ( str_starts_with( (string) $route, $prefix ) ) {
				unset( $endpoints[ $route ] );
			}
		}

		return $endpoints;
	}

	/**
	 * Enqueue the stylesheet and exactly one script for the current screen.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		$bundle = $this->resolve_screen_bundle( $hook_suffix );

		if ( null === $bundle ) {
			return;
		}

		wp_enqueue_style(
			'lpm-admin',
			Core::get_plugin_dir_url() . 'assets/dist/css/admin.css',
			array(),
			Core::get_version()
		);

		wp_style_add_data( 'lpm-admin', 'rtl', Core::get_plugin_dir_url() . 'assets/dist/css/rtl.css' );

		if ( ! in_array( $bundle, self::SCRIPT_BUNDLES, true ) ) {
			return;
		}

		wp_enqueue_script(
			'lpm-' . $bundle,
			Core::get_plugin_dir_url() . 'assets/dist/js/' . $bundle . '.js',
			array(),
			Core::get_version(),
			true
		);

		wp_localize_script( 'lpm-' . $bundle, 'd6nLpmAdmin', $this->get_script_data( $bundle ) );
	}

	/**
	 * Decide which bundle, if any, the current screen needs.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return string|null Bundle name, or null when the screen is not ours.
	 */
	private function resolve_screen_bundle( string $hook_suffix ): ?string {
		if ( str_contains( $hook_suffix, SettingsPage::PAGE_SLUG ) ) {
			return 'settings-' . $this->settings_page->get_current_tab();
		}

		if ( in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) && $this->is_product_screen() ) {
			return 'product-metabox';
		}

		if ( in_array( $hook_suffix, array( 'edit-tags.php', 'term.php' ), true ) && $this->is_product_category_screen() ) {
			return 'category-metabox';
		}

		return null;
	}

	/**
	 * Build the data object handed to the enqueued script.
	 *
	 * @param string $bundle Bundle name.
	 * @return array<string, mixed> Localized data.
	 */
	private function get_script_data( string $bundle ): array {
		return array(
			'root'      => esc_url_raw( rest_url( RestController::REST_NAMESPACE . '/' ) ),
			'namespace' => RestController::REST_NAMESPACE,
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'bundle'    => $bundle,
			'tab'       => $this->settings_page->get_current_tab(),
			'i18n'      => array(
				'saved'         => __( 'Settings saved.', 'localized-price-manager' ),
				'saveFailed'    => __( 'The settings could not be saved.', 'localized-price-manager' ),
				'networkError'  => __( 'The server could not be reached.', 'localized-price-manager' ),
				'saving'        => __( 'Saving…', 'localized-price-manager' ),
				'confirmDelete' => __( 'Are you sure? This cannot be undone.', 'localized-price-manager' ),
				'dismissNotice' => __( 'Dismiss this notice.', 'localized-price-manager' ),
				'rowRemoved'    => __( 'Row removed. Save to apply the change.', 'localized-price-manager' ),
				'loadFailed'    => __( 'The table could not be loaded.', 'localized-price-manager' ),
				'noResults'     => __( 'No countries match this search.', 'localized-price-manager' ),
				'noRows'        => __( 'No countries to show.', 'localized-price-manager' ),
				/* translators: 1: first row number, 2: last row number, 3: total rows. */
				'showing'       => __( 'Showing %1$d to %2$d of %3$d countries.', 'localized-price-manager' ),
				'previousPage'  => __( 'Previous page', 'localized-price-manager' ),
				'nextPage'      => __( 'Next page', 'localized-price-manager' ),
				/* translators: %d: page number. */
				'goToPage'      => __( 'Go to page %d', 'localized-price-manager' ),
				'noneSelected'  => __( 'Select at least one country first.', 'localized-price-manager' ),
				'noBulkAction'  => __( 'Choose a bulk action first.', 'localized-price-manager' ),
				'confirmBulk'   => __( 'Apply this action to every selected country?', 'localized-price-manager' ),
				'editGroup'     => __( 'Edit group', 'localized-price-manager' ),
				'addGroup'      => __( 'Add a group', 'localized-price-manager' ),
				/* translators: 1: item label, 2: new position, 3: number of items. */
				'movedTo'       => __( '%1$s moved to position %2$d of %3$d.', 'localized-price-manager' ),
				'unassigned'    => __( 'Unassigned', 'localized-price-manager' ),
			),
		);
	}

	/**
	 * Check whether the current post screen edits a product.
	 *
	 * @return bool True on the product edit screen.
	 */
	private function is_product_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		return 'product' === $screen?->post_type;
	}

	/**
	 * Check whether the current term screen edits a product category.
	 *
	 * @return bool True on the product category screen.
	 */
	private function is_product_category_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		return 'product_cat' === $screen?->taxonomy;
	}
}
