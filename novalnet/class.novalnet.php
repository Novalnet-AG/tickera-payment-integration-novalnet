<?php
/*
  Novalnet Payment Gateway API
 */
if ( !defined( 'ABSPATH' ) )
	exit; // Exit if accessed directly

if ( !class_exists( 'Novalnet' ) ) {
	class Novalnet {
		public static function get_novalnet_configuration(){
			/**
			 * Generate Novalnet Global Configuration parameters based on backend configuration
			 * @param null
			 * @return array
			 **/
			$settings = get_option( 'tc_settings' );
			if(isset($settings['gateways']['novalnet_payment'])) return $settings['gateways']['novalnet_payment'];
			else return '';
		}

		function common_parameters ( ) {
			/**
			 * return Novalnet Payment Required Param
			 *
			 **/
			global $tc;
			$system_name = 'Wordpress-'.$tc->title;
			$system_url = get_site_url();
			$system_version = get_bloginfo( 'version' ) . "-" . $tc->version . '-NN1.1.0';
			$lang = explode( "_", get_locale() );
			$ip = self::getIp();
			$customer_no = get_current_user_id( ) != '' ? get_current_user_id( ) : 'guest';
			$novalnet_config_param = self::get_novalnet_configuration();
			if(!is_numeric($novalnet_config_param['vendor_id']) || !is_numeric($novalnet_config_param['product_id']) || !is_numeric($novalnet_config_param['tariff']) || empty($novalnet_config_param['auth_code']) || empty($novalnet_config_param['access_key']) )
			{
				$_SESSION['novalnet_message']="<span style='color:red'>".__('Basic parameter not valid','novalnet')."</span>";
				wp_redirect( $tc->get_payment_slug( true ) );
				tc_js_redirect( $tc->get_payment_slug( true ) );
				exit;
			}
			$tc_general_settings = get_option( 'tc_general_setting', true );
			$return_url = $tc->get_confirmation_slug( true, $_SESSION['order_id'] );
			$response=array(
				'vendor'		=>	$novalnet_config_param['vendor_id'],
				'auth_code'		=>	$novalnet_config_param['auth_code'],
				'product'		=>	$novalnet_config_param['product_id'],
				'tariff'		=>	$novalnet_config_param['tariff'],

				'system_name'	=>	$system_name,
				'system_version'=>	$system_version,
				'system_url'	=>	$system_url,
				'system_ip'		=>	$ip['SERVER_ADDR'],

				'amount'		=>	($_SESSION['tc_cart_total']) * 100,
				'currency'		=>	(isset($tc_general_settings['currencies'])) ? $tc_general_settings['currencies'] : 'EUR',
				'lang'			=>	strtoupper($lang[0]),

				'order_no'		=>	$_SESSION['order_id'],
				'customer_no'	=>	$customer_no,
				'remote_ip'		=>	$ip['REMOTE_ADDR'],
				'test_mode'		=>	(isset($novalnet_config_param['test_mode'])) ? 1 : 0,
				'sepa_due_date' => self::get_sepa_due_date_by_days($novalnet_config_param['sepa_due_date']),

				'return_url'=>$return_url,
				'return_method'=>'POST',
				'error_return_url'=>$return_url,
				'error_return_method'=>'POST'
				);

			$invoice_due_date = self::get_invoice_due_date_by_days($novalnet_config_param['invoice_due_date']);
			if(	!empty($invoice_due_date)){
				$response['due_date'] = $invoice_due_date;
			}
			if(self::check_manual_limit($novalnet_config_param['on-hold']))
			{
				$response['on_hold'] = 1;
			}
			if(ctype_digit($novalnet_config_param['referrer_id']))
			{
				$response['referrer_id'] = $novalnet_config_param['referrer_id'];
			}
			if(!empty($novalnet_config_param['reference1'])) {
				$response['input1']='reference1';
				$response['inputval1']=strip_tags($novalnet_config_param['reference1']);
			}

			if(!empty($novalnet_config_param['reference2'])) {
				$response['input2']='reference2';
				$response['inputval2']=strip_tags($novalnet_config_param['reference2']);
			}
			$response = array_map('trim',$response);
			return $response;
	}

	public static function encodeProcess( $data, $key_password ) {
	$data = trim( $data );
	if ( $data == '')
		return'Error: no data';

	if (!function_exists( 'base64_encode' ) or !function_exists( 'pack' ) || !function_exists( 'crc32' ) )
		return'Error: func n/a';

	try {
	$crc = sprintf( '%u', crc32( $data ) );
	$data = $crc . '|' . $data;
	$data = bin2hex( $data . $key_password );
	$data = strrev( base64_encode( $data ) );
	}catch (Exception $e) {
	echo( 'Error: ' . $e);
	}
	return $data;
	}
	
	public static function decodeProcess( $data, $key ) {
	$data = base64_decode(strrev($data));
	$data = pack("H" . strlen($data), $data);
	$data = substr($data, 0, stripos($data, $key));
	$pos = strpos($data, "|");
	if ($pos === false) {
		$error = "Error: CKSum not found!";
	}
	$crc= substr($data, 0, $pos);
	$value = trim(substr($data, $pos + 1));
		
		return $value;
		
	} 

	static public function check_manual_limit($onhold_limit){
		if(is_numeric($onhold_limit) && ($_SESSION['tc_cart_total']) * 100 >= $onhold_limit ){
			return true;
		}
		return false;
	}

	function get_invoice_due_date_by_days($days){
		/**
		 * return invoice due date
		 **/
			$due=intval($days);
			if($due >= 7 && $due <= 14) return $due;
			else '';
	}
	
	function get_sepa_due_date_by_days($days){
		/**
		 * return sepa due date based
		 **/
		$due = intval( $days );
		if( $due < 7 ) $due = 7;
		$day_timestamp = strtotime("+ " . $due . " day");
		$due_date = date( "Y-m-d", $day_timestamp );
		return $due_date;		
	}
	public static function generate_preview( $ticket_instance_id = false, $template_id = false, $ticket_type_id = false, $ticket_name, $ticket_no )
	{
		global $tc, $pdf;

		$data = array();
		error_reporting( 0 );
		$tc_general_settings			 = get_option( 'tc_general_setting', false );
		$ticket_template_auto_pagebreak	 = 'yes';//isset( $tc_general_settings[ 'ticket_template_auto_pagebreak' ] ) ? $tc_general_settings[ 'ticket_template_auto_pagebreak' ] : 'yes';

		if ( $ticket_template_auto_pagebreak == 'no' ) {
			$ticket_template_auto_pagebreak = false;
		} else {
			$ticket_template_auto_pagebreak = true;
		}

		require_once($tc->plugin_dir . 'includes/tcpdf/examples/tcpdf_include.php');

		$output_buffering = ini_get( 'output_buffering' );

		ob_start();
		if ( isset( $output_buffering ) && $output_buffering > 0 ) {
			if ( !ob_get_level() ) {
				ob_end_clean();
				ob_start();
			}
		}

		//use $template_id only if you preview the ticket

		if ( $ticket_instance_id ) {
			$ticket_instance = new TC_Ticket( $ticket_instance_id );
		}

		if ( $template_id ) {
			$post_id = $template_id;
		} else {

			$ticket_template = get_post_meta( $ticket_instance->details->ticket_type_id, 'ticket_template', true );

			$ticket_template_alternative = get_post_meta( apply_filters( 'tc_ticket_type_id', $ticket_instance->details->ticket_type_id ), apply_filters( 'tc_ticket_template_field_name', '_ticket_template' ), true );

			$ticket_template = !empty( $ticket_template ) ? $ticket_template : $ticket_template_alternative;

			$post_id = $ticket_template;
		}

		if ( $post_id ) {//post id = template id
			$metas = tc_get_post_meta_all( $post_id );
		}

		$margin_left	 = $metas[ 'document_ticket_left_margin' ];
		$margin_top		 = $metas[ 'document_ticket_top_margin' ];
		$margin_right	 = $metas[ 'document_ticket_right_margin' ];
		// create new PDF document

		$pdf = new TCPDF( $metas[ 'document_ticket_orientation' ], PDF_UNIT, apply_filters( 'tc_additional_ticket_document_size_output', apply_filters( 'tc_document_paper_size', $metas[ "document_ticket_size" ] ) ), true, apply_filters( 'tc_ticket_document_encoding', get_bloginfo( 'charset' ) ), false );
		$pdf->setPrintHeader( false );
		$pdf->setPrintFooter( false );
		$pdf->SetFont( $metas[ 'document_font' ], '', 14 );
		// set margins
		$pdf->SetMargins( $margin_left, $margin_top, $margin_right );
		// set auto page breaks
		$pdf->SetAutoPageBreak( $ticket_template_auto_pagebreak, PDF_MARGIN_BOTTOM );
		// set font
		$pdf->AddPage();
		error_reporting( 1 ); //Don't show errors in the PDF

		if ( isset( $metas[ 'document_ticket_background_image' ] ) && $metas[ 'document_ticket_background_image' ] !== '' ) {
			if ( $metas[ 'document_ticket_orientation' ] == 'P' ) {

				if ( $metas[ 'document_ticket_size' ] == 'A4' ) {
					$pdf->Image( $metas[ 'document_ticket_background_image' ], 0, 0, 210, 297, '', '', '', true, 300, '', false, false, 0, false );
				} elseif ( $metas[ 'document_ticket_size' ] == 'A5' ) {
					$pdf->Image( $metas[ 'document_ticket_background_image' ], 0, 0, 148, 210, '', '', '', true, 300, '', false, false, 0, false );
				} elseif ( $metas[ 'document_ticket_size' ] == 'A6' ) {
					$pdf->Image( $metas[ 'document_ticket_background_image' ], 0, 0, 105, 148, '', '', '', true, 300, '', false, false, 0, false );
				} elseif ( $metas[ 'document_ticket_size' ] == 'A7' ) {
					$pdf->Image( $metas[ 'document_ticket_background_image' ], 0, 0, 74, 105, '', '', '', true, 300, '', false, false, 0, false );
				} elseif ( $metas[ 'document_ticket_size' ] == 'A8' ) {
					$pdf->Image( $metas[ 'document_ticket_background_image' ], 0, 0, 52, 74, '', '', '', true, 300, '', false, false, 0, false );
				} elseif ( ($metas[ 'document_ticket_size' ] == 'ANSI_A' ) ) {
					$pdf->Image( $metas[ 'document_ticket_background_image' ], 0, 0, 216, 279, '', '', '', true, 300, '', false, false, 0, false );
				}
			} elseif ( $metas[ 'document_ticket_orientation' ] == 'L' ) {
				if ( $metas[ 'document_ticket_size' ] == 'A4' ) {
					$pdf->Image( $metas[ 'document_ticket_background_image' ], 0, 0, 297, 210, '', '', '', true, 300, '', false, false, 0, false );
				} elseif ( $metas[ 'document_ticket_size' ] == 'A5' ) {
					$pdf->Image( $metas[ 'document_ticket_background_image' ], 0, 0, 210, 148, '', '', '', true, 300, '', false, false, 0, false );
				} elseif ( $metas[ 'document_ticket_size' ] == 'A6' ) {
					$pdf->Image( $metas[ 'document_ticket_background_image' ], 0, 0, 148, 105, '', '', '', true, 300, '', false, false, 0, false );
				} elseif ( $metas[ 'document_ticket_size' ] == 'A7' ) {
					$pdf->Image( $metas[ 'document_ticket_background_image' ], 0, 0, 105, 74, '', '', '', true, 300, '', false, false, 0, false );
				} elseif ( $metas[ 'document_ticket_size' ] == 'A8' ) {
					$pdf->Image( $metas[ 'document_ticket_background_image' ], 0, 0, 74, 52, '', '', '', true, 300, '', false, false, 0, false );
				} elseif ( ($metas[ 'document_ticket_size' ] == 'ANSI_A' ) ) {
					$pdf->Image( $metas[ 'document_ticket_background_image' ], 0, 0, 279, 216, '', '', '', true, 300, '', false, false, 0, false );
				}
			}
		}
        
		$col_1		 = 'width: 100%;';
		$col_1_width = '100%';
		$col_2		 = 'width: 49.2%; margin-right: 1%;';
		$col_2_width = '49.2%';
		$col_3		 = 'width: 32.5%; margin-right: 1%;';
		$col_3_width = '32.5%';
		$col_4		 = 'width: 24%; margin-right: 1%;';
		$col_5		 = 'width: 19%; margin-right: 1%;';
		$col_6		 = 'width: 15.66%; margin-right: 1%;';
		$col_7		 = 'width: 13.25%; margin-right: 1%;';
		$col_8		 = 'width: 11.43%; margin-right: 1%;';
		$col_9		 = 'width: 10%; margin-right: 1%;';
		$col_10		 = 'width: 8.94%; margin-right: 1%;';



		$rows = '<table>';

		for ( $i = 1; $i <= apply_filters( 'tc_ticket_template_row_number', 10 ); $i++ ) {

			$rows .= '<tr>';
			$rows_elements = get_post_meta( $post_id, 'rows_' . $i, true );

			if ( isset( $rows_elements ) && $rows_elements !== '' ) {

				$element_class_names = explode( ',', $rows_elements );
				$rows_count			 = count( $element_class_names );

				foreach ( $element_class_names as $element_class_name ) {

					if ( class_exists( $element_class_name ) ) {

						if ( isset( $post_id ) ) {
							$rows .= '<td ' . (isset( $metas[ $element_class_name . '_cell_alignment' ] ) ? 'align="' . $metas[ $element_class_name . '_cell_alignment' ] . '"' : 'align="left"') . ' style="' . ${"col_" . $rows_count} . (isset( $metas[ $element_class_name . '_cell_alignment' ] ) ? 'text-align:' . $metas[ $element_class_name . '_cell_alignment' ] . ';' : '') . (isset( $metas[ $element_class_name . '_font_size' ] ) ? 'font-size:' . $metas[ $element_class_name . '_font_size' ] . ';' : '') . (isset( $metas[ $element_class_name . '_font_color' ] ) ? 'color:' . $metas[ $element_class_name . '_font_color' ] . ';' : '') . '">';

							for ( $s = 1; $s <= ($metas[ $element_class_name . '_top_padding' ]); $s++ ) {
								$rows .= '<br />';
							}

							$element = new $element_class_name( $post_id );
							$rows .= $element->ticket_content( $ticket_instance_id, $ticket_type_id );

							for ( $s = 1; $s <= ($metas[ $element_class_name . '_bottom_padding' ]); $s++ ) {
								$rows .= '<br />';
							}

							$rows .= '</td>';
						}
					}
				}
			}
			$rows .= '</tr>';
		}
		$rows .= '</table>';
		$page1 = preg_replace( "/\s\s+/", '', $rows ); //Strip excess whitespace
		$pdf->writeHTML( $page1, true, false, true, false ); //Write page 1
		$pdf->Output( WP_CONTENT_DIR . '/uploads/' . $ticket_name.'-'.$ticket_no.'.pdf', 'F');

	}

	public function getIp()
	{
	/**
	 * return remote ip and server ip
	 **/
		$res['REMOTE_ADDR'] = ('::1' == $_SERVER['REMOTE_ADDR']) ? '127.0.0.1' : $_SERVER['REMOTE_ADDR'];
		$res['SERVER_ADDR'] = ('::1' == $_SERVER['SERVER_ADDR']) ? '127.0.0.1' : $_SERVER['SERVER_ADDR'];
		return $res;
	}


	 public static function form_bank_comments( $tid = '') {
        $tid = ( '' == $tid ) ? $_POST [ 'tid' ] : $tid;
        $config_value = self::get_novalnet_configuration();
        $novalnet_comments = '<br>';
        $novalnet_comments .= '<br>' . __( 'Please transfer the amount to the below mentioned account details of our payment processor Novalnet', 'novalnet' ) . '<br>';
        $novalnet_comments.= ( ! empty( $_POST [ 'due_date' ] ) ) ? __( 'Due date : ', 'novalnet' ) . date_i18n( get_option('date_format'), strtotime($_POST [ 'due_date' ])) . '<br>' : '';
        $novalnet_comments .= __( 'Account holder : Novalnet AG', 'novalnet' ) . '<br>';
        $novalnet_comments .= 'IBAN : ' . $_POST [ 'invoice_iban' ] . '<br>';
        $novalnet_comments .= 'BIC : ' . $_POST [ 'invoice_bic' ] . '<br>';
        $novalnet_comments .= 'Bank : ' . $_POST [ 'invoice_bankname' ] . ' ' . $_POST [ 'invoice_bankplace' ] . '<br>';

        $novalnet_comments .= __( 'Amount : ', 'novalnet' ) . apply_filters( 'tc_cart_currency_and_format', $_POST['amount'] ) .' '. '<br>';

        $novalnet_comments .=  __( 'Please use any one of the following references as the payment reference, as only through this way your payment is matched and assigned to the order : ', 'novalnet' );

        $novalnet_comments .= '<br>'.__( 'Payment Reference 1 : ', 'novalnet' )."BNR-".$config_value['product_id']."-".$_POST['order_no'].'<br>'.__( 'Payment Reference 2 : ', 'novalnet' )."TID ".$_POST['tid'].'<br>'.__( 'Payment Reference 3 : ', 'novalnet' ).__('Order number ', 'novalnet' ).' '.$_POST['order_no'];

        return self::novalnet_format_text( $novalnet_comments );
    }

   public static function novalnet_format_text( $text ) {
    return '<br>' . html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ). '<br>';
}


	public static function get_ticket_post_ids( $parant_id ) {
		/**
		 * returent ticket post id by order post id
		 **/
			global $wpdb;
			return $wpdb->get_results( $wpdb->prepare( "SELECT ID FROM " . $wpdb->posts . " WHERE post_parent = %s AND post_type = 'tc_tickets_instances'", $parant_id ),ARRAY_A );
		}

	public static function insert_callback_script_table_on_order_place(){
		/**
		 * insert callback table on order placing
		 **/
		global $wpdb;
		$wpdb->query("CREATE TABLE IF NOT EXISTS wp_novalnet_callback_script (
                        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                        order_no varchar(200) DEFAULT NULL,
                        amount int(11) NOT NULL,
                        callback_amount int(11) NOT NULL,
                        reference_tid bigint(20) NOT NULL,
                        callback_datetime datetime NOT NULL,
                        tid bigint(20) DEFAULT NULL,
                        payment_name varchar(200),
                        PRIMARY KEY (id),
                        KEY order_no (order_no),
                        KEY callback_amount (callback_amount),
                        KEY reference_tid (reference_tid),
                        KEY callback_datetime (callback_datetime),
                        KEY tid (tid),
                        KEY payment_name (payment_name)
            )");


    $amount = ( '27' == $_POST['key'] || ('PAYPAL' == $_POST['payment_type'] && $_POST['status'] == '90')) ? '': ($_POST['amount']*100);
    if( '49' == $_POST['key'] || '33' == $_POST['key'] || '34' == $_POST['key'] ){
		$amount = $amount/100;
		$order_amount = $_POST['amount'];
	}else{
		$order_amount = $_POST['amount'] * 100;
	}

    $data['order_no']=$_POST['order_no'];
    $data['callback_amount']=$amount;
    $data['amount'] = $order_amount;
    $data['reference_tid']='';
    $data['tid'] = $_POST['tid'];
    $data['payment_name']=self::get_payment_type_by_responce_key();
    self::insert_callback($data);

	}

	public static function insert_callback($data){
		/**
		 * perform insert callback script table db operation
		 *
		 **/
		global $wpdb;
		$table_name = $wpdb->prefix . "novalnet_callback_script";
		$wpdb->insert($table_name, array(
                                'order_no' => $data['order_no'],
                                'callback_amount' => $data['callback_amount'],
                                'amount'	=>	$data['amount'],
                                'reference_tid' => $data['reference_tid'],
                                'callback_datetime' => date("Y-m-d H:i:s"),
                                'tid' => $data['tid'],
                                'payment_name'	=>	$data['payment_name']
                                ),array(
                                '%s',
                                '%d',
                                '%d',
                                '%s',
                                '%s',
                                '%s',
                                '%s')
        );

	}
	public static function get_payment_type_by_responce_key( $front_end = false ){
		/**
		 * return payment type by payment key
		 **/
		 if(!$front_end){
		$payment_name = array(
			'6'	    =>	__( 'novalnet_cc', 'novalnet' ),
			'27'    =>	array(__( 'novalnet_prepayment', 'novalnet' ),__( 'novalnet_invoice', 'novalnet' )),
			'37'	=>	__( 'novalnet_sepa', 'novalnet' ),
			'33'	=>	__( 'novalnet_instant', 'novalnet' ),
			'34'	=>	__( 'novalnet_paypal', 'novalnet' ),
			'49'	=>	__( 'novalnet_ideal', 'novalnet' ),
			'59'	=>	__( 'novalnet_cashpayment', 'novalnet' ),
			'50'	=>	__( 'novalnet_eps', 'novalnet' ),
			'69'	=>	__( 'novalnet_giropay', 'novalnet' ),
			'78'    =>	__( 'novalnet_przelewy24', 'novalnet' ),
		);
		if('INVOICE_START' == $_POST['payment_type'] ){
			 if('prepayment' == $_POST['invoice_type']){
				 return __( 'novalnet_prepayment', 'novalnet' );
			 }else{
				 return __( 'novalnet_invoice', 'novalnet' );
			 }
		}
		}else{
		$payment_name = array(
			'6'	    =>	__( 'Credit Card', 'novalnet' ),
			'37'	=>	__( 'Direct Debit SEPA', 'novalnet' ),
			'33'	=>	__( 'Instant Bank Transfer', 'novalnet' ),
			'34'	=>	__( 'PayPal', 'novalnet' ),
			'49'	=>	__( 'iDEAL', 'novalnet' ),
			'59'	=>	__( 'Barzahlen/viacash', 'novalnet' ),
			'50'	=>	__( 'eps', 'novalnet' ),
			'69'	=>	__( 'giropay', 'novalnet' ),
			'78'   =>	__( 'przelewy24', 'novalnet' ),
		);
		if('INVOICE_START' == $_POST['payment_type'] ){
			 if('prepayment' == $_POST['invoice_type']){
				 return __( 'Prepayment', 'novalnet' );
			 }else{
				 return __( 'Invoice', 'novalnet' );
			 }
		}

		}
		return $payment_name[$_POST['key']];

	}

	public static function add_novalnet_payment_comments($post_id, $cancelled = false){
		/**
		 * Add novalnet transaction commends to post meta
		 *
		 **/
		global $wpdb;
		$table_name = $wpdb->prefix . "postmeta";
		$config_value = self::get_novalnet_configuration();
		$shop_payment_info = get_post_meta($post_id,'tc_payment_info');
		$payment = self::get_payment_type_by_responce_key(true);
		if(in_array($_POST['payment_type'], array('PAYPAL','ONLINE_TRANSFER','IDEAL','PRZELEWY24','EPS','GIROPAY'))){
		 $novalnet_config_param = self::get_novalnet_configuration();
		 $test_mode = self::decodeProcess( $_POST['test_mode'], $novalnet_config_param['access_key'] );
		}else{
		 $test_mode = $_POST['test_mode'];	
		}
		$test_order_status = ('1' == $test_mode) ? '<br>'.__('Test order','novalnet') : '';
		$novalnet_order_details = '<br>' . $payment . '<br>' .  __('Novalnet Transaction ID : ','novalnet') .' '. $_POST['tid']  . $test_order_status;
		if( '27' == $_POST['key']) $novalnet_order_details .= '<br>' . self::form_bank_comments();
			if($cancelled) $novalnet_order_details .= '<br>' . ( isset($_POST['status_desc']) ? $_POST['status_desc'] : ( isset($_POST['status_text']) ? $_POST['status_text'] : (isset($_POST['status_message']) ? $_POST['status_message'] : '')));
			$shop_payment_info[0]['transaction_id']=$novalnet_order_details;
			
			 if('CASHPAYMENT' == $_POST['payment_type']){
		            $novalnet_order_details .= '<br>'.'Slip expiry date: '.$_POST['cashpayment_due_date'].'<br>';
                    $novalnet_order_details .= '<br>'. 'Store(s) near you'.'<br>';
					$nearest_store =  self::getNearestStore($_POST,'nearest_store');
					 if (!empty($nearest_store)) {
					  $i = 0;
					foreach ($nearest_store as $key => $values) {
						$i++;
						if(!empty($nearest_store['nearest_store_title_'.$i])) {
							$novalnet_order_details .= '<br>' . $nearest_store['nearest_store_title_'.$i].'<br>';
						}
						if (!empty($nearest_store['nearest_store_street_'.$i])) {
							$novalnet_order_details .= $nearest_store['nearest_store_street_'.$i].'<br>';	
						}
						if(!empty($nearest_store['nearest_store_city_'.$i])) {
							$novalnet_order_details .= $nearest_store['nearest_store_city_'.$i].'<br>';
						}
						if(!empty($nearest_store['nearest_store_zipcode_'.$i])) {
							$novalnet_order_details .= $nearest_store['nearest_store_zipcode_'.$i].'<br>';
						}
						if(!empty($nearest_store['nearest_store_country_'.$i])) {
							$novalnet_order_details .= $nearest_store['nearest_store_country_'.$i].'<br>';
						}
					}
				}
				$shop_payment_info[0]['transaction_id']=$novalnet_order_details;
				}

	update_post_meta( $post_id, 'tc_payment_info', $shop_payment_info[0], $shop_payment_info='');
	add_post_meta( $post_id, 'novalnet_comments', $novalnet_order_details,  false );

	}

    /**
	 * To get nearest cashpayment store details 
	 * 
	 * @param array $response
	 * @param string $store_name
	 * return array
	 */ 

	public static function getNearestStore($response,$store_name){
		$stores_details = array();
		foreach ($response as $iKey => $stores_details){
			if(stripos($iKey,$store_name)!==FALSE){
				$stores[$iKey] = $stores_details;
			}
		}
		return $stores;
	}

	function novalnet_checks_tickera_active() {
    echo '<div id="notice" class="error"><p>' .  __( 'Tickera plugin must be active for the plugin <b>Novalnet Payment Gateway for Tickera</b>', 'wc-novalnet' ) . '</p></div>';
	}


	public static function send_novalnet_details($order_id, $attachments = array(), $cart_contents = false, $cart_info = false, $payment_info = false){
		global $tc;

	$tc_email_settings = get_option( 'tc_email_setting', false );

	$email_send_type = isset( $tc_email_settings[ 'email_send_type' ] ) ? $tc_email_settings[ 'email_send_type' ] : 'wp_mail';

	$order_id = strtoupper( $order_id );

	$order = tc_get_order_id_by_name( $order_id );

	if ( $cart_contents === false ) {
		$cart_contents = get_post_meta( $order->ID, 'tc_cart_contents', true );
	}

	if ( $cart_info === false ) {
		$cart_info = get_post_meta( $order->ID, 'tc_cart_info', true );
	}

	$buyer_name = $cart_info[ 'buyer_data' ][ 'first_name_post_meta' ] . ' ' . $cart_info[ 'buyer_data' ][ 'last_name_post_meta' ];

	if ( $payment_info === false ) {
		$payment_info = get_post_meta( $order->ID, 'tc_payment_info', true );
	}

	add_filter( 'wp_mail_content_type', 'set_content_type' );

	do_action( 'tc_before_order_created_email' );

	function set_content_type(){
		return "text/html";
	}
	add_filter( 'wp_mail_content_type','set_content_type' );

			add_filter( 'wp_mail_from', 'client_email_from_email', 999 );
			add_filter( 'wp_mail_from_name', 'client_email_from_name', 999 );

			$subject = isset( $tc_email_settings[ 'client_order_subject' ] ) ? $tc_email_settings[ 'client_order_subject' ] : __( 'Order Received', 'tc' );

			$default_message = 'Hello, <br /><br />Your order (ORDER_ID) totalling <strong>ORDER_TOTAL</strong> is completed. <br />';
			$message		 = isset( $tc_email_settings[ 'client_order_message' ] ) ? $tc_email_settings[ 'client_order_message' ] : $default_message;

			$order				 = new TC_Order( $order->ID );
			$order_status_url	 = '';

			$placeholders		 = array( 'ORDER_ID', 'ORDER_TOTAL', 'DOWNLOAD_URL', 'BUYER_NAME', 'ORDER_DETAILS' );
			$placeholder_values	 = array( $order_id, apply_filters( 'tc_cart_currency_and_format', $payment_info[ 'total' ] ), $order_status_url, $buyer_name, tc_get_order_details_email( $order->details->ID, $order->details->tc_order_date, true ,'' ));

			$to = $cart_info[ 'buyer_data' ][ 'email_post_meta' ];
			$message = str_replace('You can download your tickets DOWNLOAD_URL', '', $message);

			$message = str_replace( apply_filters( 'tc_order_completed_client_email_placeholders', $placeholders ), apply_filters( 'tc_order_completed_client_email_placeholder_values', $placeholder_values ), $message );

			$client_headers = '';

			if ( $email_send_type == 'wp_mail' ) {
				wp_mail( $to, $subject, self::novalnet_format_text( stripcslashes( apply_filters( 'tc_order_completed_admin_email_message', wpautop( $message ) ) ) ), apply_filters( 'tc_order_completed_client_email_headers', $client_headers ), $attachments);
				remove_filter( 'wp_mail_content_type','set_content_type' );
			} else {
				$headers = 'MIME-Version: 1.0' . "\r\n";
				$headers .= 'Content-type: text/html; charset=UTF-8' . "\r\n";
				$headers .= 'From: ' . client_email_from_email( '' ) . "\r\n" .
				'Reply-To: ' . client_email_from_email( '' ) . "\r\n" .
				'X-Mailer: PHP/' . phpversion();

				mail( $to, $subject, stripcslashes( wpautop( $message ) ), apply_filters( 'tc_order_completed_client_email_headers', $headers ), $attachments);
			}

	}

	}
}
