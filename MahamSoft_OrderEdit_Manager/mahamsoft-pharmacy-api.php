<?php
/**
 * Mahamsoft - Pharmacy API Module (rebuilt v4)
 * -------------------------------------------------------------------------
 * ماژول اتصال به وب‌سرویس داروخانه (داروپردازان) - مستقل از فایل اصلی.
 *
 * --- معماری نسخه ۴ ---
 *
 * مشکلات رفع‌شده در این نسخه نسبت به نسخه‌های قبلی:
 *
 * 1) مودال تاریخ انقضاء اکنون در همان «لحظهٔ انتخاب محصول» باز می‌شود:
 *    در نسخه‌های قبلی رهگیری روی دکمهٔ ذخیره/بروزرسانی بود و محصول
 *    پیش از باز شدن مودال به سفارش اضافه می‌شد. حالا به‌محض انتخاب
 *    محصول در دراپ‌داون select2 ووکامرس، رویداد select2:select رهگیری
 *    شده، انتخاب پیش‌فرض لغو می‌شود و مودال باز می‌گردد. کاربر تعداد را
 *    به تفکیک تاریخ انقضاء توزیع می‌کند و مجموع آن داخل فیلد تعداد
 *    ووکامرس قرار می‌گیرد. سپس همان محصول با تعداد نهایی به سفارش اضافه
 *    می‌شود و انتخاب‌های تاریخ در یک input مخفی برای سمت سرور ذخیره
 *    می‌شوند.
 *
 * 2) تغییر تعداد یک آیتم موجود (افزایش یا کاهش):
 *    مبنای «تعداد اولیه» هر ردیف در یک ویژگی اختصاصی نگه‌داری می‌شود که
 *    با بارگذاری مجدد جدول آیتم‌ها توسط ووکامرس از بین نمی‌رود. با تغییر
 *    تعداد در فیلد، مودال باز شده و تفاوت نسبت به مبنای درست محاسبه
 *    می‌شود (افزایش = کسر از انبار با انتخاب تاریخ، کاهش = برگشت خودکار
 *    به انبار با تاریخ متای آیتم).
 *
 * 3) حذف ردیف محصول از سفارش → برگشت خودکار موجودی به انبار:
 *    هنگام حذف یک ردیف، بدون نمایش مودال و به‌صورت خودکار، کل تعداد آن
 *    آیتم با همان تاریخ انقضاء ذخیره‌شده روی متای آیتم (_expiry_date) به
 *    انبار برگردانده می‌شود. این کار کاملاً سمت سرور در
 *    on_save_order_items انجام می‌شود.
 *
 * 4) سازگاری کامل با HPOS:
 *    صفحهٔ ویرایش/افزودن سفارش هم در حالت کلاسیک (post.php / post-new.php)
 *    و هم در حالت HPOS (admin.php?page=wc-orders) تشخیص داده می‌شود.
 *
 * 5) تمام تنظیمات وب‌سرویس از آپشن mahamsoft_order_edit_settings خوانده
 *    می‌شوند - هیچ مقداری هاردکد نشده است. codeMelliDaroo هر محصول از
 *    SKU خودش خوانده می‌شود.
 *
 * @package Mahamsoft_Order_Edit
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Mahamsoft_Pharmacy_API
{

    /** @var Mahamsoft_Pharmacy_API|null */
    private static $instance = null;

    /**
     * @return Mahamsoft_Pharmacy_API
     */
    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * سازنده - ثبت تمام هوک‌ها
     */
    private function __construct()
    {

        add_action('admin_footer', array($this, 'render_modal_html'));

        add_action('wp_ajax_mahamsoft_get_quantity', array($this, 'ajax_get_quantity'));
        add_action('wp_ajax_mahamsoft_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_mahamsoft_get_item_expiry', array($this, 'ajax_get_item_expiry'));

        /*
         * --- زمان‌بندی کسر/اضافه به انبار ---
         * طبق نیاز، فراخوانی SetQuantity فقط هنگام کلیک دکمهٔ اصلی
         * «ایجاد/به‌روزرسانی سفارش» انجام می‌شود، نه با دکمهٔ کوچک
         * «به‌روزرسانی» داخل متاباکس آیتم‌ها.
         *
         * مرحله ۱) درست قبل از اعمال تغییرات آیتم‌ها، تعداد فعلی هر آیتم را
         *          در یک transient ذخیره می‌کنیم (snapshot قبل).
         * مرحله ۲) پس از ذخیرهٔ کامل سفارش (woocommerce_process_shop_order_meta
         *          که با دکمهٔ اصلی فعال می‌شود) snapshot را با وضعیت نهایی
         *          مقایسه کرده و کسر/اضافه/برگشت را انجام می‌دهیم.
         */
        add_action('woocommerce_before_save_order_items', array($this, 'capture_qty_snapshot'), 1, 2);

        // دکمهٔ اصلی ایجاد/به‌روزرسانی سفارش (کلاسیک و HPOS هر دو این هوک را فعال می‌کنند)
        add_action('woocommerce_process_shop_order_meta', array($this, 'process_stock_on_order_save'), 70, 1);

        // برگشت موجودی هنگام استرداد
        add_action('woocommerce_order_refunded', array($this, 'on_refund'), 10, 2);

        // مورد ۴: برگشت کل موجودی هنگام تغییر وضعیت به یکی از «وضعیت‌های قابل بازگشت»
        add_action('woocommerce_order_status_changed', array($this, 'on_status_changed_restock'), 20, 4);

        // دکمه تست اتصال در صفحه تنظیمات پلاگین اصلی
        add_action('mahamsoft_oe_settings_after_form', array($this, 'render_connection_test_button'));

        // مورد ۶: نمایش توزیع تاریخ انقضاء زیر نام محصول در ادیتور سفارش (رنگ قرمز)
        add_action('woocommerce_after_order_itemmeta', array($this, 'render_expiry_breakdown_under_item'), 10, 3);

        // مورد ۲: پنهان‌کردن متاهای داخلی از جدول display_meta در ادیتور سفارش
        add_filter('woocommerce_hidden_order_itemmeta', array($this, 'hide_internal_item_meta'));
    }

    /**
     * افزودن کلیدهای متای داخلی به فهرست متاهای پنهان ووکامرس تا در جدول
     * متادیتای آیتم سفارش نمایش داده نشوند (فقط div قرمز توزیع باقی بماند).
     *
     * @param array $hidden
     * @return array
     */
    public function hide_internal_item_meta($hidden)
    {
        $hidden[] = '_expiry_date';
        $hidden[] = '_mwp_expiry_breakdown';
        $hidden[] = '_mwp_expiry_data';
        $hidden[] = '_mwp_stock_applied';
        $hidden[] = '_mwp_applied_tokens';
        return $hidden;
    }

    /* ====================================================================
     * بخش تنظیمات - خواندن تمام مقادیر از آپشن پلاگین اصلی
     * ==================================================================== */

    /**
     * خواندن یک کلید تنظیمات از آپشن ذخیره‌شده پلاگین اصلی
     * (mahamsoft_order_edit_settings).
     *
     * @param string $key     کلید تنظیمات
     * @param mixed  $default مقدار پیش‌فرض در صورت نبود کلید
     * @return mixed
     */
    private function cfg($key, $default = '')
    {
        static $settings = null;

        if (null === $settings) {
            $settings = get_option('mahamsoft_order_edit_settings', array());
            if (!is_array($settings)) {
                $settings = array();
            }
        }

        return (isset($settings[$key]) && '' !== $settings[$key]) ? $settings[$key] : $default;
    }

    /* ====================================================================
     * HTML + JavaScript مودال
     * ==================================================================== */

    public function render_modal_html()
    {

        if (!$this->is_order_screen()) {
            return;
        }

        $nonce = wp_create_nonce('mahamsoft_pharmacy_nonce');
        $warehouse_name = $this->cfg('warehouse_name', 'انبار');
        ?>
        <style>
            #mwp-ov {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, .55);
                z-index: 199998;
            }

            #mwp-modal {
                display: none;
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                z-index: 199999;
                width: 680px;
                max-width: 97%;
                max-height: 86vh;
                overflow-y: auto;
                background: #fff;
                border-radius: 8px;
                box-shadow: 0 8px 32px rgba(0, 0, 0, .28);
                direction: rtl;
            }

            .mwp-hd {
                background: #135e96;
                color: #fff;
                padding: 13px 18px;
                border-radius: 8px 8px 0 0;
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 10px;
            }

            .mwp-hd h2 {
                margin: 0;
                font-size: 14px;
                color: #fff;
                flex: 1;
                overflow: hidden;
                white-space: nowrap;
                text-overflow: ellipsis;
            }

            .mwp-hd button {
                background: none;
                border: none;
                color: #fff;
                font-size: 22px;
                cursor: pointer;
                line-height: 1;
                flex-shrink: 0;
            }

            #mwp-tabs {
                display: flex;
                gap: 0;
                border-bottom: 2px solid #135e96;
                padding: 0 18px;
                background: #f8f8f8;
                flex-wrap: wrap;
            }

            .mwp-tab {
                padding: 9px 16px;
                cursor: pointer;
                font-size: 13px;
                font-weight: 600;
                border: 1px solid #ddd;
                border-bottom: none;
                background: #f0f0f0;
                color: #555;
                border-radius: 4px 4px 0 0;
                margin-left: 4px;
                margin-top: 6px;
                white-space: nowrap;
            }

            .mwp-tab.active {
                background: #fff;
                color: #135e96;
                border-color: #135e96;
                border-bottom: 2px solid #fff;
                margin-bottom: -2px;
            }

            .mwp-tab.mwp-tab-remove {
                color: #a33;
            }

            .mwp-tab.mwp-tab-remove.active {
                color: #c00;
                border-color: #c00;
                border-bottom-color: #fff;
            }

            .mwp-tab-pane {
                display: none;
                padding: 16px 18px;
            }

            .mwp-tab-pane.active {
                display: block;
            }

            #mwp-err {
                background: #fef0f0;
                color: #c00;
                border: 1px solid #f5c6cb;
                border-radius: 4px;
                padding: 9px 13px;
                margin-bottom: 12px;
                display: none;
                font-size: 13px;
                white-space: pre-wrap;
            }

            .mwp-load {
                text-align: center;
                padding: 22px;
                color: #666;
            }

            table.mwp-tbl {
                width: 100%;
                border-collapse: collapse;
                font-size: 13px;
                margin-bottom: 10px;
            }

            table.mwp-tbl th {
                background: #f0f0f0;
                padding: 7px 9px;
                text-align: right;
                border-bottom: 2px solid #ddd;
                font-weight: 600;
            }

            table.mwp-tbl td {
                padding: 7px 9px;
                border-bottom: 1px solid #eee;
                vertical-align: middle;
            }

            .mwp-qin {
                width: 70px;
                padding: 4px 6px;
                border: 1px solid #ccc;
                border-radius: 4px;
                text-align: center;
                font-size: 13px;
            }

            .mwp-qin:focus {
                border-color: #135e96;
                outline: none;
            }

            .mwp-qerr {
                color: #c00;
                font-size: 11px;
                display: none;
                margin-top: 2px;
            }

            .mwp-badge {
                background: #eaf3e6;
                color: #276624;
                border-radius: 10px;
                padding: 2px 9px;
                font-size: 12px;
                font-weight: 600;
                display: inline-block;
            }

            .mwp-sum-row td {
                background: #f0f6ff;
                font-weight: 600;
                border-top: 2px solid #135e96;
            }

            .mwp-diff {
                background: #fff8e6;
                border: 1px solid #f0c040;
                border-radius: 4px;
                padding: 8px 12px;
                font-size: 13px;
                margin-bottom: 10px;
            }

            .mwp-diff.mwp-diff-remove {
                background: #fdecec;
                border-color: #f0a0a0;
            }

            .mwp-need {
                color: #135e96;
                font-weight: 600;
            }

            .mwp-need-bad {
                color: #c00;
                font-weight: 600;
            }

            #mwp-foot {
                padding: 12px 18px 16px;
                border-top: 1px solid #eee;
                display: flex;
                gap: 8px;
                justify-content: flex-end;
            }

            #mwp-ok {
                background: #135e96;
                color: #fff;
                border: none;
                padding: 8px 20px;
                border-radius: 4px;
                cursor: pointer;
                font-size: 13px;
                font-weight: 600;
            }

            #mwp-ok:hover {
                background: #0d4474;
            }

            #mwp-ok:disabled {
                opacity: .5;
                cursor: not-allowed;
            }

            #mwp-cancel {
                background: #f0f0f0;
                color: #333;
                border: 1px solid #ccc;
                padding: 8px 16px;
                border-radius: 4px;
                cursor: pointer;
                font-size: 13px;
            }

            .mwp-retry-btn {
                background: #e87722;
                color: #fff;
                border: none;
                padding: 5px 12px;
                border-radius: 4px;
                cursor: pointer;
                font-size: 12px;
                margin-top: 8px;
                display: block;
            }

            /* مودال تست اتصال */
            #mwp-co {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, .45);
                z-index: 299998;
            }

            #mwp-cm {
                display: none;
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                z-index: 299999;
                width: 420px;
                max-width: 94%;
                background: #fff;
                border-radius: 8px;
                box-shadow: 0 6px 24px rgba(0, 0, 0, .22);
                direction: rtl;
            }
        </style>

        <div id="mwp-ov"></div>
        <div id="mwp-modal" role="dialog" aria-modal="true">
            <div class="mwp-hd">
                <h2 id="mwp-title"><?php esc_html_e('انتخاب تاریخ انقضا', 'mahamsoft-order-edit'); ?></h2>
                <button type="button" id="mwp-x">&times;</button>
            </div>
            <div id="mwp-tabs" style="display:none;"></div>
            <div style="padding:0 18px;">
                <div id="mwp-err">
                    <span id="mwp-err-txt"></span>
                    <button type="button" id="mwp-retry" class="mwp-retry-btn" style="display:none;">
                        <?php esc_html_e('↺ تلاش مجدد', 'mahamsoft-order-edit'); ?>
                    </button>
                </div>
            </div>
            <div id="mwp-panes"></div>
            <div id="mwp-foot">
                <button type="button" id="mwp-ok" disabled><?php esc_html_e('تأیید', 'mahamsoft-order-edit'); ?></button>
                <button type="button" id="mwp-cancel"><?php esc_html_e('انصراف', 'mahamsoft-order-edit'); ?></button>
            </div>
        </div>

        <!-- مودال تست اتصال -->
        <div id="mwp-co"></div>
        <div id="mwp-cm">
            <div class="mwp-hd" style="background:#2c7a2c;">
                <h2><?php esc_html_e('نتیجه تست اتصال', 'mahamsoft-order-edit'); ?></h2>
                <button type="button" id="mwp-cx">&times;</button>
            </div>
            <div style="padding:18px;">
                <div id="mwp-cr" style="font-size:14px;line-height:1.8;"></div>
            </div>
            <div style="padding:12px 18px 16px;border-top:1px solid #eee;display:flex;justify-content:flex-end;">
                <button type="button" id="mwp-cc" class="button button-primary"><?php esc_html_e('بستن', 'mahamsoft-order-edit'); ?></button>
            </div>
        </div>

        <script type="text/javascript">
            (function ($) {
                'use strict';

                var NONCE = <?php echo wp_json_encode($nonce); ?>;
                var AJAX_URL = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                var WH_NAME = <?php echo wp_json_encode($warehouse_name); ?>;

                var I18N = {
                    confirmAdd: <?php echo wp_json_encode(__('تأیید و افزودن', 'mahamsoft-order-edit')); ?>,
                    confirmSave: <?php echo wp_json_encode(__('تأیید', 'mahamsoft-order-edit')); ?>,
                    titleAdd: <?php echo wp_json_encode(__('انتخاب تاریخ انقضا - افزودن محصول', 'mahamsoft-order-edit')); ?>,
                    titleEdit: <?php echo wp_json_encode(__('انتخاب تاریخ انقضا - تغییر تعداد', 'mahamsoft-order-edit')); ?>,
                    loading: <?php echo wp_json_encode(__('در حال دریافت اطلاعات...', 'mahamsoft-order-edit')); ?>,
                    noStock: <?php echo wp_json_encode(__('موجودی یافت نشد.', 'mahamsoft-order-edit')); ?>,
                    detail: <?php echo wp_json_encode(__('جزئیات: ', 'mahamsoft-order-edit')); ?>,
                    errLoad: <?php echo wp_json_encode(__('خطا در دریافت اطلاعات انبار.', 'mahamsoft-order-edit')); ?>,
                    retry: <?php echo wp_json_encode(__('↺ تلاش مجدد', 'mahamsoft-order-edit')); ?>,
                    overMax: <?php echo wp_json_encode(__('بیشتر از موجودی!', 'mahamsoft-order-edit')); ?>,
                    expiry: <?php echo wp_json_encode(__('تاریخ انقضا', 'mahamsoft-order-edit')); ?>,
                    stock: <?php echo wp_json_encode(__('موجودی', 'mahamsoft-order-edit')); ?>,
                    chosenQty: <?php echo wp_json_encode(__('تعداد انتخابی', 'mahamsoft-order-edit')); ?>,
                    sumTotal: <?php echo wp_json_encode(__('جمع کل:', 'mahamsoft-order-edit')); ?>,
                    deductFromWh: <?php echo wp_json_encode(__('کسر از انبار', 'mahamsoft-order-edit')); ?>,
                    returnToWh: <?php echo wp_json_encode(__('برگشت به انبار', 'mahamsoft-order-edit')); ?>,
                    qtyBefore: <?php echo wp_json_encode(__('تعداد قبل', 'mahamsoft-order-edit')); ?>,
                    qtyAfter: <?php echo wp_json_encode(__('تعداد جدید', 'mahamsoft-order-edit')); ?>,
                    diff: <?php echo wp_json_encode(__('تفاوت', 'mahamsoft-order-edit')); ?>,
                    needSelect: <?php echo wp_json_encode(__('تعداد موردنیاز برای کسر از انبار:', 'mahamsoft-order-edit')); ?>,
                    fillRows: <?php echo wp_json_encode(__('در هر ردیف تعداد موردنیاز را وارد کنید (می‌توانید از چند تاریخ استفاده کنید):', 'mahamsoft-order-edit')); ?>,
                    returnNotice: <?php echo wp_json_encode(__('مقدار کاهش‌یافته به‌صورت خودکار با تاریخ انقضاء ثبت‌شده روی این آیتم به انبار بازگردانده می‌شود. نیازی به انتخاب تاریخ نیست.', 'mahamsoft-order-edit')); ?>,
                    fillReturn: <?php echo wp_json_encode(__('مشخص کنید چه تعداد از هر تاریخ به انبار برگردد (مجموع باید برابر مقدار کاهش باشد):', 'mahamsoft-order-edit')); ?>,
                    deductedQty: <?php echo wp_json_encode(__('تعداد کسرشده', 'mahamsoft-order-edit')); ?>,
                    returnQty: <?php echo wp_json_encode(__('تعداد برگشت', 'mahamsoft-order-edit')); ?>,
                    sumReturn: <?php echo wp_json_encode(__('جمع برگشت:', 'mahamsoft-order-edit')); ?>,
                    ofTotal: <?php echo wp_json_encode(__('از مجموع', 'mahamsoft-order-edit')); ?>,
                    addProduct: <?php echo wp_json_encode(__('افزودن محصول', 'mahamsoft-order-edit')); ?>,
                    increase: <?php echo wp_json_encode(__('افزایش تعداد', 'mahamsoft-order-edit')); ?>,
                    decrease: <?php echo wp_json_encode(__('کاهش تعداد', 'mahamsoft-order-edit')); ?>,
                    mustMatch: <?php echo wp_json_encode(__('مجموع انتخاب‌شده باید برابر تعداد موردنیاز باشد.', 'mahamsoft-order-edit')); ?>,
                    noSku: <?php echo wp_json_encode(__('این محصول کد ملی دارو (SKU) ندارد و قابل افزودن از انبار نیست.', 'mahamsoft-order-edit')); ?>,
                    testing: <?php echo wp_json_encode(__('در حال تست...', 'mahamsoft-order-edit')); ?>,
                    testBtn: <?php echo wp_json_encode(__('تست اتصال به سرور', 'mahamsoft-order-edit')); ?>,
                    statusLbl: <?php echo wp_json_encode(__('وضعیت', 'mahamsoft-order-edit')); ?>,
                    messageLbl: <?php echo wp_json_encode(__('پیام', 'mahamsoft-order-edit')); ?>,
                    errorLbl: <?php echo wp_json_encode(__('خطا', 'mahamsoft-order-edit')); ?>
                };

                /*
                 * وضعیت داخلی ماژول
                 * ---------------------------------------------------------------
                 * mode       : 'add' یا 'edit' — تعیین می‌کند بعد از تأیید چه کنیم
                 * changes    : آرایه تغییرات جاری مودال (در حالت add معمولاً یک
                 *              عضو از نوع add؛ در حالت edit یک عضو increase/decrease)
                 *              هر عضو:
                 *              {
                 *                type   : 'add' | 'increase' | 'decrease',
                 *                itemId : شناسه آیتم سفارش (برای add خالی),
                 *                name   : نام محصول,
                 *                sku    : کد ملی (SKU),
                 *                productId : شناسه واقعی محصول (برای add),
                 *                oldQty : تعداد قبل,
                 *                newQty : تعداد جدید,
                 *                need   : تعدادی که باید برای آن تاریخ انتخاب شود
                 *              }
                 * stockCache : کش موجودی به ازای SKU
                 * pendingAdd : اطلاعات محصول در انتظار افزودن (حالت add)
                 * pendingEdit: اطلاعات ردیف در حال ویرایش (حالت edit)
                 */
                var S = {
                    mode: 'add',
                    changes: [],
                    stockCache: {},
                    pendingAdd: null,
                    pendingEdit: null,
                    suppressQtyChange: false
                };

                /* ============================================================
                 * ابزارهای کمکی برای کار با جدول آیتم‌های سفارش
                 * ============================================================ */

                function escHtml(s) {
                    return $('<div>').text(s == null ? '' : s).html();
                }

                function itemRowsSelector() {
                    return '#order_line_items tr.item, .wc-order-item-line-items tr.item, table.woocommerce_order_items tr.item, tr.item';
                }

                function isProductRow($row) {
                    if (!$row || !$row.length) { return false; }
                    if ($row.hasClass('shipping') || $row.hasClass('fee') ||
                        $row.hasClass('refund') || $row.hasClass('tax')) {
                        return false;
                    }
                    if (!$row.hasClass('item')) {
                        if (!$row.find('.wc-order-item-name').length &&
                            !$row.find('input.quantity[name^="order_item_qty"]').length) {
                            return false;
                        }
                    }
                    return true;
                }

                function readRowQty($row) {
                    var $inp = $row.find('input.quantity[name^="order_item_qty"]');
                    if ($inp.length) {
                        return parseInt($inp.val(), 10) || 0;
                    }
                    var $qtySmall = $row.find('.quantity .qty, td.quantity .qty, .item_cost .qty, .quantity');
                    if ($qtySmall.length) {
                        var t = $.trim($qtySmall.first().text()).replace(/[^\d\-]/g, '');
                        if (t !== '') { return parseInt(t, 10) || 0; }
                    }
                    return 0;
                }

                function getRowItemId($row) {
                    var $inp = $row.find('input.quantity[name^="order_item_qty"]');
                    if ($inp.length) {
                        var m = ($inp.attr('name') || '').match(/\[(\d+)\]/);
                        if (m) { return m[1]; }
                    }
                    var iid = $row.data('order_item_id') || $row.attr('data-order_item_id') || '';
                    return iid ? String(iid) : '';
                }

                function getRowName($row) {
                    var n = $.trim($row.find('.wc-order-item-name').first().text());
                    if (!n) { n = $.trim($row.find('.wc-order-item-name a').first().text()); }
                    if (!n) { n = $.trim($row.find('td.name').first().text()); }
                    return n || '?';
                }

                function getRowSku($row) {
                    var sku = $.trim($row.find('.wc-order-item-sku').text());
                    if (sku) {
                        var mm = sku.match(/([0-9A-Za-z\-]+)\s*$/);
                        if (mm) { sku = mm[1]; }
                    }
                    if (!sku) {
                        sku = $.trim($row.attr('data-sku') || $row.find('input.quantity').attr('data-sku') || '');
                    }
                    return sku;
                }

                /* ============================================================
                 * مبنای تعداد اولیهٔ هر ردیف
                 * ------------------------------------------------------------
                 * مبنا را در ویژگی data اختصاصی (mwpBaseQty) ذخیره می‌کنیم.
                 * فقط زمانی مبنا را ست می‌کنیم که قبلاً ست نشده باشد، تا
                 * بارگذاری مجدد جدول توسط ووکامرس مبنا را خراب نکند.
                 * هر ردیف با شناسهٔ آیتم خود در رجیستری سراسری هم نگه داشته
                 * می‌شود تا پس از reload، مبنای صحیح بازگردانی شود.
                 * ============================================================ */

                window.__mwpBaseRegistry = window.__mwpBaseRegistry || {};

                function captureBaseline() {
                    $(itemRowsSelector()).each(function () {
                        var $row = $(this);
                        if (!isProductRow($row)) { return; }

                        var iid = getRowItemId($row);
                        var qty = readRowQty($row);

                        // اگر این ردیف قبلاً در رجیستری مبنا دارد، همان را روی
                        // ردیف فعلی (که ممکن است پس از reload عنصر جدیدی باشد) ست کن.
                        if (iid && typeof window.__mwpBaseRegistry[iid] !== 'undefined') {
                            $row.attr('data-mwp-base', window.__mwpBaseRegistry[iid]);
                            return;
                        }

                        // ردیف تازه: مبنای فعلی را ثبت کن
                        if (typeof $row.attr('data-mwp-base') === 'undefined') {
                            $row.attr('data-mwp-base', qty);
                            if (iid) { window.__mwpBaseRegistry[iid] = qty; }
                        }
                    });
                }

                function getRowBase($row) {
                    var b = $row.attr('data-mwp-base');
                    if (typeof b !== 'undefined' && b !== '') {
                        return parseInt(b, 10) || 0;
                    }
                    var iid = getRowItemId($row);
                    if (iid && typeof window.__mwpBaseRegistry[iid] !== 'undefined') {
                        return parseInt(window.__mwpBaseRegistry[iid], 10) || 0;
                    }
                    return readRowQty($row);
                }

                // پس از ذخیرهٔ موفق، مبنای جدید = تعداد فعلی (تغییرات اعمال شد)
                function refreshBaselineAfterSave() {
                    window.__mwpBaseRegistry = {};
                    $(itemRowsSelector()).each(function () {
                        var $row = $(this);
                        if (!isProductRow($row)) { return; }
                        $row.removeAttr('data-mwp-base');
                    });
                    captureBaseline();
                }

                $(document).ready(function () {
                    setTimeout(captureBaseline, 50);
                });
                $(document.body).on(
                    'woocommerce_order_items_loaded wc_order_items_reloaded init_meta_boxes',
                    function () { setTimeout(captureBaseline, 60); }
                );
                // پس از ذخیرهٔ آیتم‌ها، مبنا را به‌روزرسانی کن
                $(document.body).on('items_saved order_items_meta_saved', function () {
                    setTimeout(refreshBaselineAfterSave, 120);
                });

                /* ============================================================
                 * حالت افزودن محصول (ADD)
                 * ------------------------------------------------------------
                 * به‌محض انتخاب محصول در دراپ‌داون جستجوی ووکامرس
                 * (select2:select روی .wc-product-search داخل مودال افزودن
                 * آیتم)، انتخاب را نگه می‌داریم، موجودی را می‌گیریم و مودال
                 * تاریخ انقضاء را باز می‌کنیم. محصول بلافاصله اضافه نمی‌شود؛
                 * پس از تأیید کاربر، تعداد نهایی محاسبه و محصول افزوده می‌شود.
                 * ============================================================ */

                // داده‌های انتخاب‌شده در select2 افزودن محصول
                $(document).on('select2:select', 'select.wc-product-search, .wc-product-search', function (e) {

                    // فقط داخل مودال «افزودن محصول» ووکامرس عمل کن
                    var $sel = $(this);
                    if (!$sel.closest('.wc-backbone-modal').length) {
                        return; // این select2 مربوط به افزودن آیتم نیست
                    }

                    var data = (e.params && e.params.data) ? e.params.data : null;
                    if (!data || !data.id) { return; }

                    // شناسهٔ محصول/متغیر و متن (شامل SKU احتمالی)
                    var pid = String(data.id);
                    var label = data.text || '';

                    // اگر product_id به‌صورت "productId" یا "productId-variationId" است
                    var realId = pid.split('-')[0];

                    S.pendingAdd = {
                        productId: realId,
                        rawId: pid,
                        label: label,
                        $select: $sel
                    };

                    openAddModal();
                });

                function openAddModal() {
                    S.mode = 'add';
                    S.changes = [{
                        type: 'add',
                        itemId: '',
                        productId: S.pendingAdd.productId,
                        name: S.pendingAdd.label || I18N.addProduct,
                        sku: '',                // از سرور بر اساس product_id کشف می‌شود
                        oldQty: 0,
                        newQty: 0,                 // پس از انتخاب کاربر تعیین می‌شود
                        need: 0                  // در حالت add مجموع انتخاب آزاد است
                    }];

                    prepareModalShell(I18N.titleAdd, I18N.confirmAdd);
                    buildAddPane(0);
                    $('#mwp-ov, #mwp-modal').show();
                }

                /* ============================================================
                 * حالت تغییر تعداد (EDIT)
                 * ------------------------------------------------------------
                 * با تغییر مقدار فیلد تعداد یک ردیف موجود، تفاوت نسبت به مبنا
                 * محاسبه می‌شود. افزایش → مودال انتخاب تاریخ برای کسر از انبار.
                 * کاهش → مودال اطلاع‌رسانی (برگشت خودکار با تاریخ متای آیتم).
                 * ============================================================ */

                // برای جلوگیری از باز شدن چندبارهٔ مودال هنگام تایپ، از change استفاده می‌کنیم
                // S.suppressQtyChange : وقتی true باشد، تغییر تعداد برنامه‌ریزی‌شده (توسط
                // خود کد، نه کاربر) نادیده گرفته می‌شود تا مودال مزاحم باز نشود.
                $(document).on('change', 'input.quantity[name^="order_item_qty"]', function () {

                    if (S.suppressQtyChange) { return; }

                    var $inp = $(this);
                    var $row = $inp.closest('tr');

                    if (!isProductRow($row)) { return; }

                    var oldQty = getRowBase($row);
                    var newQty = parseInt($inp.val(), 10);
                    if (isNaN(newQty)) { return; }

                    // حذف (تعداد صفر) → بدون مودال؛ سمت سرور خودکار برگشت می‌خورد
                    if (newQty <= 0) { return; }

                    if (newQty === oldQty) { return; } // تغییری نیست

                    var iid = getRowItemId($row);
                    var name = getRowName($row);
                    var sku = getRowSku($row);

                    if (newQty > oldQty) {
                        S.mode = 'edit';
                        S.pendingEdit = { $row: $row, $input: $inp, oldQty: oldQty, newQty: newQty };
                        S.changes = [{
                            type: 'increase',
                            itemId: iid,
                            name: name,
                            sku: sku,
                            oldQty: oldQty,
                            newQty: newQty,
                            need: newQty - oldQty
                        }];
                        openEditModal('increase');
                    } else {
                        // کاهش تعداد → اطلاع‌رسانی، برگشت خودکار
                        S.mode = 'edit';
                        S.pendingEdit = { $row: $row, $input: $inp, oldQty: oldQty, newQty: newQty };
                        S.changes = [{
                            type: 'decrease',
                            itemId: iid,
                            name: name,
                            sku: sku,
                            oldQty: oldQty,
                            newQty: newQty,
                            need: 0
                        }];
                        openEditModal('decrease');
                    }
                });

                function openEditModal(kind) {
                    prepareModalShell(I18N.titleEdit, I18N.confirmSave);

                    if (kind === 'decrease') {
                        buildReturnPane(0, S.changes[0]);
                    } else {
                        buildIncreasePane(0);
                    }
                    $('#mwp-ov, #mwp-modal').show();
                }

                /* ============================================================
                 * پوستهٔ مشترک مودال
                 * ============================================================ */
                function prepareModalShell(title, okLabel) {
                    $('#mwp-err').hide();
                    $('#mwp-err-txt').text('');
                    $('#mwp-retry').hide();
                    $('#mwp-tabs').empty().hide();
                    $('#mwp-panes').empty();
                    $('#mwp-ok').prop('disabled', true).text(okLabel);
                    $('#mwp-title').text(title);
                }

                function closeModal(restore) {
                    $('#mwp-ov, #mwp-modal').hide();

                    // اگر در حالت ویرایش لغو شد، مقدار فیلد را به مبنا برگردان
                    if (restore && S.mode === 'edit' && S.pendingEdit && S.pendingEdit.$input) {
                        S.pendingEdit.$input.val(S.pendingEdit.oldQty).trigger('input');
                    }

                    // اگر در حالت افزودن لغو شد، انتخاب select2 را پاک کن
                    if (restore && S.mode === 'add' && S.pendingAdd && S.pendingAdd.$select) {
                        try {
                            S.pendingAdd.$select.val(null).trigger('change');
                        } catch (err) { }
                    }

                    S.changes = [];
                    S.pendingAdd = null;
                    S.pendingEdit = null;
                }

                $('#mwp-x, #mwp-ov, #mwp-cancel').on('click', function () { closeModal(true); });

                /* ============================================================
                 * پنل افزودن محصول (ADD) — مجموع انتخاب = تعداد نهایی محصول
                 * ============================================================ */
                function buildAddPane(idx) {
                    var $pane = $('<div>', { class: 'mwp-tab-pane active', id: 'mwp-pane-' + idx, 'data-idx': idx });
                    $('#mwp-panes').append($pane);
                    $pane.append(
                        $('<div>', { class: 'mwp-load' }).html(
                            '<span class="spinner is-active" style="float:none;vertical-align:middle;display:inline-block;"></span> ' +
                            escHtml(I18N.loading)
                        )
                    );
                    loadStock(idx);
                }

                /* ============================================================
                 * پنل افزایش تعداد (INCREASE) — مجموع باید = need باشد
                 * ============================================================ */
                function buildIncreasePane(idx) {
                    var $pane = $('<div>', { class: 'mwp-tab-pane active', id: 'mwp-pane-' + idx, 'data-idx': idx });
                    $('#mwp-panes').append($pane);
                    $pane.append(
                        $('<div>', { class: 'mwp-load' }).html(
                            '<span class="spinner is-active" style="float:none;vertical-align:middle;display:inline-block;"></span> ' +
                            escHtml(I18N.loading)
                        )
                    );
                    loadStock(idx);
                }

                /* ============================================================
                 * پنل برگشت به انبار (DECREASE)
                 * ------------------------------------------------------------
                 * تاریخ‌هایی که قبلاً برای این آیتم کسر شده‌اند را از سرور
                 * می‌گیرد و کاربر مشخص می‌کند چه مقدار از کدام تاریخ به انبار
                 * برگردد. مجموع برگشت باید برابر مقدار کاهش باشد.
                 * ============================================================ */
                function buildReturnPane(idx, chg) {
                    var $pane = $('<div>', { class: 'mwp-tab-pane active', id: 'mwp-pane-' + idx, 'data-idx': idx });
                    $('#mwp-panes').append($pane);
                    $pane.append(
                        $('<div>', { class: 'mwp-load' }).html(
                            '<span class="spinner is-active" style="float:none;vertical-align:middle;display:inline-block;"></span> ' +
                            escHtml(I18N.loading)
                        )
                    );

                    $.ajax({
                        url: AJAX_URL,
                        method: 'POST',
                        data: {
                            action: 'mahamsoft_get_item_expiry',
                            item_id: chg.itemId || 0,
                            _wpnonce: NONCE
                        },
                        success: function (resp) {
                            if (!resp.success || !resp.data || !resp.data.items || !resp.data.items.length) {
                                // داده‌ای نبود → برگشت ساده بدون انتخاب تاریخ
                                buildSimpleReturnPane(idx, chg);
                                return;
                            }
                            buildReturnTable(idx, chg, resp.data.items);
                        },
                        error: function () {
                            buildSimpleReturnPane(idx, chg);
                        }
                    });
                }

                // حالت پشتیبان: اگر توزیع تاریخ ثبت نشده باشد
                function buildSimpleReturnPane(idx, chg) {
                    var $pane = $('#mwp-pane-' + idx);
                    $pane.empty();
                    var returnQty = chg.oldQty - chg.newQty;
                    var $diff = $('<div>', { class: 'mwp-diff mwp-diff-remove' });
                    $diff.html(
                        '<strong>' + escHtml(chg.name) + '</strong><br>' +
                        escHtml(I18N.qtyBefore) + ': <strong>' + chg.oldQty + '</strong> &nbsp;|&nbsp; ' +
                        escHtml(I18N.qtyAfter) + ': <strong>' + chg.newQty + '</strong> &nbsp;|&nbsp; ' +
                        escHtml(I18N.returnToWh) + ': <strong>' + returnQty + '</strong><br><br>' +
                        escHtml(I18N.returnNotice)
                    );
                    $pane.append($diff);
                    // در این حالت انتخابی نیست؛ تأیید فعال است
                    S.changes[idx]._simpleReturn = true;
                    $('#mwp-ok').prop('disabled', false);
                }

                // جدول انتخاب مقدار برگشت از هر تاریخ کسرشده
                function buildReturnTable(idx, chg, items) {
                    var $pane = $('#mwp-pane-' + idx);
                    $pane.empty();

                    var returnQty = chg.oldQty - chg.newQty;
                    chg.need = returnQty;          // هدف: مجموع برگشت = مقدار کاهش
                    chg._simpleReturn = false;

                    var $diff = $('<div>', { class: 'mwp-diff mwp-diff-remove' });
                    $diff.html(
                        '<strong>' + escHtml(chg.name) + '</strong> — ' + escHtml(I18N.decrease) + '<br>' +
                        escHtml(I18N.qtyBefore) + ': <strong>' + chg.oldQty + '</strong> &nbsp;|&nbsp; ' +
                        escHtml(I18N.qtyAfter) + ': <strong>' + chg.newQty + '</strong> &nbsp;|&nbsp; ' +
                        escHtml(I18N.returnToWh) + ': <strong>' + returnQty + '</strong>'
                    );
                    $pane.append($diff);
                    $pane.append($('<p>', { style: 'margin-top:0;color:#555;font-size:13px;' }).text(I18N.fillReturn));

                    var $tbl = $('<table>', { class: 'mwp-tbl' });
                    $tbl.append(
                        '<thead><tr>' +
                        '<th>' + escHtml(I18N.expiry) + '</th>' +
                        '<th>' + escHtml(I18N.deductedQty) + '</th>' +
                        '<th>' + escHtml(I18N.returnQty) + '</th>' +
                        '</tr></thead>'
                    );

                    var $tb = $('<tbody>');
                    items.forEach(function (row) {
                        var avail = parseInt(row.qty, 10) || 0;
                        var $tr = $('<tr>').data({ expiry: row.expiry, maxQty: avail });
                        $tr.append($('<td>').text(row.expiry));
                        $tr.append($('<td>').append($('<span>', { class: 'mwp-badge' }).text(avail.toLocaleString())));
                        var $inp = $('<input>', { type: 'number', min: 0, max: avail, value: 0, class: 'mwp-qin', placeholder: '0' });
                        var $err = $('<div>', { class: 'mwp-qerr' }).text(I18N.overMax);
                        $tr.append($('<td>').append($inp).append($err));
                        $tb.append($tr);
                    });

                    $tb.append(
                        '<tr class="mwp-sum-row"><td colspan="2">' + escHtml(I18N.sumReturn) +
                        ' <span class="mwp-need-hint">(' + escHtml(I18N.ofTotal) + ' ' + returnQty + ')</span></td>' +
                        '<td class="mwp-pane-total">0</td></tr>'
                    );
                    $tbl.append($tb);
                    $pane.append($tbl);

                    $tb.on('input change', '.mwp-qin', function () {
                        var val = parseInt($(this).val(), 10) || 0;
                        var max = parseInt($(this).attr('max'), 10);
                        $(this).siblings('.mwp-qerr').toggle(val > max);
                        recalcReturn(idx, chg);
                    });

                    recalcReturn(idx, chg);
                }

                // اعتبارسنجی برگشت: مجموع باید = مقدار کاهش، و هیچ ردیف بیش از کسرشده نباشد
                function recalcReturn(idx, chg) {
                    var $pane = $('#mwp-pane-' + idx);
                    var total = 0, hasErr = false;
                    $pane.find('.mwp-qin').each(function () {
                        var v = parseInt($(this).val(), 10) || 0;
                        var m = parseInt($(this).attr('max'), 10);
                        total += v;
                        if (v > m) { hasErr = true; }
                    });
                    $pane.find('.mwp-pane-total').text(total);
                    var valid = (total === chg.need && total > 0 && !hasErr);
                    var $hint = $pane.find('.mwp-need-hint');
                    $hint.css('color', valid ? '#276624' : '#c00');
                    $('#mwp-ok').prop('disabled', !valid);
                }

                /* ============================================================
                 * دریافت موجودی انبار از سرور (برای add / increase)
                 * ============================================================ */
                function loadStock(idx) {
                    var chg = S.changes[idx];
                    var sku = chg.sku || '';
                    var pid = chg.productId || 0;
                    var iid = chg.itemId || 0;
                    var cacheKey = sku ? ('sku:' + sku) : ('pid:' + pid + ':iid:' + iid);

                    if (S.stockCache[cacheKey]) {
                        buildStockPane(idx, S.stockCache[cacheKey]);
                        return;
                    }

                    $.ajax({
                        url: AJAX_URL,
                        method: 'POST',
                        data: {
                            action: 'mahamsoft_get_quantity',
                            sku: sku,
                            product_id: pid,
                            item_id: iid,
                            _wpnonce: NONCE
                        },
                        success: function (resp) {
                            if (!resp.success || !resp.data || !resp.data.items || !resp.data.items.length) {
                                var msg = (resp.data && resp.data.message) ? resp.data.message : I18N.noStock;
                                var detail = (resp.data && resp.data.detail) ? '\n\n' + I18N.detail + resp.data.detail : '';
                                showPaneError(idx, msg + detail, true);
                                return;
                            }
                            // اگر سرور SKU کشف‌شده را برگرداند، روی تغییر ثبت کن
                            if (resp.data.sku) { S.changes[idx].sku = resp.data.sku; }
                            S.stockCache[cacheKey] = resp.data.items;
                            buildStockPane(idx, resp.data.items);
                        },
                        error: function (xhr, status, err) {
                            showPaneError(idx, I18N.errLoad + '\nHTTP ' + xhr.status + ': ' + (err || status), true);
                        }
                    });
                }

                function showPaneError(idx, msg, showRetry) {
                    var $pane = $('#mwp-pane-' + idx);
                    $pane.find('.mwp-load').hide();
                    $pane.find('.mwp-pane-err').remove();

                    var $err = $('<div>', {
                        class: 'mwp-pane-err',
                        style: 'background:#fef0f0;color:#c00;border:1px solid #f5c6cb;border-radius:4px;padding:9px 13px;font-size:13px;white-space:pre-wrap;'
                    }).text(msg);

                    if (showRetry) {
                        $('<button>', {
                            type: 'button',
                            class: 'mwp-retry-pane',
                            style: 'display:block;margin-top:8px;background:#e87722;color:#fff;border:none;padding:5px 12px;border-radius:4px;cursor:pointer;',
                            'data-idx': idx
                        }).text(I18N.retry).appendTo($err);
                    }

                    $pane.append($err);
                    recalcAndValidate();
                }

                $(document).on('click', '.mwp-retry-pane', function () {
                    var idx = parseInt($(this).data('idx'), 10);
                    var chg = S.changes[idx];
                    var cacheKey = chg.sku ? ('sku:' + chg.sku) : ('pid:' + (chg.productId || chg.itemId || 0));
                    delete S.stockCache[cacheKey];
                    $(this).closest('.mwp-pane-err').remove();
                    $('#mwp-pane-' + idx).html(
                        '<div class="mwp-load"><span class="spinner is-active" style="float:none;vertical-align:middle;display:inline-block;"></span> ' +
                        escHtml(I18N.loading) + '</div>'
                    );
                    loadStock(idx);
                });

                /* ============================================================
                 * ساخت محتوای پنل انتخاب تاریخ (برای add / increase)
                 * ============================================================ */
                function buildStockPane(idx, items) {
                    var chg = S.changes[idx];
                    var $pane = $('#mwp-pane-' + idx);
                    $pane.empty();

                    var isAdd = (chg.type === 'add');
                    var typeLabel = isAdd ? I18N.addProduct : I18N.increase;

                    var $diff = $('<div>', { class: 'mwp-diff' });
                    if (isAdd) {
                        $diff.html(
                            '<strong>' + escHtml(chg.name) + '</strong> — ' + escHtml(typeLabel) + '<br>' +
                            escHtml(I18N.fillRows)
                        );
                    } else {
                        // مورد ۳: متن «تعداد موردنیاز برای کسر از انبار» نمایش داده نمی‌شود
                        $diff.html(
                            '<strong>' + escHtml(chg.name) + '</strong> — ' + escHtml(typeLabel) + '<br>' +
                            escHtml(I18N.qtyBefore) + ': <strong>' + chg.oldQty + '</strong> &nbsp;|&nbsp; ' +
                            escHtml(I18N.qtyAfter) + ': <strong>' + chg.newQty + '</strong>'
                        );
                    }
                    $pane.append($diff);

                    if (!isAdd) {
                        $pane.append($('<p>', { style: 'margin-top:0;color:#555;font-size:13px;' }).text(I18N.fillRows));
                    }

                    var $tbl = $('<table>', { class: 'mwp-tbl' });
                    $tbl.append(
                        '<thead><tr>' +
                        '<th>' + escHtml(I18N.expiry) + '</th>' +
                        '<th>' + escHtml(I18N.stock) + ' (' + escHtml(WH_NAME) + ')</th>' +
                        '<th>' + escHtml(I18N.chosenQty) + '</th>' +
                        '</tr></thead>'
                    );

                    var $tb = $('<tbody>');
                    items.forEach(function (row) {
                        var $tr = $('<tr>').data({ expiry: row.expirDate, maxQty: row.quantity });
                        $tr.append($('<td>').text(row.expirDate));
                        $tr.append($('<td>').append($('<span>', { class: 'mwp-badge' }).text(Number(row.quantity).toLocaleString())));

                        var $inp = $('<input>', { type: 'number', min: 0, max: row.quantity, value: 0, class: 'mwp-qin', placeholder: '0' });
                        var $err = $('<div>', { class: 'mwp-qerr' }).text(I18N.overMax);
                        $tr.append($('<td>').append($inp).append($err));
                        $tb.append($tr);
                    });

                    // ردیف جمع: مجموع واردشده و مقدار موردنیاز را نشان می‌دهد
                    if (isAdd) {
                        $tb.append(
                            '<tr class="mwp-sum-row"><td colspan="2">' + escHtml(I18N.sumTotal) + '</td>' +
                            '<td class="mwp-pane-total">0</td></tr>'
                        );
                    } else {
                        $tb.append(
                            '<tr class="mwp-sum-row"><td colspan="2">' + escHtml(I18N.sumTotal) +
                            ' <span class="mwp-need-hint">(' + escHtml(I18N.ofTotal) + ' ' + chg.need + ')</span></td>' +
                            '<td class="mwp-pane-total">0</td></tr>'
                        );
                    }
                    $tbl.append($tb);
                    $pane.append($tbl);

                    $tb.on('input change', '.mwp-qin', function () {
                        var val = parseInt($(this).val(), 10) || 0;
                        var max = parseInt($(this).attr('max'), 10);
                        $(this).siblings('.mwp-qerr').toggle(val > max);
                        recalcAndValidate();
                    });

                    recalcAndValidate();
                }

                /* ============================================================
                 * اعتبارسنجی
                 * ------------------------------------------------------------
                 * add      : مجموع > 0 و هیچ ردیفی بیش از موجودی نباشد
                 * increase : مجموع = need و هیچ ردیفی بیش از موجودی نباشد
                 * decrease : همیشه معتبر
                 * ============================================================ */
                function recalcAndValidate() {
                    var chg = S.changes[0];
                    if (!chg) { $('#mwp-ok').prop('disabled', true); return; }

                    if (chg.type === 'decrease') {
                        $('#mwp-ok').prop('disabled', false);
                        return;
                    }

                    var $pane = $('#mwp-pane-0');
                    if ($pane.find('.mwp-load:visible').length || $pane.find('.mwp-pane-err').length) {
                        $('#mwp-ok').prop('disabled', true);
                        return;
                    }

                    var total = 0;
                    var hasErr = false;
                    $pane.find('.mwp-qin').each(function () {
                        var v = parseInt($(this).val(), 10) || 0;
                        var m = parseInt($(this).attr('max'), 10);
                        total += v;
                        if (v > m) { hasErr = true; }
                    });

                    $pane.find('.mwp-pane-total').text(total);

                    var valid;
                    if (chg.type === 'add') {
                        // افزودن: هر مجموع مثبت معتبر است (تعداد نهایی محصول)
                        valid = (total > 0 && !hasErr);
                    } else {
                        // افزایش: مجموع انتخاب‌شده باید برابر مقدار افزایش (need) باشد
                        valid = (total === chg.need && total > 0 && !hasErr);
                        // نشانگر زنده: سبز وقتی برابر شد، قرمز در غیر این صورت
                        var $hint = $pane.find('.mwp-need-hint');
                        if (valid) {
                            $hint.css('color', '#276624');
                        } else {
                            $hint.css('color', '#c00');
                        }
                    }

                    $('#mwp-ok').prop('disabled', !valid);
                }

                /* ============================================================
                 * جمع‌آوری انتخاب‌های تاریخ از پنل
                 * ============================================================ */
                function collectSelections(idx) {
                    var sels = [];
                    $('#mwp-pane-' + idx + ' tbody tr:not(.mwp-sum-row)').each(function () {
                        var qty = parseInt($(this).find('.mwp-qin').val(), 10) || 0;
                        var exp = $(this).data('expiry');
                        if (qty > 0) { sels.push({ expiry: exp, qty: qty }); }
                    });
                    return sels;
                }

                function sumSelections(sels) {
                    var t = 0;
                    sels.forEach(function (s) { t += parseInt(s.qty, 10) || 0; });
                    return t;
                }

                /* ============================================================
                 * تأیید مودال
                 * ============================================================ */
                $('#mwp-ok').on('click', function () {
                    if ($(this).prop('disabled')) { return; }

                    var chg = S.changes[0];
                    if (!chg) { closeModal(false); return; }

                    if (S.mode === 'add') {
                        confirmAdd(chg);
                    } else {
                        confirmEdit(chg);
                    }
                });

                /*
                 * تأیید افزودن محصول:
                 * 1) انتخاب‌های تاریخ و مجموع تعداد را می‌گیریم.
                 * 2) محصول را از طریق دکمهٔ افزودن مودال backbone ووکامرس اضافه
                 *    می‌کنیم (تا item_id واقعی ساخته شود).
                 * 3) پس از افزوده‌شدن، تعداد ردیف را روی مجموع ست کرده و
                 *    انتخاب‌ها را در input مخفی mwp_expiry_selections می‌نویسیم.
                 */
                function confirmAdd(chg) {
                    var sels = collectSelections(0);
                    var total = sumSelections(sels);
                    if (total <= 0) { return; }

                    var sku = chg.sku || '';

                    // شناسهٔ آیتم‌های موجود قبل از افزودن (برای یافتن ردیف جدید)
                    var beforeIds = {};
                    $(itemRowsSelector()).each(function () {
                        var iid = getRowItemId($(this));
                        if (iid) { beforeIds[iid] = true; }
                    });

                    $('#mwp-ov, #mwp-modal').hide();

                    // دکمهٔ «افزودن» داخل مودال backbone ووکامرس را پیدا و کلیک کن.
                    var $addBtn = $('.wc-backbone-modal #btn-ok, .wc-backbone-modal button#btn-ok').first();
                    if ($addBtn.length) {
                        $addBtn.trigger('click');
                    } else {
                        $('.wc-backbone-modal .wc-backbone-modal-main button.button-primary').last().trigger('click');
                    }

                    // پس از بارگذاری مجدد آیتم‌ها، ردیف جدید را پیدا کن و تعداد را ست کن
                    var attempts = 0;
                    var timer = setInterval(function () {
                        attempts++;
                        var $newRow = null;
                        $(itemRowsSelector()).each(function () {
                            var $row = $(this);
                            if (!isProductRow($row)) { return; }
                            var iid = getRowItemId($row);
                            if (iid && !beforeIds[iid]) { $newRow = $row; }
                        });

                        if ($newRow) {
                            clearInterval(timer);
                            var newIid = getRowItemId($newRow);

                            // عملیات انبار را برای ذخیرهٔ نهایی ثبت کن (قبل از هر reload)
                            writeSelectionInput(newIid || ('new_0'), {
                                type: 'add',
                                sku: pendingAddSku(sku),
                                itemId: newIid || 0,
                                oldQty: 0,
                                newQty: total,
                                selections: sels
                            });

                            // مبنای این ردیف جدید = total
                            $newRow.attr('data-mwp-base', total);
                            if (newIid) { window.__mwpBaseRegistry[newIid] = total; }

                            // تعداد ردیف جدید را روی مجموع انتخاب‌شده ست کن و با رویداد
                            // change (در حالت suppress تا مودال باز نشود) ووکامرس را وادار
                            // به بازمحاسبهٔ مبالغ و نمایش درست تعداد می‌کنیم.
                            var $qin = $newRow.find('input.quantity[name^="order_item_qty"]');
                            if ($qin.length) {
                                S.suppressQtyChange = true;
                                $qin.val(total);
                                $qin.attr('value', total);
                                $newRow.find('.quantity .qty, td.quantity .qty').first().text(total);
                                $qin.trigger('change');

                                // ذخیرهٔ تعداد در سرور تا نمایش پس از reload هم درست بماند.
                                setTimeout(function () {
                                    ensureSavedQty(newIid, total, 0);
                                }, 200);
                            }

                            S.changes = [];
                            S.pendingAdd = null;
                        } else if (attempts > 40) {
                            clearInterval(timer);
                            S.suppressQtyChange = false;
                            S.changes = [];
                            S.pendingAdd = null;
                        }
                    }, 150);
                }

                /*
                 * اطمینان از ذخیره‌شدن تعداد ردیف در سرور.
                 * دکمهٔ ذخیرهٔ آیتم‌ها را می‌زند و پس از reload بررسی می‌کند که
                 * تعداد همان مقدار هدف باشد؛ در غیر این صورت تا چند بار تلاش
                 * می‌کند (با محدودیت، برای جلوگیری از حلقهٔ بی‌نهایت).
                 */
                function ensureSavedQty(itemId, targetQty, tries) {
                    if (tries > 3) { S.suppressQtyChange = false; return; }

                    triggerSaveItems();

                    setTimeout(function () {
                        var $row = findRowByItemId(itemId);
                        var cur = $row ? readRowQty($row) : targetQty;
                        if (cur !== targetQty && $row) {
                            var $qin = $row.find('input.quantity[name^="order_item_qty"]');
                            if ($qin.length) {
                                $qin.val(targetQty).attr('value', targetQty).trigger('change');
                            }
                            $row.attr('data-mwp-base', targetQty);
                            if (itemId) { window.__mwpBaseRegistry[itemId] = targetQty; }
                            ensureSavedQty(itemId, targetQty, tries + 1);
                        } else {
                            S.suppressQtyChange = false;
                        }
                    }, 700);
                }

                // یافتن ردیف بر اساس شناسهٔ آیتم
                function findRowByItemId(itemId) {
                    var found = null;
                    if (!itemId) { return null; }
                    $(itemRowsSelector()).each(function () {
                        if (getRowItemId($(this)) === String(itemId)) { found = $(this); }
                    });
                    return found;
                }

                // SKU نهایی برای ثبت (در صورت خالی بودن، از تغییر جاری خوانده می‌شود)
                function pendingAddSku(sku) {
                    if (sku) { return sku; }
                    if (S.changes[0] && S.changes[0].sku) { return S.changes[0].sku; }
                    return '';
                }

                // ذخیرهٔ آیتم‌های سفارش از طریق دکمهٔ استاندارد ووکامرس
                function triggerSaveItems() {
                    var $btn = $('button.save_order_items, button.save-action').first();
                    if ($btn.length) {
                        $btn.trigger('click');
                    }
                }

                /*
                 * تأیید تغییر تعداد:
                 * انتخاب‌ها (برای افزایش) را در input مخفی می‌نویسیم. مقدار فیلد
                 * تعداد همان newQty است که کاربر وارد کرده و دست‌نخورده می‌ماند.
                 * سپس مودال بسته می‌شود؛ ذخیرهٔ نهایی با دکمهٔ «بروزرسانی» سفارش
                 * توسط خود ووکامرس انجام می‌گیرد و سمت سرور selections خوانده
                 * می‌شود.
                 */
                function confirmEdit(chg) {
                    var key = chg.itemId ? String(chg.itemId) : ('edit_0');

                    if (chg.type === 'increase') {
                        var sels = collectSelections(0);
                        writeSelectionInput(key, {
                            type: 'increase',
                            sku: chg.sku,
                            itemId: chg.itemId || 0,
                            oldQty: chg.oldQty,
                            newQty: chg.newQty,
                            selections: sels
                        });
                    } else {
                        // decrease — تاریخ‌ها و تعداد انتخاب‌شده برای برگشت به انبار
                        var returns = chg._simpleReturn ? [] : collectSelections(0);
                        writeSelectionInput(key, {
                            type: 'decrease',
                            sku: chg.sku,
                            itemId: chg.itemId || 0,
                            oldQty: chg.oldQty,
                            newQty: chg.newQty,
                            returns: returns
                        });
                    }

                    // مبنای ردیف را به مقدار جدید به‌روزرسانی کن
                    if (S.pendingEdit && S.pendingEdit.$row) {
                        S.pendingEdit.$row.attr('data-mwp-base', chg.newQty);
                        if (chg.itemId) { window.__mwpBaseRegistry[chg.itemId] = chg.newQty; }
                    }

                    $('#mwp-ov, #mwp-modal').hide();
                    S.changes = [];
                    S.pendingEdit = null;
                }

                /*
                 * نوشتن/به‌روزرسانی یک ورودی در input مخفی mwp_expiry_selections.
                 * این input یک شیء JSON است که کلید آن شناسهٔ آیتم (یا کلید موقت)
                 * و مقدار آن جزئیات تغییر است. هنگام ذخیرهٔ سفارش، ووکامرس این
                 * فیلد را همراه فرم ارسال می‌کند و سمت سرور آن را می‌خواند.
                 */
                function writeSelectionInput(key, obj) {
                    var $inp = $('input[name="mwp_expiry_selections"]').first();
                    var data = {};
                    if ($inp.length) {
                        try { data = JSON.parse($inp.val() || '{}') || {}; } catch (e) { data = {}; }
                    } else {
                        $inp = $('<input>', { type: 'hidden', name: 'mwp_expiry_selections', value: '{}' });
                        // مهم: input را در فرم اصلی سفارش قرار می‌دهیم (نه ناحیهٔ آیتم‌ها)
                        // تا با بارگذاری مجدد جدول آیتم‌ها (AJAX) پاک نشود و هنگام
                        // submit دکمهٔ اصلی «ایجاد/به‌روزرسانی سفارش» ارسال شود.
                        var $target = $('form#post, form#order, form.edit-order, #woocommerce-order-data').first();
                        if (!$target.length) { $target = $('body'); }
                        $inp.appendTo($target);
                    }
                    // شناسهٔ یکتا برای جلوگیری از اعمال دوبارهٔ همین تغییر در سرور
                    if (!obj.token) {
                        obj.token = 'mwp_' + Date.now() + '_' + Math.floor(Math.random() * 1e6);
                    }
                    data[key] = obj;
                    $inp.val(JSON.stringify(data));
                }

                /* ============================================================
                 * مودال تست اتصال (در صفحه تنظیمات)
                 * ============================================================ */
                $(document).on('click', '#mwp-test-conn-btn', function () {
                    var $btn = $(this);
                    var btnNonce = $btn.data('nonce') || NONCE;

                    $btn.prop('disabled', true).text(I18N.testing);

                    $.ajax({
                        url: AJAX_URL,
                        method: 'POST',
                        data: { action: 'mahamsoft_test_connection', _wpnonce: btnNonce },
                        success: function (resp) {
                            $btn.prop('disabled', false).text(I18N.testBtn);

                            var html = '';
                            if (resp.success && resp.data) {
                                var d = resp.data;
                                var ok = d.status === 0 || d.status === '0';
                                var c = ok ? '#276624' : '#c00';
                                html = '<div style="color:' + c + ';font-size:15px;font-weight:700;margin-bottom:10px;">' + (ok ? '✅' : '❌') + ' ' + escHtml(d.message || '') + '</div>';
                                html += '<table style="width:100%;font-size:13px;border-collapse:collapse;">';
                                html += '<tr><td style="padding:5px 8px;border-bottom:1px solid #eee;color:#666;">' + escHtml(I18N.statusLbl) + '</td><td style="padding:5px 8px;border-bottom:1px solid #eee;font-weight:600;">' + escHtml(String(d.status)) + '</td></tr>';
                                if (d.message) {
                                    html += '<tr><td style="padding:5px 8px;border-bottom:1px solid #eee;color:#666;">' + escHtml(I18N.messageLbl) + '</td><td style="padding:5px 8px;border-bottom:1px solid #eee;">' + escHtml(d.message) + '</td></tr>';
                                }
                                if (d.error) {
                                    html += '<tr><td style="padding:5px 8px;color:#666;">' + escHtml(I18N.errorLbl) + '</td><td style="padding:5px 8px;color:#c00;">' + escHtml(JSON.stringify(d.error)) + '</td></tr>';
                                }
                                html += '</table>';
                            } else {
                                html = '<div style="color:#c00;">❌ ' + escHtml(I18N.errorLbl) + (resp.data && resp.data.message ? ': ' + escHtml(resp.data.message) : '') + '</div>';
                            }

                            $('#mwp-cr').html(html);
                            $('#mwp-co, #mwp-cm').show();
                        },
                        error: function (xhr) {
                            $btn.prop('disabled', false).text(I18N.testBtn);
                            $('#mwp-cr').html('<div style="color:#c00;">❌ HTTP ' + xhr.status + '</div>');
                            $('#mwp-co, #mwp-cm').show();
                        }
                    });
                });

                $('#mwp-cx, #mwp-co, #mwp-cc').on('click', function () {
                    $('#mwp-co, #mwp-cm').hide();
                });

            })(jQuery);
        </script>
        <?php
    }

    /* ====================================================================
     * دکمه تست اتصال در صفحه تنظیمات پلاگین اصلی
     * ==================================================================== */

    public function render_connection_test_button()
    {
        $nonce = wp_create_nonce('mahamsoft_pharmacy_nonce');
        ?>
        <hr>
        <h2><?php esc_html_e('تست اتصال به سرور انبار', 'mahamsoft-order-edit'); ?></h2>
        <p style="color:#666;font-size:13px;">
            <?php esc_html_e('اتصال با اطلاعات ذخیره‌شده در بخش "تنظیمات اتصال به وب سرویس انبار" بالا تست می‌شود.', 'mahamsoft-order-edit'); ?>
        </p>
        <button type="button" id="mwp-test-conn-btn" class="button button-secondary" data-nonce="<?php echo esc_attr($nonce); ?>">
            <?php esc_html_e('تست اتصال به سرور', 'mahamsoft-order-edit'); ?>
        </button>
        <?php
    }

    /* ====================================================================
     * مورد ۶: نمایش توزیع تاریخ انقضاء زیر نام محصول (رنگ قرمز)
     * ==================================================================== */

    /**
     * زیر هر آیتم محصول در ادیتور سفارش، توزیع تاریخ‌های انقضاء را
     * (در صورت وجود متای _mwp_expiry_breakdown) با رنگ قرمز نمایش می‌دهد.
     *
     * @param int           $item_id شناسهٔ آیتم سفارش
     * @param WC_Order_Item $item    آیتم
     * @param WC_Product|null $product محصول (ممکن است null باشد)
     */
    public function render_expiry_breakdown_under_item($item_id, $item, $product = null)
    {

        if (!$item instanceof WC_Order_Item_Product) {
            return;
        }

        $breakdown = wc_get_order_item_meta($item_id, '_mwp_expiry_breakdown', true);

        if (empty($breakdown)) {
            return;
        }

        printf(
            '<div class="mwp-expiry-breakdown" style="color:#c00;font-size:12px;margin-top:4px;line-height:1.7;">%s</div>',
            esc_html($breakdown)
        );
    }

    /* ====================================================================
     * AJAX: دریافت موجودی انبار به تفکیک تاریخ انقضا
     * ==================================================================== */

    public function ajax_get_quantity()
    {

        check_ajax_referer('mahamsoft_pharmacy_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('دسترسی غیرمجاز.', 'mahamsoft-order-edit')));
        }

        $sku = isset($_POST['sku']) ? sanitize_text_field(wp_unslash($_POST['sku'])) : ''; // phpcs:ignore
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0; // phpcs:ignore
        $item_id = isset($_POST['item_id']) ? absint($_POST['item_id']) : 0; // phpcs:ignore

        // اگر SKU مستقیماً ارسال نشده، ابتدا از روی شناسهٔ آیتم سفارش
        // (در حالت ویرایش) و سپس از روی product_id (در حالت افزودن) بخوان.
        if (empty($sku) && $item_id) {
            $item = $this->get_order_item_by_id($item_id);
            if ($item instanceof WC_Order_Item_Product) {
                $p = $item->get_product();
                if ($p) {
                    $sku = $p->get_sku();
                }
            }
        }

        if (empty($sku) && $product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                $sku = $product->get_sku();
            } else {
                // شاید product_id در واقع شناسهٔ آیتم سفارش باشد
                $item = $this->get_order_item_by_id($product_id);
                if ($item instanceof WC_Order_Item_Product) {
                    $p = $item->get_product();
                    if ($p) {
                        $sku = $p->get_sku();
                    }
                }
            }
        }

        // اگر هنوز SKU نداریم، از مقدار پیش‌فرض تنظیمات استفاده کن (موقت)
        $code_melli = !empty($sku) ? $sku : $this->cfg('api_default_code_melli', '');

        if (empty($code_melli)) {
            wp_send_json_error(array('message' => __('این محصول کد ملی دارو (SKU) ندارد.', 'mahamsoft-order-edit')));
        }

        $conn = $this->api_connect();
        if (is_wp_error($conn)) {
            wp_send_json_error(array('message' => __('خطا در اتصال به انبار.', 'mahamsoft-order-edit'), 'detail' => $conn->get_error_message()));
        }

        $data = $this->call('/Anbar/GetQuantity', array(
            'codeMelliDaroo' => $code_melli,
            'anbarId' => $this->cfg('api_anbar_id', ''),
        ));

        if (is_wp_error($data)) {
            wp_send_json_error(array('message' => __('خطا در دریافت موجودی.', 'mahamsoft-order-edit'), 'detail' => $data->get_error_message()));
        }

        $items = isset($data['result']['darooBulksQuantities']) ? $data['result']['darooBulksQuantities'] : array();

        if (empty($items)) {
            wp_send_json_error(array('message' => __('موجودی انبار برای این محصول یافت نشد.', 'mahamsoft-order-edit')));
        }

        // مرتب‌سازی FIFO (قدیمی‌ترین تاریخ انقضا اول)
        usort($items, function ($a, $b) {
            return strcmp($a['expirDate'], $b['expirDate']);
        });

        // SKU کشف‌شده را هم برمی‌گردانیم تا سمت کلاینت روی تغییر ثبت شود.
        wp_send_json_success(array('items' => $items, 'sku' => $code_melli));
    }

    /* ====================================================================
     * AJAX: دریافت توزیع تاریخ‌های کسرشدهٔ یک آیتم (برای مودال کاهش)
     * ==================================================================== */

    /**
     * فهرست تاریخ‌هایی که قبلاً برای این آیتم از انبار کسر شده‌اند را
     * (به همراه تعداد هر تاریخ) برمی‌گرداند تا در مودال کاهش نمایش داده
     * شود و کاربر مشخص کند چه مقدار از کدام تاریخ به انبار برگردد.
     */
    public function ajax_get_item_expiry()
    {

        check_ajax_referer('mahamsoft_pharmacy_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('دسترسی غیرمجاز.', 'mahamsoft-order-edit')));
        }

        $item_id = isset($_POST['item_id']) ? absint($_POST['item_id']) : 0; // phpcs:ignore
        if (!$item_id) {
            wp_send_json_error(array('message' => __('آیتم نامعتبر.', 'mahamsoft-order-edit')));
        }

        $data = $this->get_expiry_data($item_id);

        // اگر داده‌ای ثبت نشده، از تاریخ تکیِ متای آیتم به‌عنوان fallback استفاده کن
        if (empty($data)) {
            $single_expiry = wc_get_order_item_meta($item_id, '_expiry_date', true);
            $item = $this->get_order_item_by_id($item_id);
            $qty = ($item instanceof WC_Order_Item_Product) ? (int) $item->get_quantity() : 0;
            if ($single_expiry && $qty > 0) {
                $data = array(array('expiry' => $single_expiry, 'qty' => $qty));
            }
        }

        wp_send_json_success(array('items' => $data));
    }


    public function ajax_test_connection()
    {

        check_ajax_referer('mahamsoft_pharmacy_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('دسترسی غیرمجاز.', 'mahamsoft-order-edit')));
        }

        delete_transient('mahamsoft_pharmacy_connection');

        $result = $this->call('/DataBase/Connection', array(
            'serverName' => $this->cfg('api_server_name', ''),
            'username' => $this->cfg('api_username', ''),
            'password' => $this->cfg('api_password', ''),
        ));

        if (is_wp_error($result)) {
            wp_send_json_success(array(
                'status' => 2,
                'message' => __('اتصال ناموفق', 'mahamsoft-order-edit'),
                'error' => $result->get_error_message(),
            ));
        }

        wp_send_json_success($result);
    }

    /* ====================================================================
     * ذخیره سفارش: کسر/اضافه موجودی بر اساس تغییرات
     * ==================================================================== */

    /**
     * مرحله ۱ — گرفتن snapshot از تعداد فعلی آیتم‌ها، درست قبل از اعمال
     * تغییرات. این متد هیچ فراخوانی APIی انجام نمی‌دهد؛ فقط وضعیت قبل را
     * در یک transient نگه می‌دارد تا در مرحلهٔ ذخیرهٔ نهایی سفارش با وضعیت
     * جدید مقایسه شود.
     *
     * @param int   $order_id شناسه سفارش
     * @param array $items    داده خام POST مربوط به آیتم‌ها
     */
    public function capture_qty_snapshot($order_id, $items)
    {

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $snapshot = array();

        foreach ($order->get_items() as $item_id => $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            $product = $item->get_product();
            $snapshot[(int) $item_id] = array(
                'qty' => (int) $item->get_quantity(),
                'sku' => $product ? $product->get_sku() : '',
                'expiry' => $item->get_meta('_expiry_date'),
            );
        }

        set_transient($this->qty_snapshot_key($order_id), $snapshot, 10 * MINUTE_IN_SECONDS);
    }

    /**
     * کلید transient مربوط به snapshot تعداد یک سفارش.
     *
     * @param int $order_id
     * @return string
     */
    private function qty_snapshot_key($order_id)
    {
        return 'mwp_qty_snapshot_' . absint($order_id);
    }

    /**
     * مرحله ۲ — پردازش کسر/اضافه/برگشت به انبار، فقط هنگام کلیک دکمهٔ اصلی
     * «ایجاد/به‌روزرسانی سفارش» (هوک woocommerce_process_shop_order_meta).
     *
     * منطق:
     * - آیتمی که در snapshot بود ولی اکنون در سفارش نیست → حذف‌شده →
     *   کل تعدادش با تاریخ متای آیتم به انبار برمی‌گردد (مورد ۵).
     * - آیتم تازه (در snapshot نبود) با انتخاب تاریخِ نوع add → کسر از انبار.
     * - افزایش تعداد → کسر مقدار افزایش بر اساس تاریخ‌های انتخاب‌شده.
     * - کاهش تعداد → برگشت مقدار کاهش به انبار با تاریخ متای آیتم.
     *
     * @param int $order_id شناسه سفارش
     */
    public function process_stock_on_order_save($order_id)
    {

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // فقط در محیط مدیریت و هنگام submit فرم سفارش
        if (!is_admin()) {
            return;
        }

        // محافظ «یک‌بار مصرف» در همین درخواست
        static $processed = array();
        if (isset($processed[(int) $order_id])) {
            return;
        }
        $processed[(int) $order_id] = true;

        // --- منبع تشخیص حذف: رجیستری پایدار سطح سفارش ---
        // این رجیستری نقشه‌ای از item_id به { sku, qty, expiry, data } است که
        // در پایان هر پردازش به‌روز می‌شود. چون روی خود سفارش (نه transient)
        // ذخیره می‌شود، حتی اگر هوک‌های میانی fire نشوند، وضعیت قبلی حفظ
        // می‌گردد و حذف ردیف‌ها به‌درستی تشخیص داده می‌شود.
        $registry = $order->get_meta('_mwp_items_registry');
        $registry = is_array($registry) ? $registry : array();

        // برای سازگاری، اگر snapshot ترنزینتی هم وجود داشت آن را ادغام می‌کنیم.
        $snapshot = get_transient($this->qty_snapshot_key($order_id));
        if (is_array($snapshot)) {
            foreach ($snapshot as $sid => $srow) {
                if (!isset($registry[(int) $sid])) {
                    $registry[(int) $sid] = $srow;
                }
            }
        }
        delete_transient($this->qty_snapshot_key($order_id));

        // انتخاب‌های تاریخ از مودال (در $_POST فرم اصلی سفارش)
        $selections = $this->read_selections_from_post();

        $conn = $this->api_connect();
        if (is_wp_error($conn)) {
            return;
        }

        // شناسهٔ آیتم‌های فعلی سفارش
        $current_ids = array();
        foreach ($order->get_items() as $item_id => $item) {
            if ($item instanceof WC_Order_Item_Product) {
                $current_ids[(int) $item_id] = $item;
            }
        }

        // همهٔ تغییرات موجودی در این آرایه جمع و یکجا (SetListQuantity) ارسال می‌شوند.
        $lines = array();

        /*
         * --- مورد ۴: آیتم‌های حذف‌شده ---
         * در رجیستری بودند ولی اکنون در سفارش نیستند → کل تعداد به انبار
         * برمی‌گردد. اولویت با توزیع ساختاریافتهٔ ذخیره‌شده در رجیستری است.
         */
        foreach ($registry as $reg_item_id => $reg) {
            if (isset($current_ids[(int) $reg_item_id])) {
                continue; // هنوز در سفارش است
            }
            $sku = $this->arr_get($reg, 'sku', '');
            $code = !empty($sku) ? $sku : $this->cfg('api_default_code_melli', '');

            // توزیع ساختاریافتهٔ ذخیره‌شده در رجیستری (دقیق‌ترین منبع)
            $data = $this->arr_get($reg, 'data', array());
            if (!is_array($data)) {
                $data = array();
            }

            if (!empty($data)) {
                foreach ($data as $row) {
                    $q = absint($this->arr_get($row, 'qty', 0));
                    $e = sanitize_text_field($this->arr_get($row, 'expiry', ''));
                    if ($q <= 0) {
                        continue;
                    }
                    $lines[] = array('codemelliDaroo' => $code, 'mojodiIncOrDec' => $q, 'expireDate' => $e);
                }
            } else {
                $qty = absint($this->arr_get($reg, 'qty', 0));
                if ($qty > 0) {
                    $lines[] = array(
                        'codemelliDaroo' => $code,
                        'mojodiIncOrDec' => $qty, // مثبت = برگشت
                        'expireDate' => $this->arr_get($reg, 'expiry', ''),
                    );
                }
            }
        }

        /*
         * --- آیتم‌های فعلی: افزودن / افزایش / کاهش ---
         * منبع حقیقت برای مقدار تغییر، انتخاب‌های ثبت‌شده در مودال است
         * (selections برای افزودن/افزایش و returns برای کاهش)، نه مقایسهٔ
         * snapshot. این رویکرد دقیق‌ترین حالت است و به ذخیره‌های میانی
         * حساس نیست.
         *
         * برای جلوگیری از اعمال دوبارهٔ یک تغییر (در صورت ذخیرهٔ مجدد سفارش)
         * هر انتخاب با یک شناسهٔ یکتا (token) علامت‌گذاری می‌شود و در متای
         * آیتم ذخیره می‌گردد.
         */
        foreach ($current_ids as $item_id => $item) {

            $product = $item->get_product();
            $sku = $product ? $product->get_sku() : '';
            $code = !empty($sku) ? $sku : $this->cfg('api_default_code_melli', '');

            $item_sel = $this->find_selection_for_item($selections, $item_id, $sku);

            if (!$item_sel) {
                continue; // برای این آیتم تغییری از مودال ثبت نشده
            }

            $type = $this->arr_get($item_sel, 'type', '');
            $token = $this->arr_get($item_sel, 'token', '');

            // جلوگیری از پردازش دوبارهٔ همان انتخاب
            if ($token) {
                $applied_tokens = $item->get_meta('_mwp_applied_tokens');
                $applied_tokens = is_array($applied_tokens) ? $applied_tokens : array();
                if (in_array($token, $applied_tokens, true)) {
                    continue;
                }
            }

            if ('add' === $type && !empty($item_sel['selections'])) {

                // افزودن محصول: کل مجموع انتخاب‌شده از انبار کسر می‌شود
                foreach ($item_sel['selections'] as $sel) {
                    $q = absint($this->arr_get($sel, 'qty', 0));
                    $e = sanitize_text_field($this->arr_get($sel, 'expiry', ''));
                    if ($q <= 0) {
                        continue;
                    }
                    $lines[] = array('codemelliDaroo' => $code, 'mojodiIncOrDec' => -$q, 'expireDate' => $e);
                    wc_update_order_item_meta($item_id, '_expiry_date', $e);
                }
                $this->add_to_expiry_data($item_id, $item_sel['selections']);

            } elseif ('increase' === $type && !empty($item_sel['selections'])) {

                // افزایش تعداد: مجموع انتخاب‌شده از انبار کسر می‌شود
                foreach ($item_sel['selections'] as $sel) {
                    $q = absint($this->arr_get($sel, 'qty', 0));
                    $e = sanitize_text_field($this->arr_get($sel, 'expiry', ''));
                    if ($q <= 0) {
                        continue;
                    }
                    $lines[] = array('codemelliDaroo' => $code, 'mojodiIncOrDec' => -$q, 'expireDate' => $e);
                    wc_update_order_item_meta($item_id, '_expiry_date', $e);
                }
                $this->add_to_expiry_data($item_id, $item_sel['selections']);

            } elseif ('decrease' === $type && !empty($item_sel['returns'])) {

                // کاهش تعداد: مجموع انتخاب‌شده به انبار برمی‌گردد
                foreach ($item_sel['returns'] as $ret) {
                    $q = absint($this->arr_get($ret, 'qty', 0));
                    $e = sanitize_text_field($this->arr_get($ret, 'expiry', ''));
                    if ($q <= 0) {
                        continue;
                    }
                    $lines[] = array('codemelliDaroo' => $code, 'mojodiIncOrDec' => $q, 'expireDate' => $e); // مثبت = برگشت
                }
                $this->subtract_from_expiry_data($item_id, $item_sel['returns']);

            } else {
                continue;
            }

            // علامت‌گذاری این انتخاب به‌عنوان «اعمال‌شده»
            if ($token) {
                $applied_tokens = $item->get_meta('_mwp_applied_tokens');
                $applied_tokens = is_array($applied_tokens) ? $applied_tokens : array();
                $applied_tokens[] = $token;
                wc_update_order_item_meta($item_id, '_mwp_applied_tokens', $applied_tokens);
            }

            // مبنای تعداد را برابر مقدار فعلی نگه می‌داریم
            wc_update_order_item_meta($item_id, '_mwp_stock_applied', 'yes');
        }

        // --- به‌روزرسانی رجیستری سفارش با وضعیت فعلی همهٔ آیتم‌ها ---
        // این رجیستری در ذخیرهٔ بعدی برای تشخیص حذف ردیف‌ها استفاده می‌شود.
        $new_registry = array();
        foreach ($current_ids as $cid => $citem) {
            $cprod = $citem->get_product();
            $csku = $cprod ? $cprod->get_sku() : '';
            $new_registry[(int) $cid] = array(
                'sku' => $csku,
                'qty' => (int) $citem->get_quantity(),
                'expiry' => $citem->get_meta('_expiry_date'),
                'data' => $this->get_expiry_data((int) $cid),
            );
        }
        $order->update_meta_data('_mwp_items_registry', $new_registry);
        $order->save();

        // ارسال یکجای همهٔ تغییرات به انبار (SetListQuantity)
        if (!empty($lines)) {
            $this->set_list_quantity($lines);
        }
    }

    /**
     * کاهش مقدار مشخص از توزیع تاریخ‌ها به‌روش FIFO (قدیمی‌ترین تاریخ اول)،
     * برای زمانی که کاربر تاریخ خاصی انتخاب نکرده است.
     *
     * @param int $item_id
     * @param int $qty
     */
    private function reduce_expiry_data_fifo($item_id, $qty)
    {

        $qty = absint($qty);
        if ($qty <= 0) {
            return;
        }

        $data = $this->get_expiry_data($item_id);
        if (empty($data)) {
            return;
        }

        // مرتب‌سازی بر اساس تاریخ (صعودی = قدیمی‌ترین اول)
        usort($data, function ($a, $b) {
            $ea = (is_array($a) && isset($a['expiry'])) ? $a['expiry'] : '';
            $eb = (is_array($b) && isset($b['expiry'])) ? $b['expiry'] : '';
            return strcmp($ea, $eb);
        });

        $remaining = $qty;
        foreach ($data as &$row) {
            if ($remaining <= 0) {
                break;
            }
            $row_qty = (int) $this->arr_get($row, 'qty', 0);
            $take = min($row_qty, $remaining);
            $row['qty'] = $row_qty - $take;
            $remaining -= $take;
        }
        unset($row);

        $this->save_expiry_data($item_id, $data);
    }

    /* ====================================================================
     * مدیریت دادهٔ ساختاریافتهٔ توزیع تاریخ انقضاء روی آیتم
     * --------------------------------------------------------------------
     * متای _mwp_expiry_data : JSON آرایه‌ای از { expiry, qty } که نشان
     *   می‌دهد از هر تاریخ چه تعداد برای این آیتم از انبار کسر شده است.
     * متای _mwp_expiry_breakdown : رشتهٔ خوانا که از روی همان داده ساخته
     *   شده و برای نمایش (قرمز) و گزارش استفاده می‌شود.
     * ==================================================================== */

    /**
     * خواندن دادهٔ ساختاریافتهٔ توزیع از متای آیتم.
     *
     * @param int $item_id
     * @return array آرایه‌ای از { expiry, qty }
     */
    private function get_expiry_data($item_id)
    {
        $raw = wc_get_order_item_meta($item_id, '_mwp_expiry_data', true);
        if (empty($raw)) {
            return array();
        }
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * ذخیرهٔ دادهٔ ساختاریافته + ساخت و ذخیرهٔ متن نمایشی.
     * تاریخ‌های با تعداد صفر حذف می‌شوند.
     *
     * @param int   $item_id
     * @param array $data آرایه‌ای از { expiry, qty }
     */
    private function save_expiry_data($item_id, $data)
    {

        // تجمیع تاریخ‌های یکسان و حذف صفرها
        $merged = array();
        foreach ($data as $row) {
            $exp = sanitize_text_field($this->arr_get($row, 'expiry', ''));
            $qty = (int) $this->arr_get($row, 'qty', 0);
            if ('' === $exp) {
                continue;
            }
            if (!isset($merged[$exp])) {
                $merged[$exp] = 0;
            }
            $merged[$exp] += $qty;
        }

        $clean = array();
        $parts = array();
        foreach ($merged as $exp => $qty) {
            if ($qty <= 0) {
                continue;
            }
            $clean[] = array('expiry' => $exp, 'qty' => $qty);
            $parts[] = sprintf(
                /* translators: 1: تعداد 2: تاریخ انقضاء */
                __('%1$s عدد از تاریخ انقضاء %2$s', 'mahamsoft-order-edit'),
                number_format($qty),
                $exp
            );
        }

        if (empty($clean)) {
            wc_delete_order_item_meta($item_id, '_mwp_expiry_data');
            wc_delete_order_item_meta($item_id, '_mwp_expiry_breakdown');
            return;
        }

        wc_update_order_item_meta($item_id, '_mwp_expiry_data', wp_json_encode($clean));
        wc_update_order_item_meta($item_id, '_mwp_expiry_breakdown', implode(' - ', $parts));
    }

    /**
     * افزودن مقادیر کسرشده (selections) به دادهٔ موجود آیتم.
     * برای حالت افزودن محصول و افزایش تعداد.
     *
     * @param int   $item_id
     * @param array $selections آرایه‌ای از { expiry, qty }
     */
    private function add_to_expiry_data($item_id, $selections)
    {
        if (empty($selections) || !is_array($selections)) {
            return;
        }
        $data = $this->get_expiry_data($item_id);
        foreach ($selections as $sel) {
            $data[] = array(
                'expiry' => sanitize_text_field($this->arr_get($sel, 'expiry', '')),
                'qty' => absint($this->arr_get($sel, 'qty', 0)),
            );
        }
        $this->save_expiry_data($item_id, $data);
    }

    /**
     * کسر مقادیر برگشتی (returns) از دادهٔ موجود آیتم.
     * برای حالت کاهش تعداد (برگشت به انبار).
     *
     * @param int   $item_id
     * @param array $returns آرایه‌ای از { expiry, qty }
     */
    private function subtract_from_expiry_data($item_id, $returns)
    {
        if (empty($returns) || !is_array($returns)) {
            return;
        }
        $data = $this->get_expiry_data($item_id);

        // تبدیل به نقشهٔ expiry => qty
        $map = array();
        foreach ($data as $row) {
            $exp = sanitize_text_field($this->arr_get($row, 'expiry', ''));
            $qty = (int) $this->arr_get($row, 'qty', 0);
            if ('' === $exp) {
                continue;
            }
            $map[$exp] = (isset($map[$exp]) ? $map[$exp] : 0) + $qty;
        }

        foreach ($returns as $ret) {
            $exp = sanitize_text_field($this->arr_get($ret, 'expiry', ''));
            $qty = absint($this->arr_get($ret, 'qty', 0));
            if ('' === $exp || $qty <= 0) {
                continue;
            }
            if (isset($map[$exp])) {
                $map[$exp] = max(0, $map[$exp] - $qty);
            }
        }

        $rebuilt = array();
        foreach ($map as $exp => $qty) {
            $rebuilt[] = array('expiry' => $exp, 'qty' => $qty);
        }
        $this->save_expiry_data($item_id, $rebuilt);
    }

    /**
     * خواندن و رمزگشایی JSON انتخاب‌های ارسال‌شده از مودال.
     *
     * @return array کلید = شناسه آیتم یا new_X، مقدار = اطلاعات انتخاب
     */
    private function read_selections_from_post()
    {

        $sel_raw = isset($_POST['mwp_expiry_selections']) // phpcs:ignore
            ? wp_unslash($_POST['mwp_expiry_selections']) // phpcs:ignore
            : '';

        if (!is_string($sel_raw) || '' === $sel_raw) {
            return array();
        }

        $decoded = json_decode($sel_raw, true);

        return is_array($decoded) ? $decoded : array();
    }

    /**
     * یافتن انتخاب مرتبط با یک آیتم سفارش از payload مودال.
     *
     * payload با کلید itemId ذخیره می‌شود، ولی برای محصولات تازه‌اضافه‌شده
     * (که در زمان ساخت مودال itemId نهایی نداشتند) ممکن است کلید new_X
     * باشد. در این حالت بر اساس SKU تطبیق می‌دهیم.
     *
     * @param array      $selections payload کامل
     * @param int        $item_id    شناسه آیتم سفارش
     * @param string     $sku        SKU محصول
     * @return array|null
     */
    private function find_selection_for_item($selections, $item_id, $sku)
    {

        if (empty($selections)) {
            return null;
        }

        // 1) تطبیق مستقیم با itemId
        if (isset($selections[(string) $item_id])) {
            return $selections[(string) $item_id];
        }
        if (isset($selections[$item_id])) {
            return $selections[$item_id];
        }

        // 2) تطبیق بر اساس SKU (برای محصولات تازه‌اضافه‌شده با کلید new_X)
        if (!empty($sku)) {
            foreach ($selections as $key => $sel) {
                if (
                    isset($sel['sku']) && (string) $sel['sku'] === (string) $sku
                    && in_array($this->arr_get($sel, 'type'), array('add', 'increase'), true)
                ) {
                    return $sel;
                }
            }
        }

        return null;
    }

    /**
     * کمکی: خواندن امن یک کلید از آرایه
     */
    private function arr_get($arr, $key, $default = '')
    {
        return (is_array($arr) && isset($arr[$key])) ? $arr[$key] : $default;
    }

    /* ====================================================================
     * مورد ۴: برگشت کل موجودی هنگام تغییر وضعیت به «وضعیت قابل بازگشت»
     * ==================================================================== */

    /**
     * هنگامی که وضعیت سفارش به یکی از وضعیت‌های انتخاب‌شده در تنظیمات
     * «وضعیت‌های قابل بازگشت سفارش» تغییر کند، کل موجودی آیتم‌های سفارش
     * بر اساس توزیع تاریخ ثبت‌شده (یا تاریخ متای آیتم) به انبار برمی‌گردد.
     *
     * برای جلوگیری از برگشت دوباره، روی سفارش یک پرچم
     * (_mwp_restocked_on_status) ذخیره می‌شود. اگر وضعیت از حالت بازگشتی
     * خارج و دوباره به آن وارد شود، امکان برگشت مجدد فراهم می‌گردد (پرچم
     * هنگام خروج از وضعیت بازگشتی پاک می‌شود).
     *
     * @param int      $order_id
     * @param string   $old_status
     * @param string   $new_status
     * @param WC_Order $order
     */
    public function on_status_changed_restock($order_id, $old_status, $new_status, $order)
    {

        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
            if (!$order) {
                return;
            }
        }

        $restockable = $this->get_restockable_statuses();

        $entering = in_array($new_status, $restockable, true);
        $leaving = in_array($old_status, $restockable, true);

        // خروج از وضعیت بازگشتی → پاک‌کردن پرچم تا دفعهٔ بعد دوباره برگردد
        if ($leaving && !$entering) {
            $order->delete_meta_data('_mwp_restocked_on_status');
            $order->save();
            return;
        }

        if (!$entering) {
            return;
        }

        // جلوگیری از برگشت دوباره
        if ('yes' === $order->get_meta('_mwp_restocked_on_status')) {
            return;
        }

        $conn = $this->api_connect();
        if (is_wp_error($conn)) {
            return;
        }

        $lines = array();

        foreach ($order->get_items() as $item_id => $item) {

            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            $product = $item->get_product();
            $sku = $product ? $product->get_sku() : '';
            $code = !empty($sku) ? $sku : $this->cfg('api_default_code_melli', '');

            // اولویت با توزیع ساختاریافته؛ در غیر این صورت کل تعداد با تاریخ متا
            $data = $this->get_expiry_data($item_id);

            if (!empty($data)) {
                foreach ($data as $row) {
                    $q = absint($this->arr_get($row, 'qty', 0));
                    $e = sanitize_text_field($this->arr_get($row, 'expiry', ''));
                    if ($q <= 0) {
                        continue;
                    }
                    $lines[] = array('codemelliDaroo' => $code, 'mojodiIncOrDec' => $q, 'expireDate' => $e); // مثبت = برگشت
                }
            } else {
                $qty = (int) $item->get_quantity();
                if ($qty > 0) {
                    $expiry = $item->get_meta('_expiry_date');
                    $lines[] = array(
                        'codemelliDaroo' => $code,
                        'mojodiIncOrDec' => $qty, // مثبت = برگشت
                        'expireDate' => $expiry ? $expiry : '',
                    );
                }
            }

            // پس از برگشت، توزیع آیتم صفر می‌شود (دیگر چیزی کسر نشده نیست)
            $this->save_expiry_data($item_id, array());
        }

        // ارسال یکجا به انبار
        if (!empty($lines)) {
            $this->set_list_quantity($lines);
        }

        // رجیستری را خالی می‌کنیم چون همهٔ موجودی به انبار برگشت داده شد؛
        // بنابراین حذف ردیف‌ها پس از این، برگشت دوباره ایجاد نمی‌کند.
        $order->update_meta_data('_mwp_items_registry', array());
        $order->update_meta_data('_mwp_restocked_on_status', 'yes');
        $order->save();
    }

    /**
     * خواندن «وضعیت‌های قابل بازگشت سفارش» از تنظیمات.
     *
     * @return array
     */
    private function get_restockable_statuses()
    {
        $settings = get_option('mahamsoft_order_edit_settings', array());
        if (!is_array($settings) || empty($settings['restockable_statuses'])) {
            return array();
        }
        return array_map('sanitize_key', (array) $settings['restockable_statuses']);
    }

    /* ====================================================================
     * استرداد سفارش: برگشت موجودی به انبار
     * ==================================================================== */

    public function on_refund($order_id, $refund_id)
    {

        $refund = wc_get_order($refund_id);
        if (!$refund) {
            return;
        }

        $conn = $this->api_connect();
        if (is_wp_error($conn)) {
            return;
        }

        $lines = array();

        foreach ($refund->get_items() as $item) {

            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            $qty = abs((int) $item->get_quantity());
            if ($qty <= 0) {
                continue;
            }

            $product = $item->get_product();
            $sku = $product ? $product->get_sku() : '';
            $code_melli = !empty($sku) ? $sku : $this->cfg('api_default_code_melli', '');
            $expiry = $item->get_meta('_expiry_date');

            $lines[] = array(
                'codemelliDaroo' => $code_melli,
                'mojodiIncOrDec' => $qty, // مثبت = برگشت به انبار
                'expireDate' => $expiry ? $expiry : '',
            );
        }

        if (!empty($lines)) {
            $this->set_list_quantity($lines);
        }
    }

    /* ====================================================================
     * وب‌سرویس - متدهای فراخوانی HTTP
     * ==================================================================== */

    /**
     * اتصال به وب‌سرویس (با کش transient ۵ دقیقه‌ای)
     *
     * @return array|WP_Error
     */
    private function api_connect()
    {

        $cached = get_transient('mahamsoft_pharmacy_connection');
        if (false !== $cached) {
            return $cached;
        }

        $resp = $this->call('/DataBase/Connection', array(
            'serverName' => $this->cfg('api_server_name', ''),
            'username' => $this->cfg('api_username', ''),
            'password' => $this->cfg('api_password', ''),
        ));

        if (!is_wp_error($resp)) {
            set_transient('mahamsoft_pharmacy_connection', $resp, 5 * MINUTE_IN_SECONDS);
        }

        return $resp;
    }

    /**
     * ارسال دسته‌ای تغییرات موجودی به انبار از طریق Anbar/SetListQuantity.
     *
     * هر عضو آرایه باید شامل کلیدهای زیر باشد:
     *   - codemelliDaroo (string)
     *   - mojodiIncOrDec (int)  منفی = کسر، مثبت = اضافه
     *   - expireDate     (string) اختیاری
     * مقادیر darookhaneID و anbarID از تنظیمات تکمیل می‌شوند.
     *
     * @param array $lines آرایه‌ای از خطوط تغییر موجودی
     * @return array|WP_Error|null  null اگر چیزی برای ارسال نبود
     */
    private function set_list_quantity(array $lines)
    {

        $payload = array();
        $darookhaneh = (int) $this->cfg('api_darookhaneh_id', 0);
        $anbar = $this->cfg('api_anbar_id', '');

        foreach ($lines as $line) {
            $delta = (int) $this->arr_get($line, 'mojodiIncOrDec', 0);
            $code = (string) $this->arr_get($line, 'codemelliDaroo', '');
            if (0 === $delta || '' === $code) {
                continue;
            }
            $entry = array(
                'codemelliDaroo' => $code,
                'darookhaneID' => $darookhaneh,
                'anbarID' => $anbar,
                'mojodiIncOrDec' => $delta,
            );
            $exp = (string) $this->arr_get($line, 'expireDate', '');
            if ('' !== $exp) {
                $entry['expireDate'] = $exp;
            }
            $payload[] = $entry;
        }

        if (empty($payload)) {
            return null;
        }

        return $this->call('/Anbar/SetListQuantity', $payload);
    }

    /**
     * فراخوانی یک endpoint وب‌سرویس داروپردازان
     *
     * @param string $endpoint مسیر API (بدون دامنه)
     * @param array  $body     بدنه JSON برای ارسال
     * @return array|WP_Error
     */
    private function call($endpoint, array $body)
    {

        $base = $this->cfg('api_base_url', '');

        if (empty($base)) {
            return new WP_Error('no_base_url', __('آدرس وب‌سرویس در تنظیمات وارد نشده است.', 'mahamsoft-order-edit'));
        }

        $url = rtrim($base, '/') . '/' . ltrim($endpoint, '/');

        $resp = wp_remote_post($url, array(
            'method' => 'POST',
            'timeout' => 15,
            'sslverify' => false,
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $this->cfg('api_key', ''),
            ),
            'body' => wp_json_encode($body),
        ));

        if (is_wp_error($resp)) {
            return $resp;
        }

        $code = wp_remote_retrieve_response_code($resp);
        $raw = wp_remote_retrieve_body($resp);

        if ($code < 200 || $code >= 300) {
            return new WP_Error('http_error', "HTTP {$code}: {$raw}");
        }

        $decoded = json_decode($raw, true);

        if (null === $decoded) {
            return new WP_Error('json_error', "JSON نامعتبر: {$raw}");
        }

        if (!empty($decoded['error'])) {
            return new WP_Error(
                'api_error',
                is_string($decoded['error']) ? $decoded['error'] : wp_json_encode($decoded['error'])
            );
        }

        return $decoded;
    }

    /* ====================================================================
     * کمکی: یافتن یک آیتم سفارش بر اساس شناسهٔ آن
     * ==================================================================== */

    /**
     * @param int $item_id شناسهٔ آیتم سفارش
     * @return WC_Order_Item|false
     */
    private function get_order_item_by_id($item_id)
    {
        if (class_exists('WC_Order_Factory') && method_exists('WC_Order_Factory', 'get_order_item')) {
            return WC_Order_Factory::get_order_item($item_id);
        }
        return false;
    }

    /* ====================================================================
     * کمکی: تشخیص فعال‌بودن HPOS
     * ==================================================================== */

    /**
     * آیا ذخیره‌سازی سفارش‌ها روی جداول سفارشی (HPOS) فعال است؟
     *
     * @return bool
     */
    public static function is_hpos_enabled()
    {
        if (
            class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && method_exists('\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled')
        ) {
            return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        }
        return 'yes' === get_option('woocommerce_custom_orders_table_enabled');
    }

    /* ====================================================================
     * کمکی: تشخیص صفحه ویرایش/افزودن سفارش (سازگار با HPOS و کلاسیک)
     * ==================================================================== */

    private function is_order_screen()
    {

        global $pagenow;

        if (!is_admin()) {
            return false;
        }

        // --- صفحه تنظیمات پلاگین اصلی (برای رندر مودال + اسکریپت دکمه تست اتصال) ---
        if (
            'admin.php' === $pagenow
            && isset($_GET['page']) // phpcs:ignore
            && 'mahamsoft-order-edit' === sanitize_key($_GET['page']) // phpcs:ignore
        ) {
            return true;
        }

        // --- حالت HPOS: admin.php?page=wc-orders&action=edit|new ---
        if ('admin.php' === $pagenow) {
            $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : ''; // phpcs:ignore
            $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : ''; // phpcs:ignore
            if ('wc-orders' === $page && in_array($action, array('edit', 'new'), true)) {
                return true;
            }
            // برخی نسخه‌ها هنگام ساخت سفارش جدید action ندارند
            if ('wc-orders' === $page && '' === $action && isset($_GET['action']) === false) { // phpcs:ignore
                return true;
            }
        }

        // --- حالت کلاسیک: ویرایش سفارش موجود (post.php) ---
        if (
            'post.php' === $pagenow
            && isset($_GET['action']) && 'edit' === $_GET['action'] // phpcs:ignore
            && isset($_GET['post']) // phpcs:ignore
        ) {
            $ptype = get_post_type(absint($_GET['post'])); // phpcs:ignore
            if ('shop_order' === $ptype) {
                return true;
            }
        }

        // --- حالت کلاسیک: ساخت سفارش جدید (post-new.php) ---
        if (
            'post-new.php' === $pagenow
            && isset($_GET['post_type']) && 'shop_order' === $_GET['post_type'] // phpcs:ignore
        ) {
            return true;
        }

        return false;
    }

    private function log($object, $print_r = true, $title = null)
    {
        $date = date('Y-m-d H:i:s');
        $log_dir = plugin_dir_path(__FILE__) . 'logs';

        if ($print_r) {
            $object_string = print_r($object, true);
        } else {
            ob_start();
            var_dump($object);
            $object_string = ob_get_clean();
        }

        // 3. ترکیب محتوای نهایی لاگ
        $content = "[{$date}] [{$title}]:\n" . $object_string . "\n-------------------\n";

        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0777, true);
        }
        $log_file = $log_dir . "/log.txt";
        error_log($content, 3, $log_file);
    }
}

add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) {
        Mahamsoft_Pharmacy_API::instance();
    }
}, 11);