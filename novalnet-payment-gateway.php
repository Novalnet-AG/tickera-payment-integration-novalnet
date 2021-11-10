<?php
/*
 * Plugin Name: Novalnet Payment Plugin - Tickera
 * Plugin URI : https://www.novalnet.de/modul/tickera
 * Description: Plug-in to process payments in Tickera through Novalnet Gateway
 * Author:      Novalnet AG
 * Author URI:  https://www.novalnet.de
 * Version:     1.1.0
 * Text Domain: novalnet
 * Domain Path: /languages/
 * License:     GNU General Public License
 */
global $tc;

if ( file_exists( $tc->plugin_dir . "/includes/classes/class.payment_gateways.php" )) {
	include_once $tc->plugin_dir . "/includes/classes/class.payment_gateways.php";
	include_once( $tc->plugin_dir . 'includes/classes/class.ticket_template.php' );
}

register_deactivation_hook( __FILE__ , 'novalnet_uninstallation_process' );

function novalnet_uninstallation_process() {
	$settings = get_option( 'tc_settings' );
	if (isset($settings['gateways']['novalnet_payment'])) {
		unset($settings['gateways']['novalnet_payment']);
	}
	update_option('tc_settings', $settings);
}

load_plugin_textdomain( 'novalnet', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
include_once( 'novalnet/class.novalnet.php' );


if ( class_exists( 'TC_Gateway_API' ) ) {
	$settings = get_option( 'tc_settings' );
		if(!in_array('novalnet_payment',$settings['gateways']['active'])){
			$settings['gateways']['novalnet_payment'] = array();
			update_option( 'tc_settings', $settings );
		}

class TC_Gateway_Novalnet_Payment extends TC_Gateway_API {

	var $plugin_name				 = 'novalnet_payment';
	var $admin_name				 = '';
	var $public_name				 = '';
	var $method_img_url			 = '';
	var $admin_img_url			 = '';
	var $vendor_id;
	var $auth_code;
	var $product_id;
	var $tariff;
	var $invoice_due_date;
	var $sepa_due_date;
	var $reference1;
	var $reference2;
	var $onhold_limit;
	var $access_key;
	var $referrer_id;
	var $test_mode=0;
	var $enable_email;
	var $callback_test_mode;
	var $debug_mode=0;
	var $email_to;
	var $email_bcc;
    var $skip_payment_screen = true;



	//Support for older payment gateway API
	function on_creation() {
		$this->init();
	}

	function init() {
		global $tc,$wp;
		$this->admin_name	 = __( 'Novalnet Payment', 'novalnet' );
		$this->public_name	 = __( 'Pay with Novalnet (over 100 payment methods worldwide)', 'novalnet' );

		$this->method_img_url	 = apply_filters( 'tc_gateway_method_img_url', wp_make_link_relative(plugin_dir_url( __FILE__ ) . 'images/nnlogofront.png'), $this->plugin_name );

		$this->admin_img_url	 = apply_filters( 'tc_gateway_admin_img_url', wp_make_link_relative(plugin_dir_url( __FILE__ ) . 'images/nnlogo.png'), $this->plugin_name );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( &$this, 'action_novalnet_links' ) );
		add_filter( 'tc_order_fields', array( $this, 'add_novalnet_comments'),10 );
		add_action( 'init', array( $this, 'process_callback') );

	}

	public function process_callback() {
		if( ! empty( $_REQUEST ['tc-api'] ) && 'novalnet_callback' == $_REQUEST ['tc-api'] ) {
			include_once('novalnet-callback-api.php');
		}
	}

	public function add_novalnet_comments( $default_fields ) {
		$value_exist = false;
		foreach($default_fields as  $value) {
			if(isset($value['id']) && $value['id'] == 'noval_comments' ){
				$value_exist = true;
				break;
			}
		}
		if( ! $value_exist ) {
			$default_fields[] = array(
				'id'				 => 'noval_comments',
				'field_name'		 => 'noval_comments',
				'field_title'		 => ' ',
				'field_type'		 => 'function',
				'function'			 => 'get_novalnet_comments',
				'field_description'	 => '',
				'table_visibility'	 => true,
				'post_field_type'	 => 'post_meta'
			);
		}
		return $default_fields;

    }

	public function action_novalnet_links( $links ) {
		if(!in_array('settings',$links)) {
			return array_merge( array( 'settings' => '<a href="' . admin_url( 'edit.php?post_type=tc_events&page=tc_settings&tab=gateways' ) . '">' . __( 'Settings','novalnet' ) . '</a>' ) , $links );
		}

    }

	function payment_form( $cart ) {
		global $tc;
		if(!empty($_SESSION['novalnet_message'])) 
			echo $_SESSION['novalnet_message'];
		unset($_SESSION['novalnet_message']);
		return $this->get_option( 'info' );
	}

	function process_payment( $cart ) {
		global $tc;
		$this->maybe_start_session();
		$this->save_cart_info();
		$url = 'https://paygate.novalnet.de/paygate.jsp?';
		$order_id = $tc->generate_order_id();
		$paid = false;
		$payment_info = $this->save_payment_info();
		$tc->create_order( $order_id, $this->cart_contents(), $this->cart_info(), $payment_info, $paid );
		$_SESSION['order_id'] = $order_id;
		$_SESSION['cart_info'] = $this->cart_info();
		$_SESSION['payment_info'] = $payment_info;
		$common_param = Novalnet::common_parameters();
		$common_param ['first_name'] = $this->buyer_info( 'first_name' );
        $common_param ['last_name']  = $this->buyer_info( 'last_name' );
        $common_param ['email'] 	 = $this->buyer_info( 'email' );
        $common_param ['lhide']       = '1';
		$frmData = '<form name="frmnovalnet" method="post" action="'.$url.'">';

            $frmEnd  = __('You will be redirected to Novalnet AG in a few seconds', 'novalnet' ) . '<br> <input type="submit" id="enter" name="enter" value="'.__('Continue', 'novalnet' ).'" onClick="document.forms.frmnovalnet.submit();document.getElementById(\'enter\').disabled=\'true\';" />';
            $js      = '<script type="text/javascript" language="javascript">window.onload = function() { document.forms.frmnovalnet.submit(); } </script>';

             foreach($common_param as $k => $v ) {
               $frmData .= '<input type="hidden" name="' . $k . '" value="' . $v . '" />' . "\n";
              }
              $frmData .= '<input type="hidden" name="chosen_only" value="0" />' . "\n";
              $frmData .= '<input type="hidden" name="address_form" value="1" />' . "\n";
            $frmEnd.='</form>';
            echo $frmData, $frmEnd, $js;
            exit();
	}

	function order_confirmation( $order, $payment_info = '', $cart_info = '' ) {
		global $tc,$wp;
		$ticket_status = false;
		$order_post_id = $tc->order_to_post_id($order);
		$ticket_instance_ids = Novalnet::get_ticket_post_ids($order_post_id);
		if(isset($_POST) && isset($_POST['tid'])){
			$order = new TC_Order( $_POST['order_no'] );
				$ticket_status = true;
				$payment_status = true;

			if(($_POST['status'] == '100' ||($_POST['key'] == '34' && $_POST['status'] == '90')) && in_array($_POST['key'],array(6,37,27,33,34,49,69,50,59))) {
				if('INVOICE_START' == $_POST['payment_type'] || ('PAYPAL' == $_POST['payment_type'] && $_POST['status'] == '90') || $_POST['tid_status'] != '100'){
					$ticket_status = false;
					$payment_status = false;
				}
				if( 'INVOICE_START' == $_POST['payment_type'] && 'prepayment' != $_POST['invoice_type'] ){
					$ticket_status = true;
				}
				Novalnet::add_novalnet_payment_comments($order_post_id);
				$attachments = array();
				if(isset($_POST['invoice_type']) && 'invoice' == $_POST['invoice_type']){
				$ticket_no = 1;
				foreach($ticket_instance_ids as $data){
					$ticket_instance_id = $data['ID'];
					Novalnet::generate_preview($ticket_instance_id,false,false,$order->id, $ticket_no);
					$ticket_no++;
				}
				$number_of_tickets = count($ticket_instance_ids);
				for($i=1;$i<=$number_of_tickets;$i++){
					$attachments[] = WP_CONTENT_DIR . '/uploads/' . $_POST['order_no'] . '-' . $i. '.pdf';
				}

				}

				if($payment_status) {
					$tc->update_order_payment_status( $_POST['order_no'], true );
				}else{
					Novalnet::send_novalnet_details( $_POST['order_no'], $attachments );
				}

			Novalnet::insert_callback_script_table_on_order_place();


			}elseif( isset($_POST['status']) &&  !in_array( $_POST['status'], array('100','90'))){
				$_SESSION['novalnet_message']="<span style='color:red'>".__($_POST['status_desc'],'novalnet')."</span>";
				Novalnet::add_novalnet_payment_comments($order_post_id, true);
				$tc->update_order_status( $order_post_id, 'order_fraud' );
				wp_redirect( $tc->get_payment_slug( true ) );
				tc_js_redirect( $tc->get_payment_slug( true ) );
				exit;
			}
		}
	}

	function gateway_admin_settings( $settings, $visible ) {
		global $tc;

		echo '<input type="hidden" id="nn_field_error" value="' . __( 'Please fill in all the mandatory fields', 'novalnet' ).'" "><script type="text/javascript" src=' . wp_make_link_relative(plugin_dir_url( __FILE__ ) . 'js/novalnet.js').'></script>';
		?>

		<div id="<?php echo $this->plugin_name; ?>" class="postbox" <?php echo (!$visible ? 'style="display:none;"' : ''); ?>>
			<h3 class='handle'><span><?php printf( __( '%s', 'novalnet' ), $this->admin_name ); ?></span></h3>
			<div class="inside">

				<?php
				$fields = array(
					'test_mode'		 => array(
						'title'	 		 => __( 'Enable test mode', 'novalnet' ),
						'type' 		 => 'checkboxes',
						'description'	 => __( 'The payment will be processed in the test mode therefore amount for this transaction will not be charged', 'novalnet' ),
						'default'	 	 => $this->test_mode,
						'options'		 => array('1'=>' '),
						),
					'vendor_id'		 => array(
						'title'			 => __( 'Merchant ID', 'novalnet' ),
						'type'			 => 'text',
						'description'	 => __( 'Enter Novalnet merchant ID', 'novalnet' ),
						'default'		 => $this->vendor_id,
						'custom_attributes' => array( 'autocomplete' => 'off')
						),
					'auth_code'		 => array(
						'title'			 => __( 'Authentication code', 'novalnet' ),
						'type'			 => 'text',
						'description'	 => __( 'Enter Novalnet authentication code', 'novalnet' ),
						'default'		 => $this->auth_code,
						'custom_attributes' => array( 'autocomplete' => 'off')
						),
					'product_id'		 => array(
						'title'			 => __( 'Project ID', 'novalnet' ),
						'type'			 => 'text',
						'description'	 => __( 'Enter Novalnet project ID', 'novalnet' ),
						'default'		 => $this->product_id,
						'custom_attributes' => array( 'autocomplete' => 'off')
						),
					'tariff'		     => array(
						'title'			 => __( 'Tariff ID', 'novalnet' ),
						'type'			 => 'text',
						'description'	 => __( 'Enter Novalnet tariff ID', 'novalnet' ),
						'default'		 => $this->tariff,
						'custom_attributes' => array( 'autocomplete' => 'off')
						),
					'access_key'		 => array(
						'title'			 => __( 'Payment access key', 'novalnet' ),
						'type'			 => 'text',
						'description'	 => __( 'Enter the Novalnet payment access key', 'novalnet' ),
						'default'		 => $this->access_key,
						'custom_attributes' => array( 'autocomplete' => 'off')
						),
					'on-hold'		 	 => array(
						'title'			 => __( 'Set a limit for on-hold transaction (in cents)', 'novalnet' ),
						'type'			 => 'text',
						'description'	 => __( 'In case the order amount exceeds mentioned limit, the transaction will be set on hold till your confirmation of transaction', 'novalnet' ),
						'default'		 => $this->onhold_limit,
						'custom_attributes' => array( 'autocomplete' => 'off')
						),
					'referrer_id'		 => array(
						'title'			 => __( 'Referrer ID', 'novalnet' ),
						'type'			 => 'text',
						'description'	 => __( 'Enter the referrer ID of the person/company who recommended you Novalnet', 'novalnet' ),
						'default'		 => $this->referrer_id,
						'custom_attributes' => array( 'autocomplete' => 'off')
						),
					'sepa_due_date'		 => array(
						'title'	 		 => __( 'SEPA Payment duration (in days)', 'novalnet' ),
						'type' 		 => 'text',
						'description'	 => __( 'Enter the number of days after which the payment should be processed (must be greater than 6 days)', 'novalnet' ),
						'default'	 	 => $this->sepa_due_date,
						'custom_attributes' => array( 'autocomplete' => 'off')
						),
					'invoice_due_date'		 => array(
						'title'	 		 => __( 'Payment due date (in days)', 'novalnet' ),
						'type' 		 => 'text',
						'description'	 => __( 'Enter the number of days to transfer the payment amount to Novalnet (must be greater than 7 days). In case if the field is empty, 14 days will be set as due date by default', 'novalnet' ),
						'default'	 	 => $this->invoice_due_date,
						'custom_attributes' => array( 'autocomplete' => 'off')
						),
					'reference1'		 => array(
						'title'	 		 => __( 'Transaction reference 1', 'novalnet' ),
						'type' 		 => 'text',
						'description'	 => __( 'This reference will appear in your bank account statement', 'novalnet' ),
						'default'	 	 => $this->reference1,
						'custom_attributes' => array( 'autocomplete' => 'off')
						),
					'reference2'		 => array(
						'title'	 		 => __( 'Transaction reference 2', 'novalnet' ),
						'type' 		 => 'text',
						'description'	 => __( 'This reference will appear in your bank account statement', 'novalnet' ),
						'default'	 	 => $this->reference2,
						'custom_attributes' => array( 'autocomplete' => 'off')
						),

					'enable_email'		 => array(
						'title'	 		 => __( 'Enable E-mail notification for callback', 'novalnet' ),
						'type'	 		 => 'checkboxes',
						'description'	 => '',
						'default'	 	 => $this->enable_email,
						'options'		 => array('1'=>' ',),
						),

					'callback_test_mode'		 => array(
						'title'	 		 => __( 'Enable test mode', 'novalnet' ),
						'type' 		 => 'checkboxes',
						'description'	 => '',
						'default'	 	 => $this->callback_test_mode,
						'options'		 => array('1'=>' '),
						),
					'debug_mode'		 => array(
						'title'	 		 => __( 'Enable debug mode', 'novalnet' ),
						'type' 		 => 'checkboxes',
						'description'	 => __('Set the debug mode to execute the merchant script in debug mode','novalnet'),
						'default'	 	 => $this->debug_mode,
						'options'		 => array('1'=>''),
						),
					'email_to'		 => array(
						'title'			 => __( 'E-mail address (To)', 'novalnet' ),
						'type'			 => 'text',
						'description'	 => __('E-mail address of the recipient','novalnet'),
						'default'		 => $this->email_to
						),
					'email_bcc'		 => array(
						'title'			 => __( 'E-mail address (Bcc)', 'novalnet' ),
						'type'			 => 'text',
						'description'	 => __('E-mail address of the recipient for BCC','novalnet'),
						'default'		 => $this->email_bcc
						),
					);
				$form = new TC_Form_Fields_API( $fields, 'tc', 'gateways', $this->plugin_name );

				unset($form->form_fields['skip_confirmation_page']);
				?>

				<table class="form-table">
					<?php $form->admin_options();
					?>
				</table>

			</div>
		</div>
		<?php
	}
}
function get_novalnet_comments($field_name = '', $post_id = ''){
	$cart_contents	 = get_post_meta( $post_id, 'novalnet_comments', true );
	echo $cart_contents;
}
tc_register_gateway_plugin( 'TC_Gateway_Novalnet_Payment', 'novalnet_payment', __( 'Novalnet Payment', 'novalnet' ) );
}else{
	add_action( 'admin_notices', 'Novalnet::novalnet_checks_tickera_active' );
    return;
}
