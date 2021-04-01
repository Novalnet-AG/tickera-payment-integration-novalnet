<?php
/**
 * Novalnet Callback Script for Wordpress Tickera plugin
 *
 * NOTICE
 *
 * This script is used for real time capturing of parameters
 * passed from Novalnet AG after Payment processing of
 * customers.
 *
 * This script is only free to the use for Merchants of
 * Novalnet AG
 *
 * If you have found this script useful a small recommendation
 * as well as a comment on merchant form would be greatly
 * appreciated.
 *
 * Please contact sales@novalnet.de for enquiry or info
 *
 * ABSTRACT: This script is called from Novalnet, as soon as
 * a payment done for payment methods.
 * An email will be sent if an error occurs
 *
 * @category   Novalnet
 * @package    Novalnet
 * @version    1.1.0
 * @copyright  Copyright (c)  Novalnet AG. (https://www.novalnet.de)
 * @license    GNU General Public License
 */
require_once( 'wp-load.php' );
global $tc,$wpdb,$processTestMode,$processDebugMode;
include_once dirname( __FILE__ ).'/novalnet/class.novalnet.php';
$helper_object = new Novalnet();
$config_values = $helper_object->get_novalnet_configuration();

$aryCaptureparams = $_REQUEST;// Assign Callback parameters

$aryCaptureparams = array_map('trim',$aryCaptureparams);

$processTestMode    = ( isset($config_values['callback_test_mode'])) ? true : false;
$processDebugMode   = ( isset($config_values['debug_mode'])) ? true : false;

$nnVendorScript = new NovalnetVendorScript($aryCaptureparams); 

add_filter( 'wp_mail_content_type','set_content_type' );
add_filter( 'wp_mail_from', 'client_email_from_email', 999 );
add_filter( 'wp_mail_from_name', 'client_email_from_name', 999 );

