<?php

class FoxyStripe_Controller extends Page_Controller {
	
	const URLSegment = 'foxystripe';

	public function getURLSegment() {
		return self::URLSegment;
	}
	
	static $allowed_actions = array(
		'index',
        'sso',
        'parseOrders',
        'encryptResponse'
	);
	
	public function index() {
	    // handle POST from FoxyCart API transaction
		if ((isset($_POST["FoxyData"]) OR isset($_POST['FoxySubscriptionData']))) {
			$FoxyData_encrypted = (isset($_POST["FoxyData"])) ?
                urldecode($_POST["FoxyData"]) :
                urldecode($_POST["FoxySubscriptionData"]);
			$FoxyData_decrypted = rc4crypt::decrypt(FoxyCart::getStoreKey(),$FoxyData_encrypted);
			self::handleDataFeed($FoxyData_encrypted, $FoxyData_decrypted);
			
			// extend to allow for additional integrations with Datafeed
			$this->extend('addIntegrations', $FoxyData_encrypted);
			
			return 'foxy';
			
		} else {
			
			return "No FoxyData or FoxySubscriptionData received.";
			
		}
	}

    public function handleDataFeed($encrypted, $decrypted){

        //handle encrypted & decrypted data
        $orders = simplexml_load_string($decrypted, NULL, LIBXML_NOCDATA);

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

                // record transaction as order
                $order->write();

                // parse order
                $this->parseOrder($order->ID);
            }

        }
    }

    public function parseOrder($id) {

        if ($order = Order::get()->byID($id)) {

            if ($order->Response) {

                // grab response, decrypt, parse as XML
                $decrypted = urldecode($order->Response);
                $decrypted = rc4crypt::decrypt(FoxyCart::getStoreKey(), $decrypted);
                $response = new SimpleXMLElement($decrypted);

                $this->parseOrderInfo($order, $response);
                $this->parseOrderCustomer($order, $response);
                $this->parseOrderDetails($order, $response);

                // record transaction as order
                $order->write();

                return true;

            } else {

                return false;

            }
        }
    }

    public function parseOrderInfo($order, $response) {

        foreach ($response->transactions->transaction as $transaction) {

            // Record transaction data from FoxyCart Datafeed:
            $order->Store_ID = (int) $transaction->store_id;
            $order->TransactionDate = (string) $transaction->transaction_date;
            $order->ProductTotal = (float) $transaction->product_total;
            $order->TaxTotal = (float) $transaction->tax_total;
            $order->ShippingTotal = (float) $transaction->shipping_total;
            $order->OrderTotal = (float) $transaction->order_total;
            $order->ReceiptURL = (string) $transaction->receipt_url;
            $order->OrderStatus = (string) $transaction->status;
        }
    }

    public function parseOrderCustomer($order, $response) {

        foreach ($response->transactions->transaction as $transaction) {

            // if not a guest transaction in FoxyCart
            if (isset($transaction->customer_email) && $transaction->is_anonymous == 0) {

                // if Customer is existing member, associate with current order
                if(Member::get()->filter('Email', $transaction->customer_email)->First()) {

                    $customer = Member::get()->filter('Email', $transaction->customer_email)->First();

                // if new customer, create account with data from FoxyCart
                } else {

                    // set PasswordEncryption to 'none' so imported, encrypted password is not encrypted again
                    Config::inst()->update('Security', 'password_encryption_algorithm', 'none');

                    // create new Member, set password info from FoxyCart
                    $customer = Member::create();
                    $customer->Customer_ID = (int) $transaction->customer_id;
                    $customer->FirstName = (string) $transaction->customer_first_name;
                    $customer->Surname = (string) $transaction->customer_last_name;
                    $customer->Email = (string) $transaction->customer_email;
                    $customer->Password = (string) $transaction->customer_password;
                    $customer->Salt = (string) $transaction->customer_password_salt;
                    $customer->PasswordEncryption = 'none';

                    // record member record
                    $customer->write();
                }

                // set Order MemberID
                $order->MemberID = $customer->ID;

            }
        }
    }

    public function parseOrderDetails($order, $response) {

        // remove previous OrderDetails and OrderOptions so we don't end up with duplicates
        foreach ($order->Details() as $detail) {
            foreach ($detail->OrderOptions() as $orderOption) {
                $orderOption->delete();
            }
            $detail->delete();
        }

        foreach ($response->transactions->transaction as $transaction) {

            // Associate ProductPages, Options, Quantity with Order
            foreach ($transaction->transaction_details->transaction_detail as $detail) {

                $OrderDetail = OrderDetail::create();

                $OrderDetail->Quantity = (int) $detail->product_quantity;
                $OrderDetail->Price = (float) $detail->product_price;
                $OrderDetail->ProductName = (string) $detail->product_name;
                $OrderDetail->ProductCode = (string) $detail->product_code;
                $OrderDetail->ProductImage = (string) $detail->image;
                $OrderDetail->ProductCategory = (string) $detail->category_code;

                // Find product via product_id custom variable
                foreach ($detail->transaction_detail_options->transaction_detail_option as $option) {
                    if ($option->product_option_name == 'product_id') {

                        // if product could be found, set relation to OrderDetail
                        $OrderProduct = ProductPage::get()->byID((int) $option->product_option_value);
                        if ($OrderProduct) $OrderDetail->ProductID = $OrderProduct->ID;

                    } else {

                        $OrderOption = OrderOption::create();
                        $OrderOption->Name = (string) $option->product_option_name;
                        $OrderOption->Value = (string) $option->product_option_value;
                        $OrderOption->write();
                        $OrderDetail->OrderOptions()->add($OrderOption);

                    }

                    // associate with this order
                    $OrderDetail->OrderID = $order->ID;

                }

                // extend OrderDetail parsing, allowing for recording custom fields from FoxyCart
                $this->extend('handleOrderItem', $order, $response, $OrderDetail);

                // write
                $OrderDetail->write();

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
     * Re-parse all Orders from XML in Response field
     */
    public function parseOrders() {
        if (Permission::check('Product_ORDERS')) {

            $ct = 0;
            foreach (Order::get() as $order) {
                if ($this->parseOrder($order->ID)) $ct++;
            }
            return $ct . ' orders updated';

        } else {
            return Security::permissionFailure();
        }
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