<?php

class FoxyStripe_Controller extends Page_Controller {
	
	const URLSegment = 'foxystripe';

	public function getURLSegment() {
		return self::URLSegment;
	}
	
	static $allowed_actions = array(
		'index',
        'sso',
        'parseOrders'
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
        foreach ($orders->transactions->transaction as $order) {

            // if FoxyCart order id, then parse order
            if (isset($order->id)) {

                ($transaction = Order::get()->filter('Order_ID', (int) $order->id)->First()) ?
                    $transaction = Order::get()->filter('Order_ID', (int) $order->id)->First() :
                    $transaction = Order::create();

                // save base order info
                $transaction->Order_ID = (int) $order->id;
                $transaction->Response = $decrypted;

                // record transaction as order
                $transaction->write();

                // parse order
                $result = $this->parseOrder($transaction->ID);
            }

        }
    }

    public function parseOrder($id) {

        if ($transaction = Order::get()->byID($id)) {

            if (($transaction->Response)) {

                // grab response, parse as XML
                $orders = new SimpleXMLElement($transaction->Response);



                $this->parseOrderInfo($orders, $transaction);
                $this->parseOrderCustomer($orders, $transaction);
                // record transaction so user info can be accessed from parseOrderDetails()
                $transaction->write();
                $this->parseOrderDetails($orders, $transaction);

                // record transaction as order
                $transaction->write();

                return true;

            } else {

                return false;

            }
        }
    }

    public function parseOrderInfo($orders, $transaction) {

        foreach ($orders->transactions->transaction as $order) {

            // Record transaction data from FoxyCart Datafeed:
            $transaction->Store_ID = (int)$order->store_id;
            $transaction->TransactionDate = (string)$order->transaction_date;
            $transaction->ProductTotal = (float)$order->product_total;
            $transaction->TaxTotal = (float)$order->tax_total;
            $transaction->ShippingTotal = (float)$order->shipping_total;
            $transaction->OrderTotal = (float)$order->order_total;
            $transaction->ReceiptURL = (string)$order->receipt_url;
            $transaction->OrderStatus = (string)$order->status;
        }
    }

    public function parseOrderCustomer($orders, $transaction) {

        foreach ($orders->transactions->transaction as $order) {

            // if not a guest transaction in FoxyCart
            if (isset($order->customer_email) && $order->is_anonymous == 0) {

                // if Customer is existing member, associate with current order
                if(Member::get()->filter('Email', $order->customer_email)->First()) {

                    $customer = Member::get()->filter('Email', $order->customer_email)->First();

                // if new customer, create account with data from FoxyCart
                } else {

                    // set PasswordEncryption to 'none' so imported, encrypted password is not encrypted again
                    Config::inst()->update('Security', 'password_encryption_algorithm', 'none');

                    // create new Member, set password info from FoxyCart
                    $customer = Member::create();
                    $customer->Customer_ID = (int)$order->customer_id;
                    $customer->FirstName = (string)$order->customer_first_name;
                    $customer->Surname = (string)$order->customer_last_name;
                    $customer->Email = (string)$order->customer_email;
                    $customer->Password = (string)$order->customer_password;
                    $customer->Salt = (string)$order->customer_password_salt;
                    $customer->PasswordEncryption = 'none';

                    // record member record
                    $customer->write();
                }

                // set Order MemberID
                $transaction->MemberID = $customer->ID;

            }
        }
    }

    public function parseOrderDetails($orders, $transaction) {

        // remove previous OrderDetails so we don't end up with duplicates
        foreach ($transaction->Details() as $detail) {
            $detail->delete();
        }

        foreach ($orders->transactions->transaction as $order) {

            // Associate ProductPages, Options, Quantity with Order
            foreach ($order->transaction_details->transaction_detail as $product) {

                $OrderDetail = OrderDetail::create();

                // set Quantity
                $OrderDetail->Quantity = (int)$product->product_quantity;

                // set calculated price (after option modifiers)
                $OrderDetail->Price = (float)$product->product_price;

                $OrderDetail->ProductName = (string)$product->product_name;
                $OrderDetail->ProductCode = (string)$product->product_code;
                $OrderDetail->ProductImage = (string)$product->image;
                $OrderDetail->ProductCategory = (string)$product->category_code;

                // Find product via product_id custom variable
                foreach ($product->transaction_detail_options->transaction_detail_option as $option) {
                    if ($option->product_option_name == 'product_id') {

                        $OrderProduct = ProductPage::get()
                            ->filter('ID', (int)$option->product_option_value)
                            ->First();

                        // if product could be found, then set Option Items
                        if ($OrderProduct) {
                            // set ProductID
                            $OrderDetail->ProductID = $OrderProduct->ID;
                        }

                    } else {

                        $OrderOption = OrderOption::create();
                        $OrderOption->Name = (string) $option->product_option_name;
                        $OrderOption->Value = (string) $option->product_option_value;
                        $OrderOption->write();
                        $OrderDetail->OrderOptions()->add($OrderOption);

                    }

                    // associate with this order
                    $OrderDetail->OrderID = $transaction->ID;

                    // extend OrderDetail parsing, allowing for recording custom fields from FoxyCart
                    $this->extend('handleOrderItem', $decrypted, $product, $OrderDetail);

                    // write
                    $OrderDetail->write();

                }
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
	 * Get the current DataObject Item from the ID if one exists
	 */
    public function getCurrentItemByID($itemID = null) {


        if($itemID) {
            return Order::get()->byID($itemID);
        } elseif(isset($params['ID'])) {
            //Sanitize
            $ID = Convert::raw2sql($params['ID']);

            return Order::get()->filter("ID", $ID)->first();
        }
    }

    /*
     * Re-parse all Orders from XML in Response field
     */
    public function parseOrders() {
        if (Permission::check('Product_ORDERS')) {
            $params = $this->request->allParams();
            if (isset($params['ID'])) {

                $ID = Convert::raw2sql($params['ID']);
                $order = Order::get()->byID($ID);
                debug::show($order);
                $this->parseOrder($order->ID);
                return 'Order Updated';

            } else {

                $ct = 0;
                foreach (Order::get() as $order) {
                    if ($this->parseOrder($order->ID)) $ct++;
                }
                return $ct . ' orders updated';
            }
        } else {
            return Security::permissionFailure();
        }
    }
	
}