if( isset( $aryCaptureparams['vendor_activation'] ) && 1 == $aryCaptureparams['vendor_activation'] ) {
	
}
else{
	$nntransHistory = $nnVendorScript->getOrderReference(); // Order reference of given callback request using novalnet_callback_history table

	 $nnCaptureParams = $nnVendorScript->getCaptureParams(); // Collect callback capture parameters
	    $order_id = $nntransHistory['order_no']; // Given shop order ID
	     $post_id = $tc->order_to_post_id($order_id);
         if($nnVendorScript->getPaymentTypeLevel() == 2 && $nnCaptureParams['tid_status'] == 100) {
			//Credit entry of INVOICE or PREPAYMENT
            if(in_array($nnCaptureParams['payment_type'] , array('INVOICE_CREDIT','CASHPAYMENT_CREDIT'))) {

				 if($nnCaptureParams['subs_billing'] != 1) {	
					 if ($nntransHistory['order_paid_amount'] <= $nntransHistory['order_total_amount']) {
						
						 $callback_comments = PHP_EOL.sprintf( __('Novalnet Callback Script executed successfully for the TID: %s with amount %s %s on %s. Please refer PAID transaction in our Novalnet Merchant Administration with the TID: %s', 'novalnet' ), $nnCaptureParams['shop_tid'], ($nnCaptureParams['amount']/100), $nnCaptureParams['currency'], date_i18n( get_option('date_format'), strtotime(date('Y-m-d'))), $nnCaptureParams['tid'] ).PHP_EOL;			 
						 
						 
						 						 
						 if($nntransHistory['order_total_amount'] <= ($nntransHistory['order_paid_amount'] + $nnCaptureParams['amount'])) {							
							 	//Full Payment paid
							 	 
						$payment_name = $nnVendorScript->get_payment_text($nnCaptureParams['payment_type'],$nntransHistory['payment_name'] == 'novalnet_invoice' ? true : false);
						$nn_comments = PHP_EOL.$payment_name.PHP_EOL.__('Novalnet Transaction ID : ','novalnet'). $nnCaptureParams['shop_tid'];
						if(!empty($aryCaptureparams['test_mode']))
						{
							$nn_comments .= PHP_EOL . __('Test order','novalnet').PHP_EOL;
						}	
						
						$novalnet_transaction_post = get_post_meta($post_id,'tc_payment_info');	
						$novalnet_transaction_post[0]['transaction_id']=$nn_comments;	
						update_post_meta( $post_id, 'tc_payment_info', $novalnet_transaction_post[0]);						
							$tc->update_order_payment_status( $order_id, true );
						} 								
						$nnVendorScript->updateOrderComment($post_id,$callback_comments);
												
						 if(isset($config_values['enable_email']) && is_email($config_values['email_to'])){
							 $to[] = $config_values['email_to'];
							if( is_email($config_values['email_bcc'])){
								 $to[] = $config_values['email_bcc']; 
							}
							 wp_mail($to,'Callback Script Execution',$callback_comments);
						 }
						$callback_table_param = array(
                                'order_no' => $order_id,
                                'tid' => $nnCaptureParams['shop_tid'],
                                'reference_tid' => $nnCaptureParams['tid'],
                                'callback_amount' => $nnCaptureParams['amount'],
                                'amount' => $nntransHistory['order_total_amount'],
                                'payment_name' => $nntransHistory['payment_name'],
                        );
					
					$nnVendorScript->callback_script_table_insert($callback_table_param);
					$nnVendorScript->display_message($callback_comments );
					
				 }else{
					 $nnVendorScript->display_message('Novalnet callback received. Callback Script executed already. Refer Order :'.$order_id);
				 }		
							
			} 			
						
		 }			 
		 }else if($nnVendorScript->getPaymentTypeLevel() == 1 && $nnCaptureParams['tid_status'] == 100) {
			
			 $callback_comments = in_array($nnCaptureParams['payment_type'], array('PAYPAL_BOOKBACK', 'REFUND_BY_BANK_TRANSFER_EU','CREDITCARD_BOOKBACK', 'PRZELEWY24_REFUND','CASHPAYMENT_REFUND','GUARANTEED_INVOICE_BOOKBACK','GUARANTEED_SEPA_BOOKBACK')) ? sprintf( __(' Novalnet callback received. Refund/Bookback executed successfully for the TID: %s amount: %s %s on %s. The subsequent TID: %s.', 'novalnet' ), $nntransHistory['tid'], sprintf( '%0.2f',( $nnCaptureParams['amount']/100) ) , $nnCaptureParams['currency'], date_i18n( get_option('date_format'), strtotime(date('Y-m-d'))), $nnCaptureParams['tid'] ) . PHP_EOL : sprintf( __(' Novalnet callback received. Chargeback executed successfully for the TID: %s amount: %s %s on %s. The subsequent TID: %s.', 'novalnet' ), $nntransHistory['tid'], sprintf( '%0.2f',( $nnCaptureParams['amount']/100) ), $nnCaptureParams['currency'], date_i18n( get_option('date_format'), strtotime(date('Y-m-d'))), $nnCaptureParams['tid'] ) . PHP_EOL;
			 
			 
			 $callback_table_param = array(
									'order_no'  => $nnCaptureParams['order_no'],
									'tid'  => $nnCaptureParams['shop_tid'],
									'reference_tid'  => $nnCaptureParams['tid'],
									'callback_amount'  => $nnCaptureParams['amount'],
									'amount'  => $nntransHistory['order_total_amount'],
									'payment_name'  => $nntransHistory['payment_name'],
									);
			 $novalnet_transaction_post = get_post_meta($post_id,'tc_payment_info');	
			 $novalnet_transaction_post[0]['transaction_id'] = $novalnet_transaction_post[0]['transaction_id'] . PHP_EOL . $callback_comments;	
			 update_post_meta( $post_id, 'tc_payment_info', $novalnet_transaction_post[0]);									
             $nnVendorScript->updateOrderComment($post_id,$callback_comments);
             $nnVendorScript->callback_script_table_insert($callback_table_param);
			 if(isset($config_values['enable_email']) && is_email($config_values['email_to'])){
					 $to[] = $config_values['email_to'];
					if( is_email($config_values['email_bcc'])){
						 $to[] = $config_values['email_bcc']; 
					}
					 wp_mail($to,'Callback Script Execution',$callback_comments);
				 }
             $nnVendorScript->display_message( $callback_comments );
			 
		 }else if($nnVendorScript->getPaymentTypeLevel() == 0 && $nnCaptureParams['tid_status'] == 100){
			if($nnCaptureParams['subs_billing'] == 1) {
               
            }else if($nnCaptureParams['payment_type'] == 'PAYPAL') {
               if ($nntransHistory['order_paid_amount'] < $nntransHistory['order_total_amount']) {
                   
					$callback_comments = '';

					$test_order == (!empty($aryCaptureparams['test_mode'])) ? 'Test order' : '';
					
					$nn_comments = PHP_EOL.'Novalnet transaction details'.PHP_EOL.'Transaction ID : '. $nnCaptureParams['shop_tid'].PHP_EOL.$test_order.PHP_EOL;
					$novalnet_transaction_post = get_post_meta($post_id,'tc_payment_info');	
					$novalnet_transaction_post[0]['transaction_id']=$nn_comments;	
					update_post_meta( $post_id, 'tc_payment_info', $novalnet_transaction_post[0]);
					
					$tc->update_order_payment_status( $_POST['order_no'], true );
					
					$callback_comments = PHP_EOL.sprintf( __('Novalnet Callback Script executed successfully for the TID: %s with amount %s %s on %s. Please refer PAID transaction in our Novalnet Merchant Administration with the TID: %s', 'novalnet' ), $nnCaptureParams['shop_tid'], ($nnCaptureParams['amount']/100), $nnCaptureParams['currency'], date_i18n( get_option('date_format'), strtotime(date('Y-m-d'))), $nnCaptureParams['tid'] ).PHP_EOL;

					$nnVendorScript->updateOrderComment($post_id,$callback_comments);
                 
					$callback_table_param = array(
                                'order_no' => $order_id,
                                'tid' => $nnCaptureParams['shop_tid'],
                                'reference_tid' => $nnCaptureParams['tid_payment'],
                                'callback_amount' => $nntransHistory['order_total_amount'],
                                'amount' => $nntransHistory['order_total_amount'],
                                'payment_name' => $nntransHistory['payment_name'],
                                );
				if(isset($config_values['enable_email']) && is_email($config_values['email_to'])){
					 $to[] = $config_values['email_to'];
					if( is_email($config_values['email_bcc'])){
						 $to[] = $config_values['email_bcc']; 
					}
					wp_mail($to,'Callback Script Execution',$callback_comments);
				 }						
					
				$nnVendorScript->callback_script_table_insert($callback_table_param);
				$nnVendorScript->display_message($callback_comments);
                 }else
                {
					$nnVendorScript->display_message('Novalnet Callbackscript received. Order already Paid');
				}
                   
            } else {
                $error = 'Novalnet Callbackscript received. Payment type ( '.$nnCaptureParams['payment_type'].' ) is not applicable for this process!';
                $nnVendorScript->display_message($error);
            }
		 } else {
			 $nnVendorScript->display_message('Novalnet callback received. TID Status ('.$aryCapture['tid_status'].') is not valid: Only 100 is allowed');
		 }
}
	
	class NovalnetVendorScript {
		/** @Array Type of payment available - Level : 0 */
		protected $aryPayments = array(
				'CREDITCARD',
				'INVOICE_START',
				'DIRECT_DEBIT_SEPA',
				'GUARANTEED_INVOICE',
				'PAYPAL',
				'ONLINE_TRANSFER',
				'IDEAL',
				'EPS',
			);
  /** @Array Type of Chargebacks available - Level : 1 */
  protected $aryChargebacks = array(
				'RETURN_DEBIT_SEPA',
				'CREDITCARD_BOOKBACK',
				'CREDITCARD_CHARGEBACK',
				'REVERSAL',
				'REFUND_BY_BANK_TRANSFER_EU',
				'CASHPAYMENT_REFUND',
			);
  
    /** @Array Type of CreditEntry payment and Collections available - Level : 2 */
  protected $aryCollection = array(
				'INVOICE_CREDIT',
				'CREDIT_ENTRY_CREDITCARD',
				'CREDIT_ENTRY_SEPA',
				'DEBT_COLLECTION_SEPA',
				'DEBT_COLLECTION_CREDITCARD',
				'CREDIT_ENTRY_DE',
				'CASHPAYMENT_CREDIT',
			);
  
  protected $arySubscription = array(
				'SUBSCRIPTION_STOP'
			);
 
  protected $aryPaymentGroups = array(
				'novalnet_cc' => array(
						'CREDITCARD', 
						'CREDITCARD_BOOKBACK', 
						'CREDITCARD_CHARGEBACK', 
						'CREDIT_ENTRY_CREDITCARD',
						'SUBSCRIPTION_STOP',
						'DEBT_COLLECTION_CREDITCARD'
						),
				'novalnet_sepa' => array(
						'DIRECT_DEBIT_SEPA', 
						'RETURN_DEBIT_SEPA',
						'SUBSCRIPTION_STOP',
						'DEBT_COLLECTION_SEPA',
						'CREDIT_ENTRY_SEPA',
						'REFUND_BY_BANK_TRANSFER_EU',
				),
				'novalnet_ideal' => array(
						'IDEAL',
						'REVERSAL',
						'REFUND_BY_BANK_TRANSFER_EU',
						'CREDIT_ENTRY_DE',
				),
				'novalnet_instant' => array(
						'ONLINE_TRANSFER',
						'REVERSAL',
						'REFUND_BY_BANK_TRANSFER_EU',
						'CREDIT_ENTRY_DE',
				),
				'novalnet_paypal' => array(
						'PAYPAL', 
						'SUBSCRIPTION_STOP',
						'PAYPAL_BOOKBACK'
				),
				'novalnet_prepayment' => array(
						'INVOICE_START',
						'INVOICE_CREDIT', 
						'SUBSCRIPTION_STOP',
						'REFUND_BY_BANK_TRANSFER_EU'
				),
				'novalnet_invoice' => array(
						'INVOICE_START',  
						'INVOICE_CREDIT',  
						'SUBSCRIPTION_STOP',
						'REFUND_BY_BANK_TRANSFER_EU',
						'CREDIT_ENTRY_DE',
				),
				'novalnet_eps' => array(
						'EPS',
						'REVERSAL',
						'REFUND_BY_BANK_TRANSFER_EU',
						'CREDIT_ENTRY_DE',
				),
				'novalnet_cashpayment' => array(
						'CASHPAYMENT_CREDIT',
						'CASHPAYMENT_REFUND'
				)
   );
  /** @Array Callback Capture parameters */
  protected $aryCaptureparams = array();
  protected $paramsRequired = array();

  
    function __construct($aryCapture = array()) {
	self::validateIpAddress();
	$this->paramsRequired = array('vendor_id', 'tid', 'payment_type', 'status', 'amount', 'tid_status');
	
	 if (isset($aryCapture['subs_billing']) && $aryCapture['subs_billing'] == 1){
      array_push($this->paramsRequired, 'signup_tid');
    } elseif (isset($aryCapture['payment_type'])
				&& in_array($aryCapture['payment_type'], array_merge($this->aryChargebacks, array('INVOICE_CREDIT')))) {
    array_push($this->paramsRequired, 'tid_payment');
    }

	$this->aryCaptureparams = self::validateCaptureParams($aryCapture);
     
  }
  
  
  
   /*
  * Validate IP address
  *
  * @return void
  */
  function validateIpAddress() {
		global $processTestMode;
		$client_ip = tc_get_client_ip();
		$get_host_name = gethostbyname('pay-nn.de');
		if(empty($get_host_name)){
			echo "Novalnet HOST IP missing";exit;
		}elseif($get_host_name !== $client_ip && !$processTestMode){
			echo "Novalnet callback received. Unauthorised access from the IP ".$client_ip;exit;
		}
    } 
  
  
  /*
  * Perform parameter validation process
  * @param $aryCapture
  *
  * @return array
  */
  function validateCaptureParams($aryCapture) {
        if(!isset($aryCapture['vendor_activation'])) {
            foreach ($this->paramsRequired as $v) {
                if (empty($aryCapture[$v])) {
                      self::display_message('Required param ( ' . $v . '  ) missing!');
                     }
                if (in_array($v, array('tid', 'tid_payment', 'signup_tid')) && !preg_match('/^\d{17}$/', $aryCapture[$v])) {
                    self::display_message('Novalnet callback received. Invalid TID ['. $aryCapture[$v] . '] for Order.');
                }
            }
            
            if (!in_array(($aryCapture['payment_type']), array_merge($this->aryPayments, $this->aryChargebacks, $this->aryCollection,$this->arySubscription))) {
                self::display_message('Novalnet callback received. Payment type ( '.$aryCapture['payment_type'].' ) is mismatched!');
            } 
            if (isset($aryCapture['status']) && $aryCapture['status'] !=100)  {
                self::display_message('Novalnet callback received. Status ('.$aryCapture['status'].') is not valid: Only 100 is allowed');
            }
            if (!is_numeric($aryCapture['amount'])) {
              self::display_message('Novalnet callback received. The requested amount ('. $aryCapture['amount'] .') is not valid');
            }
            if (!is_numeric($aryCapture['amount']) || $aryCapture['amount'] < 0) {
                self::display_message('Novalnet callback received. The requested amount (' . $aryCapture['amount'] . ') is not valid');
            }
            if(!empty($aryCapture['signup_tid'])) { // Subscription
                $aryCapture['shop_tid'] = $aryCapture['signup_tid'];
            }
            else if(in_array($aryCapture['payment_type'], array_merge($this->aryChargebacks, array('INVOICE_CREDIT')))) {
                $aryCapture['shop_tid'] = $aryCapture['tid_payment'];
            }
            else if(!empty($aryCapture['tid'])) {
                $aryCapture['shop_tid'] = $aryCapture['tid'];
            }
               }
           return $aryCapture;
  }
  /*
  * Display the error message
  * @param $errorMsg
  *
  * @return void
  */
  public function display_message($errorMsg) {
	    global $processDebugMode;
     if ($processDebugMode) {
        echo utf8_decode($errorMsg);
	}  
    exit;
  }
  
 /*
  * Get order reference from the novalnet_callback_history table on shop database
  *
  * @return array
  */
  function getOrderReference() { 
	  global $wpdb;
    $tid = $this->aryCaptureparams['shop_tid'];
    $orderRefQry = $wpdb->get_row("select order_no,callback_amount, amount, payment_name from wp_novalnet_callback_script where tid = '".$tid."' ORDER BY id DESC LIMIT 1",ARRAY_A);
	$order_no = ($orderRefQry['order_no']);
	
  if (empty($orderRefQry) && !empty($this->aryCaptureparams['order_no'])) { 
	  # handle communication failure
		$this->handleCommunicationFailure();
       } 	
  
     if (!empty($orderRefQry)) {
      $orderRefQry['tid'] = $tid;
    
      $payment_type = strtoupper($orderRefQry['payment_name']);
      list($orderRefQry['order_current_status']) = self::getOrderCurrentStatus($order_no);
    
      if(in_array($payment_type, array('NOVALNET_INVOICE','NOVALNET_PREPAYMENT'))) {
                
      }
      
      $orderRefQry['order_total_amount'] = $orderRefQry['amount'];
      //Collect paid amount information from the novalnet_callback_history
      $orderRefQry['order_paid_amount'] = 0;
      $payment_type_level = self::getPaymentTypeLevel();     
      
      if (in_array($payment_type_level,array(0,2))) {
        $dbCallbackTotalVal = $wpdb->get_var("select sum(callback_amount) as amount_total from wp_novalnet_callback_script where order_no = '".$order_no."'");
         $orderRefQry['order_paid_amount'] = (isset($dbCallbackTotalVal)) ? $dbCallbackTotalVal : 0 ;
      }
   
      if (!isset($orderRefQry['payment_name']) || !in_array($this->aryCaptureparams['payment_type'], $this->aryPaymentGroups[$orderRefQry['payment_name']])) {
          self::display_message('Novalnet callback received. Payment Type [' . $this->aryCaptureparams['payment_type'] . '] is not valid.');
      } 
      if (!empty($this->aryCaptureparams['order_no']) && $this->aryCaptureparams['order_no'] != $order_no) {
          self::display_message('Novalnet callback received. Order Number is not valid.');
      }
    } else {
        self::display_message('Transaction mapping failed');
    }
    return $orderRefQry;
  }
 
  /*
  * Get orders_status from the orders table on shop database
  * @param $order_id
  *
  * @return array
  */
  function getOrderCurrentStatus($order_id = '') {
	global $wpdb;
	$detailstable=$wpdb->prefix.'posts';
    $order_status = $wpdb->get_row("select post_status from $detailstable where post_title = '".$order_id."' ",ARRAY_A);
    $orderRefQry['orders_status'] = $order_status['post_status'];
    return array($orderRefQry['orders_status']);
  }
  
  
   /*
  * Get given payment_type level for process
  *
  * @return integer
  */
  function getPaymentTypeLevel() {
      if(in_array($this->aryCaptureparams['payment_type'], $this->aryPayments)) {
        return 0;
      }
      else if(in_array($this->aryCaptureparams['payment_type'], $this->aryChargebacks)) {
        return 1;
      }
      else if(in_array($this->aryCaptureparams['payment_type'], $this->aryCollection)) {
        return 2;
      }
  }
  
  /*
  * Return capture parameters
  *
  * @return array
  */
  function getCaptureParams() {
    // DO THE STEPS FOR PARAMETER VALIDATION / PARAMETERS MAPPING WITH SHOP BASED PROCESS IF REQUIRED
    return $this->aryCaptureparams;
  }
  

  /*
  * Set orders_status from the orders table on shop database
  * @param $order_id 
  *
  * 
  */
  function updateOrderComment($post_id, $novalnet_order_details) {	  
	 $previous_value = get_post_meta( $post_id, 'novalnet_comments', true );
	 $comments = $previous_value."<br><br>".$novalnet_order_details;
	  update_post_meta( $post_id, 'novalnet_comments', $comments);	   
  }
  		

  	 function callback_script_table_insert($data) {	
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
  		
	/*
	 * handle the order on the communication failure
	 *
	 * @param none
	 * return none
	 */
  function handleCommunicationFailure() {
	  global $wpdb,$tc, $payment_type;

	  $detailstable=$wpdb->prefix.'posts';
	  $aryCaptureparams = $this->getCaptureParams();
	  $order = $wpdb->get_results("select ID,post_status from $detailstable where post_title='".$aryCaptureparams['order_no']."'",ARRAY_A);
	  $post_id = $tc->order_to_post_id($aryCaptureparams['order_no']);
	if($order){
		$serialize_amount_details = get_post_meta($order[0]['ID']);
		$amount_details = unserialize($serialize_amount_details['tc_payment_info'][0]);
		$param = $this->getCaptureParams();
		$novalnet_payment_type=self::get_payment_name_by_type($param['payment_type']);
		$order_total_amount = $amount_details['total'] * 100;
		
				$payment_name = $this->get_payment_text($aryCaptureparams['payment_type'],isset($aryCaptureparams['invoice_type']) && $aryCaptureparams['invoice_type'] == 'invoice' ? true : false);
				
				$callback_comments = PHP_EOL . $payment_name;
				$callback_comments .= PHP_EOL .__('Novalnet Transaction ID : ','novalnet').$aryCaptureparams['tid'];
				if(!empty($aryCaptureparams['test_mode']))
				{
					$callback_comments .= PHP_EOL . __('Test order','novalnet');
				}				
				if (!in_array($aryCaptureparams['tid_status'], array(90,91,98,99,100))) {
					$aryCaptureparams['amount'] = 0;
					$callback_comments .= "<br>" .(isset($aryCaptureparams['status_text'])?$aryCaptureparams['status_text'] : (isset($aryCaptureparams['status_message'])? $aryCaptureparams['status_message']:(isset($aryCaptureparams['status_desc'])?$aryCaptureparams['status_desc']:'')));
					
					$shop_payment_info = get_post_meta($post_id,'tc_payment_info');
					$shop_payment_info[0]['transaction_id'] = $callback_comments;
					update_post_meta( $post_id, 'tc_payment_info', $shop_payment_info[0]);
					self::updateOrderComment($post_id, $callback_comments);
					$tc->update_order_status( $post_id, 'order_fraud' );
				} else {
					$shop_payment_info = get_post_meta($post_id,'tc_payment_info');
					$shop_payment_info[0]['transaction_id'] = $callback_comments;
					update_post_meta( $post_id, 'tc_payment_info', $shop_payment_info[0]);
					self::updateOrderComment($post_id, $callback_comments);
					$tc->update_order_payment_status( $aryCaptureparams['order_no'], true );
				}
				
				$data = array( 			
					'order_no' => $aryCaptureparams['order_no'],
					'tid' => $aryCaptureparams['shop_tid'],
					'reference_tid' => '',
					'callback_amount' => 0,
					'callback_datetime' => date("Y-m-d H:i:s"),
					'amount' => $order_total_amount,
					'payment_name' => $novalnet_payment_type
                 );
		self::callback_script_table_insert($data);
		self::display_message($aryCaptureparams['payment_type'] . ' payment status updated!');		 
	}
	else{
		 self::display_message('Invalid Order');
	}
	}
	
	function get_payment_name_by_type($type){
		/**
		 * return payment name based on payment type parameter of callback param
		 * 
		 **/
		 $payment = array(
				'CREDITCARD'		=>	'novalnet_cc',						
				'DIRECT_DEBIT_SEPA'	=>	'novalnet_sepa',
				'IDEAL'				=>	'novalnet_ideal',
				'ONLINE_TRANSFER'	=>	'novalnet_instant',
				'PAYPAL'			=>	'novalnet_paypal',
				'INVOICE_START'		=>	'novalnet_prepayment',
				'INVOICE_CREDIT'	=>	'novalnet_invoice',
				'EPS'               =>  'novalnet_eps',
			    'GIROPAY'           =>  'novalnet_giropay',
			    'PRZELEWY24'        =>  'novalnet_przelewy24',
			    'CASHPAYMENT'       =>  'novalnet_cashpayment', 
			    'PRZELEWY24'        =>  'novalnet_przelewy24',    
				);
				if(isset($payment[$type])) return $payment[$type];
				else return "";		
	}
	
	function get_payment_text($type, $invoice = false) {
		$payment = array(
			'CREDITCARD'          => __( 'Credit Card', 'novalnet' ),						
			'DIRECT_DEBIT_SEPA'   => __( 'Direct Debit SEPA', 'novalnet' ),
			'IDEAL'               => __( 'iDeal', 'novalnet' ),
			'ONLINE_TRANSFER'     => __( 'Instant Bank Transfer', 'novalnet' ),
			'PAYPAL'              => __( 'PayPal', 'novalnet' ),
			'INVOICE_START'       => __( 'Prepayment', 'novalnet' ),
			'INVOICE_CREDIT'      => __( 'Prepayment', 'novalnet' ),
			'EPS'                 => __( 'eps', 'novalnet' ),
			'GIROPAY'             => __( 'giropay', 'novalnet' ),
			'PRZELEWY24'          => __( 'przelewy24', 'novalnet' ),
			'CASHPAYMENT'         => __( 'Barzahlen/viacash', 'novalnet' ),
			);
		if ($invoice) {	 
			$payment['INVOICE_START'] = __( 'Invoice', 'novalnet' );
			$payment['INVOICE_CREDIT'] = __( 'Invoice', 'novalnet' );
		}
			if(isset($payment[$type])) return $payment[$type];
			else return "";		
	}
  
}
?>
