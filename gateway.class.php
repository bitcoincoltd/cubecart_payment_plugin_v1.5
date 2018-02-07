<?php

define('MODULE_PAYMENT_BXCOINPAY_TEXT_PAYMSG','Select currency to send coins');
define('IMAGE_BUTTON_CONFIRM_ORDER','Make Payment');
define('MODULE_PAYMENT_BXCOINPAY_TEXT_AFTERPAY','After you have completed payment please click the &quot;'.IMAGE_BUTTON_CONFIRM_ORDER.'&quot; button');
define('MODULE_PAYMENT_BXCOINPAY_TEXT_ADDRESS','Address:');
define('MODULE_PAYMENT_BXCOINPAY_TEXT_AMOUNT','Amount:');
define('MODULE_PAYMENT_BXCOINPAY_TEXT_COUNTDOWN','You must send the bitcoins within the next %s Minutes %s Seconds');
define('MODULE_PAYMENT_BXCOINPAY_TEXT_COUNTDOWN_EXP','Coinpay payment time has expired, please refresh the page to get a new address');
define('MODULE_PAYMENT_BXCOINPAY_TEXT_ERROR','Sorry Coinpay payments are currently unavailable');
define('MODULE_PAYMENT_BXCOINPAY_TITLE_ERROR','Error');

// Add main classes
include_once('includes/coinpay_api_client.php');

class Gateway {
	private $_config;
	private $_module;
	private $_basket;
	private $_result_message;
	private $api;
	private $enabled;
  private $expecting;

	public function __construct($module = false, $basket = false) {
		$this->_db		=& $GLOBALS['db'];
		$this->_module	= $module;
		$this->_basket =& $GLOBALS['cart']->basket;
		$this->_config['ipn_url'] = $GLOBALS['storeURL'].'/index.php?_g=rm&type=gateway&cmd=call&module=BXCoinPay';
		$this->enabled = true;
    $this->expecting = false;
		$this->api = new CoinpayApiClient($this->_module['api_id']);


		if(!$this->api){
			$this->enabled = false;
		}
	}

	##################################################

	public function transfer() {
		$transfer	= array(
			'action'	=> currentPage(),
			'method'	=> 'post',
			'target'	=> '_self',
			'submit'	=> 'manual',
		);
		return $transfer;
	}

	##################################################

	public function repeatVariables() {
		return (isset($hidden)) ? $hidden : false;
	}

	public function fixedVariables() {
		$hidden['gateway']	= basename(dirname(__FILE__));
		return (isset($hidden)) ? $hidden : false;
	}

	public function call() {
    $data = json_decode(file_get_contents("php://input"), true);

    if( $this->api->validIPN($data) ) {
      $order_id = $data['order_id'];
      $order = Order::getInstance();
      $order_summary = $order->getSummary($order_id);

      $order->paymentStatus(Order::PAYMENT_SUCCESS, $order_id);
      $order->orderStatus(Order::ORDER_PROCESS, $order_id);

      ## Build the transaction log data
      $transData = array();
      $transData['gateway']		= 'BX CoinPay';
      $transData['order_id']		= $order_id;
      $transData['trans_id']		= $data['order_id'];
      $transData['status']		= $data['message'];
      $transData['notes'][]	= 'BX CoinPay IPN: '.$data['message'];
      $order->logTransaction($transData);

      $this->add_order_note("[BX CoinPay paid in full!]", $order_id);

      ob_end_clean();
      echo 'IPN Success';
      exit();
    }else{
      ob_end_clean();
      header("HTTP/1.0 403 Forbidden");
      echo 'IPN Failed';
      exit();
    }

  }

