<?php

class SubscriptionItem extends OptionItem {

    private static $db = array(

    );

    private static $has_one = array(
        'SubscribableProduct' => 'SubscribableProduct'
    );

    public function getCMSFields() {
        $fields = parent::getCMSFields();

        $fields->fieldByName("Root.Main.DetailsHD")->setTitle('Subscription Options');
        $fields->fieldByName("Root.Main.Title")->setTitle('Subscription Option Name');
        $fields->removeByName("ProductOptionGroupID");

        return $fields;

    }

}