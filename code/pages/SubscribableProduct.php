<?php

class SubscribableProduct extends ProductPage {

    private static $has_many = array(
        'SubscriptionOptions' => 'SubscriptionItem'
    );

    private static $singular_name = 'Subscribable Product';
    private static $plural_name = 'Subscribable Products';
    private static $description = 'A product subscription that can be added to the shopping cart';

    public function getCMSFields() {
        $fields = parent::getCMSFields();

        $fields->removeByName('Options');
        $fields->removeByName('Discounts');

        // Subscription Options field
        $config = GridFieldConfig_RelationEditor::create();
        if (class_exists('GridFieldBulkManager')) $config->addComponent(new GridFieldBulkManager());
        if (class_exists('GridFieldSortableRows')){
            $config->addComponent(new GridFieldSortableRows('SortOrder'));
            $products = $this->SubscriptionOptions()->sort('SortOrder');
        }else{
            $products = $this->SubscriptionOptions();
        }
        $config->removeComponentsByType('GridFieldAddExistingAutocompleter');
        $prodSubField = GridField::create(
            'SubscriptionOptions',
            _t('ProductPage.SubscriptionOptions', 'Options'),
            $products,
            $config
        );

        // Subscription Options Tab
        $fields->addFieldsToTab('Root.Subscription', array(
            HeaderField::create('SubscriptionHD', _t('ProductPage.SubscriptionHD', 'Subscription Options'), 2),
            LiteralField::create('SubscriptionDescrip', _t(
                'Page.SubscriptionDescrip',
                '<p>Product Options allow products to be customized by attributes such as size or color.
                    Options can also modify the product\'s price, weight or code.</p>'
            )),
            $prodSubField
        ));

        return $fields;
    }

}

class SubscribableProduct_Controller extends ProductPage_Controller {

    public function PurchaseForm() {

        $form = parent::PurchaseForm();
        $fields = $form->Fields();

        // remove quantity field
        $fields->removeByName('quantity');

        return $form;

    }

}