  public function process() {

		//$order				= Order::getInstance();

    // Check if already paid
    $result = $this->api->checkPaymentReceived(
      $_SESSION['bx_payment_addresses']
    );

    // Give error if no result
    if( !$result ) {
      $this->_result_message = "Payment error: ". $result->error;
    }

    // Give error if no payment found
    if( $result->payment_received === false ) {
      $this->_result_message = "
        Did you already pay it? We still did not see your payment!
        It can take a few seconds for your payment to appear.
        If you already paid - press button again.";
      return;
    }

    // Give error if payment is not enough
    if( $result->is_enough === false ) {
      $str = 'Payment amount in not enough. Got: ';
      foreach( $result->paid as $key => $value ) {
        $str .= " {$value->amount} in {$value->cryptocurrency}; ";
      }
      $this->_result_message = $str;
      $this->add_order_note($str, $_SESSION['order_id']);
      return;
    }

    // If pass all error check, then save order id
    $order_saved = $this->api->saveOrderId(
      $_SESSION['bx_payment_addresses'],
      $_SESSION['order_id']
    );

    if( $order_saved === false ) {
      $this->_result_message = "Something went wrong! Order ID can't be saved: "
        . $order_saved->error;
      return;
    }


    // if all pass, redirect to complete
    $this->add_order_note(
      "[BX CoinPay: Payment awaiting confirmation] ",
      $_SESSION['order_id']
    );
    $this->add_order_note(
      $this->payments_received($result),
      $_SESSION['order_id']
    );

    unset($_SESSION['order_id']);
    unset($_SESSION['bx_payment_addresses']);
    unset($_SESSION['payment_details_hash']);
    unset($_SESSION['payment_details']);
    unset($_SESSION['payment_received']);
    unset($_SESSION['expecting']);
    httpredir(currentPage(array('_g', 'type', 'cmd', 'module'), array('_a' => 'complete')));

  }

  /**
   * @return string
   */
	public function form() {

		## Process transaction
		if (isset($_POST['order_id'])) {
			$return	= $this->process();
		}

		// Display payment result message
		if (!empty($this->_result_message))	{
			$GLOBALS['gui']->setError($this->_result_message);
		}

    $request = new PaymentDetailsRequest(
      $this->_config['ipn_url'],
      $this->_basket['total'],
      $GLOBALS['config']->get('config', 'default_currency'),
      (string)$this->_module['cryptocurrencies'],
      "payment for order"
    );

    // Refresh payment details if has in session
    if($this->paymentDetailsMustBeRefreshed($request) ) {
      $payment_details = $this->api->getPaymentDetails($request);
      $_SESSION['payment_details'] = $payment_details;
      $_SESSION['payment_details_hash'] = $request->hash();
    }else{
      $payment_details = $_SESSION['payment_details'];
    }

    if( !$payment_details ) {
      $this->getPaymentDetailsFailed();
    }

    // Loop addresses
    $addresses_arr = array();
    foreach( $payment_details as $key => $value ) {
      foreach( $value as $key => $item ) {
        array_push($addresses_arr, $item->address);
      }
    }
    $_SESSION['bx_payment_addresses'] = $addresses_arr;
    $_SESSION['order_id'] = $this->_basket['cart_order_id'];

    // Save comments
    $this->expecting_amount($payment_details);

    // render address
    include_once('includes/payment_fields.php');
    $fields .= "<input type='hidden' name='order_id' value='".$this->_basket['cart_order_id']."' >";
    return $fields;
	}

  /**
   * @return bool
   */
  protected function paymentDetailsMustBeRefreshed($request)
  {
    return $_SESSION['payment_details_hash'] != $request->hash()
      OR !$_SESSION['payment_details'];
  }

  /**
   * @return string
   */
  protected function getPaymentDetailsFailed()
  {
    echo "<p>Sorry, BX Coinpay payments are currencly unavailable: "
      . $this->api->getError() . "</p>";
  }

  /**
   * @return void
   */
  protected function expecting_amount($details)
  {
    $str = 'Expecting: ';
    foreach( $details->addresses as $key => $value ) {
      if( $value->available ) {
        $str .= " {$value->amount} in {$key} to {$value->address}; ";
      }
    }

    if( !isset($_SESSION['expecting']) ) {
      $this->add_order_note($str, $_SESSION['order_id']);
      $_SESSION['expecting'] = true;
    }
  }

  /**
   * @return string
   */
  protected function payments_received($result)
  {
    return "Paid: {$result->paid_by->amount} in {$result->paid_by->name} ({$result->paid_by->ticker}) to {$result->paid_by->address} proof link: {$result->paid_by->proof_link} ";
  }

  /**
   * @return void
   */
  protected function add_order_note($note, $order_id)
  {
    $GLOBALS['db']->insert('CubeCart_order_notes', [
     'admin_id' => 0,
     'cart_order_id' => $order_id,
     'content' => strip_tags($note),
     'time' => time()
    ]);
  }

}
?>
