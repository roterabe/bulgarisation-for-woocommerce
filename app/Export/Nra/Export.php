<?php
namespace Woo_BG\Export\Nra;

use Woo_BG\Admin\Tabs\Nra_Tab;
use Woo_BG\Admin\Order\Documents;

defined( 'ABSPATH' ) || exit;

class Export {
	public $status, $payment_methods, $not_included_orders, $options, $completed_orders_ids, $refunded_orders_ids, $generate_files, $date, $xml_shop, $xml_orders;

	function __construct( $date, $generate_files ) {
		$this->status = apply_filters( 'woo_bg/admin/export/orders_statuses', array( 'wc-completed', 'wc-refunded' ) );
		$this->payment_methods = woo_bg_get_payment_types_for_meta();
		$this->generate_files = $generate_files;
		$this->date = $date;
		$this->load_woo_orders();
		$this->load_options();


	}

	protected function load_woo_orders() {
		$args = array(
			'date_created' => strtotime( 'first day of ' . $this->date . ' ' . wp_timezone_string() ) . '...' . strtotime( 'last day of ' . $this->date . ' 23:59:59 ' . wp_timezone_string() ),
			'status' => $this->status,
			'limit' => -1,
		);

		$orders = apply_filters( 'woo_bg/admin/export/orders', wc_get_orders( $args ), $this );

		$this->completed_orders_ids = array();
		$this->refunded_orders_ids = array();

		foreach ( $orders as $order ) {
			if ( 
				is_a( $order, 'Automattic\WooCommerce\Admin\Overrides\OrderRefund' ) || 
				is_a( $order, 'WC_Order_Refund' )
			) {
				if ( $order->get_parent_id() ) {
					$parent_order = wc_get_order( $order->get_parent_id() );
				} else {
					$parent_order = $order;
				}

				$this->refunded_orders_ids[] = $parent_order->get_id();

				if ( $parent_order->get_date_completed() ) {
					$completed_date = strtotime( $parent_order->get_date_completed()->__toString() );
				} else {
					$completed_date = strtotime( $order->get_date_created()->__toString() );
				}

				if ( date_i18n( 'Y-m', $completed_date ) === $this->date  ) {
					$this->completed_orders_ids[] = $parent_order->get_id();
				}
			} else {
				$this->completed_orders_ids[] = $order->get_id();
			}
		}

		$this->refunded_orders_ids = array_reverse( array_unique( $this->refunded_orders_ids ) );
		$this->completed_orders_ids = array_reverse( array_unique( $this->completed_orders_ids ) );
	}

	protected function load_options() {
		$settings = new Nra_Tab();
		$settings->load_fields();
		$this->options = $settings->get_localized_fields();
	}

	protected function load_xml_shop() {
		$this->xml_shop = new Xml\Shop(
			$this->options['nap']['eik']['value'], 
			$this->options['nap']['nap_number']['value'],
			$this->options['nap']['domain']['value'],
			new \DateTime(), 
			false, 
			gmdate('Y', strtotime( $this->date ) ), 
			gmdate('m', strtotime( $this->date ) )
		);
	}

	public function get_xml_file() {
		$this->load_xml_shop();

		foreach ( $this->completed_orders_ids as $order_id ) {
			$xml_order = new Order( wc_get_order( $order_id ), $this->generate_files );

			if ( ! $xml_order->payment_method_type ) {
				$this->not_included_orders[] = $xml_order->order_id_to_show;
				continue;
			}

			$this->xml_shop->addOrder( $xml_order->get_xml_order() );
		}

		foreach ( $this->refunded_orders_ids as $order_id ) {
			$refunded_order = new RefundedOrder( wc_get_order( $order_id ), $this->date );

			$this->xml_shop->addReturnedOrder( $refunded_order->get_xml_order() );
		}

		return $this->upload_xml();
	}

	protected function upload_xml() {
		add_filter( 'upload_dir', array( 'Woo_BG\Image_Uploader', 'change_upload_dir' ) );
		$name = uniqid( wp_rand(), true );
		$xml = wp_upload_bits( $name . '.xml', null, Xml\XmlConverter::convert( $this->xml_shop ) );
		remove_filter( 'upload_dir', array( 'Woo_BG\Image_Uploader', 'change_upload_dir' ) );

		if ( is_wp_error( $xml ) ) {
			return;
		}

		$attachment = array(
			 'guid' => $xml[ 'file' ], 
			 'post_mime_type' => $xml['type'],
			 'post_title' => $name,
			 'post_content' => '',
			 'post_status' => 'inherit'
		);

		$attach_id = wp_insert_attachment( $attachment, $xml[ 'file' ] );

		return array(
			'file' => wp_get_attachment_url( $attach_id ),
			'not_included_orders' => $this->not_included_orders,
			'totals' => $this->calculate_totals_message(),
		);
	}

	protected function calculate_totals_message() {
		$message = sprintf( 
			__( 'Total: %s | Total vat: %s | Returned Total: %s', 'woo-bg' ), 
			wc_price( $this->xml_shop->getOrdersTotal() ), 
			wc_price( $this->xml_shop->getOrdersTotalVat() ),
			wc_price( $this->xml_shop->getTotalAmountReturnedOrders() ) 
		);

		return $message;
	}
}
