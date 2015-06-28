<?php

class Order extends DataObject implements PermissionProvider{

	private static $db = array(
        'Order_ID' => 'Int',
        'TransactionDate' => 'SS_Datetime',
        'ProductTotal' => 'Currency',
        'TaxTotal' => 'Currency',
        'ShippingTotal' => 'Currency',
        'OrderTotal' => 'Currency',
        'ReceiptURL' => 'Varchar(255)',
        'OrderStatus' => 'Varchar(255)',
        'Response' => 'Text'
    );

	private static $has_one = array(
        'Member' => 'Member'
    );

	private static $has_many = array(
        'Details' => 'OrderDetail'
    );

    private static $singular_name = 'Order';
    private static $plural_name = 'Orders';
    private static $description = 'Orders from FoxyCart Datafeed';
    private static $default_sort = 'TransactionDate DESC, ID DESC';

    private static $summary_fields = array(
        'Order_ID',
        'TransactionDate.NiceUS',
        'Member.Name',
        'ProductTotal.Nice',
        'ShippingTotal.Nice',
        'TaxTotal.Nice',
        'OrderTotal.Nice',
        'ReceiptLink'
    );

	private static $searchable_fields = array(
        'Order_ID',
        'TransactionDate' => array(
            "field" => "DateField",
            "filter" => "PartialMatchFilter"
        ),
        'Member.ID',
        'OrderTotal',
        'Details.ProductID'
    );

    private static $casting = array(
        'ReceiptLink' => 'HTMLVarchar'
    );
    
    private static $indexes = array(
        'Order_ID' => true // make unique
    );

    function fieldLabels($includerelations = true) {
        $labels = parent::fieldLabels();

        $labels['Order_ID'] = _t('Order.Order_ID', 'Order ID#');
        $labels['TransactionDate'] = _t('Order.TransactionDate', "Date");
        $labels['TransactionDate.NiceUS'] = _t('Order.TransactionDate', "Date");
        $labels['Member.Name'] = _t('Order.MemberName', 'Customer');
        $labels['Member.ID'] = _t('Order.MemberName', 'Customer');
        $labels['ProductTotal.Nice'] = _t('Order.ProductTotal', 'Sub Total');
        $labels['TaxTotal.Nice'] = _t('Order.TaxTotal', 'Tax');
        $labels['ShippingTotal.Nice'] = _t('Order.ShippingTotal', 'Shipping');
        $labels['OrderTotal'] = _t('Order.OrderTotal', 'Total');
        $labels['OrderTotal.Nice'] = _t('Order.OrderTotal', 'Total');
        $labels['ReceiptLink'] = _t('Order.ReceiptLink', 'Invoice');
        $labels['Details.ProductID'] = _t('Order.Details.ProductID', 'Product');

        return $labels;
    }

    function ReceiptLink() {
        return $this->getReceiptLink();
    }

    function getReceiptLink(){
        $obj= HTMLVarchar::create();
        $obj->setValue('<a href="' . $this->ReceiptURL . '" target="_blank" class="cms-panel-link action external-link">view</a>');
        return $obj;
    }

    public function getDecryptedResponse() {
        $decrypted = urldecode($this->Response);
        return rc4crypt::decrypt(FoxyCart::getStoreKey(), $decrypted);
    }

    public function onBeforeWrite() {

        $this->parseOrder();
        parent::onBeforeWrite();
    }

    public function parseOrder() {

        if ($this->getDecryptedResponse()) {

            $response = new SimpleXMLElement($this->getDecryptedResponse());

            $this->parseOrderInfo($response);
            $this->parseOrderCustomer($response);
            $this->parseOrderDetails($response);

            return true;

        } else {

            return false;

        }
    }

    public function parseOrderInfo($response) {

        foreach ($response->transactions->transaction as $transaction) {

            // Record transaction data from FoxyCart Datafeed:
            $this->Store_ID = (int) $transaction->store_id;
            $this->TransactionDate = (string) $transaction->transaction_date;
            $this->ProductTotal = (float) $transaction->product_total;
            $this->TaxTotal = (float) $transaction->tax_total;
            $this->ShippingTotal = (float) $transaction->shipping_total;
            $this->OrderTotal = (float) $transaction->order_total;
            $this->ReceiptURL = (string) $transaction->receipt_url;
            $this->OrderStatus = (string) $transaction->status;

            $this->extend('handleOrderInfo', $order, $response);
        }
    }

    public function parseOrderCustomer($response) {

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
                $this->MemberID = $customer->ID;

                $this->extend('handleOrderCustomer', $order, $response, $customer);

            }
        }
    }

    public function parseOrderDetails($response) {

        // remove previous OrderDetails and OrderOptions so we don't end up with duplicates
        foreach ($this->Details() as $detail) {
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

                // parse OrderOptions
                foreach ($detail->transaction_detail_options->transaction_detail_option as $option) {

                    // Find product via product_id custom variable
                    if ($option->product_option_name == 'product_id') {

                        // if product is found, set relation to OrderDetail
                        $OrderProduct = ProductPage::get()->byID((int) $option->product_option_value);
                        if ($OrderProduct) $OrderDetail->ProductID = $OrderProduct->ID;

                    } else {

                        $OrderOption = OrderOption::create();
                        $OrderOption->Name = (string) $option->product_option_name;
                        $OrderOption->Value = (string) $option->product_option_value;
                        $OrderOption->write();
                        $OrderDetail->OrderOptions()->add($OrderOption);

                    }

                    $this->extend('handleOrderOption', $order, $response, $OrderDetail, $option);

                }

                // associate with this order
                $OrderDetail->OrderID = $this->ID;

                // extend OrderDetail parsing, allowing for recording custom fields from FoxyCart
                $this->extend('handleOrderItem', $order, $response, $OrderDetail);

                // write
                $OrderDetail->write();

            }
        }
    }


	public function canView($member = false) {
		return Permission::check('Product_ORDERS');
	}

	public function canEdit($member = null) {
        return false;
        //return Permission::check('Product_ORDERS');
	}

	public function canDelete($member = null) {
        //return false;
        return Permission::check('Product_ORDERS');
	}

	public function canCreate($member = null) {
		return false;
	}

	public function providePermissions() {
		return array(
			'Product_ORDERS' => 'Allow user to manage Orders and related objects'
		);
	}

}