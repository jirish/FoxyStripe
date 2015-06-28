<?php

class FoxyStripe_Controller extends Page_Controller {
	
	const URLSegment = 'foxystripe';

	public function getURLSegment() {
		return self::URLSegment;
	}
	
	static $allowed_actions = array(
		'index',
        'sso',
        'encryptResponse'
	);
	
	public function index() {

	    // handle POST from FoxyCart API transaction
		if ((isset($_POST["FoxyData"]) OR isset($_POST['FoxySubscriptionData']))) {

			$FoxyData_encrypted = (isset($_POST["FoxyData"])) ?
                urldecode($_POST["FoxyData"]) :
                urldecode($_POST["FoxySubscriptionData"]);
			$FoxyData_decrypted = rc4crypt::decrypt(FoxyCart::getStoreKey(),$FoxyData_encrypted);

            // parse the response and save the order
			self::handleDataFeed($FoxyData_encrypted, $FoxyData_decrypted);
			
			// extend to allow for additional integrations with Datafeed
			$this->extend('addIntegrations', $FoxyData_encrypted);
			
			return 'foxy';
			
		} else {
			
			return "No FoxyData or FoxySubscriptionData received.";
			
		}
	}

    public function handleDataFeed($encrypted, $decrypted){

        $orders = new SimpleXMLElement($decrypted);

        // loop over each transaction to find FoxyCart Order ID
        foreach ($orders->transactions->transaction as $transaction) {

            // if FoxyCart order id, then parse order
            if (isset($transaction->id)) {

                ($order = Order::get()->filter('Order_ID', (int) $transaction->id)->First()) ?
                    $order = Order::get()->filter('Order_ID', (int) $transaction->id)->First() :
                    $order = Order::create();

                // save base order info
                $order->Order_ID = (int) $transaction->id;
                $order->Response = urlencode($encrypted);
                $order->write();

                // parse order
                $order->parseOrder();
            }

        }
    }





	// Single Sign on integration with FoxyCart
    public function sso() {

	    // GET variables from FoxyCart Request
        $fcsid = $this->request->getVar('fcsid');
        $timestampNew = strtotime('+30 days');

        // get current member if logged in. If not, create a 'fake' user with Customer_ID = 0
        // fake user will redirect to FC checkout, ask customer to log in
        // to do: consider a login/registration form here if not logged in
        if($Member = Member::currentUser()) {
            $Member = Member::currentUser();
        } else {
            $Member = new Member();
            $Member->Customer_ID = 0;
        }

        $auth_token = sha1($Member->Customer_ID . '|' . $timestampNew . '|' . FoxyCart::getStoreKey());

        $redirect_complete = 'https://' . FoxyCart::getFoxyCartStoreName() . '.foxycart.com/checkout?fc_auth_token=' . $auth_token .
            '&fcsid=' . $fcsid . '&fc_customer_id=' . $Member->Customer_ID . '&timestamp=' . $timestampNew;
	
	    $this->redirect($redirect_complete);

    }

    /*
     * encrypt responses that were saved unencrypted
     */
    public function encryptResponse() {

        if (Permission::check('Product_ORDERS')) {

            $ct = 0;
            $needle = '<?xml version="1.0" encoding="UTF-8"';
            $needle2 = "<?xml version='1.0' encoding='UTF-8'";
            $length = strlen($needle);

            foreach (Order::get() as $order) {

                if (substr($order->Response, 0, $length) === $needle || substr($order->Response, 0, $length) === $needle2) {

                    $encrypted = rc4crypt::encrypt(FoxyCart::getStoreKey(), $order->Response);
                    $encrypted = urlencode($encrypted);

                    $order->Response = $encrypted;
                    $order->write();
                    $ct++;
                }

            }
            return $ct . ' order responses encrypted';

        } else {
            return Security::permissionFailure();
        }
    }
	
}