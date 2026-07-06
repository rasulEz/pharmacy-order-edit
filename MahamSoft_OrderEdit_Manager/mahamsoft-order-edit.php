<?php
/**
 * Plugin Name:       Mahamsoft - Order Edit Manager
 * Plugin URI:        https://mahamsoft.local
 * Description:       مدیریت ویرایش سفارش‌های ووکامرس - کنترل موجودی هنگام ویرایش، تعیین کاربران و وضعیت‌های مجاز برای ویرایش/بازگشت به انبار، و ثبت کامل گزارش تغییرات سفارش (دستی و آنلاین).
 * Version:           2.1.0
 * Author:            Mahamsoft
 * Text Domain:       mahamsoft-order-edit
 * Requires Plugins:  woocommerce
 *
 * فایل اصلی پلاگین.
 * ماژول وب‌سرویس داروپردازان در فایل mahamsoft-pharmacy-api.php قرار دارد.
 *
 * --- تغییرات نسخه 2.1.0 ---
 * 1) تشخیص خودکار HPOS و سازگاری کامل با هر دو حالت (کلاسیک و HPOS)
 *    از طریق متد متمرکز is_hpos_enabled().
 * 2) اعلام سازگاری با HPOS به ووکامرس (FeaturesUtil) تا اخطار
 *    «ناسازگار» در صفحه افزونه‌ها نمایش داده نشود.
 * 3) هماهنگی با منطق جدید ماژول وب‌سرویس برای برگشت موجودی هنگام
 *    حذف ردیف محصول (با همان _expiry_date ذخیره‌شده).
 */

// جلوگیری از دسترسی مستقیم به فایل
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// مسیر پوشه پلاگین
define( 'MAHAMSOFT_OE_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAHAMSOFT_OE_FILE', __FILE__ );

// بارگذاری ماژول وب‌سرویس داروپردازان
$pharmacy_module = MAHAMSOFT_OE_DIR . 'mahamsoft-pharmacy-api.php';
if ( file_exists( $pharmacy_module ) ) {
    require_once $pharmacy_module;
}

/*
 * --- اعلام سازگاری با HPOS (High-Performance Order Storage) ---
 * این بلاک باید قبل از init و روی هوک before_woocommerce_init ثبت شود
 * تا ووکامرس بداند این افزونه با ذخیره‌سازی جدید سفارش‌ها سازگار است.
 */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            MAHAMSOFT_OE_FILE,
            true
        );
    }
} );

/**
 * ============================================================================
 * کلاس اصلی پلاگین
 * ----------------------------------------------------------------------------
 * تمامی منطق پلاگین به صورت متدهای استاتیک/نمونه در این کلاس قرار گرفته است
 * تا از تداخل نام توابع (function name collision) با سایر پلاگین‌ها جلوگیری شود.
 * ============================================================================
 */
final class Mahamsoft_Order_Edit_Manager {

    /** @var string نام جدول گزارش تغییرات سفارش (بدون پیشوند $wpdb->prefix) */
    const LOG_TABLE = 'mahamsoft_dpn_order_edit';

    /** @var string کلید ذخیره تنظیمات در wp_options */
    const OPTION_KEY = 'mahamsoft_order_edit_settings';

    /** @var string نسخه دیتابیس - برای آپدیت‌های بعدی جدول */
    const DB_VERSION = '2.0.0';

    /** @var string کلید آپشن نسخه دیتابیس نصب‌شده */
    const DB_VERSION_OPTION = 'mahamsoft_order_edit_db_version';

    /** @var Mahamsoft_Order_Edit_Manager|null نمونه singleton */
    private static $instance = null;

    /**
     * آرایه‌ای از شناسه سفارش‌هایی که در همین درخواست (request) جاری
     * برای اولین بار ایجاد شده‌اند (هوک woocommerce_new_order برای آن‌ها
     * اجرا شده است).
     *
     * @var array<int,bool>
     */
    private $orders_created_this_request = array();

    /**
     * دریافت نمونه singleton کلاس
     *
     * @return Mahamsoft_Order_Edit_Manager
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * سازنده کلاس - تمامی hook های پلاگین در اینجا ثبت می‌شوند
     */
    private function __construct() {

        // ---------------------------------------------------------------
        // Activation / Deactivation Hooks
        // ---------------------------------------------------------------
        register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );
        register_deactivation_hook( __FILE__, array( __CLASS__, 'deactivate' ) );

        // بررسی نسخه دیتابیس در هر بار بارگذاری ادمین (برای آپدیت‌های بعدی پلاگین)
        add_action( 'plugins_loaded', array( $this, 'maybe_upgrade_db' ) );

        // --- اطمینان از وجود جدول گزارش (در صورت عدم اجرای activation hook) ---
        add_action( 'admin_init', array( $this, 'maybe_create_log_table' ), 5 );
        add_action( 'admin_notices', array( $this, 'maybe_show_table_error_notice' ) );

        // ---------------------------------------------------------------
        // منوهای ادمین
        // ---------------------------------------------------------------
        add_action( 'admin_menu', array( $this, 'register_admin_menus' ) );

        // ثبت تنظیمات (Settings API)
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // ---------------------------------------------------------------
        // بخش ۱: نمایش موجودی قابل استفاده در فیلد تعداد + مودال خطا
        // ---------------------------------------------------------------
        add_filter( 'woocommerce_admin_order_item_quantity', array( $this, 'add_available_stock_attribute' ), 10, 3 );
        add_action( 'admin_footer', array( $this, 'render_stock_error_modal_assets' ) );

        // ---------------------------------------------------------------
        // بخش ۲: بررسی موجودی قبل از ذخیره آیتم‌های سفارش (AJAX امن)
        // ---------------------------------------------------------------
        add_action( 'woocommerce_before_save_order_items', array( $this, 'validate_stock_before_save' ), 10, 2 );

        // رفع باگ "Undefined" notice/warning قبل از پاسخ AJAX
        add_action( 'wp_ajax_woocommerce_save_order_items', array( $this, 'start_output_buffer_for_ajax' ), 1 );

        // ---------------------------------------------------------------
        // بخش ۳: جلوگیری از کاهش دوباره موجودی توسط ادمین
        // ---------------------------------------------------------------
        add_filter( 'woocommerce_can_reduce_order_stock', array( $this, 'prevent_double_stock_reduction' ), 10, 2 );

        // ---------------------------------------------------------------
        // بخش ۴: محدودسازی ویرایش/بازگشت سفارش بر اساس تنظیمات
        // ---------------------------------------------------------------
        add_filter( 'wc_order_is_editable', array( $this, 'filter_order_is_editable' ), 20, 2 );
        add_filter( 'woocommerce_can_restock_refunded_items', array( $this, 'filter_can_restock_refunded_items' ), 20, 2 );

        // محدودسازی دسترسی کاربران به ویرایش سفارش (متابوکس‌های ادیتور)
        add_action( 'admin_init', array( $this, 'restrict_order_edit_access' ) );

        // ---------------------------------------------------------------
        // بخش ۵: ثبت گزارش تغییرات سفارش
        // ---------------------------------------------------------------
        $this->register_logging_hooks();

        // ثبت سفارش‌های دستی از طریق پیشخوان
        add_action( 'admin_init', array( $this, 'maybe_flag_manual_order_creation' ) );
        add_action( 'woocommerce_new_order', array( $this, 'log_new_order_creation' ), 20, 2 );

        // ---------------------------------------------------------------
        // AJAX برای نمایش مودال "مشاهده تغییرات"
        // ---------------------------------------------------------------
        add_action( 'wp_ajax_mahamsoft_get_order_edit_log', array( $this, 'ajax_get_order_edit_log_details' ) );

        // خروجی CSV گزارش تغییرات یک سفارش
        add_action( 'admin_post_mahamsoft_oe_export_log', array( $this, 'export_order_edit_log_csv' ) );

        // حذف گروهی/تکی/همه رکوردهای گزارش (از صفحه گزارش)
        add_action( 'admin_post_mahamsoft_oe_bulk_delete_logs', array( $this, 'handle_bulk_delete_logs' ) );

        // حذف یک رکورد لاگ تکی (از داخل مودال تغییرات سفارش)
        add_action( 'admin_post_mahamsoft_oe_delete_single_log', array( $this, 'handle_delete_single_log' ) );
    }

    /* =====================================================================
     * تشخیص HPOS (سازگاری با هر دو حالت)
     * ===================================================================== */

    /**
     * تشخیص فعال بودن HPOS (High-Performance Order Storage).
     *
     * این متد به‌صورت ایمن و با چند fallback کار می‌کند تا روی نسخه‌های
     * مختلف ووکامرس (قدیمی بدون HPOS و جدید با HPOS) بدون خطا اجرا شود.
     *
     * @return bool true اگر HPOS فعال باشد
     */
    public static function is_hpos_enabled() {

        // روش رسمی و ترجیحی (ووکامرس 6.4+ / 7.1+)
        if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
            if ( method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' ) ) {
                return (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
            }
        }

        // fallback: بررسی آپشن مستقیم
        $option = get_option( 'woocommerce_custom_orders_table_enabled' );
        return ( 'yes' === $option );
    }

    /**
     * دریافت آدرس لیست سفارش‌ها (سازگار با کلاسیک و HPOS)
     *
     * @return string
     */
    private function get_orders_list_url() {
        if ( self::is_hpos_enabled() ) {
            return admin_url( 'admin.php?page=wc-orders' );
        }
        return admin_url( 'edit.php?post_type=shop_order' );
    }

    /* =====================================================================
     * ACTIVATION / DEACTIVATION
     * ===================================================================== */

    /**
     * عملیات هنگام فعال‌سازی پلاگین:
     * - ساخت جدول گزارش تغییرات (mahamsoft_dpn_order_edit)
     * - ثبت مقادیر پیش‌فرض تنظیمات (در صورت عدم وجود)
     */
    public static function activate() {
        self::create_log_table();
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );

        // مقادیر پیش‌فرض تنظیمات در صورت نبود
        if ( false === get_option( self::OPTION_KEY ) ) {
            $defaults = array(
                'editable_statuses'    => array( 'pending', 'processing', 'on-hold' ),
                'restockable_statuses' => array( 'cancelled', 'refunded' ),
                'allowed_users'        => array(),
                'new_order_log_scope'  => array( 'admin' ),
                'log_phase_filter'     => array( 'auto', 'edit' ),
            );
            update_option( self::OPTION_KEY, $defaults );
        }
    }

    /**
     * عملیات هنگام غیرفعال‌سازی پلاگین
     * (جدول گزارش حذف نمی‌شود تا داده‌های تاریخی از بین نروند)
     */
    public static function deactivate() {
        // در صورت نیاز به پاکسازی کامل (uninstall)، باید از uninstall.php
        // یا register_uninstall_hook استفاده شود. در اینجا عمداً داده‌ای حذف نمی‌شود.
    }

    /**
     * بررسی نسخه دیتابیس و ساخت/به‌روزرسانی جدول در صورت نیاز.
     */
    public function maybe_upgrade_db() {

        $installed_version = get_option( self::DB_VERSION_OPTION );

        if ( $installed_version === self::DB_VERSION ) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::LOG_TABLE;

        // ارتقا از نسخه 1.x به 2.0.0: حذف کامل جدول قبلی (طبق تصمیم کارفرما)
        if ( $installed_version && version_compare( $installed_version, '2.0.0', '<' ) ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore
        }

        self::create_log_table();
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );

        // پاک‌سازی کش بررسی وجود جدول تا بار بعد دوباره چک شود
        delete_transient( 'mahamsoft_oe_table_check' );
    }

    /**
     * --- اطمینان از وجود جدول گزارش در هر بارگذاری پنل مدیریت ---
     */
    public function maybe_create_log_table() {

        // فقط در پنل مدیریت - برای جلوگیری از سربار روی فرانت‌اند
        if ( ! is_admin() ) {
            return;
        }

        $cached = get_transient( 'mahamsoft_oe_table_check' );
        if ( 'exists' === $cached ) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::LOG_TABLE;

        $table_exists = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
        );

        if ( $table_name === $table_exists ) {
            set_transient( 'mahamsoft_oe_table_check', 'exists', 10 * MINUTE_IN_SECONDS );
            return;
        }

        // جدول وجود ندارد - تلاش برای ساخت آن
        self::create_log_table();

        $table_exists_after = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
        );

        if ( $table_name === $table_exists_after ) {
            set_transient( 'mahamsoft_oe_table_check', 'exists', 10 * MINUTE_IN_SECONDS );
            delete_option( 'mahamsoft_oe_table_creation_error' );
        } else {
            update_option(
                'mahamsoft_oe_table_creation_error',
                $wpdb->last_error ? $wpdb->last_error : __( 'دلیل نامشخص - دسترسی CREATE TABLE کاربر دیتابیس را بررسی کنید.', 'mahamsoft-order-edit' )
            );
            set_transient( 'mahamsoft_oe_table_check', 'missing', MINUTE_IN_SECONDS );
        }
    }

    /**
     * نمایش پیام خطا در پنل مدیریت در صورتی که جدول گزارش ساخته نشده باشد.
     */
    public function maybe_show_table_error_notice() {

        $error = get_option( 'mahamsoft_oe_table_creation_error' );

        if ( ! $error ) {
            return;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::LOG_TABLE;

        printf(
            '<div class="notice notice-error"><p>%s</p><p><code>%s</code></p></div>',
            sprintf(
                /* translators: %s: نام جدول */
                esc_html__( 'پلاگین "Mahamsoft - Order Edit Manager" نتوانست جدول گزارش (%s) را در دیتابیس بسازد. لطفاً با میزبان سایت خود تماس بگیرید و از دسترسی CREATE TABLE برای کاربر دیتابیس مطمئن شوید.', 'mahamsoft-order-edit' ),
                '<strong>' . esc_html( $table_name ) . '</strong>'
            ),
            esc_html( $error )
        );
    }

    /**
     * ساخت جدول گزارش تغییرات سفارش با استفاده از dbDelta
     */
    private static function create_log_table() {
        global $wpdb;

        $table_name      = $wpdb->prefix . self::LOG_TABLE;
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            order_type VARCHAR(20) NOT NULL DEFAULT 'online',
            created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            editor_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            log_phase VARCHAR(10) NOT NULL DEFAULT 'edit',
            change_summary VARCHAR(255) NOT NULL DEFAULT '',
            change_details LONGTEXT NULL,
            last_status VARCHAR(60) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY created_at (created_at),
            KEY log_phase (log_phase)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /* =====================================================================
     * تنظیمات (Settings) - منوها و رجیستر آپشن‌ها
     * ===================================================================== */

    /**
     * ثبت منو اصلی "ویرایش سفارش" و زیرمنوهای آن
     */
    public function register_admin_menus() {

        // منوی اصلی
        add_menu_page(
            __( 'ویرایش سفارش', 'mahamsoft-order-edit' ),
            __( 'ویرایش سفارش', 'mahamsoft-order-edit' ),
            'manage_woocommerce',
            'mahamsoft-order-edit',
            array( $this, 'render_settings_page' ),
            'dashicons-edit-page',
            56
        );

        // زیرمنو: تنظیمات
        add_submenu_page(
            'mahamsoft-order-edit',
            __( 'تنظیمات ویرایش سفارش', 'mahamsoft-order-edit' ),
            __( 'تنظیمات', 'mahamsoft-order-edit' ),
            'manage_woocommerce',
            'mahamsoft-order-edit',
            array( $this, 'render_settings_page' )
        );

        // زیرمنو: گزارش سفارشات ویرایش شده
        add_submenu_page(
            'mahamsoft-order-edit',
            __( 'گزارش سفارشات ویرایش شده', 'mahamsoft-order-edit' ),
            __( 'گزارش سفارشات ویرایش شده', 'mahamsoft-order-edit' ),
            'manage_woocommerce',
            'mahamsoft-order-edit-report',
            array( $this, 'render_report_page' )
        );
    }

    /**
     * ثبت تنظیمات با استفاده از Settings API
     */
    public function register_settings() {
        register_setting(
            'mahamsoft_order_edit_settings_group',
            self::OPTION_KEY,
            array( $this, 'sanitize_settings' )
        );
    }

    /**
     * پاک‌سازی و اعتبارسنجی داده‌های تنظیمات قبل از ذخیره
     *
     * @param array $input داده‌های خام ارسالی از فرم
     * @return array داده‌های پاک‌سازی شده
     */
    public function sanitize_settings( $input ) {
        $clean = array();

        $clean['editable_statuses'] = isset( $input['editable_statuses'] ) && is_array( $input['editable_statuses'] )
            ? array_map( 'sanitize_key', $input['editable_statuses'] )
            : array();

        $clean['restockable_statuses'] = isset( $input['restockable_statuses'] ) && is_array( $input['restockable_statuses'] )
            ? array_map( 'sanitize_key', $input['restockable_statuses'] )
            : array();

        $clean['allowed_users'] = isset( $input['allowed_users'] ) && is_array( $input['allowed_users'] )
            ? array_map( 'absint', $input['allowed_users'] )
            : array();

        $clean['new_order_log_scope'] = isset( $input['new_order_log_scope'] ) && is_array( $input['new_order_log_scope'] )
            ? array_intersect( array_map( 'sanitize_key', $input['new_order_log_scope'] ), array( 'admin', 'customer' ) )
            : array();

        $clean['log_phase_filter'] = isset( $input['log_phase_filter'] ) && is_array( $input['log_phase_filter'] )
            ? array_intersect( array_map( 'sanitize_key', $input['log_phase_filter'] ), array( 'auto', 'edit' ) )
            : array();

        // --- تنظیمات وب سرویس انبار ---
        $clean['warehouse_name']         = isset( $input['warehouse_name'] ) ? sanitize_text_field( $input['warehouse_name'] ) : 'داروپردازان';
        $clean['api_base_url']           = isset( $input['api_base_url'] ) ? esc_url_raw( $input['api_base_url'] ) : '';
        $clean['api_key']                = isset( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : '';
        $clean['api_server_name']        = isset( $input['api_server_name'] ) ? sanitize_text_field( $input['api_server_name'] ) : '';
        $clean['api_username']           = isset( $input['api_username'] ) ? sanitize_text_field( $input['api_username'] ) : '';
        $clean['api_password']           = isset( $input['api_password'] ) ? sanitize_text_field( $input['api_password'] ) : '';
        $clean['api_anbar_id']           = isset( $input['api_anbar_id'] ) ? sanitize_text_field( $input['api_anbar_id'] ) : '';
        $clean['api_darookhaneh_id']     = isset( $input['api_darookhaneh_id'] ) ? absint( $input['api_darookhaneh_id'] ) : 0;
        $clean['api_default_code_melli'] = isset( $input['api_default_code_melli'] ) ? sanitize_text_field( $input['api_default_code_melli'] ) : '';

        return $clean;
    }

    /**
     * دریافت تنظیمات ذخیره‌شده پلاگین به همراه مقادیر پیش‌فرض
     *
     * @return array
     */
    private function get_settings() {
        $defaults = array(
            'editable_statuses'      => array( 'pending', 'processing', 'on-hold' ),
            'restockable_statuses'   => array( 'cancelled', 'refunded' ),
            'allowed_users'          => array(),
            'new_order_log_scope'    => array( 'admin' ),
            'log_phase_filter'       => array( 'auto', 'edit' ),
            // وب سرویس انبار
            'warehouse_name'         => 'داروپردازان',
            'api_base_url'           => '',
            'api_key'                => '',
            'api_server_name'        => '',
            'api_username'           => '',
            'api_password'           => '',
            'api_anbar_id'           => '',
            'api_darookhaneh_id'     => 0,
            'api_default_code_melli' => '',
        );

        $saved = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $saved ) ) {
            $saved = array();
        }

        return wp_parse_args( $saved, $defaults );
    }

    /**
     * رندر صفحه تنظیمات
     */
    public function render_settings_page() {

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'شما اجازه دسترسی به این صفحه را ندارید.', 'mahamsoft-order-edit' ) );
        }

        // ذخیره تنظیمات در صورت ارسال فرم
        if ( isset( $_POST['mahamsoft_oe_save_settings'] ) ) {

            check_admin_referer( 'mahamsoft_oe_settings_nonce' );

            $input = isset( $_POST[ self::OPTION_KEY ] ) && is_array( $_POST[ self::OPTION_KEY ] )
                ? wp_unslash( $_POST[ self::OPTION_KEY ] ) // phpcs:ignore
                : array();

            $clean = $this->sanitize_settings( $input );
            update_option( self::OPTION_KEY, $clean );

            echo '<div class="notice notice-success is-dismissible"><p>' .
                esc_html__( 'تنظیمات با موفقیت ذخیره شد.', 'mahamsoft-order-edit' ) .
                '</p></div>';
        }

        $settings = $this->get_settings();

        // دریافت تمام وضعیت‌های سفارش ووکامرس
        $all_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();

        // دریافت کاربران به جز نقش‌های مشتری و مشترک (customer, subscriber)
        $excluded_roles = array( 'customer', 'subscriber' );
        $all_users      = get_users( array( 'orderby' => 'display_name', 'order' => 'ASC' ) );

        $eligible_users = array();
        foreach ( $all_users as $u ) {
            $roles             = (array) $u->roles;
            $has_eligible_role = array_diff( $roles, $excluded_roles );
            if ( ! empty( $has_eligible_role ) ) {
                $eligible_users[] = $u;
            }
        }
        ?>
        <div class="wrap" dir="rtl">
            <h1><?php esc_html_e( 'تنظیمات ویرایش سفارش', 'mahamsoft-order-edit' ); ?></h1>

            <form method="post" action="">
                <?php wp_nonce_field( 'mahamsoft_oe_settings_nonce' ); ?>

                <table class="form-table" role="presentation">
                    <tbody>

                        <!-- وضعیت‌های قابل ویرایش -->
                        <tr>
                            <th scope="row">
                                <label for="mahamsoft_editable_statuses">
                                    <?php esc_html_e( 'وضعیت‌های قابل ویرایش', 'mahamsoft-order-edit' ); ?>
                                </label>
                            </th>
                            <td>
                                <select multiple
                                        id="mahamsoft_editable_statuses"
                                        name="<?php echo esc_attr( self::OPTION_KEY ); ?>[editable_statuses][]"
                                        style="min-height: 180px; min-width: 320px;">
                                    <?php foreach ( $all_statuses as $status_key => $status_label ) :
                                        $status_slug = str_replace( 'wc-', '', $status_key );
                                        ?>
                                        <option value="<?php echo esc_attr( $status_slug ); ?>"
                                            <?php echo in_array( $status_slug, (array) $settings['editable_statuses'], true ) ? 'selected="selected"' : ''; ?>>
                                            <?php echo esc_html( $status_label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?php esc_html_e( 'فقط سفارش‌هایی با این وضعیت‌ها قابل ویرایش خواهند بود (دکمه ویرایش آیتم‌ها فعال می‌شود).', 'mahamsoft-order-edit' ); ?>
                                </p>
                            </td>
                        </tr>

                        <!-- وضعیت‌های قابل بازگشت سفارش -->
                        <tr>
                            <th scope="row">
                                <label for="mahamsoft_restockable_statuses">
                                    <?php esc_html_e( 'وضعیت‌های قابل بازگشت سفارش', 'mahamsoft-order-edit' ); ?>
                                </label>
                            </th>
                            <td>
                                <select multiple
                                        id="mahamsoft_restockable_statuses"
                                        name="<?php echo esc_attr( self::OPTION_KEY ); ?>[restockable_statuses][]"
                                        style="min-height: 180px; min-width: 320px;">
                                    <?php foreach ( $all_statuses as $status_key => $status_label ) :
                                        $status_slug = str_replace( 'wc-', '', $status_key );
                                        ?>
                                        <option value="<?php echo esc_attr( $status_slug ); ?>"
                                            <?php echo in_array( $status_slug, (array) $settings['restockable_statuses'], true ) ? 'selected="selected"' : ''; ?>>
                                            <?php echo esc_html( $status_label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?php esc_html_e( 'فقط سفارش‌هایی با این وضعیت‌ها اجازه بازگشت اقلام به انبار را خواهند داشت.', 'mahamsoft-order-edit' ); ?>
                                </p>
                            </td>
                        </tr>

                        <!-- کاربران ویرایش‌کننده سفارش -->
                        <tr>
                            <th scope="row">
                                <label for="mahamsoft_allowed_users">
                                    <?php esc_html_e( 'کاربران ویرایش‌کننده سفارش', 'mahamsoft-order-edit' ); ?>
                                </label>
                            </th>
                            <td>
                                <select multiple
                                        id="mahamsoft_allowed_users"
                                        name="<?php echo esc_attr( self::OPTION_KEY ); ?>[allowed_users][]"
                                        style="min-height: 220px; min-width: 320px;">
                                    <?php foreach ( $eligible_users as $u ) : ?>
                                        <option value="<?php echo esc_attr( $u->ID ); ?>"
                                            <?php echo in_array( (int) $u->ID, (array) $settings['allowed_users'], true ) ? 'selected="selected"' : ''; ?>>
                                            <?php echo esc_html( $u->display_name . ' (' . $u->user_login . ')' ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?php esc_html_e( 'فقط کاربران انتخاب‌شده (به جز مشتری و مشترک) اجازه ویرایش سفارش‌ها را خواهند داشت. مدیران (administrator) همواره دسترسی کامل دارند.', 'mahamsoft-order-edit' ); ?>
                                </p>
                            </td>
                        </tr>

                        <!-- گزارش ثبت سفارشات جدید -->
                        <tr>
                            <th scope="row">
                                <label for="mahamsoft_new_order_log_scope">
                                    <?php esc_html_e( 'گزارش ثبت سفارشات جدید', 'mahamsoft-order-edit' ); ?>
                                </label>
                            </th>
                            <td>
                                <select multiple
                                        id="mahamsoft_new_order_log_scope"
                                        name="<?php echo esc_attr( self::OPTION_KEY ); ?>[new_order_log_scope][]"
                                        style="min-height: 80px; min-width: 320px;">
                                    <?php
                                    $new_order_log_scope_options = array(
                                        'admin'    => __( 'مدیر (سفارش‌های ثبت‌شده از پیشخوان)', 'mahamsoft-order-edit' ),
                                        'customer' => __( 'کاربر (سفارش‌های ثبت‌شده آنلاین توسط مشتری)', 'mahamsoft-order-edit' ),
                                    );
                                    foreach ( $new_order_log_scope_options as $opt_key => $opt_label ) :
                                        ?>
                                        <option value="<?php echo esc_attr( $opt_key ); ?>"
                                            <?php echo in_array( $opt_key, (array) $settings['new_order_log_scope'], true ) ? 'selected="selected"' : ''; ?>>
                                            <?php echo esc_html( $opt_label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?php esc_html_e( 'مشخص می‌کند ثبت یک سفارش جدید (بدون هیچ ویرایش بعدی) در گزارش سفارشات ویرایش‌شده درج شود یا نه. برای انتخاب هر دو، هر دو گزینه را انتخاب کنید. سفارش‌های آنلاین در صورت عدم انتخاب "کاربر"، فقط زمانی که ویرایش شوند وارد گزارش می‌شوند.', 'mahamsoft-order-edit' ); ?>
                                </p>
                            </td>
                        </tr>

                        <!-- فیلتر فاز ثبت لاگ -->
                        <tr>
                            <th scope="row">
                                <label for="mahamsoft_log_phase_filter">
                                    <?php esc_html_e( 'ثبت لاگ‌ها طبق', 'mahamsoft-order-edit' ); ?>
                                </label>
                            </th>
                            <td>
                                <select multiple
                                        id="mahamsoft_log_phase_filter"
                                        name="<?php echo esc_attr( self::OPTION_KEY ); ?>[log_phase_filter][]"
                                        style="min-height: 80px; min-width: 320px;">
                                    <?php
                                    $log_phase_filter_options = array(
                                        'auto' => __( 'فرایند خودکار (ثبت سفارش جدید، تغییرات ضمن ثبت سفارش)', 'mahamsoft-order-edit' ),
                                        'edit' => __( 'ویرایش دستی (تغییرات بعدی توسط ادمین)', 'mahamsoft-order-edit' ),
                                    );
                                    foreach ( $log_phase_filter_options as $opt_key => $opt_label ) :
                                        ?>
                                        <option value="<?php echo esc_attr( $opt_key ); ?>"
                                            <?php echo in_array( $opt_key, (array) $settings['log_phase_filter'], true ) ? 'selected="selected"' : ''; ?>>
                                            <?php echo esc_html( $opt_label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?php esc_html_e( 'مشخص می‌کند کدام دسته از لاگ‌ها در گزارش سفارشات ویرایش‌شده ثبت شوند: لاگ‌های مربوط به فرایند خودکار (ثبت سفارش)، لاگ‌های ویرایش دستی، یا هر دو. اگر هیچ‌کدام انتخاب نشود، هر دو دسته ثبت می‌شوند.', 'mahamsoft-order-edit' ); ?>
                                </p>
                            </td>
                        </tr>

                    </tbody>
                </table>

                <hr>
                <h2><?php esc_html_e( 'تنظیمات اتصال به وب سرویس انبار', 'mahamsoft-order-edit' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tbody>

                        <!-- نام انبار -->
                        <tr>
                            <th scope="row">
                                <label for="mahamsoft_warehouse_name">
                                    <?php esc_html_e( 'نام انبار', 'mahamsoft-order-edit' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text"
                                       id="mahamsoft_warehouse_name"
                                       name="<?php echo esc_attr( self::OPTION_KEY ); ?>[warehouse_name]"
                                       value="<?php echo esc_attr( $settings['warehouse_name'] ); ?>"
                                       style="min-width: 320px;">
                                <p class="description">
                                    <?php esc_html_e( 'نام انبار که در متن لاگ‌ها نمایش داده می‌شود. مثال: داروپردازان', 'mahamsoft-order-edit' ); ?>
                                </p>
                            </td>
                        </tr>

                        <!-- آدرس API -->
                        <tr>
                            <th scope="row">
                                <label for="mahamsoft_api_base_url">
                                    <?php esc_html_e( 'آدرس پایه API', 'mahamsoft-order-edit' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text"
                                       id="mahamsoft_api_base_url"
                                       name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_base_url]"
                                       value="<?php echo esc_attr( $settings['api_base_url'] ); ?>"
                                       style="min-width: 400px;"
                                       placeholder="https://example.com">
                            </td>
                        </tr>

                        <!-- API Key -->
                        <tr>
                            <th scope="row">
                                <label for="mahamsoft_api_key">
                                    <?php esc_html_e( 'API Key (x-api-key)', 'mahamsoft-order-edit' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text"
                                       id="mahamsoft_api_key"
                                       name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_key]"
                                       value="<?php echo esc_attr( $settings['api_key'] ); ?>"
                                       style="min-width: 320px;">
                            </td>
                        </tr>

                        <!-- اطلاعات اتصال دیتابیس -->
                        <tr>
                            <th scope="row"><?php esc_html_e( 'اطلاعات اتصال (Connection)', 'mahamsoft-order-edit' ); ?></th>
                            <td>
                                <table>
                                    <tr>
                                        <td><label><?php esc_html_e( 'serverName:', 'mahamsoft-order-edit' ); ?></label></td>
                                        <td>
                                            <input type="text"
                                                   name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_server_name]"
                                                   value="<?php echo esc_attr( $settings['api_server_name'] ); ?>"
                                                   style="min-width: 200px;">
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><label><?php esc_html_e( 'username:', 'mahamsoft-order-edit' ); ?></label></td>
                                        <td>
                                            <input type="text"
                                                   name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_username]"
                                                   value="<?php echo esc_attr( $settings['api_username'] ); ?>"
                                                   style="min-width: 200px;">
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><label><?php esc_html_e( 'password:', 'mahamsoft-order-edit' ); ?></label></td>
                                        <td>
                                            <input type="password"
                                                   name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_password]"
                                                   value="<?php echo esc_attr( $settings['api_password'] ); ?>"
                                                   style="min-width: 200px;">
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>

                        <!-- اطلاعات انبار پیش‌فرض -->
                        <tr>
                            <th scope="row"><?php esc_html_e( 'اطلاعات انبار پیش‌فرض', 'mahamsoft-order-edit' ); ?></th>
                            <td>
                                <table>
                                    <tr>
                                        <td><label><?php esc_html_e( 'anbarID:', 'mahamsoft-order-edit' ); ?></label></td>
                                        <td>
                                            <input type="text"
                                                   name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_anbar_id]"
                                                   value="<?php echo esc_attr( $settings['api_anbar_id'] ); ?>"
                                                   style="min-width: 320px;">
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><label><?php esc_html_e( 'darookhaneID:', 'mahamsoft-order-edit' ); ?></label></td>
                                        <td>
                                            <input type="number"
                                                   name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_darookhaneh_id]"
                                                   value="<?php echo esc_attr( $settings['api_darookhaneh_id'] ); ?>"
                                                   style="min-width: 100px;">
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><label><?php esc_html_e( 'codeMelliDaroo پیش‌فرض (موقت):', 'mahamsoft-order-edit' ); ?></label></td>
                                        <td>
                                            <input type="text"
                                                   name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_default_code_melli]"
                                                   value="<?php echo esc_attr( $settings['api_default_code_melli'] ); ?>"
                                                   style="min-width: 200px;">
                                            <p class="description"><?php esc_html_e( 'در صورتی که محصول SKU نداشته باشد، این مقدار به‌عنوان کد ملی دارو استفاده می‌شود.', 'mahamsoft-order-edit' ); ?></p>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>

                    </tbody>
                </table>

                <?php submit_button( __( 'ذخیره تنظیمات', 'mahamsoft-order-edit' ), 'primary', 'mahamsoft_oe_save_settings' ); ?>

            </form>

            <?php
            // محل نمایش بخش‌های اضافی (مثل دکمه تست اتصال ماژول وب‌سرویس)
            do_action( 'mahamsoft_oe_settings_after_form' );
            ?>
        </div>
        <?php
    }

    /* =====================================================================
     * بخش ۱: نمایش موجودی قابل استفاده + مودال خطا
     * ===================================================================== */

    /**
     * افزودن attribute "data-available-stock" به فیلد تعداد در ادیتور سفارش
     *
     * @param string                $qty_html HTML فیلد تعداد
     * @param WC_Order_Item_Product $item     آیتم سفارش
     * @param WC_Product|false      $product  محصول مرتبط
     * @return string HTML اصلاح‌شده
     */
    public function add_available_stock_attribute( $qty_html, $item, $product ) {

        $available = 999999;

        if ( $product && $product->managing_stock() ) {
            $current_stock = (int) $product->get_stock_quantity();
            $old_qty       = (int) $item->get_quantity();
            $available     = $current_stock + $old_qty;
        }

        $qty_html = str_replace(
            '<input',
            '<input data-available-stock="' . esc_attr( $available ) . '"',
            $qty_html
        );

        return $qty_html;
    }

    /**
     * رندر مودال خطای موجودی + اسکریپت‌های مرتبط در صفحه ویرایش سفارش (admin_footer)
     */
    public function render_stock_error_modal_assets() {

        if ( ! $this->is_order_edit_screen() ) {
            return;
        }
        ?>
        <style>
            #mwp-stock-error-modal {
                display: none;
                position: fixed;
                z-index: 99999;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                width: 400px;
                max-width: 90%;
                background: #fff;
                border: 1px solid #ccc;
                box-shadow: 0 5px 20px rgba(0,0,0,0.3);
                padding: 20px;
                border-radius: 8px;
                direction: rtl;
            }
            #mwp-stock-error-modal h2 {
                margin-top: 0;
                font-size: 18px;
                color: #a00;
            }
            #mwp-stock-error-modal p {
                white-space: pre-line;
            }
            #mwp-stock-error-modal button {
                margin-top: 15px;
                padding: 6px 12px;
                background: #a00;
                color: #fff;
                border: none;
                border-radius: 4px;
                cursor: pointer;
            }
        </style>

        <div id="mwp-stock-error-modal">
            <h2><?php esc_html_e( 'خطای موجودی', 'mahamsoft-order-edit' ); ?></h2>
            <p id="mwp-stock-error-text"></p>
            <button id="mwp-stock-error-close"><?php esc_html_e( 'بستن', 'mahamsoft-order-edit' ); ?></button>
        </div>

        <script type="text/javascript">
        jQuery(function ($) {

            // بستن مودال با کلیک روی دکمه بستن
            $('#mwp-stock-error-close').on('click', function () {
                $('#mwp-stock-error-modal').hide();
            });

            /**
             * بررسی سمت کلاینت: هنگام تغییر مقدار در فیلد تعداد،
             * اگر مقدار وارد شده بیشتر از موجودی قابل استفاده باشد
             * مودال خطا نمایش داده و مقدار به سقف موجودی بازگردانده می‌شود.
             */
            $(document).on('change', 'input.qty', function () {
                var $input    = $(this);
                var qty       = Number($input.val()) || 0;
                var available = Number($input.attr('data-available-stock')) || 999999;

                if (qty > available && available < 999999) {
                    $('#mwp-stock-error-text').html(
                        '<?php echo esc_js( __( 'تعداد وارد شده بیشتر از موجودی قابل استفاده است!', 'mahamsoft-order-edit' ) ); ?>\n' +
                        '<?php echo esc_js( __( 'موجودی قابل استفاده: ', 'mahamsoft-order-edit' ) ); ?>' + available + '\n' +
                        '<?php echo esc_js( __( 'تعداد وارد شده: ', 'mahamsoft-order-edit' ) ); ?>' + qty
                    );
                    $('#mwp-stock-error-modal').show();
                    $input.val(available > 0 ? available : 0);
                }
            });

            /**
             * بررسی پاسخ AJAX ذخیره آیتم‌های سفارش (woocommerce_save_order_items).
             */
            $(document).ajaxComplete(function (event, xhr, settings) {
                if (settings.data && settings.data.indexOf('action=woocommerce_save_order_items') !== -1) {
                    try {
                        var response = $.parseJSON(xhr.responseText);

                        if (response && response.success === false && response.data && response.data.messages) {

                            if (typeof wc_meta_boxes_order_alert === 'function') {
                                window.wc_meta_boxes_order_alert = function () {};
                            }

                            var msg = response.data.messages.join('<hr>');
                            $('#mwp-stock-error-text').html(msg);
                            $('#mwp-stock-error-modal').show();
                        }
                    } catch (e) {
                        if (window.console && window.console.warn) {
                            console.warn('Mahamsoft Order Edit: failed to parse AJAX response', e, xhr.responseText);
                        }
                    }
                }
            });
        });
        </script>
        <?php
    }

    /**
     * بررسی اینکه آیا صفحه فعلی، صفحه ویرایش سفارش ووکامرس است
     * (سازگار با هر دو حالت کلاسیک post-type=shop_order و HPOS)
     *
     * @return bool
     */
    private function is_order_edit_screen() {
        global $post, $pagenow;

        // حالت کلاسیک (Custom Post Type shop_order)
        if ( $post && isset( $post->post_type ) && 'shop_order' === $post->post_type ) {
            return true;
        }

        // حالت HPOS (High-Performance Order Storage) - بر اساس screen
        if ( function_exists( 'get_current_screen' ) ) {
            $screen = get_current_screen();
            if ( $screen && isset( $screen->id ) ) {
                if ( false !== strpos( $screen->id, 'wc-order' ) || 'woocommerce_page_wc-orders' === $screen->id ) {
                    return true;
                }
            }
        }

        // بررسی پارامتر page برای HPOS در admin.php
        if ( 'admin.php' === $pagenow && isset( $_GET['page'] ) && 'wc-orders' === $_GET['page'] ) { // phpcs:ignore
            return true;
        }

        return false;
    }

    /* =====================================================================
     * رفع باگ "Undefined" - Output Buffering برای AJAX
     * ===================================================================== */

    /**
     * شروع output buffering در ابتدای اجرای اکشن AJAX ذخیره آیتم‌های سفارش.
     */
    public function start_output_buffer_for_ajax() {
        if ( ! ob_get_level() ) {
            ob_start();
        }
    }

    /* =====================================================================
     * بخش ۲: بررسی موجودی قبل از ذخیره آیتم‌های سفارش
     * ===================================================================== */

    /**
     * بررسی سرور-ساید موجودی قبل از ذخیره تغییرات آیتم‌های سفارش.
     *
     * @param int   $order_id شناسه سفارش
     * @param array $items    داده‌های خام ارسالی از فرم ($_POST مربوط به آیتم‌ها)
     */
    public function validate_stock_before_save( $order_id, $items ) {

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $errors = array();
        $logger = wc_get_logger();

        foreach ( $order->get_items() as $item ) {

            $item_id = absint( $item->get_id() );

            $new_qty = isset( $items['order_item_qty'][ $item_id ] )
                ? absint( $items['order_item_qty'][ $item_id ] )
                : (int) $item->get_quantity();

            if ( $new_qty <= 0 ) {
                continue;
            }

            $product = $item->get_product();

            if ( ! $product || ! $product->managing_stock() ) {
                continue;
            }

            $current_stock = (int) $product->get_stock_quantity();
            $old_qty       = (int) $item->get_quantity();
            $available     = $current_stock + $old_qty;

            if ( $new_qty > $available ) {

                $product_name = $this->get_linked_product_name( $item );

                $errors[] =
                    sprintf( esc_html__( 'محصول: %s', 'mahamsoft-order-edit' ), $product_name ) . '<br>' .
                    sprintf( esc_html__( 'موجودی فعلی انبار: %s', 'mahamsoft-order-edit' ), number_format( $current_stock ) ) . '<br>' .
                    sprintf( esc_html__( 'موجودی رزرو شده در این سفارش: %s', 'mahamsoft-order-edit' ), number_format( $old_qty ) ) . '<br>' .
                    sprintf( esc_html__( 'حداکثر قابل سفارش: %s', 'mahamsoft-order-edit' ), number_format( $available ) ) . '<br>' .
                    sprintf( esc_html__( 'تعداد وارد شده: %s', 'mahamsoft-order-edit' ), number_format( $new_qty ) ) . '<br>';

                if ( $logger ) {
                    $logger->error(
                        sprintf(
                            'Order %1$d | Stock error | Product ID %2$d | Entered: %3$d | Available: %4$d',
                            $order_id,
                            $product->get_id(),
                            $new_qty,
                            $available
                        ),
                        array( 'source' => 'mahamsoft-order-stock-validation' )
                    );
                }
            }
        }

        if ( ! empty( $errors ) ) {

            $message  = implode( "\n" . str_repeat( '- ', 20 ) . "\n", $errors );
            $message .= "\n" . esc_html__( 'امکان ورودی تعداد، بیشتر از موجودی نمی‌باشد.', 'mahamsoft-order-edit' );

            if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {

                if ( ob_get_level() > 0 ) {
                    ob_clean();
                }

                wp_send_json_error(
                    array(
                        'messages' => array( $message ),
                    )
                );

            } else {

                if ( ob_get_level() > 0 ) {
                    ob_end_clean();
                }

                wp_die(
                    wp_kses_post( nl2br( $message ) ),
                    esc_html__( 'خطای موجودی', 'mahamsoft-order-edit' ),
                    array( 'back_link' => true )
                );
            }
        }

        if ( defined( 'DOING_AJAX' ) && DOING_AJAX && ob_get_level() > 0 ) {
            ob_end_flush();
        }
    }

    /**
     * دریافت نام لینک‌شده محصول برای نمایش در پیام خطا (با پشتیبانی از محصولات متغیر)
     *
     * @param WC_Order_Item_Product $item آیتم سفارش
     * @return string HTML شامل نام محصول + لینک ویرایش
     */
    private function get_linked_product_name( $item ) {

        $product = $item->get_product();

        if ( ! $product ) {
            return esc_html( $item->get_name() );
        }

        $product_id   = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
        $product_name = html_entity_decode( get_the_title( $product_id ), ENT_QUOTES, 'UTF-8' );
        $edit_link    = get_edit_post_link( $product_id );

        $name = htmlspecialchars( $product_name, ENT_QUOTES, 'UTF-8' );

        if ( $product->is_type( 'variation' ) ) {

            $attr_parts           = array();
            $variation_attributes = $product->get_attributes();

            foreach ( $variation_attributes as $attr_name => $attr_obj ) {

                $label = wc_attribute_label( $attr_name );
                $value = $item->get_meta( $attr_name );

                if ( taxonomy_exists( $attr_name ) ) {
                    $term = get_term_by( 'slug', $value, $attr_name );
                    if ( $term && ! is_wp_error( $term ) ) {
                        $value = $term->name;
                    }
                }

                if ( $value ) {
                    $attr_parts[] = "{$label}: {$value}";
                }
            }

            if ( ! empty( $attr_parts ) ) {
                $name .= ' - ' . implode( ' | ', $attr_parts );
            }
        }

        if ( $edit_link ) {
            $name .= ' <a href="' . esc_url( $edit_link ) . '" target="_blank">(' . esc_html__( 'ویرایش محصول', 'mahamsoft-order-edit' ) . ')</a>';
        }

        return $name;
    }

    /* =====================================================================
     * بخش ۳: جلوگیری از کاهش دوباره موجودی توسط ادمین
     * ===================================================================== */

    /**
     * جلوگیری از کاهش مجدد موجودی هنگام ذخیره سفارش توسط ادمین در ووکامرس.
     *
     * @param bool     $can_reduce وضعیت فعلی امکان کاهش موجودی
     * @param WC_Order $order      سفارش
     * @return bool
     */
    public function prevent_double_stock_reduction( $can_reduce, $order ) {
        if ( is_admin() && ! wp_doing_ajax() ) {
            return false;
        }
        return $can_reduce;
    }

    /* =====================================================================
     * بخش ۴: محدودسازی ویرایش/بازگشت بر اساس تنظیمات (وضعیت + کاربر)
     * ===================================================================== */

    /**
     * تعیین اینکه آیا سفارش با توجه به وضعیت آن، طبق تنظیمات پلاگین قابل ویرایش است.
     *
     * @param bool     $is_editable نتیجه پیش‌فرض ووکامرس
     * @param WC_Order $order       سفارش
     * @return bool
     */
    public function filter_order_is_editable( $is_editable, $order ) {

        if ( ! $order instanceof WC_Order ) {
            return $is_editable;
        }

        $current_status = $order->get_status();

        // وضعیت‌های پیش‌نویس همواره قابل ویرایش (برای دکمه‌های افزودن آیتم هنگام ساخت سفارش جدید)
        $draft_statuses = array( 'auto-draft', 'draft', 'checkout-draft' );
        if ( in_array( $current_status, $draft_statuses, true ) ) {
            return $is_editable;
        }

        $settings          = $this->get_settings();
        $editable_statuses = (array) $settings['editable_statuses'];

        if ( empty( $editable_statuses ) ) {
            return $is_editable;
        }

        return in_array( $current_status, $editable_statuses, true );
    }

    /**
     * تعیین اینکه آیا اقلام سفارش در صورت بازگشت/استرداد قابل بازگشت به انبار هستند.
     *
     * @param bool     $can_restock نتیجه پیش‌فرض
     * @param WC_Order $order       سفارش
     * @return bool
     */
    public function filter_can_restock_refunded_items( $can_restock, $order ) {

        if ( ! $order instanceof WC_Order ) {
            return $can_restock;
        }

        $settings             = $this->get_settings();
        $restockable_statuses = (array) $settings['restockable_statuses'];

        if ( empty( $restockable_statuses ) ) {
            return $can_restock;
        }

        $current_status = $order->get_status();

        return in_array( $current_status, $restockable_statuses, true );
    }

    /**
     * محدودسازی دسترسی به ویرایش سفارش بر اساس لیست "کاربران ویرایش‌کننده سفارش".
     * (سازگار با کلاسیک و HPOS)
     */
    public function restrict_order_edit_access() {

        global $pagenow;

        // حالت کلاسیک: post.php?post=ID&action=edit
        $is_classic_edit = ( 'post.php' === $pagenow )
            && isset( $_GET['action'], $_GET['post'] ) // phpcs:ignore
            && 'edit' === $_GET['action']; // phpcs:ignore

        // حالت HPOS: admin.php?page=wc-orders&action=edit&id=ID
        $is_hpos_edit = ( 'admin.php' === $pagenow )
            && isset( $_GET['page'], $_GET['action'], $_GET['id'] ) // phpcs:ignore
            && 'wc-orders' === $_GET['page'] // phpcs:ignore
            && 'edit' === $_GET['action']; // phpcs:ignore

        if ( ! $is_classic_edit && ! $is_hpos_edit ) {
            return;
        }

        // در حالت کلاسیک، اطمینان از اینکه پست از نوع shop_order است
        if ( $is_classic_edit ) {
            $post_id   = absint( $_GET['post'] ); // phpcs:ignore
            $post_type = get_post_type( $post_id );
            if ( 'shop_order' !== $post_type ) {
                return;
            }
        }

        $current_user = wp_get_current_user();

        // مدیران کل همیشه دسترسی کامل دارند
        if ( in_array( 'administrator', (array) $current_user->roles, true ) ) {
            return;
        }

        $settings      = $this->get_settings();
        $allowed_users = (array) $settings['allowed_users'];

        // اگر لیست کاربران مجاز خالی است، محدودیتی اعمال نمی‌شود
        if ( empty( $allowed_users ) ) {
            return;
        }

        if ( ! in_array( (int) $current_user->ID, $allowed_users, true ) ) {

            // ریدایرکت به لیست سفارش‌ها (سازگار با کلاسیک و HPOS)
            $redirect_url = add_query_arg(
                array( 'mahamsoft_oe_denied' => 1 ),
                $this->get_orders_list_url()
            );

            wp_safe_redirect( $redirect_url );
            exit;
        }
    }

    /* =====================================================================
     * بخش ۵: ثبت گزارش تغییرات سفارش (Logging)
     * ===================================================================== */

    /**
     * ثبت تمامی hook های مربوط به ثبت تغییرات سفارش در جدول گزارش.
     */
    private function register_logging_hooks() {

        // مرحله ۱: گرفتن snapshot قبل از ذخیره آیتم‌های سفارش
        add_action( 'woocommerce_before_save_order_items', array( $this, 'capture_order_snapshot_before' ), 5, 2 );

        // snapshot برای اکشن‌های AJAX جداگانه ووکامرس
        add_action( 'wp_ajax_woocommerce_add_order_item', array( $this, 'capture_snapshot_from_post_order_id' ), 1 );
        add_action( 'wp_ajax_woocommerce_add_order_fee', array( $this, 'capture_snapshot_from_post_order_id' ), 1 );
        add_action( 'wp_ajax_woocommerce_add_order_shipping', array( $this, 'capture_snapshot_from_post_order_id' ), 1 );
        add_action( 'wp_ajax_woocommerce_add_coupon_discount', array( $this, 'capture_snapshot_from_post_order_id' ), 1 );
        add_action( 'wp_ajax_woocommerce_remove_order_coupon', array( $this, 'capture_snapshot_from_post_order_id' ), 1 );
        add_action( 'wp_ajax_woocommerce_remove_order_item', array( $this, 'capture_snapshot_from_post_order_id' ), 1 );

        // مرحله ۲: مقایسه و ثبت تغییرات بعد از پایان درخواست
        add_action( 'shutdown', array( $this, 'diff_and_log_order_snapshots' ), 999 );

        // ثبت تغییر وضعیت سفارش
        add_action( 'woocommerce_order_status_changed', array( $this, 'log_order_status_change' ), 10, 4 );

        // ثبت استرداد سفارش (کلی یا جزئی)
        add_action( 'woocommerce_order_refunded', array( $this, 'log_order_refund' ), 10, 2 );

        // ثبت یادداشت‌های سفارش (خصوصی/عمومی) - افزودن
        add_action( 'woocommerce_order_note_added', array( $this, 'log_order_note_added' ), 10, 2 );

        // ذخیره snapshot قبل از حذف یادداشت
        add_action( 'wp_ajax_delete_order_note', array( $this, 'capture_note_before_delete' ), 1 );
    }

    /**
     * گرفتن snapshot کامل از وضعیت فعلی سفارش و ذخیره آن در یک transient.
     *
     * @param int $order_id شناسه سفارش
     */
    public function capture_order_snapshot_before( $order_id, $items = null ) {

        $order_id = absint( $order_id );
        if ( ! $order_id ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        try {
            $key      = $this->get_snapshot_transient_key( $order_id );
            $existing = get_transient( $key );

            // جلوگیری از بازنویسی snapshot قبلی در همین درخواست
            if ( false !== $existing ) {
                return;
            }

            $snapshot = $this->build_order_snapshot( $order );
            set_transient( $key, $snapshot, 5 * MINUTE_IN_SECONDS );

        } catch ( \Throwable $e ) {
            if ( function_exists( 'wc_get_logger' ) ) {
                wc_get_logger()->error(
                    'Mahamsoft Order Edit: build_order_snapshot failed - ' . $e->getMessage(),
                    array( 'source' => 'mahamsoft-order-edit-log' )
                );
            }
        }
    }

    /**
     * گرفتن snapshot قبل از پردازش اکشن‌های AJAX که order_id را در $_POST دارند.
     */
    public function capture_snapshot_from_post_order_id() {

        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0; // phpcs:ignore

        if ( $order_id ) {
            $this->capture_order_snapshot_before( $order_id );
        }
    }

    /**
     * گرفتن snapshot قبل از حذف یادداشت سفارش (از AJAX delete_order_note)
     */
    public function capture_note_before_delete() {

        $note_id = isset( $_POST['note_id'] ) ? absint( $_POST['note_id'] ) : 0; // phpcs:ignore
        if ( ! $note_id ) {
            return;
        }

        $note = get_comment( $note_id );
        if ( ! $note ) {
            return;
        }

        $order_id         = (int) $note->comment_post_ID;
        $is_customer_note = get_comment_meta( $note_id, 'is_customer_note', true );

        $data = array(
            'order_id'         => $order_id,
            'content'          => $note->comment_content,
            'is_customer_note' => (bool) $is_customer_note,
        );

        set_transient( 'mahamsoft_oe_deleted_note_' . $note_id, $data, 5 * MINUTE_IN_SECONDS );
    }

    /**
     * ساخت کلید transient برای snapshot یک سفارش خاص
     *
     * @param int $order_id شناسه سفارش
     * @return string
     */
    private function get_snapshot_transient_key( $order_id ) {
        return 'mahamsoft_oe_snapshot_' . absint( $order_id ) . '_' . get_current_user_id();
    }

    /**
     * ساخت یک آرایه ساختاریافته (snapshot) از وضعیت فعلی سفارش.
     *
     * @param WC_Order $order سفارش
     * @return array
     */
    private function build_order_snapshot( WC_Order $order ) {

        $snapshot = array(
            'status'           => $order->get_status(),
            'total'            => (float) $order->get_total(),
            'items'            => array(),
            'coupons'          => array(),
            'shipping'         => array(),
            'fees'             => array(),
            'billing'          => array(),
            'shipping_address' => array(),
            'customer_note'    => $order->get_customer_note(),
        );

        // آیتم‌های محصول (ساده و متغیر)
        foreach ( $order->get_items() as $item_id => $item ) {

            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }

            $product = $item->get_product();

            $snapshot['items'][ $item_id ] = array(
                'name'      => $this->get_linked_product_name( $item ),
                'sku'       => $product ? $product->get_sku() : '',
                'quantity'  => (int) $item->get_quantity(),
                'total'     => (float) $item->get_total(),
                'expiry'    => $item->get_meta( '_expiry_date' ),
                'breakdown' => $item->get_meta( '_mwp_expiry_breakdown' ),
            );
        }

        // کدهای تخفیف
        foreach ( $order->get_items( 'coupon' ) as $item_id => $coupon_item ) {
            /** @var WC_Order_Item_Coupon $coupon_item */
            $snapshot['coupons'][ $item_id ] = array(
                'code'     => $coupon_item->get_code(),
                'discount' => (float) $coupon_item->get_discount(),
            );
        }

        // هزینه‌های حمل‌ونقل
        foreach ( $order->get_items( 'shipping' ) as $item_id => $shipping_item ) {
            /** @var WC_Order_Item_Shipping $shipping_item */
            $snapshot['shipping'][ $item_id ] = array(
                'method_title' => $shipping_item->get_method_title(),
                'total'        => (float) $shipping_item->get_total(),
            );
        }

        // دستمزدها (Fees)
        foreach ( $order->get_items( 'fee' ) as $item_id => $fee_item ) {
            /** @var WC_Order_Item_Fee $fee_item */
            $snapshot['fees'][ $item_id ] = array(
                'name'  => $fee_item->get_name(),
                'total' => (float) $fee_item->get_total(),
            );
        }

        // اطلاعات صورتحساب (Billing)
        $snapshot['billing'] = array(
            'first_name'     => $order->get_billing_first_name(),
            'last_name'      => $order->get_billing_last_name(),
            'phone'          => $order->get_billing_phone(),
            'address'        => $this->format_address_string( $order, 'billing' ),
            'payment_method' => $order->get_payment_method_title(),
            'transaction_id' => $order->get_transaction_id(),
        );

        // اطلاعات تحویل‌گیرنده (Shipping Address)
        $shipping_phone = method_exists( $order, 'get_shipping_phone' ) ? $order->get_shipping_phone() : '';

        $snapshot['shipping_address'] = array(
            'first_name' => $order->get_shipping_first_name(),
            'last_name'  => $order->get_shipping_last_name(),
            'phone'      => $shipping_phone,
            'address'    => $this->format_address_string( $order, 'shipping' ),
            'note'       => $order->get_customer_note(),
        );

        return $snapshot;
    }

    /**
     * تبدیل آدرس سفارش (صورتحساب یا تحویل‌گیرنده) به یک رشته خوانا
     *
     * @param WC_Order $order سفارش
     * @param string   $type  نوع آدرس: 'billing' یا 'shipping'
     * @return string
     */
    private function format_address_string( WC_Order $order, $type ) {

        $getter_state    = "get_{$type}_state";
        $getter_city     = "get_{$type}_city";
        $getter_country  = "get_{$type}_country";
        $getter_address1 = "get_{$type}_address_1";
        $getter_address2 = "get_{$type}_address_2";

        $state    = method_exists( $order, $getter_state ) ? $order->$getter_state() : '';
        $city     = method_exists( $order, $getter_city ) ? $order->$getter_city() : '';
        $country  = method_exists( $order, $getter_country ) ? $order->$getter_country() : '';
        $address1 = method_exists( $order, $getter_address1 ) ? $order->$getter_address1() : '';
        $address2 = method_exists( $order, $getter_address2 ) ? $order->$getter_address2() : '';

        // تبدیل کد استان به نام کامل فارسی
        if ( $state && function_exists( 'WC' ) && WC()->countries ) {
            $states = WC()->countries->get_states( $country ? $country : 'IR' );
            if ( is_array( $states ) && isset( $states[ $state ] ) ) {
                $state = $states[ $state ];
            }
        }

        $parts = array_filter( array( $state, $city, trim( $address1 . ' ' . $address2 ) ) );

        return implode( '، ', $parts );
    }

    /**
     * مقایسه snapshot قبل و بعد سفارش (در پایان درخواست) و ثبت تمامی تفاوت‌ها.
     */
    public function diff_and_log_order_snapshots() {

        $order_id = $this->get_order_id_from_current_request();

        // حالت خاص: حذف یادداشت سفارش
        if ( ! $order_id && isset( $_POST['note_id'] ) ) { // phpcs:ignore
            $note_id = absint( $_POST['note_id'] ); // phpcs:ignore
            $data    = get_transient( 'mahamsoft_oe_deleted_note_' . $note_id );
            if ( is_array( $data ) && ! empty( $data['order_id'] ) ) {
                $this->log_notes_diff( (int) $data['order_id'] );
            }
            return;
        }

        if ( ! $order_id ) {
            return;
        }

        $key             = $this->get_snapshot_transient_key( $order_id );
        $snapshot_before = get_transient( $key );

        if ( false === $snapshot_before ) {
            return;
        }

        // پاک‌سازی transient بعد از استفاده
        delete_transient( $key );

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        try {
            $snapshot_after = $this->build_order_snapshot( $order );

            $this->log_items_diff( $order, $snapshot_before, $snapshot_after );
            $this->log_coupons_diff( $order, $snapshot_before, $snapshot_after );
            $this->log_shipping_diff( $order, $snapshot_before, $snapshot_after );
            $this->log_fees_diff( $order, $snapshot_before, $snapshot_after );
            $this->log_billing_diff( $order, $snapshot_before, $snapshot_after );
            $this->log_shipping_address_diff( $order, $snapshot_before, $snapshot_after );
            $this->log_customer_note_diff( $order, $snapshot_before, $snapshot_after );
            $this->log_notes_diff( $order_id );
        } catch ( \Throwable $e ) {
            if ( function_exists( 'wc_get_logger' ) ) {
                wc_get_logger()->error(
                    'Mahamsoft Order Edit: diff_and_log_order_snapshots failed - ' . $e->getMessage(),
                    array( 'source' => 'mahamsoft-order-edit-log' )
                );
            }
        }
    }

    /**
     * تشخیص شناسه سفارش از درخواست فعلی (برای استفاده در hook شاتدون)
     *
     * @return int شناسه سفارش یا 0 در صورت عدم تشخیص
     */
    private function get_order_id_from_current_request() {

        $candidates = array( 'order_id', 'post_id', 'id' );

        foreach ( $candidates as $key ) {
            if ( isset( $_POST[ $key ] ) ) { // phpcs:ignore
                $val = absint( $_POST[ $key ] ); // phpcs:ignore

                if ( $val <= 0 ) {
                    continue;
                }

                // wc_get_order در هر دو حالت (کلاسیک و HPOS) به درستی کار می‌کند
                if ( wc_get_order( $val ) ) {
                    return $val;
                }

                // پشتیبان: بررسی حالت کلاسیک
                if ( 'shop_order' === get_post_type( $val ) ) {
                    return $val;
                }
            }
        }

        return 0;
    }

    /**
     * ثبت رکورد جدید در جدول گزارش تغییرات سفارش
     *
     * @param int    $order_id       شناسه سفارش
     * @param string $change_summary خلاصه نوع تغییر
     * @param string $change_details جزئیات کامل تغییر (HTML)
     * @param string $log_phase      فاز ثبت لاگ: 'auto' یا 'edit'. پیش‌فرض 'edit'.
     */
    private function insert_log_record( $order_id, $change_summary, $change_details, $log_phase = 'edit' ) {

        global $wpdb;

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $log_phase = in_array( $log_phase, array( 'auto', 'edit' ), true ) ? $log_phase : 'edit';

        // --- فیلتر فاز ثبت لاگ (تنظیمات) ---
        $settings       = $this->get_settings();
        $allowed_phases = (array) $settings['log_phase_filter'];

        if ( ! empty( $allowed_phases ) && ! in_array( $log_phase, $allowed_phases, true ) ) {
            return;
        }

        $table_name = $wpdb->prefix . self::LOG_TABLE;

        $order_type = $this->get_order_creation_type( $order );
        $created_by = $this->get_order_created_by_user_id( $order );
        $editor_id  = get_current_user_id();

        $wpdb->insert(
            $table_name,
            array(
                'order_id'       => absint( $order_id ),
                'order_type'     => $order_type,
                'created_by'     => $created_by,
                'editor_id'      => $editor_id,
                'log_phase'      => $log_phase,
                'change_summary' => $change_summary,
                'change_details' => $change_details,
                'last_status'    => $order->get_status(),
                'created_at'     => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
        );
    }

    /**
     * تشخیص نوع ثبت سفارش: "admin" (پنل مدیریت) یا "online" (آنلاین)
     *
     * @param WC_Order $order سفارش
     * @return string 'admin' یا 'online'
     */
    private function get_order_creation_type( WC_Order $order ) {
        $created_via = $order->get_created_via();
        return ( 'admin' === $created_via ) ? 'admin' : 'online';
    }

    /**
     * دریافت شناسه کاربری "ثبت‌کننده" سفارش
     *
     * @param WC_Order $order سفارش
     * @return int شناسه کاربری
     */
    private function get_order_created_by_user_id( WC_Order $order ) {

        if ( 'admin' === $this->get_order_creation_type( $order ) ) {
            $admin_creator = $order->get_meta( 'mahamsoft_oe_created_by' );
            if ( $admin_creator ) {
                return (int) $admin_creator;
            }
        }

        return (int) $order->get_customer_id();
    }

    /**
     * ساخت رشته "ثبت‌کننده" برای نمایش در جدول گزارش
     *
     * @param WC_Order $order سفارش
     * @return string
     */
    private function get_order_created_by_label( WC_Order $order ) {

        $user_id = $this->get_order_created_by_user_id( $order );
        $user    = $user_id ? get_userdata( $user_id ) : false;

        $display_name = $user ? $user->display_name : esc_html__( 'مهمان', 'mahamsoft-order-edit' );

        if ( 'admin' === $this->get_order_creation_type( $order ) ) {

            $role_label = esc_html__( 'کاربر', 'mahamsoft-order-edit' );

            if ( $user && ! empty( $user->roles ) ) {
                $role_slug  = $user->roles[0];
                $wp_roles   = wp_roles();
                $role_label = isset( $wp_roles->role_names[ $role_slug ] )
                    ? translate_user_role( $wp_roles->role_names[ $role_slug ] )
                    : $role_slug;
            }

            return $role_label . ' | ' . $display_name;
        }

        return esc_html__( 'کاربرسایت', 'mahamsoft-order-edit' ) . ' | ' . $display_name;
    }

    /* ---------------------------------------------------------------------
     * دیف آیتم‌های سفارش (افزایش/کاهش تعداد، افزودن/حذف محصول)
     * ------------------------------------------------------------------- */

    /**
     * مقایسه آیتم‌های سفارش قبل و بعد و ثبت تغییرات تعداد
     */
    private function log_items_diff( WC_Order $order, $before, $after ) {

        $items_before = isset( $before['items'] ) ? $before['items'] : array();
        $items_after  = isset( $after['items'] ) ? $after['items'] : array();

        foreach ( $items_after as $item_id => $after_item ) {

            if ( ! isset( $items_before[ $item_id ] ) ) {
                $this->log_item_added( $order, $after_item );
                continue;
            }

            $before_item = $items_before[ $item_id ];

            if ( (int) $before_item['quantity'] === (int) $after_item['quantity'] ) {
                continue;
            }

            $old_qty = (int) $before_item['quantity'];
            $new_qty = (int) $after_item['quantity'];

            $is_increase = $new_qty > $old_qty;
            $diff_qty    = abs( $new_qty - $old_qty );

            $summary = $is_increase
                ? esc_html__( 'افزایش تعداد سفارش', 'mahamsoft-order-edit' )
                : esc_html__( 'کاهش تعداد سفارش', 'mahamsoft-order-edit' );

            $stock_action_label = $is_increase
                ? esc_html__( 'کالاهای کسر شده از انبار داروپردازان', 'mahamsoft-order-edit' )
                : esc_html__( 'کالاهای اضافه شده به انبار داروپردازان', 'mahamsoft-order-edit' );

            $details  = '<strong>' . $stock_action_label . ':</strong><br>';
            $details .= sprintf(
                /* translators: 1: نام محصول 2: کد محصول (SKU) 3: تعداد 4: تاریخ انقضا 5: قیمت کل */
                esc_html__( 'نام: %1$s | کد: %2$s | تعداد: %3$s | تاریخ انقضاء: %4$s به مبلغ: %5$s', 'mahamsoft-order-edit' ),
                $after_item['name'],
                $after_item['sku'] ? $after_item['sku'] : '-',
                number_format( $diff_qty ),
                $after_item['expiry'] ? $after_item['expiry'] : '-',
                $this->format_price( $order, $after_item['total'] )
            );

            $details .= '<br>';
            $details .= sprintf(
                /* translators: 1: تعداد قبل 2: تعداد جدید */
                esc_html__( 'تعداد قبل: %1$s | تعداد جدید: %2$s', 'mahamsoft-order-edit' ),
                number_format( $old_qty ),
                number_format( $new_qty )
            );

            // مورد ۶: نمایش توزیع تاریخ‌ها با رنگ قرمز (فقط هنگام افزایش)
            if ( $is_increase && ! empty( $after_item['breakdown'] ) ) {
                $details .= '<br><span style="color:#c00;">' . esc_html( $after_item['breakdown'] ) . '</span>';
            }

            $this->insert_log_record( $order->get_id(), $summary, $details );
        }

        // آیتم‌هایی که حذف شده‌اند
        foreach ( $items_before as $item_id => $before_item ) {
            if ( ! isset( $items_after[ $item_id ] ) ) {
                $this->log_item_removed( $order, $before_item );
            }
        }
    }

    /**
     * ثبت لاگ افزودن یک محصول جدید به سفارش
     */
    private function log_item_added( WC_Order $order, $item ) {

        $summary = esc_html__( 'افزودن محصول به سفارش', 'mahamsoft-order-edit' );

        $details  = '<strong>' . esc_html__( 'کالاهای کسر شده از انبار داروپردازان', 'mahamsoft-order-edit' ) . ':</strong><br>';
        $details .= sprintf(
            esc_html__( 'نام: %1$s | کد: %2$s | تعداد: %3$s | تاریخ انقضاء: %4$s به مبلغ: %5$s', 'mahamsoft-order-edit' ),
            $item['name'],
            $item['sku'] ? $item['sku'] : '-',
            number_format( (int) $item['quantity'] ),
            $item['expiry'] ? $item['expiry'] : '-',
            $this->format_price( $order, $item['total'] )
        );
        $details .= '<br>';
        $details .= sprintf(
            esc_html__( 'تعداد قبل: %1$s | تعداد جدید: %2$s', 'mahamsoft-order-edit' ),
            '0',
            number_format( (int) $item['quantity'] )
        );

        // مورد ۶: نمایش توزیع تاریخ‌ها با رنگ قرمز
        if ( ! empty( $item['breakdown'] ) ) {
            $details .= '<br><span style="color:#c00;">' . esc_html( $item['breakdown'] ) . '</span>';
        }

        $this->insert_log_record( $order->get_id(), $summary, $details );
    }

    private function log_item_removed( WC_Order $order, $item ) {

        $summary = esc_html__( 'حذف محصول از سفارش', 'mahamsoft-order-edit' );

        $details  = '<strong>' . esc_html__( 'کالاهای اضافه شده به انبار داروپردازان', 'mahamsoft-order-edit' ) . ':</strong><br>';
        $details .= sprintf(
            esc_html__( 'نام: %1$s | کد: %2$s | تعداد: %3$s | تاریخ انقضاء: %4$s به مبلغ: %5$s', 'mahamsoft-order-edit' ),
            $item['name'],
            $item['sku'] ? $item['sku'] : '-',
            number_format( (int) $item['quantity'] ),
            $item['expiry'] ? $item['expiry'] : '-',
            $this->format_price( $order, $item['total'] )
        );
        $details .= '<br>';
        $details .= sprintf(
            esc_html__( 'تعداد قبل: %1$s | تعداد جدید: %2$s', 'mahamsoft-order-edit' ),
            number_format( (int) $item['quantity'] ),
            '0'
        );

        $this->insert_log_record( $order->get_id(), $summary, $details );
    }

    /* ---------------------------------------------------------------------
     * دیف کدهای تخفیف (افزودن / ویرایش / حذف)
     * ------------------------------------------------------------------- */

    private function log_coupons_diff( WC_Order $order, $before, $after ) {

        $coupons_before = isset( $before['coupons'] ) ? $before['coupons'] : array();
        $coupons_after  = isset( $after['coupons'] ) ? $after['coupons'] : array();

        $codes_before = wp_list_pluck( $coupons_before, 'code' );
        $codes_after  = wp_list_pluck( $coupons_after, 'code' );

        $total_before = isset( $before['total'] ) ? (float) $before['total'] : 0;
        $total_after  = isset( $after['total'] ) ? (float) $after['total'] : 0;

        $added_codes   = array_diff( $codes_after, $codes_before );
        $removed_codes = array_diff( $codes_before, $codes_after );

        // حالت "افزودن کد تخفیف"
        if ( ! empty( $added_codes ) && empty( $codes_before ) ) {

            foreach ( $coupons_after as $coupon ) {
                if ( in_array( $coupon['code'], $added_codes, true ) ) {

                    $summary  = esc_html__( 'افزودن کد تخفیف', 'mahamsoft-order-edit' );
                    $details  = sprintf( esc_html__( 'کد تخفیف اضافه شده: %s', 'mahamsoft-order-edit' ), $coupon['code'] ) . '<br>';
                    $details .= sprintf( esc_html__( 'ارزش کد تخفیف: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $coupon['discount'] ) ) . '<br>';
                    $details .= sprintf( esc_html__( 'مبلغ کل سفارش قبل بدون احتساب کد تخفیف: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $total_before ) ) . '<br>';
                    $details .= sprintf( esc_html__( 'مبلغ کل سفارش با احتساب کد تخفیف: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $total_after ) );

                    $this->insert_log_record( $order->get_id(), $summary, $details );
                }
            }
        } elseif ( ! empty( $added_codes ) && ! empty( $removed_codes ) ) {

            // حالت "ویرایش کد تخفیف"
            $old_coupon = reset( $coupons_before );
            $new_coupon = null;
            foreach ( $coupons_after as $coupon ) {
                if ( in_array( $coupon['code'], $added_codes, true ) ) {
                    $new_coupon = $coupon;
                    break;
                }
            }

            if ( $new_coupon ) {
                $summary  = esc_html__( 'ویرایش کد تخفیف', 'mahamsoft-order-edit' );
                $details  = sprintf( esc_html__( 'کد تخفیف قبل: %s', 'mahamsoft-order-edit' ), $old_coupon['code'] ) . '<br>';
                $details .= sprintf( esc_html__( 'ارزش کد تخفیف قبل: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $old_coupon['discount'] ) ) . '<br>';
                $details .= sprintf( esc_html__( 'کد تخفیف جدید: %s', 'mahamsoft-order-edit' ), $new_coupon['code'] ) . '<br>';
                $details .= sprintf( esc_html__( 'ارزش کد تخفیف جدید: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $new_coupon['discount'] ) ) . '<br>';
                $details .= sprintf( esc_html__( 'مبلغ کل سفارش بدون احتساب کد تخفیف: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $total_before ) ) . '<br>';
                $details .= sprintf( esc_html__( 'مبلغ کل سفارش با احتساب کد تخفیف جدید: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $total_after ) );

                $this->insert_log_record( $order->get_id(), $summary, $details );
            }
        } elseif ( ! empty( $removed_codes ) && empty( $codes_after ) ) {

            // حالت "حذف کد تخفیف"
            foreach ( $coupons_before as $coupon ) {
                if ( in_array( $coupon['code'], $removed_codes, true ) ) {

                    $summary  = esc_html__( 'حذف کد تخفیف', 'mahamsoft-order-edit' );
                    $details  = sprintf( esc_html__( 'کد تخفیف حذف شده: %s', 'mahamsoft-order-edit' ), $coupon['code'] ) . '<br>';
                    $details .= sprintf( esc_html__( 'ارزش کد تخفیف حذف شده: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $coupon['discount'] ) ) . '<br>';
                    $details .= sprintf( esc_html__( 'مبلغ کل سفارش با احتساب کد تخفیف حذف شده: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $total_before ) ) . '<br>';
                    $details .= sprintf( esc_html__( 'مبلغ کل سفارش جدید: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $total_after ) );

                    $this->insert_log_record( $order->get_id(), $summary, $details );
                }
            }
        }
    }

    /* ---------------------------------------------------------------------
     * دیف حمل‌ونقل (افزودن / ویرایش / حذف)
     * ------------------------------------------------------------------- */

    private function log_shipping_diff( WC_Order $order, $before, $after ) {

        $shipping_before = isset( $before['shipping'] ) ? $before['shipping'] : array();
        $shipping_after  = isset( $after['shipping'] ) ? $after['shipping'] : array();

        $total_before = isset( $before['total'] ) ? (float) $before['total'] : 0;
        $total_after  = isset( $after['total'] ) ? (float) $after['total'] : 0;

        foreach ( $shipping_after as $item_id => $after_shipping ) {

            if ( ! isset( $shipping_before[ $item_id ] ) ) {

                $summary  = esc_html__( 'افزودن حمل و نقل', 'mahamsoft-order-edit' );
                $details  = sprintf( esc_html__( 'مبلغ حمل و نقل: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $after_shipping['total'] ) ) . '<br>';
                $details .= sprintf( esc_html__( 'توضیحات: %s', 'mahamsoft-order-edit' ), $after_shipping['method_title'] ) . '<br>';
                $details .= sprintf( esc_html__( 'مبلغ کل سفارش قبل: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $total_before ) ) . '<br>';
                $details .= sprintf( esc_html__( 'مبلغ کل سفارش جدید: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $total_after ) );

                $this->insert_log_record( $order->get_id(), $summary, $details );
                continue;
            }

            $before_shipping = $shipping_before[ $item_id ];

            $amount_changed = ( (float) $before_shipping['total'] !== (float) $after_shipping['total'] );
            $title_changed  = ( $before_shipping['method_title'] !== $after_shipping['method_title'] );

            if ( $amount_changed || $title_changed ) {

                $summary  = esc_html__( 'ویرایش حمل و نقل', 'mahamsoft-order-edit' );
                $details  = sprintf( esc_html__( 'مبلغ حمل و نقل قبل: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $before_shipping['total'] ) ) . '<br>';
                $details .= sprintf( esc_html__( 'توضیحات قبل: %s', 'mahamsoft-order-edit' ), $before_shipping['method_title'] ) . '<br>';
                $details .= sprintf( esc_html__( 'مبلغ کل سفارش قبل: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $total_before ) ) . '<br>';
                $details .= sprintf( esc_html__( 'مبلغ حمل و نقل جدید: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $after_shipping['total'] ) ) . '<br>';
                $details .= sprintf( esc_html__( 'توضیحات جدید: %s', 'mahamsoft-order-edit' ), $after_shipping['method_title'] ) . '<br>';
                $details .= sprintf( esc_html__( 'مبلغ کل سفارش جدید: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $total_after ) );

                $this->insert_log_record( $order->get_id(), $summary, $details );
            }
        }

        foreach ( $shipping_before as $item_id => $before_shipping ) {

            if ( isset( $shipping_after[ $item_id ] ) ) {
                continue;
            }

            $summary  = esc_html__( 'حذف حمل و نقل', 'mahamsoft-order-edit' );
            $details  = sprintf( esc_html__( 'مبلغ حمل و نقل حذف شده: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $before_shipping['total'] ) ) . '<br>';
            $details .= sprintf( esc_html__( 'توضیحات حذف شده: %s', 'mahamsoft-order-edit' ), $before_shipping['method_title'] ) . '<br>';
            $details .= sprintf( esc_html__( 'مبلغ کل سفارش قبل از حذف هزینه حمل و نقل: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $total_before ) ) . '<br>';
            $details .= sprintf( esc_html__( 'مبلغ کل سفارش جدید: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $total_after ) );

            $this->insert_log_record( $order->get_id(), $summary, $details );
        }
    }

    /* ---------------------------------------------------------------------
     * دیف دستمزدها (Fees) - افزودن / ویرایش / حذف
     * ------------------------------------------------------------------- */

    private function log_fees_diff( WC_Order $order, $before, $after ) {

        $fees_before = isset( $before['fees'] ) ? $before['fees'] : array();
        $fees_after  = isset( $after['fees'] ) ? $after['fees'] : array();

        $total_before = isset( $before['total'] ) ? (float) $before['total'] : 0;
        $total_after  = isset( $after['total'] ) ? (float) $after['total'] : 0;

        foreach ( $fees_after as $item_id => $after_fee ) {

            if ( ! isset( $fees_before[ $item_id ] ) ) {

                $summary  = esc_html__( 'افزودن دستمزد به سفارش', 'mahamsoft-order-edit' );
                $details  = sprintf( esc_html__( 'مبلغ دستمزد: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $after_fee['total'] ) ) . '<br>';
                $details .= sprintf( esc_html__( 'عنوان دستمزد: %s', 'mahamsoft-order-edit' ), $after_fee['name'] ) . '<br>';
                $details .= sprintf( esc_html__( 'مبلغ کل سفارش قبل: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $total_before ) ) . '<br>';
                $details .= sprintf( esc_html__( 'مبلغ کل سفارش جدید: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $total_after ) );

                $this->insert_log_record( $order->get_id(), $summary, $details );
                continue;
            }

            $before_fee = $fees_before[ $item_id ];

            $amount_changed = ( (float) $before_fee['total'] !== (float) $after_fee['total'] );
            $name_changed   = ( $before_fee['name'] !== $after_fee['name'] );

            if ( $amount_changed || $name_changed ) {

                $summary  = esc_html__( 'ویرایش دستمزد سفارش', 'mahamsoft-order-edit' );
                $details  = sprintf( esc_html__( 'مبلغ دستمزد قبل: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $before_fee['total'] ) ) . '<br>';
                $details .= sprintf( esc_html__( 'عنوان دستمزد قبل: %s', 'mahamsoft-order-edit' ), $before_fee['name'] ) . '<br>';
                $details .= sprintf( esc_html__( 'مبلغ کل سفارش قبل: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $total_before ) ) . '<br>';
                $details .= sprintf( esc_html__( 'مبلغ دستمزد جدید: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $after_fee['total'] ) ) . '<br>';
                $details .= sprintf( esc_html__( 'عنوان دستمزد جدید: %s', 'mahamsoft-order-edit' ), $after_fee['name'] ) . '<br>';
                $details .= sprintf( esc_html__( 'مبلغ کل سفارش جدید: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $total_after ) );

                $this->insert_log_record( $order->get_id(), $summary, $details );
            }
        }

        foreach ( $fees_before as $item_id => $before_fee ) {

            if ( isset( $fees_after[ $item_id ] ) ) {
                continue;
            }

            $summary  = esc_html__( 'حذف دستمزد سفارش', 'mahamsoft-order-edit' );
            $details  = sprintf( esc_html__( 'مبلغ دستمزد حذف شده: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $before_fee['total'] ) ) . '<br>';
            $details .= sprintf( esc_html__( 'عنوان دستمزد حذف شده: %s', 'mahamsoft-order-edit' ), $before_fee['name'] ) . '<br>';
            $details .= sprintf( esc_html__( 'مبلغ کل سفارش قبل از حذف دستمزد حذف شده: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $total_before ) ) . '<br>';
            $details .= sprintf( esc_html__( 'مبلغ کل سفارش جدید: %s', 'mahamsoft-order-edit' ), $this->format_price( $order, $total_after ) );

            $this->insert_log_record( $order->get_id(), $summary, $details );
        }
    }

    /* ---------------------------------------------------------------------
     * دیف اطلاعات صورتحساب (Billing)
     * ------------------------------------------------------------------- */

    private function log_billing_diff( WC_Order $order, $before, $after ) {

        $b_before = isset( $before['billing'] ) ? $before['billing'] : array();
        $b_after  = isset( $after['billing'] ) ? $after['billing'] : array();

        if ( empty( $b_before ) || empty( $b_after ) ) {
            return;
        }

        $summary = esc_html__( 'تغییر اطلاعات صورتحساب', 'mahamsoft-order-edit' );

        if ( $b_before['address'] !== $b_after['address'] ) {
            $details = sprintf(
                esc_html__( 'آدرس تحویل‌گیرنده صورتحساب از %1$s به %2$s تغییر یافت.', 'mahamsoft-order-edit' ),
                $b_before['address'] ? $b_before['address'] : '-',
                $b_after['address'] ? $b_after['address'] : '-'
            );
            $this->insert_log_record( $order->get_id(), $summary, $details );
        }

        if ( $b_before['first_name'] !== $b_after['first_name'] ) {
            $details = sprintf(
                esc_html__( 'نام تحویل‌گیرنده صورتحساب از %1$s به %2$s تغییر یافت.', 'mahamsoft-order-edit' ),
                $b_before['first_name'] ? $b_before['first_name'] : '-',
                $b_after['first_name'] ? $b_after['first_name'] : '-'
            );
            $this->insert_log_record( $order->get_id(), $summary, $details );
        }

        if ( $b_before['last_name'] !== $b_after['last_name'] ) {
            $details = sprintf(
                esc_html__( 'نام خانوادگی تحویل‌گیرنده صورتحساب از %1$s به %2$s تغییر یافت.', 'mahamsoft-order-edit' ),
                $b_before['last_name'] ? $b_before['last_name'] : '-',
                $b_after['last_name'] ? $b_after['last_name'] : '-'
            );
            $this->insert_log_record( $order->get_id(), $summary, $details );
        }

        if ( $b_before['phone'] !== $b_after['phone'] ) {
            $details = sprintf(
                esc_html__( 'شماره تماس تحویل‌گیرنده صورتحساب از %1$s به %2$s تغییر یافت.', 'mahamsoft-order-edit' ),
                $b_before['phone'] ? $b_before['phone'] : '-',
                $b_after['phone'] ? $b_after['phone'] : '-'
            );
            $this->insert_log_record( $order->get_id(), $summary, $details );
        }

        if ( $b_before['payment_method'] !== $b_after['payment_method'] || $b_before['transaction_id'] !== $b_after['transaction_id'] ) {
            $details = sprintf(
                esc_html__( 'روش پرداخت از %1$s به %2$s و شماره تراکنش از %3$s به %4$s تغییر یافت.', 'mahamsoft-order-edit' ),
                $b_before['payment_method'] ? $b_before['payment_method'] : '-',
                $b_after['payment_method'] ? $b_after['payment_method'] : '-',
                $b_before['transaction_id'] ? $b_before['transaction_id'] : '-',
                $b_after['transaction_id'] ? $b_after['transaction_id'] : '-'
            );
            $this->insert_log_record( $order->get_id(), $summary, $details );
        }
    }

    /* ---------------------------------------------------------------------
     * دیف اطلاعات تحویل‌گیرنده (Shipping Address)
     * ------------------------------------------------------------------- */

    private function log_shipping_address_diff( WC_Order $order, $before, $after ) {

        $s_before = isset( $before['shipping_address'] ) ? $before['shipping_address'] : array();
        $s_after  = isset( $after['shipping_address'] ) ? $after['shipping_address'] : array();

        if ( empty( $s_before ) || empty( $s_after ) ) {
            return;
        }

        $summary = esc_html__( 'تغییر اطلاعات تحویل‌گیرنده ( حمل و نقل )', 'mahamsoft-order-edit' );

        if ( $s_before['address'] !== $s_after['address'] ) {
            $details = sprintf(
                esc_html__( 'آدرس تحویل‌گیرنده از %1$s به %2$s تغییر یافت.', 'mahamsoft-order-edit' ),
                $s_before['address'] ? $s_before['address'] : '-',
                $s_after['address'] ? $s_after['address'] : '-'
            );
            $this->insert_log_record( $order->get_id(), $summary, $details );
        }

        if ( $s_before['first_name'] !== $s_after['first_name'] ) {
            $details = sprintf(
                esc_html__( 'نام تحویل‌گیرنده حمل و نقل از %1$s به %2$s تغییر یافت.', 'mahamsoft-order-edit' ),
                $s_before['first_name'] ? $s_before['first_name'] : '-',
                $s_after['first_name'] ? $s_after['first_name'] : '-'
            );
            $this->insert_log_record( $order->get_id(), $summary, $details );
        }

        if ( $s_before['last_name'] !== $s_after['last_name'] ) {
            $details = sprintf(
                esc_html__( 'نام خانوادگی تحویل‌گیرنده حمل و نقل از %1$s به %2$s تغییر یافت.', 'mahamsoft-order-edit' ),
                $s_before['last_name'] ? $s_before['last_name'] : '-',
                $s_after['last_name'] ? $s_after['last_name'] : '-'
            );
            $this->insert_log_record( $order->get_id(), $summary, $details );
        }

        if ( $s_before['phone'] !== $s_after['phone'] ) {
            $details = sprintf(
                esc_html__( 'شماره تماس حمل و نقل از %1$s به %2$s تغییر یافت.', 'mahamsoft-order-edit' ),
                $s_before['phone'] ? $s_before['phone'] : '-',
                $s_after['phone'] ? $s_after['phone'] : '-'
            );
            $this->insert_log_record( $order->get_id(), $summary, $details );
        }
    }

    /**
     * مقایسه یادداشت مشتری قبل و بعد و ثبت لاگ افزودن/حذف/تغییر آن
     */
    private function log_customer_note_diff( WC_Order $order, $before, $after ) {

        $note_before = isset( $before['customer_note'] ) ? $before['customer_note'] : '';
        $note_after  = isset( $after['customer_note'] ) ? $after['customer_note'] : '';

        if ( $note_before === $note_after ) {
            return;
        }

        $summary = esc_html__( 'تغییر یادداشت مشتری', 'mahamsoft-order-edit' );

        if ( '' === $note_before && '' !== $note_after ) {
            $details = sprintf(
                esc_html__( 'یادداشت ارائه‌شده مشتری از %1$s به %2$s تغییر یافت.', 'mahamsoft-order-edit' ),
                '-',
                $note_after
            );
            $this->insert_log_record( $order->get_id(), $summary, $details );
        } elseif ( '' !== $note_before && '' === $note_after ) {
            $details = esc_html__( 'یادداشت ارائه‌شده مشتری حذف گردید.', 'mahamsoft-order-edit' );
            $this->insert_log_record( $order->get_id(), $summary, $details );
        } else {
            $details = sprintf(
                esc_html__( 'یادداشت ارائه‌شده مشتری از %1$s به %2$s تغییر یافت.', 'mahamsoft-order-edit' ),
                $note_before,
                $note_after
            );
            $this->insert_log_record( $order->get_id(), $summary, $details );
        }
    }

    /* ---------------------------------------------------------------------
     * یادداشت‌های سفارش (Order Notes) - خصوصی و عمومی
     * ------------------------------------------------------------------- */

    /**
     * ثبت لاگ افزودن یادداشت خصوصی/عمومی سفارش
     *
     * @param int      $note_id شناسه یادداشت (comment ID)
     * @param WC_Order $order   سفارش
     */
    public function log_order_note_added( $note_id, $order ) {

        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $note = get_comment( $note_id );
        if ( ! $note ) {
            return;
        }

        $is_customer_note = get_comment_meta( $note_id, 'is_customer_note', true );

        $summary = $is_customer_note
            ? esc_html__( 'افزودن یادداشت عمومی سفارش', 'mahamsoft-order-edit' )
            : esc_html__( 'افزودن یادداشت خصوصی سفارش', 'mahamsoft-order-edit' );

        $details = sprintf(
            esc_html__( 'یادداشت %s اضافه گردید.', 'mahamsoft-order-edit' ),
            $note->comment_content
        );

        $this->insert_log_record( $order->get_id(), $summary, $details );
    }

    /**
     * بررسی و ثبت لاگ یادداشت‌های حذف‌شده در همین درخواست.
     *
     * @param int $order_id شناسه سفارش جاری
     */
    private function log_notes_diff( $order_id ) {

        $note_id = isset( $_POST['note_id'] ) ? absint( $_POST['note_id'] ) : 0; // phpcs:ignore

        if ( ! $note_id ) {
            return;
        }

        $transient_key = 'mahamsoft_oe_deleted_note_' . $note_id;
        $data          = get_transient( $transient_key );

        if ( false === $data || ! is_array( $data ) ) {
            return;
        }

        if ( (int) $data['order_id'] !== (int) $order_id ) {
            return;
        }

        delete_transient( $transient_key );

        $is_customer_note = ! empty( $data['is_customer_note'] );

        $summary = $is_customer_note
            ? esc_html__( 'حذف یادداشت عمومی سفارش', 'mahamsoft-order-edit' )
            : esc_html__( 'حذف یادداشت خصوصی سفارش', 'mahamsoft-order-edit' );

        $details = sprintf(
            esc_html__( 'یادداشت %s حذف گردید.', 'mahamsoft-order-edit' ),
            $data['content']
        );

        $this->insert_log_record( $order_id, $summary, $details );
    }

    /* =====================================================================
     * تغییر وضعیت سفارش و استرداد
     * ===================================================================== */

    /**
     * ثبت لاگ تغییر وضعیت سفارش
     *
     * @param int      $order_id   شناسه سفارش
     * @param string   $old_status وضعیت قبلی
     * @param string   $new_status وضعیت جدید
     * @param WC_Order $order      سفارش
     */
    public function log_order_status_change( $order_id, $old_status, $new_status, $order ) {

        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                return;
            }
        }

        $statuses  = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
        $old_label = isset( $statuses[ 'wc-' . $old_status ] ) ? $statuses[ 'wc-' . $old_status ] : $old_status;
        $new_label = isset( $statuses[ 'wc-' . $new_status ] ) ? $statuses[ 'wc-' . $new_status ] : $new_status;

        $is_new_order_flow = isset( $this->orders_created_this_request[ (int) $order_id ] );

        if ( $is_new_order_flow ) {

            $settings            = $this->get_settings();
            $new_order_log_scope = (array) $settings['new_order_log_scope'];
            $created_via         = $order->get_created_via();

            if ( 'admin' !== $created_via && ! in_array( 'customer', $new_order_log_scope, true ) ) {
                return;
            }

            $summary   = esc_html__( 'تغییر وضعیت سفارش | جدید', 'mahamsoft-order-edit' );
            $log_phase = 'auto';
        } else {
            $summary   = esc_html__( 'تغییر وضعیت سفارش | ویرایش شده', 'mahamsoft-order-edit' );
            $log_phase = 'edit';
        }

        $details = sprintf(
            esc_html__( 'وضعیت سفارش از %1$s به %2$s تغییر یافت.', 'mahamsoft-order-edit' ),
            $old_label,
            $new_label
        );

        $this->insert_log_record( $order_id, $summary, $details, $log_phase );
    }

    /**
     * ثبت لاگ استرداد سفارش (کلی یا جزئی)
     *
     * @param int $order_id  شناسه سفارش اصلی
     * @param int $refund_id شناسه رکورد استرداد
     */
    public function log_order_refund( $order_id, $refund_id ) {

        $order  = wc_get_order( $order_id );
        $refund = wc_get_order( $refund_id );

        if ( ! $order || ! $refund ) {
            return;
        }

        $summary = esc_html__( 'استرداد سفارش', 'mahamsoft-order-edit' );

        $reason = $refund->get_reason();

        $details = sprintf( esc_html__( 'علت: %s', 'mahamsoft-order-edit' ), $reason ? $reason : '-' ) . '<br>';

        // اقلام برگشت‌داده‌شده به انبار - گروه‌بندی شده
        $grouped = array();

        foreach ( $refund->get_items() as $refund_item ) {
            /** @var WC_Order_Item_Product $refund_item */

            $qty = abs( (int) $refund_item->get_quantity() );
            if ( $qty <= 0 ) {
                continue;
            }

            $product = $refund_item->get_product();
            $name    = $this->get_linked_product_name( $refund_item );
            $sku     = $product ? $product->get_sku() : '';
            $expiry  = $refund_item->get_meta( '_expiry_date' );

            $group_key = $name . '|' . $sku . '|' . $expiry;

            if ( isset( $grouped[ $group_key ] ) ) {
                $grouped[ $group_key ]['qty'] += $qty;
            } else {
                $grouped[ $group_key ] = array(
                    'name'   => $name,
                    'sku'    => $sku,
                    'qty'    => $qty,
                    'expiry' => $expiry,
                );
            }
        }

        if ( ! empty( $grouped ) ) {
            $details .= '<strong>' . esc_html__( 'کالاهای برگشت داده شده به انبار داروپردازان', 'mahamsoft-order-edit' ) . ':</strong><br>';

            foreach ( $grouped as $item ) {
                $details .= sprintf(
                    esc_html__( 'نام: %1$s | کد: %2$s | تعداد: %3$s | تاریخ انقضاء: %4$s', 'mahamsoft-order-edit' ),
                    $item['name'],
                    $item['sku'] ? $item['sku'] : '-',
                    number_format( $item['qty'] ),
                    $item['expiry'] ? $item['expiry'] : '-'
                ) . '<br>';
            }
        }

        $refund_total            = abs( (float) $refund->get_total() );
        $refund_shipping_total   = abs( (float) $refund->get_shipping_total() );
        $refund_without_shipping = $refund_total - $refund_shipping_total;

        $details .= sprintf(
            esc_html__( 'مبلغ استرداد با احتساب حمل و نقل: %1$s یا مبلغ استرداد بدون احتساب حمل و نقل: %2$s', 'mahamsoft-order-edit' ),
            $this->format_price( $order, $refund_total ),
            $this->format_price( $order, $refund_without_shipping )
        );

        $this->insert_log_record( $order_id, $summary, $details );
    }

    /* =====================================================================
     * ثبت سفارش‌های دستی از طریق پیشخوان
     * ===================================================================== */

    /**
     * علامت‌گذاری شناسه ادمین فعلی در زمان نمایش صفحه "افزودن سفارش جدید".
     * (سازگار با کلاسیک و HPOS)
     */
    public function maybe_flag_manual_order_creation() {

        global $pagenow;

        $is_new_classic = ( 'post-new.php' === $pagenow )
            && isset( $_GET['post_type'] ) && 'shop_order' === $_GET['post_type']; // phpcs:ignore

        $is_new_hpos = ( 'admin.php' === $pagenow )
            && isset( $_GET['page'], $_GET['action'] ) // phpcs:ignore
            && 'wc-orders' === $_GET['page'] // phpcs:ignore
            && 'new' === $_GET['action']; // phpcs:ignore

        if ( $is_new_classic || $is_new_hpos ) {
            set_transient( 'mahamsoft_oe_manual_order_creator_' . get_current_user_id(), get_current_user_id(), 10 * MINUTE_IN_SECONDS );
        }
    }

    /**
     * ثبت متای "mahamsoft_oe_created_by" و رکورد گزارش برای سفارش جدید.
     *
     * @param int      $order_id شناسه سفارش
     * @param WC_Order $order    سفارش
     */
    public function log_new_order_creation( $order_id, $order ) {

        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $this->orders_created_this_request[ (int) $order_id ] = true;

        $settings            = $this->get_settings();
        $new_order_log_scope = (array) $settings['new_order_log_scope'];

        $created_via = $order->get_created_via();

        $should_log = ( 'admin' === $created_via )
            ? in_array( 'admin', $new_order_log_scope, true )
            : in_array( 'customer', $new_order_log_scope, true );

        if ( 'admin' === $created_via ) {

            $creator_id = get_current_user_id();
            if ( $creator_id ) {
                $order->update_meta_data( 'mahamsoft_oe_created_by', $creator_id );
                $order->save();
            }

            if ( $should_log ) {
                $summary = esc_html__( 'ثبت سفارش جدید از پنل مدیریت', 'mahamsoft-order-edit' );
                $details = esc_html__( 'سفارش به صورت دستی از طریق پیشخوان مدیریت ایجاد شد.', 'mahamsoft-order-edit' );

                $this->insert_log_record( $order_id, $summary, $details, 'auto' );
            }
        } else {

            if ( $should_log ) {
                $summary = esc_html__( 'ثبت سفارش جدید آنلاین', 'mahamsoft-order-edit' );
                $details = esc_html__( 'سفارش به‌صورت آنلاین توسط مشتری ثبت شد.', 'mahamsoft-order-edit' );

                $this->insert_log_record( $order_id, $summary, $details, 'auto' );
            }
        }

        if ( $should_log ) {
            $this->log_coupons_applied_at_creation( $order );
        }
    }

    /**
     * ثبت یک رکورد "اعمال کد تخفیف" برای هر کد تخفیف در لحظه ایجاد سفارش.
     *
     * @param WC_Order $order سفارش تازه‌ساخته‌شده
     */
    private function log_coupons_applied_at_creation( WC_Order $order ) {

        foreach ( $order->get_items( 'coupon' ) as $coupon_item ) {
            /** @var WC_Order_Item_Coupon $coupon_item */

            $code     = $coupon_item->get_code();
            $discount = (float) $coupon_item->get_discount();

            $summary = esc_html__( 'اعمال کد تخفیف', 'mahamsoft-order-edit' );

            $coupon     = new WC_Coupon( $code );
            $is_percent = $coupon && $coupon->get_discount_type() && false !== strpos( $coupon->get_discount_type(), 'percent' );

            if ( $is_percent ) {
                $percent_amount = (float) $coupon->get_amount();
                $details        = sprintf(
                    /* translators: 1: کد تخفیف 2: درصد تخفیف 3: معادل ریالی تخفیف */
                    esc_html__( 'اعمال کد تخفیف %1$s به ارزش %2$s درصد یا %3$s', 'mahamsoft-order-edit' ),
                    $code,
                    rtrim( rtrim( number_format( $percent_amount, 2 ), '0' ), '.' ),
                    $this->format_price( $order, $discount )
                );
            } else {
                $details = sprintf(
                    /* translators: 1: کد تخفیف 2: ارزش تخفیف */
                    esc_html__( 'اعمال کد تخفیف %1$s به ارزش %2$s', 'mahamsoft-order-edit' ),
                    $code,
                    $this->format_price( $order, $discount )
                );
            }

            $this->insert_log_record( $order->get_id(), $summary, $details, 'auto' );
        }
    }

    /* =====================================================================
     * فرمت قیمت (سازگار با واحد پول سفارش)
     * ===================================================================== */

    /**
     * فرمت یک عدد به صورت قیمت با واحد پول سفارش
     *
     * @param WC_Order $order  سفارش
     * @param float    $amount مقدار عددی
     * @return string قیمت فرمت‌شده (متن خام)
     */
    private function format_price( WC_Order $order, $amount ) {
        $formatted = wc_price( $amount, array( 'currency' => $order->get_currency() ) );
        return wp_strip_all_tags( $formatted );
    }

    /* =====================================================================
     * تبدیل تاریخ میلادی به شمسی (جلالی) و فرمت ساعت
     * ===================================================================== */

    /**
     * تبدیل یک timestamp میلادی به رشته تاریخ شمسی با فرمت "Y/m/d"
     *
     * @param int $timestamp زمان یونیکس
     * @return string تاریخ شمسی به فرمت Y/m/d
     */
    private function gregorian_to_jalali_string( $timestamp ) {

        $gy = (int) date( 'Y', $timestamp );
        $gm = (int) date( 'n', $timestamp );
        $gd = (int) date( 'j', $timestamp );

        list( $jy, $jm, $jd ) = $this->gregorian_to_jalali( $gy, $gm, $gd );

        return sprintf( '%04d/%02d/%02d', $jy, $jm, $jd );
    }

    /**
     * فرمت ساعت به صورت 24 ساعته با ثانیه: H:i:s
     *
     * @param int $timestamp زمان یونیکس
     * @return string
     */
    private function format_time_24h( $timestamp ) {
        return date( 'H:i:s', $timestamp );
    }

    /**
     * الگوریتم تبدیل تاریخ میلادی (گرگوری) به جلالی (شمسی).
     *
     * @param int $gy سال میلادی
     * @param int $gm ماه میلادی
     * @param int $gd روز میلادی
     * @return array [سال شمسی, ماه شمسی, روز شمسی]
     */
    private function gregorian_to_jalali( $gy, $gm, $gd ) {

        $g_days_in_month = array( 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );

        $gy2 = ( $gm > 2 ) ? ( $gy + 1 ) : $gy;

        $days = 355666 + ( 365 * $gy )
            + (int) floor( ( $gy2 + 3 ) / 4 )
            - (int) floor( ( $gy2 + 99 ) / 100 )
            + (int) floor( ( $gy2 + 399 ) / 400 )
            + $gd;

        for ( $i = 0; $i < $gm - 1; $i++ ) {
            $days += $g_days_in_month[ $i ];
        }

        $jy = -1595 + ( 33 * (int) floor( $days / 12053 ) );
        $days %= 12053;

        $jy += 4 * (int) floor( $days / 1461 );
        $days %= 1461;

        if ( $days > 365 ) {
            $jy += (int) floor( ( $days - 1 ) / 365 );
            $days = ( $days - 1 ) % 365;
        }

        if ( $days < 186 ) {
            $jm = 1 + (int) floor( $days / 31 );
            $jd = 1 + ( $days % 31 );
        } else {
            $jm = 7 + (int) floor( ( $days - 186 ) / 30 );
            $jd = 1 + ( ( $days - 186 ) % 30 );
        }

        return array( $jy, $jm, $jd );
    }

    /* =====================================================================
     * صفحه گزارش سفارشات ویرایش شده
     * ===================================================================== */

    /**
     * رندر صفحه "گزارش سفارشات ویرایش شده"
     */
    public function render_report_page() {

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'شما اجازه دسترسی به این صفحه را ندارید.', 'mahamsoft-order-edit' ) );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::LOG_TABLE;

        $per_page     = 20;
        $current_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore
        $offset       = ( $current_page - 1 ) * $per_page;

        $order_ids_query = $wpdb->prepare(
            "SELECT order_id, MAX(created_at) AS last_change
             FROM {$table_name}
             GROUP BY order_id
             ORDER BY last_change DESC
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        );

        $rows = $wpdb->get_results( $order_ids_query );

        $total_orders = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT order_id) FROM {$table_name}" );
        $total_pages  = (int) ceil( $total_orders / $per_page );

        ?>
        <div class="wrap" dir="rtl">
            <h1><?php esc_html_e( 'گزارش سفارشات ویرایش شده', 'mahamsoft-order-edit' ); ?></h1>

            <?php
            if ( isset( $_GET['mahamsoft_oe_deleted'] ) ) { // phpcs:ignore
                $deleted_count = absint( $_GET['mahamsoft_oe_deleted'] ); // phpcs:ignore
                printf(
                    '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                    esc_html(
                        sprintf(
                            /* translators: %d: تعداد رکوردهای حذف‌شده */
                            __( '%d رکورد گزارش با موفقیت حذف شد.', 'mahamsoft-order-edit' ),
                            $deleted_count
                        )
                    )
                );
            }
            ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="mahamsoft-oe-report-form">
                <?php wp_nonce_field( 'mahamsoft_oe_bulk_delete' ); ?>
                <input type="hidden" name="action" value="mahamsoft_oe_bulk_delete_logs">

                <div class="tablenav top">
                    <div class="alignleft actions">
                        <button type="submit"
                                name="mahamsoft_oe_bulk_action"
                                value="delete_selected"
                                class="button"
                                onclick="return confirm('<?php echo esc_js( __( 'آیا از حذف گزارش سفارش‌های انتخاب‌شده مطمئن هستید؟ این عملیات غیرقابل بازگشت است.', 'mahamsoft-order-edit' ) ); ?>');">
                            <?php esc_html_e( 'حذف موارد انتخاب‌شده', 'mahamsoft-order-edit' ); ?>
                        </button>

                        <button type="submit"
                                name="mahamsoft_oe_bulk_action"
                                value="delete_all"
                                class="button button-link-delete"
                                onclick="return confirm('<?php echo esc_js( __( 'آیا از حذف تمامی رکوردهای گزارش (همه سفارش‌ها) مطمئن هستید؟ این عملیات غیرقابل بازگشت است.', 'mahamsoft-order-edit' ) ); ?>');">
                            <?php esc_html_e( 'حذف همه', 'mahamsoft-order-edit' ); ?>
                        </button>
                    </div>
                </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <td class="manage-column column-cb check-column">
                            <input type="checkbox" id="mahamsoft-oe-select-all">
                        </td>
                        <th><?php esc_html_e( 'ردیف', 'mahamsoft-order-edit' ); ?></th>
                        <th><?php esc_html_e( 'شماره سفارش', 'mahamsoft-order-edit' ); ?></th>
                        <th><?php esc_html_e( 'نوع ثبت سفارش', 'mahamsoft-order-edit' ); ?></th>
                        <th><?php esc_html_e( 'ثبت کننده', 'mahamsoft-order-edit' ); ?></th>
                        <th><?php esc_html_e( 'آخرین وضعیت', 'mahamsoft-order-edit' ); ?></th>
                        <th><?php esc_html_e( 'تاریخ آخرین تغییر', 'mahamsoft-order-edit' ); ?></th>
                        <th><?php esc_html_e( 'ساعت آخرین تغییر', 'mahamsoft-order-edit' ); ?></th>
                        <th><?php esc_html_e( 'مشاهده تغییرات', 'mahamsoft-order-edit' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ( empty( $rows ) ) :
                        ?>
                        <tr>
                            <td colspan="9"><?php esc_html_e( 'هیچ رکوردی یافت نشد.', 'mahamsoft-order-edit' ); ?></td>
                        </tr>
                        <?php
                    else :
                        $row_number = $offset + 1;
                        foreach ( $rows as $row ) :

                            $order = wc_get_order( $row->order_id );
                            if ( ! $order ) {
                                continue;
                            }

                            $order_type_label = ( 'admin' === $this->get_order_creation_type( $order ) )
                                ? esc_html__( 'پنل مدیریت', 'mahamsoft-order-edit' )
                                : esc_html__( 'آنلاین', 'mahamsoft-order-edit' );

                            $created_by_label = $this->get_order_created_by_label( $order );

                            $statuses     = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
                            $status_key   = 'wc-' . $order->get_status();
                            $status_label = isset( $statuses[ $status_key ] ) ? $statuses[ $status_key ] : $order->get_status();

                            $last_change_timestamp = strtotime( $row->last_change );
                            $last_change_date      = $this->gregorian_to_jalali_string( $last_change_timestamp );
                            $last_change_time      = $this->format_time_24h( $last_change_timestamp );

                            ?>
                            <tr>
                                <th class="check-column">
                                    <input type="checkbox" name="order_ids[]" value="<?php echo esc_attr( $order->get_id() ); ?>">
                                </th>
                                <td><?php echo esc_html( $row_number++ ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>" target="_blank">
                                        #<?php echo esc_html( $order->get_order_number() ); ?>
                                    </a>
                                </td>
                                <td><?php echo esc_html( $order_type_label ); ?></td>
                                <td><?php echo wp_kses_post( $created_by_label ); ?></td>
                                <td><?php echo esc_html( $status_label ); ?></td>
                                <td><?php echo esc_html( $last_change_date ); ?></td>
                                <td><?php echo esc_html( $last_change_time ); ?></td>
                                <td>
                                    <button type="button"
                                            class="button mahamsoft-view-order-log"
                                            data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
                                        <?php esc_html_e( 'مشاهده', 'mahamsoft-order-edit' ); ?>
                                    </button>
                                </td>
                            </tr>
                            <?php
                        endforeach;
                    endif;
                    ?>
                </tbody>
            </table>
            </form>

            <?php if ( $total_pages > 1 ) : ?>
                <div class="tablenav">
                    <div class="tablenav-pages">
                        <?php
                        echo wp_kses_post(
                            paginate_links(
                                array(
                                    'base'      => add_query_arg( 'paged', '%#%' ),
                                    'format'    => '',
                                    'prev_text' => __( '&laquo;', 'mahamsoft-order-edit' ),
                                    'next_text' => __( '&raquo;', 'mahamsoft-order-edit' ),
                                    'total'     => $total_pages,
                                    'current'   => $current_page,
                                )
                            )
                        );
                        ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- مودال نمایش جزئیات تغییرات یک سفارش -->
            <div id="mahamsoft-order-log-modal" style="display:none; position:fixed; z-index:99999; top:50%; left:50%; transform:translate(-50%,-50%); width:1100px; max-width:96%; max-height:85vh; overflow:auto; background:#fff; border:1px solid #ccc; box-shadow:0 5px 20px rgba(0,0,0,0.3); padding:20px; border-radius:8px; direction:rtl;">
                <h2><?php esc_html_e( 'تغییرات سفارش', 'mahamsoft-order-edit' ); ?></h2>
                <div id="mahamsoft-order-log-modal-content"></div>
                <p style="text-align:left; margin-top:15px;">
                    <button type="button" id="mahamsoft-order-log-modal-close" class="button button-primary">
                        <?php esc_html_e( 'بستن', 'mahamsoft-order-edit' ); ?>
                    </button>
                </p>
            </div>
            <div id="mahamsoft-order-log-modal-overlay" style="display:none; position:fixed; z-index:99998; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.4);"></div>

        </div>

        <script type="text/javascript">
        jQuery(function ($) {

            $('#mahamsoft-oe-select-all').on('change', function () {
                $('input[name="order_ids[]"]').prop('checked', $(this).is(':checked'));
            });

            $(document).on('click', '.mahamsoft-view-order-log', function () {

                var orderId  = $(this).data('order-id');
                var $content = $('#mahamsoft-order-log-modal-content');

                $content.html('<p><?php echo esc_js( __( 'در حال بارگذاری...', 'mahamsoft-order-edit' ) ); ?></p>');
                $('#mahamsoft-order-log-modal, #mahamsoft-order-log-modal-overlay').show();

                $.post(ajaxurl, {
                    action: 'mahamsoft_get_order_edit_log',
                    order_id: orderId,
                    _wpnonce: '<?php echo esc_js( wp_create_nonce( 'mahamsoft_oe_view_log' ) ); ?>'
                }, function (response) {
                    if (response && response.success) {
                        $content.html(response.data.html);
                    } else {
                        $content.html('<p><?php echo esc_js( __( 'خطا در بارگذاری اطلاعات.', 'mahamsoft-order-edit' ) ); ?></p>');
                    }
                });
            });

            $(document).on('click', '#mahamsoft-order-log-modal-close, #mahamsoft-order-log-modal-overlay', function () {
                $('#mahamsoft-order-log-modal, #mahamsoft-order-log-modal-overlay').hide();
            });
        });
        </script>
        <?php
    }

    /**
     * هندلر AJAX برای دریافت جزئیات کامل تغییرات یک سفارش (برای نمایش در مودال).
     */
    public function ajax_get_order_edit_log_details() {

        check_ajax_referer( 'mahamsoft_oe_view_log' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'دسترسی غیرمجاز.', 'mahamsoft-order-edit' ) ) );
        }

        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0; // phpcs:ignore

        if ( ! $order_id ) {
            wp_send_json_error( array( 'message' => esc_html__( 'سفارش نامعتبر.', 'mahamsoft-order-edit' ) ) );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::LOG_TABLE;

        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE order_id = %d ORDER BY created_at DESC, id DESC",
                $order_id
            )
        );

        $order = wc_get_order( $order_id );

        ob_start();
        ?>

        <p>
            <strong><?php esc_html_e( 'شناسه سفارش:', 'mahamsoft-order-edit' ); ?></strong>
            #<?php echo esc_html( $order ? $order->get_order_number() : $order_id ); ?>
        </p>

        <p>
            <a class="button button-secondary"
               target="_blank"
               href="<?php echo esc_url(
                    add_query_arg(
                        array(
                            'action'   => 'mahamsoft_oe_export_log',
                            'order_id' => $order_id,
                            '_wpnonce' => wp_create_nonce( 'mahamsoft_oe_export_log_' . $order_id ),
                        ),
                        admin_url( 'admin-post.php' )
                    )
               ); ?>">
                <?php esc_html_e( 'خروجی اطلاعات', 'mahamsoft-order-edit' ); ?>
            </a>
        </p>

        <table class="wp-list-table widefat fixed striped mahamsoft-order-log-table">
            <thead>
                <tr>
                    <th style="width:5%;"><?php esc_html_e( 'ردیف', 'mahamsoft-order-edit' ); ?></th>
                    <th style="width:13%;"><?php esc_html_e( 'کاربر ویرایش‌کننده', 'mahamsoft-order-edit' ); ?></th>
                    <th style="width:10%;"><?php esc_html_e( 'تاریخ', 'mahamsoft-order-edit' ); ?></th>
                    <th style="width:9%;"><?php esc_html_e( 'ساعت', 'mahamsoft-order-edit' ); ?></th>
                    <th style="width:55%;"><?php esc_html_e( 'تغییرات', 'mahamsoft-order-edit' ); ?></th>
                    <th style="width:8%;"><?php esc_html_e( 'حذف', 'mahamsoft-order-edit' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ( empty( $logs ) ) :
                    ?>
                    <tr><td colspan="6"><?php esc_html_e( 'هیچ تغییری ثبت نشده است.', 'mahamsoft-order-edit' ); ?></td></tr>
                    <?php
                else :
                    $row_number = 1;
                    foreach ( $logs as $log ) :

                        $editor      = $log->editor_id ? get_userdata( $log->editor_id ) : false;
                        $editor_name = $editor ? $editor->display_name : esc_html__( 'سیستم', 'mahamsoft-order-edit' );

                        $timestamp = strtotime( $log->created_at );
                        $date_str  = $this->gregorian_to_jalali_string( $timestamp );
                        $time_str  = $this->format_time_24h( $timestamp );

                        $delete_url = add_query_arg(
                            array(
                                'action'   => 'mahamsoft_oe_delete_single_log',
                                'log_id'   => $log->id,
                                '_wpnonce' => wp_create_nonce( 'mahamsoft_oe_delete_single_log_' . $log->id ),
                            ),
                            admin_url( 'admin-post.php' )
                        );

                        ?>
                        <tr>
                            <td><?php echo esc_html( $row_number++ ); ?></td>
                            <td><?php echo esc_html( $editor_name ); ?></td>
                            <td><?php echo esc_html( $date_str ); ?></td>
                            <td><?php echo esc_html( $time_str ); ?></td>
                            <td>
                                <strong><?php echo esc_html( $log->change_summary ); ?></strong><br>
                                <?php echo wp_kses_post( $log->change_details ); ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url( $delete_url ); ?>"
                                   class="button button-link-delete"
                                   onclick="return confirm('<?php echo esc_js( __( 'آیا از حذف این رکورد مطمئن هستید؟', 'mahamsoft-order-edit' ) ); ?>');">
                                    <?php esc_html_e( 'حذف', 'mahamsoft-order-edit' ); ?>
                                </a>
                            </td>
                        </tr>
                        <?php
                    endforeach;
                endif;
                ?>
            </tbody>
        </table>

        <style>
            .mahamsoft-order-log-table td:last-child {
                white-space: normal;
                word-break: break-word;
                line-height: 1.8;
            }
        </style>
        <?php
        $html = ob_get_clean();

        wp_send_json_success( array( 'html' => $html ) );
    }

    /* =====================================================================
     * خروجی CSV گزارش تغییرات یک سفارش
     * ===================================================================== */

    /**
     * هندلر admin-post برای خروجی گرفتن (CSV) از تمامی تغییرات ثبت‌شده یک سفارش.
     */
    public function export_order_edit_log_csv() {

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'دسترسی غیرمجاز.', 'mahamsoft-order-edit' ) );
        }

        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore

        if ( ! $order_id ) {
            wp_die( esc_html__( 'سفارش نامعتبر.', 'mahamsoft-order-edit' ) );
        }

        check_admin_referer( 'mahamsoft_oe_export_log_' . $order_id );

        global $wpdb;
        $table_name = $wpdb->prefix . self::LOG_TABLE;

        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE order_id = %d ORDER BY created_at DESC, id DESC",
                $order_id
            )
        );

        $order        = wc_get_order( $order_id );
        $order_number = $order ? $order->get_order_number() : $order_id;

        if ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename=order-edit-log-' . $order_number . '.csv' );

        // BOM برای نمایش صحیح حروف فارسی در Excel
        echo "\xEF\xBB\xBF"; // phpcs:ignore

        $output = fopen( 'php://output', 'w' );

        fputcsv(
            $output,
            array(
                __( 'ردیف', 'mahamsoft-order-edit' ),
                __( 'شناسه سفارش', 'mahamsoft-order-edit' ),
                __( 'کاربر ویرایش‌کننده', 'mahamsoft-order-edit' ),
                __( 'تاریخ', 'mahamsoft-order-edit' ),
                __( 'ساعت', 'mahamsoft-order-edit' ),
                __( 'خلاصه تغییر', 'mahamsoft-order-edit' ),
                __( 'جزئیات تغییر', 'mahamsoft-order-edit' ),
            )
        );

        $row_number = 1;
        foreach ( $logs as $log ) {

            $editor      = $log->editor_id ? get_userdata( $log->editor_id ) : false;
            $editor_name = $editor ? $editor->display_name : __( 'سیستم', 'mahamsoft-order-edit' );

            $timestamp = strtotime( $log->created_at );
            $date_str  = $this->gregorian_to_jalali_string( $timestamp );
            $time_str  = $this->format_time_24h( $timestamp );

            $plain_details = preg_replace( '/<br\s*\/?>|<hr\s*\/?>/i', "\n", $log->change_details );
            $plain_details = wp_strip_all_tags( $plain_details );

            fputcsv(
                $output,
                array(
                    $row_number++,
                    $order_number,
                    $editor_name,
                    $date_str,
                    $time_str,
                    $log->change_summary,
                    $plain_details,
                )
            );
        }

        fclose( $output );
        exit;
    }

    /* =====================================================================
     * حذف رکوردهای گزارش (تکی / گروهی / همه)
     * ===================================================================== */

    /**
     * هندلر admin-post برای حذف گروهی یا حذف کامل تمامی رکوردهای جدول گزارش.
     */
    public function handle_bulk_delete_logs() {

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'دسترسی غیرمجاز.', 'mahamsoft-order-edit' ) );
        }

        check_admin_referer( 'mahamsoft_oe_bulk_delete' );

        global $wpdb;
        $table_name = $wpdb->prefix . self::LOG_TABLE;

        $bulk_action = isset( $_POST['mahamsoft_oe_bulk_action'] ) ? sanitize_key( $_POST['mahamsoft_oe_bulk_action'] ) : ''; // phpcs:ignore

        $deleted_count = 0;

        if ( 'delete_all' === $bulk_action ) {

            $deleted_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
            $wpdb->query( "TRUNCATE TABLE {$table_name}" ); // phpcs:ignore

        } elseif ( 'delete_selected' === $bulk_action ) {

            $order_ids = isset( $_POST['order_ids'] ) && is_array( $_POST['order_ids'] ) // phpcs:ignore
                ? array_map( 'absint', $_POST['order_ids'] ) // phpcs:ignore
                : array();

            $order_ids = array_filter( $order_ids );

            if ( ! empty( $order_ids ) ) {

                $placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );

                $deleted_count = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$table_name} WHERE order_id IN ({$placeholders})", // phpcs:ignore
                        $order_ids
                    )
                );

                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$table_name} WHERE order_id IN ({$placeholders})", // phpcs:ignore
                        $order_ids
                    )
                );
            }
        }

        $redirect_url = add_query_arg(
            array( 'mahamsoft_oe_deleted' => $deleted_count ),
            admin_url( 'admin.php?page=mahamsoft-order-edit-report' )
        );

        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * هندلر admin-post برای حذف یک رکورد لاگ تکی.
     */
    public function handle_delete_single_log() {

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'دسترسی غیرمجاز.', 'mahamsoft-order-edit' ) );
        }

        $log_id = isset( $_GET['log_id'] ) ? absint( $_GET['log_id'] ) : 0; // phpcs:ignore

        if ( ! $log_id ) {
            wp_die( esc_html__( 'رکورد نامعتبر.', 'mahamsoft-order-edit' ) );
        }

        check_admin_referer( 'mahamsoft_oe_delete_single_log_' . $log_id );

        global $wpdb;
        $table_name = $wpdb->prefix . self::LOG_TABLE;

        $wpdb->delete( $table_name, array( 'id' => $log_id ), array( '%d' ) );

        $redirect_url = add_query_arg(
            array( 'mahamsoft_oe_deleted' => 1 ),
            admin_url( 'admin.php?page=mahamsoft-order-edit-report' )
        );

        wp_safe_redirect( $redirect_url );
        exit;
    }
}

/**
 * بررسی فعال بودن ووکامرس و راه‌اندازی پلاگین
 */
add_action( 'plugins_loaded', function () {

    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>' .
                esc_html__( 'پلاگین "Mahamsoft - Order Edit Manager" نیاز به فعال بودن ووکامرس دارد.', 'mahamsoft-order-edit' ) .
                '</p></div>';
        } );
        return;
    }

    Mahamsoft_Order_Edit_Manager::instance();
} );